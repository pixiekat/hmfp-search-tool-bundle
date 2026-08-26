<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Command;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\PhysicianVocabulary;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianTaxonomyManager;
use Pixiekat\SymfonyHelpers\Entity as HelperEntity;
use Pixiekat\HMFPSearchToolBundle\Repository\DepartmentRepository;
use Pixiekat\HMFPSearchToolBundle\Repository\FacilityRepository;
use Pixiekat\HMFPSearchToolBundle\Repository\PhysicianRepository;
use Pixiekat\SymfonyHelpers\Services\AuditLogManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the provider demographics extract into `physicians` and `departments`.
 *
 * Scope: A first pass over a twenty-column extract. Three of those columns are
 * currently modelled; the rest (NPI, specialty, languages, facility, booking
 * URLs, …) are read past and ignored.
 *
 *   Physician::legalName    ← first_name + middle_name + last_name
 *   Physician::credentials  ← degree
 *   Department::name        ← department, split on ';'
 *   Physician::departments  ← the many-to-many built from the above
 *
 * self::COLUMNS is the seam to widen when the entities grow: add the header
 * name there, then read it in the import pass.
 *
 * The file is read twice, which looks wasteful until you try to avoid it.
 *
 *   Pass 1 (scanFile) counts rows, collects every distinct department name, and
 *          builds a cred_id → department-names map.
 *   Then   every missing Department is created and flushed in one go, and their
 *          IDs are read back.
 *   Pass 2 streams the file again creating Physicians, attaching departments by
 *          ID as it goes.
 *
 * A single interleaved pass would have to create departments on demand while
 * physicians are being flushed and cleared in batches — and clear() detaches
 * every entity, so a cached Department object goes stale the moment the first
 * batch is written. Chasing that with refetches, or with a longer-lived second
 * EntityManager, costs more than reading a few MB twice. Doing all department
 * work up front means pass 2 only ever needs an integer ID, which survives
 * clear() perfectly well (see getReference() in importPhysicians()).
 *
 * The second reason is honesty about the data: pass 1 knows the FULL department
 * list for a physician before that physician is created, so their links are
 * written once, complete, rather than being appended to across a run.
 *
 * Everything about re-importing hinges on answering one question correctly:
 * "have I seen this person before?" The extract answers it with `cred_id`, a
 * per-provider credentialing UUID, which Physician now stores under a UNIQUE
 * index. That is the whole mechanism:
 *
 *   cred_id found      → UPDATE that physician in place
 *   cred_id not found  → INSERT a new physician
 *
 * Matching on name-plus-degree instead — which is all this command could do
 * before Physician::$credId existed — is wrong in both directions at once. It
 * cannot tell "same person, degree changed" from "a different person", so a
 * physician earning an MPH silently becomes two rows; and it cannot tell "two
 * different people" from "one person", so the three name collisions in the
 * sample extract (two Anne M. Valente MDs, two Jennifer W. Lees, two Sandeep
 * Kumars) silently merge into three rows instead of six.
 *
 * Physicians created before cred_id existed have NULL in that column, and there
 * is no way to derive their UUID from what is stored. So on its next run the
 * importer ADOPTS them: a provider whose cred_id is unknown falls back to
 * matching name-plus-degree against rows that have NO cred_id, claims the first
 * such row, and writes the cred_id in. After that run every imported row has a
 * real identity and the fallback goes unused.
 *
 * Two safeguards make this safe rather than a reintroduction of the old bug:
 *
 *   1. Only NULL-cred_id rows are adoptable. A row that already has a cred_id
 *      is never matched by name, so the second Anne M. Valente cannot hijack
 *      the first one's record.
 *   2. Each adoptable row may be claimed ONCE. It is removed from the candidate
 *      set the moment it is taken, so two same-named providers cannot both
 *      adopt it — the second correctly inserts as a new person.
 *
 * A physician who leaves simply stops appearing in the extract. Nothing is ever
 * deleted in response — one truncated export would otherwise wipe thousands of
 * rows and every bio with them. Instead every physician the import sees is
 * stamped with `lastSeenInImportAt`, so the ones it did not see are afterwards
 * identifiable by their stale timestamp. See
 * PhysicianRepository::countDepartedSince(), and note the NULL trap documented
 * there: a NULL stamp means "created by hand, never in an import", which is not
 * a departure.
 *
 * All of this rests on cred_id being stable ACROSS extracts, not merely within
 * one. It is verifiably consistent inside the sample file (no cred_id maps to
 * two different name/degree combinations), and the hospital's export team —
 * who define the extract's format — own its integrity. That is a deliberate
 * division of responsibility, not an unexamined assumption: this command
 * treats cred_id as authoritative BECAUSE the team producing it guarantees it.
 *
 * The consequence if that guarantee ever lapses is worth stating plainly, so a
 * future reader can recognise the symptom. A reissued cred_id does not error —
 * it looks like a brand-new provider, so the physician is INSERTED again and
 * the original is left behind to be reported as departed. The signature is
 * therefore a sudden run with a large 'created' count and a matching departure
 * count, on a file that should have been mostly 'unchanged'. If that ever
 * appears in the summary table, suspect the identifier before the importer.
 *
 *
 * Usage
 *   # parse and report without writing anything — always do this first
 *   php bin/console hmfp:import:physicians --dry-run
 *
 *   # import the sample extract that ships in this bundle's docs/ directory
 *   php bin/console hmfp:import:physicians --no-debug
 *
 *   # a different file, comma-delimited, first 500 providers only
 *   php bin/console hmfp:import:physicians /path/to/extract.csv -d ',' --limit=500
 *
 *   # physicians only, leaving departments and their links untouched
 *   php bin/console hmfp:import:physicians --skip-departments
 */
#[AsCommand(
  name: 'hmfp:import:physicians',
  description: 'Imports provider demographics (physicians, departments, and the links between them).',
)]
final class ImportPhysiciansCommand extends Command {

  /**
   * Header name → the meaning this command attaches to it.
   *
   * Resolving columns BY NAME rather than by position is the whole reason this
   * constant exists. The extract is generated upstream from a spreadsheet, and
   * a spreadsheet's column order is one careless drag away from changing. Name
   * lookup turns "someone moved a column" from silent data corruption — every
   * physician imported with a gender where their degree should be — into a
   * clean, loud failure before a single row is written.
   *
   * Keys are the internal names used throughout this class; values are the
   * literal header cells expected in the file.
   */
  private const COLUMNS = [
    'cred_id'     => 'cred_id',
    'first_name'  => 'first_name',
    'middle_name' => 'middle_name',
    'last_name'   => 'last_name',
    'degree'      => 'degree',
    'department'  => 'department',
    'facility_name' => 'facility_name',
    'specialty'     => 'specialty',
    'languages'     => 'languages',
  ];

  /**
   * Columns that must be present AND non-empty for a row to be importable.
   *
   * Note what is absent: `department`. 867 of the sample extract's rows have an
   * empty department cell, and a physician with no department recorded is still
   * a physician worth having — they simply arrive with an empty collection.
   * Requiring it would silently drop ~4% of the file.
   */
  private const REQUIRED = ['cred_id', 'last_name', 'degree'];

  /**
   * Separator WITHIN the department cell. See point 3 in the class docblock.
   */
  private const DEPARTMENT_SEPARATOR = ';';

  /**
   * Separator WITHIN the languages cell.
   *
   * A comma, not a semicolon — the extract uses a different convention for this
   * column than for department and specialty. See splitList() for why this is
   * per-column rather than global.
   */
  private const LANGUAGE_SEPARATOR = ',';

