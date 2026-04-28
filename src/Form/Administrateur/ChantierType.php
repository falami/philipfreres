<?php

namespace App\Form\Administrateur;

use App\Entity\Chantier;
use App\Entity\Entite;
use App\Enum\ChantierStatut;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChantierType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'];

    $b
      ->add('nom', TextType::class, [
        'label' => 'Nom du chantier',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex : Nettoyage parcelles RD 12',
        ],
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
      ->add('statut', EnumType::class, [
        'class' => ChantierStatut::class,
        'label' => 'Statut',
        'choice_label' => fn(ChantierStatut $s) => $s->label(),
        'attr' => ['class' => 'form-select'],
      ])
      ->add('dateDebutPrevisionnelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Début prévisionnel global',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
          'data-week-target' => 'week-dateDebutPrevisionnelle',
        ],
      ])
      ->add('dateFinPrevisionnelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Fin prévisionnelle globale',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
          'data-week-target' => 'week-dateFinPrevisionnelle',
        ],
      ])
      ->add('dateDebutReelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Début réel global',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
          'data-week-target' => 'week-dateDebutReelle',
        ],
      ])
      ->add('dateFinReelle', DateTimeType::class, [
        'widget' => 'single_text',
        'required' => false,
        'label' => 'Fin réelle globale',
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-datetime',
          'placeholder' => 'JJ/MM/AAAA HH:MM',
          'data-week-target' => 'week-dateFinReelle',
        ],
      ])
      ->add('difficultesRencontrees', TextareaType::class, [
        'required' => false,
        'label' => 'Difficultés rencontrées globales',
        'attr' => [
          'class' => 'form-control',
          'rows' => 4,
        ],
      ])
      ->add('zones', CollectionType::class, [
        'entry_type' => ChantierZoneType::class,
        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,
        'prototype' => true,
        'prototype_name' => '__zone__',
        'entry_options' => [
          'label' => false,
          'entite' => $entite,
        ],
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
