<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Search;

/**
 * A parsed provider search: what to match loosely, exactly, and not at all.
 *
 * The search box and the "Narrow results" text fields both feed this one
 * object, so the repository only ever deals with three plain lists and never
 * has to know which control a phrase came from. That matters for behaviour as
 * much as tidiness — `"hand surgery"` typed in the box and `hand surgery` typed
 * in the "Exactly this phrase" field are the SAME request and must give the
 * same answer.
 *
 * ── The syntax the search box understands ──────────────────────────────────
 *   hand surgery        fuzzy: ranked by relevance; the words may appear
 *                       separately ("Surgery of the Hand" still matches).
 *   "hand surgery"      exact: these words, together, in this order, as whole
 *                       words ("handley" is not "hand").
 *   -pediatric          exclude a word.
 *   -"pediatric hand"   exclude a phrase.
 *
 * It is the syntax every major web search engine has taught people, which is
 * the only reason it is worth supporting at all: nobody reads help text for a
 * search box, so the box has to behave the way they already expect.
 *
 * ── Why it is a value object and not a service ──────────────────────────────
 * It holds request data, so it must never be a shared service — a container
 * singleton carrying one user's query into another user's request is exactly
 * the bug this shape rules out. src/Search/ is excluded from service loading
 * in config/services.php for that reason.
 */
final class SearchQuery {

  /**
   * The most phrases, words or exclusions of each kind that will be acted on.
   *
   * Each one becomes an EXISTS subquery, so the cap keeps a pasted paragraph
   * from turning into a fifty-subquery scan. Eight is far beyond any real
   * search for a person or a specialty.
   */
  public const MAX_CLAUSES = 8;

  /**
   * Typographic quotes, mapped to the plain double quote.
   *
   * Phones and Macs "smart quote" by default, so someone carefully typing
   * "hand surgery" on an iPad actually sends “hand surgery”. Without this they
   * would get a fuzzy search and no hint as to why the quotes did nothing.
   */
  private const QUOTE_CHARACTERS = ['“', '”', '„', '‟', '″', '«', '»'];

  /**
   * @param string       $fuzzy    Free text for the relevance-ranked search; '' for none.
   * @param list<string> $phrases  Must each appear exactly, as whole words.
   * @param list<string> $words    Must each appear somewhere, in any order.
   * @param list<string> $excluded Must NOT appear, matched as whole words.
   */
  private function __construct(
    public readonly string $fuzzy,
    public readonly array $phrases,
    public readonly array $words,
    public readonly array $excluded,
  ) {}