  /**
   * Column width of the varchar(255) columns being written.
   *
   * Doctrine would let an over-long value through, and MySQL would then either
   * truncate it silently or reject the whole batch depending on strict mode —
   * both bad outcomes mid-import. Checking first lets us skip loudly instead.
   */
  private const MAX_LENGTH = 255;

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly PhysicianRepository $physicians,
    private readonly DepartmentRepository $departments,
    private readonly FacilityRepository $facilities,
    private readonly AuditLogManager $auditLogManager,
    private readonly PhysicianTaxonomyManager $taxonomy,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addArgument(
        'file',
        InputArgument::OPTIONAL,
        'Path to the delimited extract. Defaults to the sample shipped in this bundle.',
        $this->defaultFilePath(),
      )
      ->addOption(
        'delimiter',
        'd',
        InputOption::VALUE_REQUIRED,
        'Field delimiter. The provider extract is pipe-delimited despite its .csv extension.',
        '|',
      )
      ->addOption(
        'batch-size',
        'b',
        InputOption::VALUE_REQUIRED,
        'How many physicians to accumulate before each flush + clear.',
        '500',
      )
      ->addOption(
        'limit',
        'l',
        InputOption::VALUE_REQUIRED,
        // Counts PROVIDERS PROCESSED, not rows and not rows written — so the
        // cap is predictable regardless of how many turn out to be new,
        // changed or unchanged. Note that a limited run cannot detect
        // departures; see the departure block in execute().
        'Stop after processing this many providers. Useful for smoke-testing a new extract.',
      )
      ->addOption(
        'skip-departments',
        null,
        InputOption::VALUE_NONE,
        'Import physicians only; create no departments and no department links.',
      )
      ->addOption(
        'skip-facilities',
        null,
        InputOption::VALUE_NONE,
        'Import physicians only; create no facilities and no facility links.',
      )
      ->addOption(
        'dry-run',
        null,
        InputOption::VALUE_NONE,
        'Parse, de-duplicate and report, but never write to the database.',
      )
      ->setHelp(<<<'HELP'
        Reads the provider demographics extract and creates one <info>Physician</info> per
        distinct <comment>cred_id</comment>, one <info>Department</info> per distinct department name,
        and the many-to-many links between them.

        The extract holds one row per provider per department, so the row count is
        roughly double the number of people in it. Those repeat rows are where a
        physician's additional departments come from, so they are unioned rather
        than discarded.

        The department cell is itself a <comment>;</comment>-delimited list — "Hospital Medicine;
        Medicine" is two departments — and is split accordingly.

        Departments are created with a NULL <comment>md_staff_code</comment>: the extract does not
        carry one, and inventing a value would be indistinguishable from a real
        code later. Fill them in through the admin UI.

        Re-running is safe: physicians already in the table (matched on legal name
        plus credentials) and departments already in the table (matched on name)
        are skipped rather than duplicated.

        Bulk imports should be run with <info>--no-debug</info> so the profiler does not
        collect every INSERT.
        HELP)
    ;
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    $file            = (string) $input->getArgument('file');
    $delimiter       = (string) $input->getOption('delimiter');
    $batchSize       = max(1, (int) $input->getOption('batch-size'));
    $limit           = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
    $dryRun          = (bool) $input->getOption('dry-run');
    $skipDepartments = (bool) $input->getOption('skip-departments');
    $skipFacilities  = (bool) $input->getOption('skip-facilities');

    $io->title('HMFP provider demographics import');

    // One timestamp for the entire run, captured before any work begins.
    //
    // Using a single value rather than calling "now" repeatedly is what makes
    // the departure query work: every physician seen in this run gets the
    // IDENTICAL stamp, so "anything older than this" is an exact, unambiguous
    // description of who was absent. Stamps that drifted by a few seconds
    // across a long import would make that boundary fuzzy.
    $runStartedAt = new \DateTimeImmutable();

    // ── Guard the inputs before touching the database ───────────────────────
    if (!is_file($file) || !is_readable($file)) {
      $io->error(sprintf('Cannot read "%s". Pass a readable file path as the first argument.', $file));
      return Command::FAILURE;
    }

    // fgetcsv() treats the delimiter as a single byte; anything longer is a
    // typo we would otherwise silently misinterpret.
    if (strlen($delimiter) !== 1) {
      $io->error(sprintf('--delimiter must be exactly one character, got "%s".', $delimiter));
      return Command::FAILURE;
    }

    $io->definitionList(
      ['File'        => $file],
      ['Size'        => $this->formatBytes((int) filesize($file))],
      ['Delimiter'   => $delimiter],
      ['Batch size'  => (string) $batchSize],
      ['Limit'       => $limit !== null ? (string) $limit : 'none'],
      ['Departments' => $skipDepartments ? 'skipped' : 'imported and linked'],
      ['Facilities'  => $skipFacilities ? 'skipped' : 'imported and linked'],
      ['Mode'        => $dryRun ? 'DRY RUN — nothing will be written' : 'live import'],
    );

    // Pass 1: scan
    $io->section('Pass 1 of 2 — scanning');

    try {
      $scan = $this->scanFile($io, $file, $delimiter, $skipDepartments, $skipFacilities);
    }
    catch (\RuntimeException $e) {
      $io->error($e->getMessage());
      return Command::FAILURE;
    }

    $io->text(sprintf(
      '%s row(s), %s distinct provider(s), %s distinct department(s), %s distinct facility/facilities.',
      number_format($scan['rowCount']),
      number_format(count($scan['credIdDepartments'])),
      number_format(count($scan['departmentNames'])),
      number_format(count($scan['facilityNames'])),
    ));

    // Sync departments and facilities, so pass 2 only needs their IDs
    $departmentIds      = [];
    $departmentsCreated = 0;
    $facilityIds        = [];
    $facilitiesCreated  = 0;

    if (!$skipDepartments) {
      $io->section('Departments');
      [$departmentIds, $departmentsCreated] = $this->syncNamedEntities(
        $io,
        $scan['departmentNames'],
        $dryRun,
        Entity\Department::class,
        $this->departments,
      );
    }

    if (!$skipFacilities) {
      $io->section('Facilities');
      [$facilityIds, $facilitiesCreated] = $this->syncNamedEntities(
        $io,
        $scan['facilityNames'],
        $dryRun,
        Entity\Facility::class,
        $this->facilities,
      );
    }

    // Specialties: Terms in the shared taxonomy, not their own entity
    $specialtyIds       = [];
    $specialtiesCreated = 0;

    $io->section('Specialties');
    [$specialtyIds, $specialtiesCreated] = $this->syncTerms(
      $io,
      $scan['specialtyNames'],
      $dryRun,
      PhysicianVocabulary::Specialty,
    );

    $io->section('Languages');
    [$languageIds, $languagesCreated] = $this->syncTerms(
      $io,
      $scan['languageNames'],
      $dryRun,
      PhysicianVocabulary::Language,
    );

    // Pass 2: import
    $io->section('Pass 2 of 2 — importing physicians');

    try {
      $stats = $this->importPhysicians(
        io: $io,
        file: $file,
        delimiter: $delimiter,
        rowCount: $scan['rowCount'],
        credIdDepartments: $scan['credIdDepartments'],
        departmentIds: $departmentIds,
        credIdFacilities: $scan['credIdFacilities'],
        facilityIds: $facilityIds,
        credIdSpecialties: $scan['credIdSpecialties'],
        specialtyIds: $specialtyIds,
        credIdLanguages: $scan['credIdLanguages'],
        languageIds: $languageIds,
        batchSize: $batchSize,
        limit: $limit,
        dryRun: $dryRun,
        skipDepartments: $skipDepartments,
        skipFacilities: $skipFacilities,
        runStartedAt: $runStartedAt,
      );
    }
    catch (\RuntimeException $e) {
      $io->error($e->getMessage());
      return Command::FAILURE;
    }

