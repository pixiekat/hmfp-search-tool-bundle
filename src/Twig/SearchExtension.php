<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig helpers for the provider search UI.
 */
final class SearchExtension extends AbstractExtension {

  public function getFilters(): array {
    return [
      // is_safe: html declares that this filter returns markup Twig must NOT
      // escape again. That is a promise the filter has to keep — see highlight()
      // for how it does. Without the declaration Twig would escape our <mark>
      // tags into visible &lt;mark&gt;; with it, and without the escaping done
      // inside, we would have written an XSS hole.
      new TwigFilter('hmfp_highlight', $this->highlight(...), ['is_safe' => ['html']]),
    ];
  }

  /**
   * Wraps occurrences of the search term(s) in <mark>, safely.
   *
   * Takes either one term or a list. A list is what a parsed search produces —
   * the fuzzy text plus each exact phrase and included word — and passing the
   * raw box instead would try to highlight `"hand surgery"` WITH its quotes,
   * which never appears in a name and so would highlight nothing.
   *
   * @param string|list<string>|null $terms
   */
  public function highlight(?string $subject, string|array|null $terms): string {
    $subject = (string) $subject;

    // Step 1: the subject is now safe no matter what it contained.
    $escapedSubject = htmlspecialchars($subject, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

    // Nothing to highlight. A single character would mark up almost every
    // result, which is noise rather than signal, so the useful floor is two.
    $needles = array_filter(
      array_map(static fn ($t): string => trim((string) $t), (array) $terms),
      static fn (string $t): bool => mb_strlen($t) >= 2,
    );

    if ($needles === []) {
      return $escapedSubject;
    }

    // Longest first, so "hand surgery" wins over "hand" where both would
    // match at the same position — a regex alternation takes the first
    // branch that fits, not the longest.
    usort($needles, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

    // Step 2: escape each needle identically, so it matches the escaped
    // subject. preg_quote because the terms are user input and may contain
    // regex metacharacters — a search for "C++" must not be compiled as a
    // quantifier.
    $alternatives = array_map(
      static fn (string $t): string => preg_quote(htmlspecialchars($t, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'), '/'),
      $needles,
    );

    $pattern = '/' . implode('|', $alternatives) . '/iu';

    // Step 3: our markup, and only ours. '$0' reinserts the matched text with
    // its original casing intact.
    $highlighted = preg_replace($pattern, '<mark>$0</mark>', $escapedSubject);

    // preg_replace returns null on failure — a malformed pattern, or a UTF-8
    // subject the /u flag rejects. Falling back to the escaped subject means a
    // bad input costs the highlighting, never the result itself.
    return $highlighted ?? $escapedSubject;
  }
}
