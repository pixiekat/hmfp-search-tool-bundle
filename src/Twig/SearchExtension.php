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
   * Wraps occurrences of the search term in <mark>, safely.
   */
  public function highlight(?string $subject, ?string $term): string {
    $subject = (string) $subject;
    $term    = trim((string) $term);

    // Step 1: the subject is now safe no matter what it contained.
    $escapedSubject = htmlspecialchars($subject, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

    // Nothing to highlight. A single character would mark up almost every
    // result, which is noise rather than signal, so the useful floor is two.
    if (mb_strlen($term) < 2) {
      return $escapedSubject;
    }

    // Step 2: escape the needle identically, so it matches the escaped subject.
    $escapedTerm = htmlspecialchars($term, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

    // preg_quote because the term is user input and may contain regex
    // metacharacters — a search for "C++" must not be compiled as a quantifier.
    $pattern = '/' . preg_quote($escapedTerm, '/') . '/iu';

    // Step 3: our markup, and only ours. '$0' reinserts the matched text with
    // its original casing intact.
    $highlighted = preg_replace($pattern, '<mark>$0</mark>', $escapedSubject);

    // preg_replace returns null on failure — a malformed pattern, or a UTF-8
    // subject the /u flag rejects. Falling back to the escaped subject means a
    // bad input costs the highlighting, never the result itself.
    return $highlighted ?? $escapedSubject;
  }
}