    // Report the tallies, and the reasons for any skipped rows or values.
    $io->section('Summary');
    $io->table(
      ['Outcome', 'Count'],
      [
        ['Rows read',                      number_format($stats['rows'])],
        ['Physicians created',             number_format($stats['created'])],
        ['Physicians updated',             number_format($stats['updated'])],
        ['Physicians unchanged',           number_format($stats['unchanged'])],
        ['— of the updates, adopted',      number_format($stats['adopted'])],
        ['Departments created',            $skipDepartments ? 'skipped' : number_format($departmentsCreated)],
        ['Facilities created',             $skipFacilities ? 'skipped' : number_format($facilitiesCreated)],
        ['Specialties created',            number_format($specialtiesCreated)],
        ['Languages created',              number_format($languagesCreated)],
        ['Links added',                    number_format($stats['linksAdded'])],
        ['Links removed',                  number_format($stats['linksRemoved'])],
        ['Duplicate rows (same cred_id)',  number_format($stats['duplicates'])],
        ['Rows skipped (unusable)',        number_format($stats['skipped'])],
      ],
    );

    $problems = $scan['problems'] + $stats['problems'];
    if ($problems !== []) {
      $io->section('Why rows or values were skipped');
      arsort($problems);
      $io->table(
        ['Reason', 'Count'],
        array_map(
          static fn (string $reason, int $count): array => [$reason, number_format($count)],
          array_keys($problems),
          $problems,
        ),
      );
      $io->note('Re-run with -v to see the line number of each skipped row.');
    }

    // Departures
    // Derived from ABSENCE from the file, so it is only meaningful when the
    // whole file was processed. A --limit run stops early by design, which
    // would make every unprocessed physician look departed — thousands of false
    // positives. Suppressed rather than reported wrongly.
    if (!$dryRun && $limit === null) {
      $departed = $this->physicians->countDepartedSince($runStartedAt);

      if ($departed > 0) {
        $io->section('Departures');
        $io->text(sprintf(
          '%s physician(s) were in a previous import but not this one.',
          number_format($departed),
        ));
        $io->note(
          'Nothing was deleted. They are identifiable by a stale lastSeenInImportAt — '
          . 'see PhysicianRepository::findDepartedSince().'
        );
      }
    }
    elseif (!$dryRun && $limit !== null) {
      $io->note('Departure detection skipped: --limit means most of the file was never read.');
    }

    if ($dryRun) {
      $io->warning(sprintf(
        'Dry run — nothing was written. A live run would create %s and update %s physician(s), '
        . 'create %s department(s) and %s facility/facilities, and add %s / remove %s link(s).',
        number_format($stats['created']),
        number_format($stats['updated']),
        number_format($departmentsCreated),
        number_format($facilitiesCreated),
        number_format($stats['linksAdded']),
        number_format($stats['linksRemoved']),
      ));
      return Command::SUCCESS;
    }

    // Log the import to the audit log, so it is visible in the admin UI. The log entry is
    // not a replacement for the console output, but it is a permanent record of
    // what was done and when, and it is visible to users who do not have console access.
    $this->auditLogManager->log('physicians.imported', context: [
      'file'             => $file,
      'created'          => $stats['created'],
      'updated'          => $stats['updated'],
      'unchanged'        => $stats['unchanged'],
      'adopted'          => $stats['adopted'],
      'departmentsAdded' => $departmentsCreated,
      'facilitiesAdded'  => $facilitiesCreated,
      'linksAdded'       => $stats['linksAdded'],
      'linksRemoved'     => $stats['linksRemoved'],
      'rowsSkipped'      => $stats['skipped'],
      'limited'          => $limit,
      'runStartedAt'     => $runStartedAt->format(\DateTimeInterface::ATOM),
    ]);

    $io->success(sprintf(
      'Created %s, updated %s, left %s unchanged across %s department(s) and %s facility/facilities.',
      number_format($stats['created']),
      number_format($stats['updated']),
      number_format($stats['unchanged']),
      number_format(count($departmentIds)),
      number_format(count($facilityIds)),
    ));

