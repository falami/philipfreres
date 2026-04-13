<?php

namespace App\Form\Administrateur;

use App\Entity\Chantier;
use App\Entity\Entite;
use App\Enum\ChantierStatut;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  CollectionType,
  DateType,
  EnumType,
  NumberType,
  TextType,
  TextareaType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChantierType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('nom', TextType::class, [
        'label' => 'Nom du chantier',
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Curage fossés RD 12'],
      ])
      ->add('adresse', TextType::class, [
        'required' => false,
        'label' => 'Adresse',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('complement', TextType::class, [
        'required' => false,
        'label' => 'Complément',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('codePostal', TextType::class, [
        'required' => false,
        'label' => 'Code postal',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('ville', TextType::class, [
        'required' => false,
        'label' => 'Ville',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('naturePrestation', TextareaType::class, [
        'required' => false,
        'label' => 'Nature de la prestation',
        'attr' => ['class' => 'form-control', 'rows' => 4],
      ])
      ->add('statut', EnumType::class, [
        'class' => ChantierStatut::class,
        'label' => 'Statut',
        'choice_label' => fn(ChantierStatut $s) => $s->label(),
        'attr' => ['class' => 'form-select'],
      ])
      ->add('dateDebutPrevisionnelle', DateType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Début prévisionnel',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('dateFinPrevisionnelle', DateType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Fin prévisionnelle',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('dateDebutReelle', DateType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Début réel',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('dateFinReelle', DateType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Fin réelle',
        'attr' => ['class' => 'form-control'],
      ])
      ->add('surfaceTraitee', NumberType::class, [
        'required' => false,
        'label' => 'Surface traitée (m²)',
        'scale' => 2,
        'attr' => ['class' => 'form-control', 'step' => '0.01'],
      ])
      ->add('lineaireTraite', NumberType::class, [
        'required' => false,
        'label' => 'Linéaire traité (ml)',
        'scale' => 2,
        'attr' => ['class' => 'form-control', 'step' => '0.01'],
      ])
      ->add('difficultesRencontrees', TextareaType::class, [
        'required' => false,
        'label' => 'Difficultés rencontrées',
        'attr' => ['class' => 'form-control', 'rows' => 4],
      ])
      ->add('zones', CollectionType::class, [
        'entry_type' => ChantierZoneType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'entry_options' => ['label' => false],
      ])
      ->add('ressourcesHumaines', CollectionType::class, [
        'entry_type' => ChantierRessourceHumaineType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'entry_options' => [
          'label' => false,
          'entite' => $entite,
        ],
      ])
      ->add('ressourcesEngins', CollectionType::class, [
        'entry_type' => ChantierRessourceEnginType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'entry_options' => [
          'label' => false,
          'entite' => $entite,
        ],
      ])
      ->add('ressourcesMateriels', CollectionType::class, [
        'entry_type' => ChantierRessourceMaterielType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'entry_options' => [
          'label' => false,
          'entite' => $entite,
        ],
      ])
      ->add('dechets', CollectionType::class, [
        'entry_type' => ChantierDechetType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'entry_options' => [
          'label' => false,
          'entite' => $entite,
        ],
      ])
      ->add('photos', CollectionType::class, [
        'entry_type' => ChantierPhotoType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'entry_options' => ['label' => false],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Chantier::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
