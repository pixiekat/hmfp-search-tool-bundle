<?php
declare(strict_types=1);
namespace Pixiekat\HMFPSearchToolBundle\Form;

use Pixiekat\HMFPSearchToolBundle\Entity;
use Pixiekat\HMFPSearchToolBundle\Enum;
use Pixiekat\HMFPSearchToolBundle\Interfaces;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClaimEditFormType extends AbstractType {

  public function buildForm(FormBuilderInterface $builder, array $options): void {
    $builder
      ->add('status', ChoiceType::class, [
        'label' => 'Claim Status',
        'choices' => $this->getClaimStatusChoices(),
      ])
      ->add('note', TextareaType::class, [
        'label' => 'Notes',
        'required' => false,
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void {
    $resolver->setDefaults([
      'data_class' => Entity\PhysicianClaim::class,
    ]);
  }

  private function getClaimStatusChoices(): array {
    $options = [
      'Awaiting confirmation' => Enum\ClaimStatus::Pending,
      'Verified by email and NPI' => Enum\ClaimStatus::Verified,
      'Approved by a data steward' => Enum\ClaimStatus::Approved,
      'Refused' => Enum\ClaimStatus::Rejected,
      'Withdrawn' => Enum\ClaimStatus::Revoked,
    ];
    return $options;
  }
}