    return Command::SUCCESS;
  }

  /**
   * PASS 1 — read the whole file without writing anything.
   *
   * Collects the three things pass 2 cannot work out on its own:
   *
   *   rowCount           so the progress bar has a total to work against
   *   departmentNames    the complete set of departments to create UP FRONT,
   *                      before any flush/clear cycle can complicate matters
   *   credIdDepartments  the full department list for each provider, so a
   *                      physician's links can be written once and complete
   *                      rather than appended to as duplicate rows arrive
   *
   * @return array{
   *   rowCount: int,
   *   departmentNames: array<string, string>,
   *   credIdDepartments: array<string, list<string>>,
   *   problems: array<string, int>,
   * }
   *   departmentNames maps a normalised lookup key to the canonical display
   *   name; credIdDepartments maps a cred_id to a list of those keys.
   */
  private function scanFile(
    SymfonyStyle $io,
    string $file,
    string $delimiter,
    bool $skipDepartments,
    bool $skipFacilities,
  ): array {
    $handle = fopen($file, 'rb');
    if ($handle === false) {
      throw new \RuntimeException(sprintf('Failed to open "%s".', $file));
    }

    try {
      // Resolving the header first means a wrong --delimiter or a renamed
      // column fails here, before any of the real work.
      $columnIndex = $this->resolveColumnIndex($handle, $delimiter);

      $rowCount          = 0;
      $departmentNames   = [];
      $credIdDepartments = [];
      $facilityNames     = [];
      $credIdFacilities  = [];
      $specialtyNames    = [];
      $credIdSpecialties = [];
      $languageNames     = [];
      $credIdLanguages   = [];
      $problems          = [];

      foreach ($this->readRows($handle, $delimiter) as $lineNumber => $row) {
        $rowCount++;

        $record  = $this->extractRecord($row, $columnIndex);
        $problem = $this->findProblem($record);

        if ($problem !== null) {
          // Counted here but reported once, in the summary — pass 2 will hit
          // the same rows and skip them for the same reasons, and counting a
          // row twice would be confusing.
          $problems[$problem] = ($problems[$problem] ?? 0) + 1;
          continue;
        }

        $credId = $record['cred_id'];

        // Seed both entries even for a provider with none, so pass 2 can
        // distinguish "none" from "provider not seen in pass 1".
        $credIdDepartments[$credId] ??= [];
        $credIdFacilities[$credId]  ??= [];
        $credIdSpecialties[$credId] ??= [];
        $credIdLanguages[$credId]   ??= [];

        // Specialties
        // Same ';'-nested shape as the department cell ("Internal Medicine;
        // Pediatrics" is two specialties), so it reuses the same splitter.
        //
        // Unlike departments and facilities these do not become their own
        // entity — they are Terms in the shared taxonomy's 'specialty'
        // vocabulary. Nothing in this scan needs to know that; it collects
        // names, and syncTerms() decides what they turn into.
        foreach ($this->splitList($record['specialty']) as $key => $name) {
          if (mb_strlen($name) > self::MAX_LENGTH) {
            $reason = sprintf('specialty name longer than %d characters', self::MAX_LENGTH);
            $problems[$reason] = ($problems[$reason] ?? 0) + 1;
            continue;
          }

          $specialtyNames[$key] ??= $name;
          $credIdSpecialties[$credId][$key] = true;
        }

        // Languages
        // COMMA-separated, unlike department and specialty. See splitList().
        //
        // Only about a quarter of rows carry any — 2,222 of the 10,933
        // providers — so an empty cell here is normal, not a data problem.
        foreach ($this->splitList($record['languages'], self::LANGUAGE_SEPARATOR) as $key => $name) {
          if (mb_strlen($name) > self::MAX_LENGTH) {
            $reason = sprintf('language name longer than %d characters', self::MAX_LENGTH);
            $problems[$reason] = ($problems[$reason] ?? 0) + 1;
            continue;
          }

          // The extract contains at least one broken cell —
          // "English (fluent)Spanish (" — where two values ran together with no
          // separator. Splitting it yields a value that is not a language and
          // would sit permanently in the filter dropdown.
          //
          // Rejected on shape rather than silently repaired: a language name is
          // letters, spaces, hyphens and slashes ("Haitian/Creole",
          // "American Sign Language"). Parentheses and digits mean the cell is
          // malformed. Counting it as a skipped value means it shows up in the
          // summary and can be reported back to the export team, which owns the
          // data — rather than being quietly cleaned here and re-appearing on
          // every future import.
          if (preg_match('/[()0-9]/u', $name) === 1) {
            $reason = 'malformed language value (contains digits or brackets)';
            $problems[$reason] = ($problems[$reason] ?? 0) + 1;

            if ($io->isVerbose()) {
              $io->warning(sprintf('Line %d: language value not imported: %s', $lineNumber, $name));
            }
            continue;
          }

          $languageNames[$key] ??= $name;
          $credIdLanguages[$credId][$key] = true;
        }

        // ── Departments ────────────────────────────────────────────────────
        if (!$skipDepartments) {
          foreach ($this->splitList($record['department']) as $key => $name) {
            if (mb_strlen($name) > self::MAX_LENGTH) {
              $reason = sprintf('department name longer than %d characters', self::MAX_LENGTH);
              $problems[$reason] = ($problems[$reason] ?? 0) + 1;

              if ($io->isVerbose()) {
                $io->warning(sprintf('Line %d: department name too long, not linked: %s', $lineNumber, $name));
              }
              continue;
            }

            // First spelling wins as the canonical display name. The lookup key
            // is case-folded, so "Cardiology" and "cardiology" converge on one
            // department rather than colliding on the UNIQUE index at flush time.
            $departmentNames[$key] ??= $name;

            // Keyed by $key rather than appended, so the same department
            // arriving on five separate rows for this provider stays a single
            // entry. The keys are collapsed to a list at the end.
            $credIdDepartments[$credId][$key] = true;
          }
        }

        // Facilities
        // Simpler than departments in one respect: the cell is NOT a nested
        // list, so there is nothing to split — one row names one facility. The
        // many-to-many comes purely from the SAME PROVIDER appearing on several
        // rows, which is why this unions into a set keyed by cred_id.
        if (!$skipFacilities) {
          $facility = $this->cleanFacilityName($record['facility_name']);

          if ($facility !== '') {
            if (mb_strlen($facility) > self::MAX_LENGTH) {
              $reason = sprintf('facility name longer than %d characters', self::MAX_LENGTH);
              $problems[$reason] = ($problems[$reason] ?? 0) + 1;

              if ($io->isVerbose()) {
                $io->warning(sprintf('Line %d: facility name too long, not linked: %s', $lineNumber, $facility));
              }
            }
            else {
              $key = $this->normalise($facility);
              $facilityNames[$key] ??= $facility;
              $credIdFacilities[$credId][$key] = true;
            }
          }
        }
      }
    }
    finally {
      // In a finally block so an exception mid-scan cannot leak the handle.
      fclose($handle);
    }

    // Collapse each provider's sets into plain lists. Done once here rather
    // than repeatedly in pass 2's inner loop.
    foreach ($credIdDepartments as $credId => $keys) {
      $credIdDepartments[$credId] = array_keys($keys);
    }
    foreach ($credIdFacilities as $credId => $keys) {
      $credIdFacilities[$credId] = array_keys($keys);
    }
    foreach ($credIdSpecialties as $credId => $keys) {
      $credIdSpecialties[$credId] = array_keys($keys);
    }
    foreach ($credIdLanguages as $credId => $keys) {
      $credIdLanguages[$credId] = array_keys($keys);
    }

    return [
      'rowCount'          => $rowCount,
      'departmentNames'   => $departmentNames,
      'credIdDepartments' => $credIdDepartments,
      'facilityNames'     => $facilityNames,
      'credIdFacilities'  => $credIdFacilities,
      'specialtyNames'    => $specialtyNames,
      'credIdSpecialties' => $credIdSpecialties,
      'languageNames'     => $languageNames,
      'credIdLanguages'   => $credIdLanguages,
      'problems'          => $problems,
    ];
  }

  /**
   * Strips the export artifacts off a facility cell.
   *
   * `facility_name` is the LAST column in the extract, and the xlsx export
   * appended seven empty spreadsheet columns after it. resolveColumnIndex()
   * strips those from the HEADER so the column can be found at all — but the
   * data rows carry them too, and extractRecord()'s trim() only removes
   * whitespace. Left alone, the stored value reads "Physician Performance,,,,,,".
   *
   * The count is not fixed: across the sample it ranges from 0 to 7 trailing
   * commas depending on the row. So this has to strip a variable-length run,
   * which is exactly what rtrim() with a character list does.
   */
  private function cleanFacilityName(string $cell): string {
    // Whitespace as well as commas, so " Anna Jaques Hospital , , ," resolves
    // cleanly regardless of how the export spaced its padding.
    return rtrim(trim($cell), " \t,");
  }

  /**
   * Ensures every department named in the file exists, and returns their IDs.
   *
   * Serves BOTH departments and facilities. The two are structurally identical
   * at this stage — a set of unique names that must exist as rows before pass 2
   * can link to them — so they share one implementation rather than two that
   * drift apart. What differs (the nested ';' list, the NULL md_staff_code) is
   * handled before and after this point, not here.
   *
   * Departments are created with a NULL md_staff_code — the extract carries no
   * such column, and a fabricated code is indistinguishable from a real one the
   * moment it is stored. See Entity\Department::$MdStaffCode. Facilities have no
   * equivalent field at all.
   *
   * Doing this as one up-front step is what lets pass 2 batch freely: after
   * flush() the whole set is queried back by name so pass 2 holds nothing but
   * integers, which survive clear() where entity references would not.
   *
   * @param array<string, string>   $names      Lookup key → canonical name.
   * @param class-string            $entityClass Entity to create — must have setName().
   * @param ServiceEntityRepository $repository Repository for that entity.
   *
   * @return array{0: array<string, int>, 1: int} Key → row ID, and how many were created.
   */
  private function syncNamedEntities(
    SymfonyStyle $io,
    array $names,
    bool $dryRun,
    string $entityClass,
    ServiceEntityRepository $repository,
  ): array {
    // Existing rows first, so a re-run creates nothing.
    $existing = $this->loadNameIds($repository);

    $missing = array_diff_key($names, $existing);

    $io->text(sprintf(
      '%s in the file, %s already in the database, %s to create.',
      number_format(count($names)),
      number_format(count(array_intersect_key($names, $existing))),
      number_format(count($missing)),
    ));

    if ($dryRun || $missing === []) {
      // In a dry run the new rows have no IDs, so only the pre-existing ones can
      // be reported — which is correct: pass 2 counts a link for every one it
      // WOULD attach, using the file's names rather than these IDs.
      return [$existing, count($missing)];
    }

    foreach ($missing as $name) {
      $entity = new $entityClass();
      $entity->setName($name);
      $this->entityManager->persist($entity);
    }

    // A few hundred small rows; one flush is fine, no batching needed.
    $this->entityManager->flush();
    $this->entityManager->clear();

    if ($io->isVerbose()) {
      $io->listing(array_slice(array_values($missing), 0, 25));
      if (count($missing) > 25) {
        $io->text(sprintf('… and %s more.', number_format(count($missing) - 25)));
      }
    }

    // Re-query rather than reading IDs off the objects: clear() has just
    // detached them, and a single scalar query is cheaper than keeping several
    // hundred entities managed for the rest of the run.
    return [$this->loadNameIds($repository), count($missing)];
  }

  /**
   * Ensures every named term in a vocabulary exists, and returns their IDs.
   *
   * The term-backed counterpart to syncNamedEntities(). It cannot simply reuse
   * that method, because a Term is not identified by name alone — it is
   * identified by (vocabulary, name), and it needs a Vocabulary to belong to.
   * That extra dimension is the whole cost of sharing one table across six
   * taxonomies, and it is confined to this method.
   *
   * Terms are created with source='provider-import', so a wrong term can later
   * be traced to the import that introduced it rather than being quietly fixed
   * in the admin UI and reappearing on the next run.
   *
   * @param array<string, string> $names Normalised key → canonical name.
   *
   * @return array{0: array<string, int>, 1: int} Key → term ID, and how many were created.
   */
  private function syncTerms(
    SymfonyStyle $io,
    array $names,
    bool $dryRun,
    PhysicianVocabulary $vocabulary,
  ): array {
    $existing = $this->taxonomy->termsByName($vocabulary);
    $existing = array_map(static fn ($term): int => $term->getId(), $existing);

    $missing = array_diff_key($names, $existing);

    $io->text(sprintf(
      '%s in the file, %s already in the database, %s to create.',
      number_format(count($names)),
      number_format(count(array_intersect_key($names, $existing))),
      number_format(count($missing)),
    ));

    if ($dryRun || $missing === []) {
      return [$existing, count($missing)];
    }

    foreach ($missing as $name) {
      $this->taxonomy->createTerm($vocabulary, $name, source: 'provider-import');
    }

    $this->entityManager->flush();
    $this->entityManager->clear();

    // clear() detached the Vocabulary the manager was holding; keeping it would
    // mean persisting the next Term against a detached object. See
    // PhysicianTaxonomyManager::forget().
    $this->taxonomy->forget();

    $ids = array_map(
      static fn ($term): int => $term->getId(),
      $this->taxonomy->termsByName($vocabulary),
    );

    return [$ids, count($missing)];
  }

  /**
   * Reads a name → id map for any entity with a unique `name`.
   *
   * Keyed through normalise() so the lookup agrees with the keys scanFile()
   * built. Using the same function on both sides is the point: a second,
   * subtly different normalisation is exactly how "Cardiology" and "cardiology"
   * end up as two rows under a UNIQUE index.
   *
   * @return array<string, int>
   */
  private function loadNameIds(ServiceEntityRepository $repository): array {
    $ids = [];

    foreach ($repository->createQueryBuilder('e')->select('e.id, e.name')->getQuery()->getScalarResult() as $row) {
      $ids[$this->normalise((string) $row['name'])] = (int) $row['id'];
    }

    return $ids;
  }

  /**
   * PASS 2 — stream the file again and create the physicians.
   *
   * @param array<string, list<string>> $credIdDepartments
   * @param array<string, int>          $departmentIds
   * @param array<string, list<string>> $credIdFacilities
   * @param array<string, int>          $facilityIds
   *
   * @return array{
   *   rows: int, created: int, updated: int, unchanged: int, adopted: int,
   *   duplicates: int, skipped: int, linksAdded: int, linksRemoved: int,
   *   problems: array<string, int>,
   * }
   */
  private function importPhysicians(
    SymfonyStyle $io,
    string $file,
    string $delimiter,
    int $rowCount,
    array $credIdDepartments,
    array $departmentIds,
    array $credIdFacilities,
    array $facilityIds,
    array $credIdSpecialties,
    array $specialtyIds,
    array $credIdLanguages,
    array $languageIds,
    int $batchSize,
    ?int $limit,
    bool $dryRun,
    bool $skipDepartments,
    bool $skipFacilities,
    \DateTimeImmutable $runStartedAt,
  ): array {
    $handle = fopen($file, 'rb');
    if ($handle === false) {
      throw new \RuntimeException(sprintf('Failed to open "%s".', $file));
    }

    $columnIndex = $this->resolveColumnIndex($handle, $delimiter);

    // State carried across the whole pass
    $index     = $this->loadImportIndex();
    $byCredId  = $index['byCredId'];
    $adoptable = $index['adoptable'];

    // The two association sets, described once
    // Departments and facilities are handled identically from here on: resolve
    // the desired ids, diff against the current ones, attach and detach. Rather
    // than writing that twice — and letting the two copies drift the first time
    // one is fixed — each is described as data and the loop below walks both.
    //
    // The attach/detach closures exist so the entity methods stay explicit and
    // type-checked; a variable method name ($physician->$add(…)) would work but
    // is far harder to grep for when tracing where a link gets written.
    $associations = [
      'department' => [
        'skip'    => $skipDepartments,
        'ids'     => $departmentIds,
        'perCred' => $credIdDepartments,
        'links'   => $index['departmentLinks'],
        'class'   => Entity\Department::class,
        'attach'  => static fn (Entity\Physician $p, object $e): mixed => $p->addDepartment($e),
        'detach'  => static fn (Entity\Physician $p, object $e): mixed => $p->removeDepartment($e),
      ],
      'facility' => [
        'skip'    => $skipFacilities,
        'ids'     => $facilityIds,
        'perCred' => $credIdFacilities,
        'links'   => $index['facilityLinks'],
        'class'   => Entity\Facility::class,
        'attach'  => static fn (Entity\Physician $p, object $e): mixed => $p->addFacility($e),
        'detach'  => static fn (Entity\Physician $p, object $e): mixed => $p->removeFacility($e),
      ],
      // Specialty is a Term in the shared taxonomy rather than an entity of its
      // own, but from here it is the same shape as the two above: a set of ids
      // to diff and attach. That symmetry is the point of describing
      // associations as data — a taxonomy backed by a completely different
      // storage model still costs one entry.
      'specialty' => [
        // Always synced. There is no --skip-specialties yet because nothing has
        // needed one; add an option here rather than borrowing another
        // taxonomy's flag, which would tie two unrelated switches together.
        'skip'    => false,
        'ids'     => $specialtyIds,
        'perCred' => $credIdSpecialties,
        'links'   => $index['specialtyLinks'],
        'class'   => HelperEntity\Term::class,
        'attach'  => static fn (Entity\Physician $p, object $e): mixed => $p->addTerm($e),
        'detach'  => static fn (Entity\Physician $p, object $e): mixed => $p->removeTerm($e),
      ],
      // Language: a second vocabulary in the same shared taxonomy. Note it
      // attaches and detaches through exactly the same Term methods as
      // specialty — what keeps the two apart is the vocabulary-scoped link set
      // below, NOT anything on the entity.
      'language' => [
        'skip'    => false,
        'ids'     => $languageIds,
        'perCred' => $credIdLanguages,
        'links'   => $index['languageLinks'],
        'class'   => HelperEntity\Term::class,
        'attach'  => static fn (Entity\Physician $p, object $e): mixed => $p->addTerm($e),
        'detach'  => static fn (Entity\Physician $p, object $e): mixed => $p->removeTerm($e),
      ],
    ];

    // Hash set of cred_ids handled this run. isset() on an array key is O(1)
    // and — unlike in_array() over a growing list — does not degrade as it
    // fills to ~11k entries.
    $seenCredIds = [];

    // Ids of EXISTING physicians matched this run, for the bulk stamp at the
    // end. New physicians are stamped on the entity instead and are absent
    // from this list. See stampSeen().
    $seenIds = [];

    if ($byCredId !== [] || $adoptable !== []) {
      $io->text(sprintf(
        '%s physician(s) already identified, %s adoptable (no cred_id yet).',
        number_format(count($byCredId)),
        number_format(count($adoptable)),
      ));
    }

    $stats = [
      'rows'         => 0, // data rows read
      'created'      => 0, // new Physician rows
      'updated'      => 0, // existing rows whose data changed
      'unchanged'    => 0, // existing rows already correct — stamp only
      'adopted'      => 0, // pre-cred_id rows claimed and given an identity
      'duplicates'   => 0, // repeat rows for a cred_id already handled
      'skipped'      => 0, // unusable rows — see $problems
      'linksAdded'   => 0,
      'linksRemoved' => 0,
      'problems'     => [],
    ];

    // The bar tracks ROWS, not physicians, so it maps onto the file rather than
    // onto the (roughly half as many) people that come out of it.
    $io->progressStart($rowCount);

    $pending   = 0; // entities queued since the last flush
    $processed = 0; // providers handled, for --limit

    try {
      foreach ($this->readRows($handle, $delimiter) as $lineNumber => $row) {
        $stats['rows']++;
        $io->progressAdvance();

        $record = $this->extractRecord($row, $columnIndex);

        // -- Reject rows we cannot represent --------------------------------
        $problem = $this->findProblem($record);
        if ($problem !== null) {
          $stats['skipped']++;

          // -v surfaces the offending line, so a bad extract can be traced back
          // to a spreadsheet row without re-parsing the file by hand. The
          // reason itself is already counted in pass 1's tally.
          if ($io->isVerbose()) {
            $io->warning(sprintf('Line %d skipped: %s', $lineNumber, $problem));
          }
          continue;
        }

        $credId = $record['cred_id'];

        // -- Collapse the one-row-per-department duplication ----------------
        // Safe to skip outright: pass 1 already gathered this provider's FULL
        // department list, so nothing is lost by ignoring their repeat rows.
        if (isset($seenCredIds[$credId])) {
          $stats['duplicates']++;
          continue;
        }
        $seenCredIds[$credId] = true;
        $processed++;

        $legalName   = $this->buildLegalName($record);
        $credentials = $record['degree'];

        // -- Resolve the desired ids for each association --------------------
        // Sorted and de-duplicated so the comparison against the current set
        // below is a straight equality check rather than a set operation.
        $desired = [];
        foreach ($associations as $name => $association) {
          $ids = [];

          if (!$association['skip']) {
            foreach ($association['perCred'][$credId] ?? [] as $key) {
              if (isset($association['ids'][$key])) {
                $ids[$association['ids'][$key]] = true;
              }
            }
          }

          $list = array_keys($ids);
          sort($list);
          $desired[$name] = $list;
        }

        // have we seen this person before?
        $match   = $byCredId[$credId] ?? null;
        $adopted = false;

        if ($match === null) {
          // Fall back to adopting a pre-cred_id row. Only rows with NO cred_id
          // are candidates, and each may be claimed once — see the class
          // docblock for why both restrictions matter.
          $signature = $this->signature($legalName, $credentials);

          if (isset($adoptable[$signature])) {
            $match = [
              'id'          => $adoptable[$signature],
              'legalName'   => $legalName,
              'credentials' => $credentials,
            ];
            $adopted = true;

            // Consumed. A second provider with the same name and degree must
            // NOT claim this same row — they are different people.
            unset($adoptable[$signature]);
          }
        }

        if ($match === null) {
          // insert a new row, with all the associations in one go. The entity is
          // stamped with the run time so it is not left out of the bulk update at the end.
          $stats['created']++;

          if (!$dryRun) {
            $physician = new Entity\Physician();
            $physician->setCredId($credId);
            $physician->setLegalName($legalName);
            $physician->setCredentials($credentials);
            // Stamped inline: a brand-new entity is already being written, so
            // this costs nothing and keeps it out of the bulk update.
            $physician->setLastSeenInImportAt($runStartedAt);

            foreach ($associations as $name => $association) {
              foreach ($desired[$name] as $targetId) {
                // getReference() is the key to batching cleanly. It returns a
                // lazy proxy carrying nothing but the ID — no SELECT is issued.
                // That matters because the EntityManager is cleared after every
                // batch: a real Department or Facility object fetched once and
                // reused would be detached by the first clear() and would throw
                // on the next flush. A reference is created fresh inside the
                // current batch, so it is always attached to the current
                // EntityManager.
                //
                // All Doctrine needs to write a join-table row is the two IDs,
                // so a proxy is not merely sufficient — it is exactly right.
                $association['attach'](
                  $physician,
                  $this->entityManager->getReference($association['class'], $targetId),
                );
              }
            }

            $this->entityManager->persist($physician);
            $pending++;

            // Register so a repeat cred_id later in the file resolves to this
            // physician rather than inserting a second copy. The id is not
            // known until flush, but it is never read for a row created in this
            // same run — the $seenCredIds guard above catches those first.
            $byCredId[$credId] = [
              'id'          => 0,
              'legalName'   => $legalName,
              'credentials' => $credentials,
            ];
          }

          foreach ($associations as $name => $_) {
            $stats['linksAdded'] += count($desired[$name]);
          }
        }
        else {
          // either update or skip. The entity is NOT loaded yet — the decision to load
          // it is made from memory, so a physician whose details did not change avoids
          // a SELECT entirely.
          $id = $match['id'];
          $seenIds[] = $id;

          $fieldsChanged = $adopted
            || $match['legalName'] !== $legalName
            || $match['credentials'] !== $credentials;

          // Diff every association up front, so the decision to load the entity
          // at all can be made from memory.
          $diffs = [];
          foreach ($associations as $name => $association) {
            // With --skip-departments / --skip-facilities that side of the file
            // is not read at all, so an empty desired set means "unknown", not
            // "none". Treating it as a diff would delete every existing link.
            if ($association['skip']) {
              continue;
            }

            $currentList = array_keys($association['links'][$id] ?? []);
            sort($currentList);

            // Compare as sorted lists: the order of the links is not significant, only their presence or absence.
            if ($desired[$name] === $currentList) {
              continue;
            }

            $diffs[$name] = [
              'added'   => array_diff($desired[$name], $currentList),
              'removed' => array_diff($currentList, $desired[$name]),
            ];
          }

          if (!$fieldsChanged && $diffs === []) {
            $stats['unchanged']++;
          }
          else {
            $stats['updated']++;
            if ($adopted) {
              $stats['adopted']++;
            }

            foreach ($diffs as $diff) {
              $stats['linksAdded']   += count($diff['added']);
              $stats['linksRemoved'] += count($diff['removed']);
            }

            if (!$dryRun) {
              // Only NOW is the entity loaded. Everything above was decided
              // from the in-memory index, which is what keeps the common case
              // — a physician whose details did not change — free of queries.
              $physician = $this->entityManager->find(Entity\Physician::class, $id);

              if ($physician === null) {
                // Deleted by someone else between the index load and here.
                // Rare, but silently dereferencing null would be worse.
                $reason = 'physician disappeared mid-import';
                $stats['problems'][$reason] = ($stats['problems'][$reason] ?? 0) + 1;
                continue;
              }

              if ($fieldsChanged) {
                // Only extract-owned fields. `bio` is never touched — see the
                // class docblock.
                $physician->setCredId($credId);
                $physician->setLegalName($legalName);
                $physician->setCredentials($credentials);
              }

              foreach ($diffs as $name => $diff) {
                $association = $associations[$name];

                // Touching a collection initialises it (one lazy SELECT), so
                // this is deliberately reached only for physicians whose links
                // actually changed rather than for all ~11k.
                foreach ($diff['added'] as $targetId) {
                  $association['attach'](
                    $physician,
                    $this->entityManager->getReference($association['class'], $targetId),
                  );
                }
                foreach ($diff['removed'] as $targetId) {
                  $association['detach'](
                    $physician,
                    $this->entityManager->getReference($association['class'], $targetId),
                  );
                }
              }

              $physician->setUpdatedAt($runStartedAt);
              $pending++;
            }
          }
        }

        // Flush in batches
        // persist() only queues. Without a periodic flush + clear, the identity
        // map would hold every entity touched so far and each successive flush
        // would walk all of them looking for changes — quadratic work, and
        // memory that only ever grows.
        if (!$dryRun && $pending >= $batchSize) {
          $this->entityManager->flush();
          $this->entityManager->clear();
          $pending = 0;
        }

        if ($limit !== null && $processed >= $limit) {
          break;
        }
      }
    }
    finally {
      fclose($handle);
    }

    // Anything left in the last, partial batch.
    if (!$dryRun && $pending > 0) {
      $this->entityManager->flush();
      $this->entityManager->clear();
    }

    // The stamp for existing physicians, applied set-based rather than by
    // loading ~11k entities to write one timestamp each.
    if (!$dryRun && $seenIds !== []) {
      $this->stampSeen($seenIds, $runStartedAt);
    }

    $io->progressFinish();

    return $stats;
  }

  /**
   * Streams the file one data row at a time.
   *
   * A generator rather than a returned array: this keeps exactly one row in
   * memory regardless of how large the extract grows. The key yielded is the
   * 1-based line number (the header is line 1, so data starts at 2), which is
   * what makes a "line 4,213 skipped" warning actionable.
   *
   * @param resource $handle A file handle positioned just past the header row.
   *
   * @return \Generator<int, list<string|null>>
   */
  private function readRows($handle, string $delimiter): \Generator {
    // Line 1 was the header, consumed by resolveColumnIndex().
    $lineNumber = 1;

    // escape: '' opts into RFC 4180 behaviour. PHP's historic default treats a
    // backslash as an escape character — not a thing CSV actually has, and it
    // mangles any field containing one. The legacy behaviour is deprecated as
    // of PHP 8.4, so passing '' explicitly is both correct and future-proof.
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
      $lineNumber++;

      // fgetcsv() reports a blank line as [null]. A trailing newline at the end
      // of the file is the usual source; it is not an error.
      if ($row === [null]) {
        continue;
      }

      yield $lineNumber => $row;
    }
  }

  /**
   * Reads the header row and maps each column we care about to its position.
   *
   * @param resource $handle A freshly opened file handle.
   *
   * @return array<string, int> Internal column name → zero-based index.
   *
   * @throws \RuntimeException If the file is empty or a required column is absent.
   */
  private function resolveColumnIndex($handle, string $delimiter): array {
    $header = fgetcsv($handle, 0, $delimiter, '"', '');

    if ($header === false || $header === [null]) {
      throw new \RuntimeException('The file appears to be empty — no header row found.');
    }

    // Normalise each header cell before matching. The sample extract's final
    // header cell arrives as "facility_name,,,,,,," — seven empty spreadsheet
    // columns exported alongside the real ones. Stripping trailing commas and
    // whitespace, and lower-casing, means neither that artifact nor a stray
    // capital letter upstream breaks the lookup.
    $normalised = [];
    foreach ($header as $index => $name) {
      $clean = strtolower(trim(rtrim((string) $name, " \t\n\r\0\x0B,")));

      // Also strip a UTF-8 BOM, which Excel likes to prepend to the first cell
      // and which would otherwise stop "npi" matching "\u{FEFF}npi".
      $clean = ltrim($clean, "\u{FEFF}");

      if ($clean !== '') {
        $normalised[$clean] = $index;
      }
    }

    $map     = [];
    $missing = [];
    foreach (self::COLUMNS as $key => $headerName) {
      if (!isset($normalised[$headerName])) {
        $missing[] = $headerName;
        continue;
      }
      $map[$key] = $normalised[$headerName];
    }

    if ($missing !== []) {
      throw new \RuntimeException(sprintf(
        'The file is missing required column(s): %s. Found: %s. '
        . 'Check --delimiter is right for this file — a wrong delimiter makes every column look missing.',
        implode(', ', $missing),
        implode(', ', array_keys($normalised)) ?: '(none)',
      ));
    }

    return $map;
  }

  /**
   * Pulls the columns we care about out of a raw row, trimmed.
   *
   * A short row (fewer cells than the header promised) yields empty strings
   * rather than a warning, so a malformed line becomes a counted skip in
   * findProblem() instead of a fatal error mid-import.
   *
   * @param list<string|null>  $row
   * @param array<string, int> $columnIndex
   *
   * @return array<string, string>
   */
  private function extractRecord(array $row, array $columnIndex): array {
    $record = [];
    foreach ($columnIndex as $key => $index) {
      $record[$key] = trim((string) ($row[$index] ?? ''));
    }
    return $record;
  }

  /**
   * Splits a multi-value cell into its individual values.
   *
   * "Hospital Medicine; Medicine" → ['hospital medicine' => 'Hospital Medicine',
   *                                  'medicine'          => 'Medicine']
   *
   * Returning key => canonical-name in one go means every caller normalises
   * identically; a second, subtly different normalisation elsewhere is exactly
   * how "Cardiology" and "cardiology" end up as two rows under a UNIQUE index.
   *
   * ── Why the separator is a PARAMETER and not a constant ──────────────────
   * Because this one file uses three different conventions, and getting them
   * confused corrupts data silently:
   *
   *   |  between fields
   *   ;  inside `department` and `specialty`
   *   ,  inside `languages`  ("Albanian, Italian, Spanish")
   *
   * The comma is the dangerous one. It CANNOT be applied globally: the
   * facility "Mount Auburn Cambridge, IPA" contains a real comma, and splitting
   * on it would invent a facility called "IPA". Which delimiter applies is a
   * property of the column, so the caller states it rather than this method
   * assuming.
   *
   * @param string $cell      The raw cell.
   * @param string $separator The delimiter WITHIN this particular column.
   *
   * @return array<string, string> Normalised lookup key → display name.
   */
  private function splitList(string $cell, string $separator = self::DEPARTMENT_SEPARATOR): array {
    if ($cell === '') {
      return [];
    }

    $departments = [];
    foreach (explode($separator, $cell) as $name) {
      // Collapse internal whitespace as well as trimming the ends, so
      // "Medicine ;  Neurology" and "Medicine;Neurology" agree.
      $name = trim((string) preg_replace('/\s+/u', ' ', $name));

      if ($name === '') {
        continue;
      }

      $departments[$this->normalise($name)] = $name;
    }

    return $departments;
  }

  /**
   * The single normalisation used for every name-based lookup key.
   *
   * Case-folded and whitespace-collapsed. Deliberately NOT stripping
   * punctuation: "Diagnostic & Interventional Radiology" and "Cardiology,
   * Non-Invasive" carry real distinctions in their punctuation, and being
   * aggressive here would merge departments that are genuinely separate.
   */
  private function normalise(string $value): string {
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
  }

  /**
   * Returns a human-readable reason this row cannot be imported, or null if it can.
   *
   * Returning the reason rather than a bare bool is what lets the summary group
   * skips by cause — "1,204 rows had no degree" is a fixable finding, "1,204
   * rows skipped" is not.
   *
   * @param array<string, string> $record
   */
  private function findProblem(array $record): ?string {
    foreach (self::REQUIRED as $key) {
      if (($record[$key] ?? '') === '') {
        return sprintf('missing %s', $key);
      }
    }

    // Length is checked against the assembled name, not the parts, because it
    // is the joined string that has to fit the column.
    if (mb_strlen($this->buildLegalName($record)) > self::MAX_LENGTH) {
      return sprintf('legal name longer than %d characters', self::MAX_LENGTH);
    }

    if (mb_strlen($record['degree']) > self::MAX_LENGTH) {
      return sprintf('credentials longer than %d characters', self::MAX_LENGTH);
    }

    return null;
  }

  /**
   * Joins the name parts into the single string the entity stores.
   *
   * Only first/middle/last, per the current scope. Note what is deliberately
   * NOT here: the extract also carries a `suffix` column (Jr, III, …, populated
   * on 113 of 21,556 rows) and a `preferred_full_name` column that already
   * reads "Joseph E. Dubin, MD". Both are candidates when this widens — suffix
   * most obviously, since dropping it makes a Jr and a Sr indistinguishable,
   * and the cross-run signature below is built from exactly this string.
   *
   * @param array<string, string> $record
   */
  private function buildLegalName(array $record): string {
    $parts = array_filter([
      $record['first_name']  ?? '',
      $record['middle_name'] ?? '',
      $record['last_name']   ?? '',
    ], static fn (string $part): bool => $part !== '');

    // Collapse internal runs of whitespace so "Mary  Anne" and "Mary Anne"
    // produce the same signature and do not import as two people.
    return (string) preg_replace('/\s+/u', ' ', implode(' ', $parts));
  }

  /**
   * Loads everything needed to decide insert-vs-update, in two queries.
   *
   * The alternative is a findOneBy() per provider — around 11,000 round trips,
   * where per-query overhead dominates every other cost in the command. Two
   * scalar queries returning arrays instead of hydrated entities keep the whole
   * index in a few MB, which is the same order as the output itself.
   *
   * The two lookup tables, and why there are two
   *   byCredId    the real index: cred_id → current field values. Used for
   *               every physician a previous import created.
   *   adoptable   signature → id, containing ONLY rows whose cred_id is NULL —
   *               hand-created physicians, and rows that predate the cred_id
   *               column. This is the fallback that lets an old row be claimed
   *               once and given its identity. Restricting it to NULL rows is
   *               what stops a second same-named provider from hijacking an
   *               already-identified physician's record.
   *
   * @return array{
   *   byCredId: array<string, array{id: int, legalName: string, credentials: string}>,
   *   adoptable: array<string, int>,
   *   links: array<int, array<int, true>>,
   * }
   */
  private function loadImportIndex(): array {
    $byCredId  = [];
    $adoptable = [];

    $rows = $this->physicians->createQueryBuilder('p')
      ->select('p.id AS id, p.credId AS credId, p.legalName AS legalName, p.credentials AS credentials')
      ->getQuery()
      ->getScalarResult();

    foreach ($rows as $row) {
      $id          = (int) $row['id'];
      $legalName   = (string) $row['legalName'];
      $credentials = (string) $row['credentials'];
      $credId      = $row['credId'] !== null ? (string) $row['credId'] : null;

      if ($credId !== null && $credId !== '') {
        $byCredId[$credId] = [
          'id'          => $id,
          'legalName'   => $legalName,
          'credentials' => $credentials,
        ];
        continue;
      }

      // No cred_id — adoptable. First row wins if two unidentified rows somehow
      // share a signature; the loser simply stays unadopted rather than being
      // silently overwritten, and will show up as an untouched duplicate that a
      // human can resolve.
      $adoptable[$this->signature($legalName, $credentials)] ??= $id;
    }

    return [
      'byCredId'        => $byCredId,
      'adoptable'       => $adoptable,
      'departmentLinks' => $this->loadExistingLinks('physician_departments', 'department_id'),
      'facilityLinks'   => $this->loadExistingLinks('physician_facilities', 'facility_id'),
      // Scoped to ONE vocabulary. physician_terms holds every taxonomy at once,
      // so an unscoped read would hand the specialty diff every language and
      // condition link too — and the sync, seeing them absent from the file's
      // specialty list, would delete them.
      'specialtyLinks'  => $this->loadExistingLinks(
        'physician_terms',
        'term_id',
        PhysicianVocabulary::Specialty,
      ),
      // Separately scoped from specialtyLinks even though both read the same
      // table. Sharing one unscoped read would let each vocabulary's sync see
      // the other's links as "current" and delete them.
      'languageLinks'   => $this->loadExistingLinks(
        'physician_terms',
        'term_id',
        PhysicianVocabulary::Language,
      ),
    ];
  }

  /**
   * Loads the current physician↔department links as a nested lookup.
   *
   * One query for the whole join table (~20k rows in the sample) rather than
   * initialising a Doctrine collection per physician. That distinction matters:
   * touching $physician->getDepartments() on a fetched entity triggers a lazy
   * SELECT, so diffing 11,000 physicians the obvious way would issue 11,000
   * extra queries — most of them to discover that nothing changed at all.
   *
   * With this map the "did anything change?" question is answered in memory,
   * and an entity is only loaded for the physicians that genuinely need
   * writing.
   *
   * Uses raw DBAL rather than DQL because the join table is not an entity —
   * a many-to-many association table has no PHP class to query against.
   *
   * @param string $table  Join table to read.
   * @param string $column The non-physician side's column in that table.
   *
   * @return array<int, array<int, true>> physician id → set of related ids.
   */
  private function loadExistingLinks(
    string $table,
    string $column,
    ?PhysicianVocabulary $vocabulary = null,
  ): array {
    $sql    = sprintf('SELECT l.physician_id, l.%s FROM %s l', $column, $table);
    $params = [];

    // Shared-taxonomy links need narrowing to one vocabulary, because
    // physician_terms holds all of them in one table. Without this, the
    // specialty sync would receive every language and condition link as
    // "current", find them missing from the file's specialty list, and delete
    // them — one taxonomy silently wiping another.
    if ($vocabulary !== null) {
      $sql .= sprintf(
        ' JOIN vocabulary_terms t ON t.id = l.%s
          JOIN vocabularies v ON v.id = t.vocabulary_id
         WHERE v.name = :vocabulary',
        $column,
      );
      $params['vocabulary'] = $vocabulary->value;
    }

    $rows = $this->entityManager->getConnection()
      ->executeQuery($sql, $params)
      ->fetchAllAssociative();

    $links = [];
    foreach ($rows as $row) {
      $links[(int) $row['physician_id']][(int) $row[$column]] = true;
    }

    return $links;
  }

  /**
   * Stamps lastSeenInImportAt on every physician this run matched.
   *
   * This stamp touches EVERY physician in the file, including the great
   * majority whose data did not change at all. Loading ~11,000 entities purely
   * to write one timestamp each would undo the entire point of the in-memory
   * diff — so the unchanged ones are never hydrated, and their stamp is applied
   * by a set-based UPDATE instead.
   *
   * Newly inserted physicians are NOT passed here: they get their stamp set
   * directly on the entity at construction, where it costs nothing extra.
   *
   * @param list<int> $ids Physician ids seen in this run.
   */
  private function stampSeen(array $ids, \DateTimeImmutable $seenAt): void {
    foreach (array_chunk($ids, 1000) as $chunk) {
      $this->entityManager->createQuery(
        'UPDATE ' . Entity\Physician::class . ' p
           SET p.lastSeenInImportAt = :seenAt
         WHERE p.id IN (:ids)'
      )
        ->setParameter('seenAt', $seenAt)
        ->setParameter('ids', $chunk)
        ->execute();
    }

    // A DQL bulk UPDATE bypasses the UnitOfWork entirely — it issues SQL
    // directly and does not update any entity already in memory. Nothing here
    // reads lastSeenInImportAt afterwards, so that is fine, but it is exactly
    // the sort of thing that surprises people later: an entity loaded before
    // this call will still report the OLD timestamp until it is refreshed.
  }

  /**
   * Builds the key used to decide "have we already got this person?".
   *
   * Case-folded so a later extract that changes "MacDonald" to "Macdonald" does
   * not re-import the same physician. This is the stopgap identity discussed in
   * the class docblock — replace it with a cred_id lookup once that column
   * exists.
   */
  private function signature(string $legalName, string $credentials): string {
    return $this->normalise($legalName) . '|' . $this->normalise($credentials);
  }

  /**
   * The sample extract that ships alongside this bundle's source.
   *
   * dirname(__DIR__, 2) walks up from src/Command/ to the bundle root, so the
   * default keeps working whether the bundle is installed as a path repository
   * or from a dist archive.
   */
  private function defaultFilePath(): string {
    return \dirname(__DIR__, 2) . '/docs/sample-provider-demographics.csv';
  }

  /**
   * Formats a byte count for the settings list. Cosmetic only.
   */
  private function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    $power = min($power, count($units) - 1);

    return sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
  }
}
