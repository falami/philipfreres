<?php

declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\Dechet;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  ChoiceType,
  TextType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DechetType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('nom', TextType::class, [
        'label' => 'Nom',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex. Gravats, terre, béton, ferraille…',
        ],
      ])

      ->add('unite', ChoiceType::class, [
        'label' => 'Unité',
        'choices' => [
          'Kilogramme (kg)' => 'kg',
          'Tonne (t)'       => 't',
          'Mètre cube (m³)' => 'm3',
          'Litre (L)'       => 'L',
          'Unité (u)'       => 'u',
        ],
        'attr' => [
          'class' => 'form-select',
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => Dechet::class,
    ]);
  }
}
