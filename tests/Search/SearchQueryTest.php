<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Tests\Search;

use Pixiekat\HMFPSearchToolBundle\Search\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SearchQuery turns what someone typed into what they meant.
 *
 * These pin the PARSING only — which text is fuzzy, which is exact, which is
 * excluded. Whether the SQL then honours that is a database question and
 * belongs in the application's functional tests.
 */
final class SearchQueryTest extends TestCase {

  /**
   * The search box syntax, one shape per case.
   *
   * @param list<string> $phrases
   * @param list<string> $excluded
   */
  #[DataProvider('boxProvider')]
  public function testParsesTheSearchBox(string $q, string $fuzzy, array $phrases, array $excluded): void {
    $query = SearchQuery::fromInput($q);

    $this->assertSame($fuzzy, $query->fuzzy);
    $this->assertSame($phrases, $query->phrases);
    $this->assertSame($excluded, $query->excluded);
  }

  /**
   * @return iterable<string, array{string, string, list<string>, list<string>}>
   */
  public static function boxProvider(): iterable {
    yield 'plain words stay fuzzy'      => ['hand surgery', 'hand surgery', [], []];
    yield 'quotes make a phrase'        => ['"hand surgery"', '', ['hand surgery'], []];
    yield 'smart quotes count too'      => ['“hand surgery”', '', ['hand surgery'], []];
    yield 'unclosed quote runs to end'  => ['smith "hand surg', 'smith', ['hand surg'], []];
    yield 'minus excludes a word'       => ['surgery -pediatric', 'surgery', [], ['pediatric']];
    yield 'minus excludes a phrase'     => ['surgery -"pediatric hand"', 'surgery', [], ['pediatric hand']];
    yield 'hyphenated names untouched'  => ['smith-jones', 'smith-jones', [], []];
    yield 'a lone minus is just text'   => ['a - b', 'a - b', [], []];
    yield 'empty quotes are ignored'    => ['""', '', [], []];
  }

  /**
   * The three fields arrive as whole values; quotes inside them are dropped.
   */
  public function testMergesTheNarrowingFields(): void {
    $query = SearchQuery::fromInput('cardio', '"heart failure"', 'boston  adult', 'pediatric');

    $this->assertSame('cardio', $query->fuzzy);
    $this->assertSame(['heart failure'], $query->phrases);
    $this->assertSame(['boston', 'adult'], $query->words);
    $this->assertSame(['pediatric'], $query->excluded);
    $this->assertSame(['phrase', 'words', 'exclude'], $query->appliedKeys());
  }

  /**
   * The same phrase from the box and from its field counts once.
   */
  public function testDeduplicatesCaseInsensitively(): void {
    $query = SearchQuery::fromInput('"Hand Surgery"', 'hand surgery');

    $this->assertSame(['Hand Surgery'], $query->phrases);
  }

  /**
   * With no fuzzy text, the positive clauses stand in for ranking — and the
   * exclusions never do.
   */
  public function testRankingTextFallsBackToPositiveClauses(): void {
    $query = SearchQuery::fromInput('"hand surgery" -pediatric', '', 'boston');

    $this->assertSame('hand surgery boston', $query->rankingText());
    $this->assertSame(['hand', 'surgery', 'boston'], $query->rankingWords());
    $this->assertNotContains('pediatric', $query->highlightTerms());
  }

  public function testCapsEachKindOfClause(): void {
    $query = SearchQuery::fromInput('', '', implode(' ', range(1, 20)));

    $this->assertCount(SearchQuery::MAX_CLAUSES, $query->words);
  }

  public function testEmptyInputIsEmpty(): void {
    $this->assertTrue(SearchQuery::fromInput('  ', ' ', '', '""')->isEmpty());
  }
}
