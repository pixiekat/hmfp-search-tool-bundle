<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Command;

use Pixiekat\HMFPSearchToolBundle\Repository\SearchEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports what people have been searching for.
 *
 * A console report rather than an admin screen, for now. The numbers are worth
 * having while search is still being tested and tuned, and a command reaches
 * that point in an afternoon rather than a week — the queries it runs are the
 * same ones a dashboard would, so nothing here is thrown away when one is
 * built.
 */
#[AsCommand(
  name: 'hmfp:search:stats',
  description: 'Reports search volume, popular terms and searches that found nothing.',
)]
final class SearchStatsCommand extends Command {

  public function __construct(
    private readonly SearchEventRepository $events,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'How many days back to report on.', '30')
      ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per table.', '15')
      ->addOption('prune', null, InputOption::VALUE_REQUIRED, 'Instead of reporting, delete raw events older than this many days.')
      ->setHelp(<<<'HELP'
        Aggregates the <info>search_events</info> table.

        Nothing here identifies anyone: the table holds no user id, no IP and no
        session, so every number is an aggregate by construction.

        <comment>Zero-result terms</comment> is the most useful table. Each row is a
        question the directory failed to answer — a taxonomy gap, a spelling the
        matcher should handle, or a provider who genuinely is not here.
        HELP)
    ;
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    // Prune mode
    if ($input->getOption('prune') !== null) {
      $days   = max(1, (int) $input->getOption('prune'));
      $before = new \DateTimeImmutable(sprintf('-%d days', $days));

      $removed = $this->events->prune($before);

      $io->success(sprintf(
        'Removed %s raw search event(s) older than %s.',
        number_format($removed),
        $before->format('j M Y'),
      ));

      return Command::SUCCESS;
    }

    $days  = max(1, (int) $input->getOption('days'));
    $limit = max(1, (int) $input->getOption('limit'));
    $since = new \DateTimeImmutable(sprintf('-%d days', $days));

    $io->title(sprintf('Search statistics — last %d day%s', $days, $days === 1 ? '' : 's'));

    $summary = $this->events->summary($since);

    if ($summary['searches'] === 0) {
      $io->warning('No searches recorded in this period.');
      return Command::SUCCESS;
    }

    $io->definitionList(
      ['Searches'          => number_format($summary['searches'])],
      ['With a query'      => number_format($summary['with_term'])],
      ['Filters only'      => number_format($summary['filter_only'])],
      ['Found nothing'     => number_format($summary['zero_results'])],
      // Named for what it measures, not what it approximates. A search that
      // returned results is not proof anyone found what they wanted — that
      // needs click-through, which needs the click events joined on
      // correlation_id. Calling this a "success rate" would overclaim.
      ['Zero-result rate'  => $summary['zero_result_rate'] . '%'],
    );

    $this->table($io, 'Most searched terms', ['Term', 'Searches', 'Avg results', 'Found nothing'],
      $this->events->topTerms($since, $limit),
      static fn (array $r): array => [
        $r['term'],
        number_format((int) $r['searches']),
        $r['avg_results'],
        number_format((int) $r['zero_results']),
      ],
    );

    $this->table($io, 'Top specialties and interests searched by name', ['Term', 'Vocabulary', 'Searches'],
      $this->events->topMatchedTerms($since, $limit),
      static fn (array $r): array => [
        $r['matched_term_name'],
        str_replace('_', ' ', (string) $r['matched_vocabulary']),
        number_format((int) $r['searches']),
      ],
      'No query exactly named a specialty or clinical interest in this period.',
    );

    $this->table($io, 'Searches that found nothing', ['Term', 'Searches'],
      $this->events->zeroResultTerms($since, $limit),
      static fn (array $r): array => [$r['term'], number_format((int) $r['searches'])],
      'Every search in this period returned at least one result.',
    );

    $this->table($io, 'Filter usage', ['Filter', 'Searches'],
      $this->events->filterUsage($since),
      static fn (array $r): array => [str_replace('_', ' ', $r['filter']), number_format($r['searches'])],
      'No filters were used in this period.',
    );

    return Command::SUCCESS;
  }

  /**
   * Renders one report table, or an explanatory line when it is empty.
   *
   * An empty table with headings and no rows makes a reader wonder whether the
   * report is broken. Saying why it is empty is the difference between "no data
   * yet" and "something is wrong".
   *
   * @param list<array<string, mixed>> $rows
   */
  private function table(SymfonyStyle $io, string $heading, array $headers, array $rows, callable $map, string $emptyMessage = 'Nothing recorded.'): void {
    $io->section($heading);

    if ($rows === []) {
      $io->text('<comment>' . $emptyMessage . '</comment>');
      return;
    }

    $io->table($headers, array_map($map, $rows));
  }
}