  /**
   * Builds a query from the search box and the three narrowing fields.
   *
   * @param string $q       The search box, which may carry quotes and minus signs.
   * @param string $phrase  "Exactly this phrase" — the whole field is one phrase.
   * @param string $words   "Including these words" — whitespace-separated.
   * @param string $exclude "Excludes this phrase" — the whole field is one phrase.
   */
  public static function fromInput(string $q, string $phrase = '', string $words = '', string $exclude = ''): self {
    $phrases  = [];
    $excluded = [];
    $fuzzy    = [];

    // ── Tokenise the search box ───────────────────────────────────────────
    // One pattern, three shapes, tried left to right:
    //   (-?)"([^"]*)"?   an optionally negated quoted phrase. The closing quote
    //                    is OPTIONAL, so an unbalanced `"hand surgery` treats
    //                    the rest of the line as the phrase — what the person
    //                    plainly meant, rather than an error.
    //   (\S+)            any other run of non-space characters.
    // /u so multibyte names ("Núñez") are not split mid-character.
    preg_match_all('/(-?)"([^"]*)"?|(\S+)/u', self::normaliseQuotes($q), $matches, \PREG_SET_ORDER);

    foreach ($matches as $match) {
      $isQuoted = isset($match[3]) === false || $match[3] === '';

      if ($isQuoted) {
        $text = self::collapse($match[2] ?? '');

        if ($match[1] === '-') {
          $excluded[] = $text;
        }
        else {
          $phrases[] = $text;
        }

        continue;
      }

      $token = $match[3];

      // A leading minus excludes the word, but only when something follows it:
      // a lone "-" is punctuation, not an instruction. Hyphenated names such
      // as "Smith-Jones" are untouched because the minus is not at the start.
      if (str_starts_with($token, '-') && mb_strlen($token) > 1) {
        $excluded[] = mb_substr($token, 1);
        continue;
      }

      $fuzzy[] = $token;
    }

    // ── The narrowing fields ──────────────────────────────────────────────
    // Quotes are stripped from these rather than parsed: the field's label
    // already says what kind of match it is, so a quote typed into "Exactly
    // this phrase" is the user being doubly sure, not new syntax.
    $phrases[]  = self::collapse(str_replace('"', ' ', self::normaliseQuotes($phrase)));
    $excluded[] = self::collapse(str_replace('"', ' ', self::normaliseQuotes($exclude)));

    $wordList = preg_split('/\s+/u', str_replace('"', ' ', self::normaliseQuotes($words)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

    return new self(
      fuzzy: implode(' ', $fuzzy),
      phrases: self::clean($phrases),
      words: self::clean($wordList),
      excluded: self::clean($excluded),
    );
  }

  /**
   * True when nothing at all was asked for.
   */
  public function isEmpty(): bool {
    return $this->fuzzy === '' && $this->phrases === [] && $this->words === [] && $this->excluded === [];
  }

  /**
   * The text the relevance tiers are scored against.
   *
   * The fuzzy text when there is any. Otherwise the positive phrases and words
   * stand in, so that a search for `"hand surgery"` alone still lands on the
   * exact-specialty tier instead of every result scoring zero. Exclusions never
   * contribute: ranking a result higher for the very thing it must not contain
   * would be perverse.
   */
  public function rankingText(): string {
    if ($this->fuzzy !== '') {
      return $this->fuzzy;
    }

    return implode(' ', [...$this->phrases, ...$this->words]);
  }

  /**
   * The individual words of the fuzzy text.
   *
   * @return list<string>
   */
  public function fuzzyWords(): array {
    return self::clean(preg_split('/\s+/u', $this->fuzzy, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
  }

  /**
   * The individual words of rankingText().
   *
   * @return list<string>
   */
  public function rankingWords(): array {
    return self::clean(preg_split('/\s+/u', $this->rankingText(), -1, \PREG_SPLIT_NO_EMPTY) ?: []);
  }

  /**
   * Everything worth wrapping in <mark> on the results page.
   *
   * Exclusions are left out for the obvious reason: they cannot be on the
   * page, and if one somehow were, highlighting it would read as a match.
   *
   * @return list<string>
   */
  public function highlightTerms(): array {
    return self::clean([$this->fuzzy, ...$this->phrases, ...$this->words]);
  }

  /**
   * Which kinds of narrowing were used, as plain keys, for analytics.
   *
   * Named the same as the request parameters, and reported whichever control
   * they came from — the question worth answering is "does anyone use exact
   * phrases?", not "does anyone use the second text field?".
   *
   * @return list<string>
   */
  public function appliedKeys(): array {
    return array_keys(array_filter([
      'phrase'  => $this->phrases !== [],
      'words'   => $this->words !== [],
      'exclude' => $this->excluded !== [],
    ]));
  }

  /**
   * How many narrowing clauses are in force, for the "N applied" badge.
   */
  public function narrowingCount(): int {
    return count($this->phrases) + count($this->words) + count($this->excluded);
  }

  /**
   * Replaces typographic quotes with the plain double quote.
   */
  private static function normaliseQuotes(string $value): string {
    return str_replace(self::QUOTE_CHARACTERS, '"', $value);
  }

  /**
   * Trims and squeezes internal whitespace to single spaces.
   */
  private static function collapse(string $value): string {
    return trim((string) preg_replace('/\s+/u', ' ', $value));
  }

  /**
   * Drops empties, removes case-insensitive duplicates, and applies the cap.
   *
   * @param list<string> $values
   *
   * @return list<string>
   */
  private static function clean(array $values): array {
    $seen = [];

    foreach ($values as $value) {
      $value = self::collapse($value);

      if ($value === '') {
        continue;
      }

      // Keyed by the lowercase form so "Hand" and "hand" count once, while
      // the first spelling the person typed is the one kept for display.
      $seen[mb_strtolower($value)] ??= $value;
    }

    return array_slice(array_values($seen), 0, self::MAX_CLAUSES);
  }
}
