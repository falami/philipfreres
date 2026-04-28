<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierZone;
use App\Entity\Entite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChantierZoneType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'];

    $b
      ->add('nom', TextType::class, [
        'label' => 'Nom du sous-chantier / parcelle',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex : Parcelle Nord',
        ],
      ])
      ->add('parcelle', TextType::class, [
        'label' => 'Référence parcelle',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex : ZC 148',
        ],
      ])
      ->add('naturePrestation', TextareaType::class, [
        'required' => false,
        'label' => 'Nature de la prestation',
        'attr' => [
          'class' => 'form-control',
          'rows' => 3,
        ],
      ])
      ->add('dateDebutPrevisionnelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Début prévisionnel',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
        ],
      ])
      ->add('dateFinPrevisionnelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Fin prévisionnelle',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
        ],
      ])
      ->add('dateDebutReelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Début réel',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
        ],
      ])
      ->add('dateFinReelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Fin réelle',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
        ],
      ])
      ->add('surfaceTraitee', NumberType::class, [
        'required' => false,
        'label' => 'Surface traitée',
        'scale' => 2,
        'attr' => [
          'class' => 'form-control text-end',
          'step' => '0.01',
          'placeholder' => '0,00',
        ],
      ])
      ->add('lineaireTraite', NumberType::class, [
        'required' => false,
        'label' => 'Linéaire traité',
        'scale' => 2,
        'attr' => [
          'class' => 'form-control text-end',
          'step' => '0.01',
          'placeholder' => '0,00',
        ],
      ])
      ->add('difficultesRencontrees', TextareaType::class, [
        'required' => false,
        'label' => 'Difficultés rencontrées sur cette parcelle',
        'attr' => [
          'class' => 'form-control',
          'rows' => 3,
        ],
      ])
      ->add('ordre', IntegerType::class, [
        'label' => 'Ordre',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'min' => 0,
        ],
      ])
      ->add('ressourcesHumaines', CollectionType::class, [
        'entry_type' => ChantierRessourceHumaineType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'prototype_name' => '__rh__',
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
        'prototype_name' => '__engin__',
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
        'prototype_name' => '__materiel__',
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
        'prototype_name' => '__dechet__',
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
        'prototype_name' => '__photo__',
        'entry_options' => [
          'label' => false,
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ChantierZone::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
