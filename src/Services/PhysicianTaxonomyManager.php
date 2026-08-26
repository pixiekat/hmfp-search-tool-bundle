<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Services;

use Doctrine\ORM\EntityManagerInterface;
use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum\PhysicianVocabulary;
use Pixiekat\SymfonyHelpers\Entity as HelperEntity;

/**
 * HMFP's semantics over the helpers bundle's generic taxonomy.
 */
final class PhysicianTaxonomyManager {

  /**
   * In-process cache of Vocabulary rows, keyed by machine name.
   *
   * An import resolves the same vocabulary once per row; without this that is
   * 21,556 identical lookups. Scoped to the request/command rather than to a
   * shared cache pool because the entities must belong to the CURRENT
   * EntityManager — see the note in forget() about clear().
   *
   * @var array<string, HelperEntity\Vocabulary>
   */
  private array $vocabularies = [];

  public function __construct(
    private readonly EntityManagerInterface $entityManager,
  ) {  }

  /**
   * Returns the Vocabulary row for a case of the enum, creating it if absent.
   *
   * Create-on-demand rather than a fixtures step, because the enum is the
   * source of truth for which vocabularies exist: adding a case should be
   * enough to make it usable, without a second manual action that someone will
   * forget on the production deploy.
   *
   * NOT flushed here. The caller decides when to write, so this can be used
   * inside a batched import without forcing a flush mid-batch.
   */
  public function vocabulary(PhysicianVocabulary $vocabulary): HelperEntity\Vocabulary {
    $key = $vocabulary->value;

    if (isset($this->vocabularies[$key])) {
      return $this->vocabularies[$key];
    }

    $repository = $this->entityManager->getRepository(HelperEntity\Vocabulary::class);
    $entity     = $repository->findOneBy(['name' => $key]);

    if ($entity === null) {
      $entity = new HelperEntity\Vocabulary();
      $entity->setName($key);
      $entity->setLabel($vocabulary->label());
      $this->entityManager->persist($entity);
    }

    return $this->vocabularies[$key] = $entity;
  }

  /**
   * Loads every term in a vocabulary, keyed by a normalised name.
   *
   * The key is what an importer matches against, so it must agree with whatever
   * normalisation that importer applies. Case-folded and whitespace-collapsed,
   * matching PhysicianRepository and the import command — a second, subtly
   * different normalisation is exactly how "Cardiology" and "cardiology" become
   * two rows under the unique constraint.
   *
   * @return array<string, HelperEntity\Term>
   */
  public function termsByName(PhysicianVocabulary $vocabulary): array {
    $terms = $this->entityManager->getRepository(HelperEntity\Term::class)
      ->createQueryBuilder('t')
      ->join('t.vocabulary', 'v')
      ->where('v.name = :vocabulary')
      ->setParameter('vocabulary', $vocabulary->value)
      ->getQuery()
      ->getResult();

    $byName = [];
    foreach ($terms as $term) {
      $byName[$this->normalise((string) $term->getName())] = $term;
    }

    return $byName;
  }

  /**
   * The terms attached to a physician within ONE vocabulary.
   *
   * This is the read that should be used in templates and reports, rather than
   * Physician::getTerms(), which returns every vocabulary mixed together. The
   * entity deliberately has no vocabulary awareness; this is where it lives.
   *
   * @return list<HelperEntity\Term>
   */
  public function termsFor(Entity\Physician $physician, PhysicianVocabulary $vocabulary): array {
    $matching = [];

    foreach ($physician->getTerms() as $term) {
      if ($term->getVocabulary()?->getName() === $vocabulary->value) {
        $matching[] = $term;
      }
    }

    usort(
      $matching,
      static fn (HelperEntity\Term $a, HelperEntity\Term $b): int
        => strcasecmp((string) $a->getName(), (string) $b->getName()),
    );

    return $matching;
  }

  /**
   * Creates a term in a vocabulary. Does not flush.
   *
   * `source` records where the term came from, so a wrong term can be traced to
   * the import that introduced it rather than being silently corrected in the
   * admin UI and reappearing on the next run.
   */
  public function createTerm(
    PhysicianVocabulary $vocabulary,
    string $name,
    ?string $code = null,
    ?string $source = null,
  ): HelperEntity\Term {
    $term = new HelperEntity\Term();
    $term->setVocabulary($this->vocabulary($vocabulary));
    $term->setName($name);
    $term->setCode($code);
    $term->setSource($source);
    $term->setWeight(0);

    $this->entityManager->persist($term);

    return $term;
  }

  /**
   * Drops the in-process vocabulary cache.
   *
   * Must be called after EntityManager::clear(), which detaches every entity:
   * a cached Vocabulary held across a clear() is a detached object, and
   * persisting a Term against it throws. Batched importers clear on every
   * flush, so this is not a theoretical concern — it is the failure that
   * caching entities in a long-running process always produces eventually.
   */
  public function forget(): void {
    $this->vocabularies = [];
  }

  /**
   * The single normalisation used for name-based term lookup.
   */
  private function normalise(string $value): string {
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
  }
}
