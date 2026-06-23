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
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Entity\Mandataire;
use App\Repository\MandataireRepository;

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

      ->add('codeChantier', TextType::class, [
        'required' => false,
        'label' => 'Code chantier',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex : CH-2026-001',
        ],
      ])
      ->add('nominationChantier', TextType::class, [
        'required' => false,
        'label' => 'Nomination chantier',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex : Entretien annuel - secteur nord',
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

      ->add('mandataires', EntityType::class, [
        'class' => Mandataire::class,
        'label' => 'Mandataires',
        'required' => false,
        'multiple' => true,
        'expanded' => false,
        'by_reference' => false,
        'choice_label' => fn(Mandataire $m) => (string) $m,
        'query_builder' => function (MandataireRepository $repo) use ($entite) {
          return $repo->createQueryBuilder('m')
            ->andWhere('m.entite = :entite')
            ->andWhere('m.actif = true')
            ->setParameter('entite', $entite)
            ->orderBy('m.societe', 'ASC')
            ->addOrderBy('m.nom', 'ASC');
        },
        'attr' => [
          'class' => 'form-select js-ts-mandataires',
          'placeholder' => 'Sélectionner un ou plusieurs mandataires',
        ],
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
      ])
    ;
    if ($o['can_manage_affectations']) {
      $b->add('utilisateursAffectes', EntityType::class, [
        'class' => Utilisateur::class,
        'label' => 'Utilisateurs autorisés à modifier ce chantier',
        'required' => false,
        'multiple' => true,
        'expanded' => false,
        'by_reference' => false,
        'choice_label' => fn(Utilisateur $u) => trim(($u->getPrenom() ?: '') . ' ' . ($u->getNom() ?: '')),
        'query_builder' => function (UtilisateurRepository $repo) use ($entite) {
          return $repo->createQueryBuilder('u')
            ->andWhere('u.entite = :entite')
            ->setParameter('entite', $entite)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');
        },
        'attr' => [
          'class' => 'form-select',
        ],
        'help' => 'Ces utilisateurs pourront voir et modifier ce chantier, sauf s’il est en brouillon.',
      ]);
    }
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Chantier::class,
      'entite' => null,
      'can_manage_affectations' => false,
    ]);

    $r->setAllowedTypes('can_manage_affectations', 'bool');
  }
}
