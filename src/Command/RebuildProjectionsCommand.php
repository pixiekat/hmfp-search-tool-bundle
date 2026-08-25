<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\EditableField;
use Pixiekat\HMFPSearchToolBundle\Repository\PhysicianRepository;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianEditManager;
use Pixiekat\HMFPSearchToolBundle\Services\PhysicianTaxonomyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds taxonomy links from the physician edit log.
 *
 * ── Why a projection needs a rebuild command at all ─────────────────────────
 * physician_terms is DERIVED state for editable vocabularies: the truth is the
 * edit log, and the links are a copy kept in a shape search can JOIN against.
 * Derived state drifts — a request that dies between approving and flushing, a
 * database restored from a backup taken mid-write, someone correcting a row by
 * hand. Drift in a read model is uniquely nasty because nothing errors; the
 * search is simply, quietly wrong.
 *
 * The answer is not to try to make drift impossible. It is to make correcting
 * it trivial and safe, so that "rebuild the projections" is a boring thing
 * anyone can run at any time. That is what this is.
 *
 * ── The property that makes it safe ─────────────────────────────────────────
 * PhysicianEditManager::project() recomputes the FULL desired set from the edit
 * log and diffs it against what is stored. It reads nothing that a previous run
 * wrote. So running this once, twice, or halfway and again converges on the
 * same answer, and a run that dies partway leaves the remaining physicians
 * exactly as they were — never half-applied.
 *
 * ── What it does NOT touch ──────────────────────────────────────────────────
 * Only vocabularies backed by an editable field. Specialties and languages come
 * from the import and are rebuilt by re-running hmfp:import:physicians; this
 * command would have no idea what they should contain, and deliberately never
 * looks at them.
 */
#[AsCommand(
  name: 'hmfp:projections:rebuild',
  description: 'Rebuilds physician taxonomy links from the edit log (clinical interests).',
)]
final class RebuildProjectionsCommand extends Command {

  private const BATCH_SIZE = 200;

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly PhysicianRepository $physicians,
    private readonly PhysicianEditManager $editManager,
    private readonly PhysicianTaxonomyManager $taxonomy,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing.')
      ->setHelp(<<<'HELP'
        Recomputes the taxonomy links that come from physician edits, using the
        edit log as the only source of truth.

        Safe to run at any time and as often as you like: it is idempotent, and
        a clean run reports zero changes. A run that reports changes on a system
        nobody has touched is itself the finding — it means something drifted.

        Only editable vocabularies are rebuilt. Imported ones (specialty,
        language) come from <info>hmfp:import:physicians</info> instead.
        HELP)
    ;
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io     = new SymfonyStyle($input, $output);
    $dryRun = (bool) $input->getOption('dry-run');

    $io->title('Rebuild physician edit projections');

    $fields = array_filter(EditableField::active(), static fn (EditableField $f): bool => $f->isTaxonomy());

    if ($fields === []) {
      $io->warning('No taxonomy-backed editable fields are active. Nothing to rebuild.');
      return Command::SUCCESS;
    }

    $io->text(sprintf(
      'Rebuilding: %s%s',
      implode(', ', array_map(static fn (EditableField $f): string => $f->label(), $fields)),
      $dryRun ? '   [DRY RUN]' : '',
    ));

    // Only physicians that HAVE an edit can have anything to project. Everyone
    // else provably resolves to an empty set, and walking all 10,933 to
    // discover that would be a lot of work to change nothing.
    //
    // The exception that matters: a physician whose only live edit was
    // superseded still needs projecting, because their links must go away. They
    // still appear here — they have edits, just none live — so the empty-set
    // case is covered.
    $ids = array_map(
      static fn (array $row): int => (int) $row['id'],
      $this->entityManager->getConnection()
        ->executeQuery('SELECT DISTINCT physician_id AS id FROM physician_edits ORDER BY physician_id')
        ->fetchAllAssociative(),
    );

    if ($ids === []) {
      $io->success('No physician edits exist yet — nothing to project.');
      return Command::SUCCESS;
    }

    $io->progressStart(count($ids) * count($fields));

    $totals  = ['added' => 0, 'removed' => 0, 'changed' => 0];
    $pending = 0;

    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      foreach ($chunk as $id) {
        $physician = $this->physicians->find($id);

        if ($physician === null) {
          // Deleted between the id query and here. The FK is ON DELETE CASCADE
          // so their edits are gone too; nothing to do.
          $io->progressAdvance(count($fields));
          continue;
        }

        foreach ($fields as $field) {
          $result = $this->editManager->project($physician, $field);
          $io->progressAdvance();

          if ($result['added'] === 0 && $result['removed'] === 0) {
            continue;
          }

          $totals['added']   += $result['added'];
          $totals['removed'] += $result['removed'];
          $totals['changed']++;
          $pending++;

          if ($io->isVerbose()) {
            $io->writeln(sprintf(
              "\n  %s — %s: +%d / -%d",
              $physician->getLegalName(),
              $field->label(),
              $result['added'],
              $result['removed'],
            ));
          }
        }
      }

      if ($dryRun) {
        // Discard rather than write. clear() also drops the collections mutated
        // above, so nothing survives to be flushed accidentally later.
        $this->entityManager->clear();
        $this->taxonomy->forget();
        $pending = 0;
        continue;
      }

      if ($pending > 0) {
        $this->entityManager->flush();
      }

      // Cleared every batch for the same reason the importer does it, and the
      // taxonomy cache is dropped alongside because it holds Vocabulary
      // entities that clear() has just detached.
      $this->entityManager->clear();
      $this->taxonomy->forget();
      $pending = 0;
    }

    $io->progressFinish();

    $io->table(
      ['Outcome', 'Count'],
      [
        ['Physicians with edits', number_format(count($ids))],
        ['Projections changed',   number_format($totals['changed'])],
        ['Links added',           number_format($totals['added'])],
        ['Links removed',         number_format($totals['removed'])],
      ],
    );

    if ($totals['changed'] === 0) {
      $io->success('Projections already match the edit log. Nothing drifted.');
      return Command::SUCCESS;
    }

    if ($dryRun) {
      $io->warning(sprintf(
        'Dry run — nothing written. %s projection(s) are out of step with the edit log.',
        number_format($totals['changed']),
      ));
      return Command::SUCCESS;
    }

    $io->success(sprintf('Rebuilt %s projection(s).', number_format($totals['changed'])));

    return Command::SUCCESS;
  }
}
