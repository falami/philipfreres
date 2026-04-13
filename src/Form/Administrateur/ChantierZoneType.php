<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChantierZoneType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('nom', TextType::class, [
        'label' => 'Nom du sous-chantier',
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Ouvrage Nord'],
      ])
      ->add('parcelle', TextType::class, [
        'label' => 'Parcelle',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : ZC 148'],
      ])
      ->add('ordre', IntegerType::class, [
        'label' => 'Ordre',
        'required' => false,
        'attr' => ['class' => 'form-control', 'min' => 0],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => ChantierZone::class]);
  }
}
