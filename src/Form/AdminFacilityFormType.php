<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Form;

use Pixiekat\HMFPSearchToolBundle\Entity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type as FormTypes;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AdminFacilityFormType extends AbstractType {
  public function buildForm(FormBuilderInterface $builder, array $options): void {
    $builder
      ->add('name', FormTypes\TextType::class, [
        'label' => 'Facility Name',
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Length(
            min: 2,
            max: 255,
            minMessage: 'The facility name should be at least {{ limit }} characters.',
          ),
        ],
      ])
    ;

    /*
     * ── Address and coordinates ─────────────────────────────────────────────
     * All optional. The importer creates facilities from a bare name, so a row
     * exists long before anyone fills the rest in, and requiring these would
     * make every imported facility un-editable until someone had the address to
     * hand.
     */
    $builder
      ->add('addressLine', FormTypes\TextType::class, [
        'label'    => 'Street address',
        'required' => false,
      ])
      ->add('city', FormTypes\TextType::class, [
        'label'    => 'City',
        'required' => false,
      ])
      ->add('state', FormTypes\TextType::class, [
        'label'    => 'State',
        'required' => false,
        'help'     => 'Two-letter code, e.g. MA.',
        'constraints' => [new Assert\Length(max: 8)],
      ])
      ->add('postalCode', FormTypes\TextType::class, [
        'label'    => 'ZIP code',
        'required' => false,
        // TextType, never NumberType. A ZIP is an identifier written with
        // digits, and treating it as a number drops the leading zero from every
        // code in New England — 02215 becomes 2215.
        'help'     => 'Keep the leading zero, e.g. 02215.',
        'constraints' => [new Assert\Length(max: 16)],
      ])
    ;

    /*
     * ── Coordinates ─────────────────────────────────────────────────────────
     * Range-checked, because the failure mode is silent. A latitude of 423.389
     * from a slipped decimal point, or a longitude entered as 71.1056 instead
     * of -71.1056, does not error — it places a Boston hospital in China, and
     * the only symptom is a distance search quietly returning the wrong
     * providers. A bounds check turns that into a form error at the moment it
     * is typed.
     *
     * It cannot catch a plausible-but-wrong coordinate; nothing can, short of a
     * map. It catches the transcription mistakes, which are the common ones.
     */
    $builder
      ->add('latitude', FormTypes\TextType::class, [
        'label'    => 'Latitude',
        'required' => false,
        'help'     => 'Decimal degrees, e.g. 42.3389. Both coordinates are needed for distance search.',
        'constraints' => [
          new Assert\Type(type: 'numeric', message: 'Latitude must be a number in decimal degrees.'),
          new Assert\Range(min: -90, max: 90, notInRangeMessage: 'Latitude must be between {{ min }} and {{ max }}.'),
        ],
      ])
      ->add('longitude', FormTypes\TextType::class, [
        'label'    => 'Longitude',
        'required' => false,
        'help'     => 'Decimal degrees, e.g. -71.1056. Negative for the Americas.',
        'constraints' => [
          new Assert\Type(type: 'numeric', message: 'Longitude must be a number in decimal degrees.'),
          new Assert\Range(min: -180, max: 180, notInRangeMessage: 'Longitude must be between {{ min }} and {{ max }}.'),
        ],
      ])
      ->add('epicId', FormTypes\TextType::class, [
        'label'    => 'Epic ID',
        'required' => false,
        'help'     => 'For the Epic integration in a later phase. Must be unique across facilities.',
        'constraints' => [new Assert\Length(max: 64)],
      ])
    ;

    /**
     * Add a post-submit listener to check that the password and confirm password fields match.
     */
    $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
      $form = $event->getForm();

      /*
       * Both coordinates or neither.
       *
       * A facility with only a latitude is worse than one with none: it passes
       * hasCoordinates() nowhere but reads as half-entered, and any code that
       * forgets to check both would place it on the prime meridian. Catching it
       * here means the invalid state never reaches the database.
       */
      $facility = $event->getData();

      if (!$facility instanceof Entity\Facility) {
        return;
      }

      $hasLat = $facility->getLatitude() !== null;
      $hasLng = $facility->getLongitude() !== null;

      if ($hasLat !== $hasLng) {
        $form->get($hasLat ? 'longitude' : 'latitude')->addError(new FormError(
          'Latitude and longitude must be given together — a half-placed facility cannot be found by distance.'
        ));
      }
    });
  }

  public function configureOptions(OptionsResolver $resolver): void {
    $resolver->setDefaults([
      'data_class' => Entity\Facility::class,
    ]);
  }
}
