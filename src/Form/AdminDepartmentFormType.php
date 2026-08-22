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

class AdminDepartmentFormType extends AbstractType {
  public function buildForm(FormBuilderInterface $builder, array $options): void {
    $builder
      ->add('name', FormTypes\TextType::class, [
        'label' => 'Department Name',
        'constraints' => [
          new Assert\NotBlank(),
          new Assert\Length(
            min: 2,
            max: 255,
            minMessage: 'The department name should be at least {{ limit }} characters.',
          ),
        ],
      ])
    ;

    /**
     * Add a post-submit listener to check that the password and confirm password fields match.
     */
    $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
      $form = $event->getForm();
    });
  }

  public function configureOptions(OptionsResolver $resolver): void {
    $resolver->setDefaults([
      'data_class' => Entity\Department::class,
    ]);
  }
}
