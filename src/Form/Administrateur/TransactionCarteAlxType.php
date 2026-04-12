<?php
// src/Form/Administrateur/TransactionCarteAlxType.php

declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\{Entite, Engin, Utilisateur, TransactionCarteAlx};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  DateType,
  TimeType,
  IntegerType,
  TextType,
  NumberType,
  DateTimeType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TransactionCarteAlxType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'];

    $rowAttr = ['row_attr' => ['class' => 'mb-3']];
    $ctrl    = ['class' => 'form-control'];
    $sel     = ['class' => 'form-select js-tomselect'];

    // ✅ Tous les champs éditables de l’entité (hors id / entite / provider)
    $b
      // --- Transaction
      ->add('journee', DateType::class, [
        'required' => false,
        'label' => 'Journée',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('horaire', TimeType::class, [
        'required' => false,
        'label' => 'Horaire',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'attr' => $ctrl,
      ] + $rowAttr)

      ->add('vehicule', TextType::class, [
        'required' => false,
        'label' => 'Véhicule (libellé import)',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('codeVeh', TextType::class, [
        'required' => false,
        'label' => 'Code véhicule',
        'attr' => $ctrl,
      ] + $rowAttr)

      ->add('codeAgent', TextType::class, [
        'required' => false,
        'label' => 'Code agent',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('agent', TextType::class, [
        'required' => false,
        'label' => 'Agent (libellé import)',
        'attr' => $ctrl,
      ] + $rowAttr)

      ->add('operation', IntegerType::class, [
        'required' => false,
        'label' => 'Opération',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('cuve', IntegerType::class, [
        'required' => false,
        'label' => 'Cuve',
        'attr' => $ctrl,
      ] + $rowAttr)

      ->add('quantite', NumberType::class, [
        'required' => false,
        'label' => 'Quantité',
        'scale' => 3,
        'attr' => $ctrl + ['step' => '0.001'],
      ] + $rowAttr)
      ->add('prixUnitaire', NumberType::class, [
        'required' => false,
        'label' => 'Prix unitaire',
        'scale' => 4,
        'attr' => $ctrl + ['step' => '0.0001'],
      ] + $rowAttr)
      ->add('compteur', NumberType::class, [
        'required' => false,
        'label' => 'Compteur',
        'scale' => 0,
        'attr' => $ctrl + ['step' => '1'],
      ] + $rowAttr)

      // --- Meta import
      ->add('sourceFilename', TextType::class, [
        'required' => false,
        'label' => 'Fichier source',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('sourceRow', IntegerType::class, [
        'required' => false,
        'label' => 'Ligne source',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('importKey', TextType::class, [
        'required' => false,
        'label' => 'Import key',
        'help' => 'Attention : sert d’anti-doublon.',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('importedAt', DateTimeType::class, [
        'required' => false,
        'label' => 'Importé le',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'attr' => $ctrl,
      ] + $rowAttr)

      // --- Liaisons (engin / utilisateur)
      ->add('engin', EntityType::class, [
        'class' => Engin::class,
        'required' => false,
        'label' => 'Engin lié',
        'placeholder' => '— Aucun —',
        'choice_label' => fn(Engin $e) => trim(sprintf(
          '%s%s',
          $e->getNom() ?? ('Engin #' . $e->getId()),
          $e->getImmatriculation() ? (' — ' . $e->getImmatriculation()) : ''
        )),
        'query_builder' => function (EntityRepository $er) use ($entite) {
          $qb = $er->createQueryBuilder('e')->orderBy('e.nom', 'ASC');
          if ($entite) {
            $qb->andWhere('e.entite = :entite')->setParameter('entite', $entite);
          }
          return $qb;
        },
        'attr' => $sel,
        'row_attr' => ['class' => 'mb-3'],
      ])
      ->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'required' => false,
        'label' => 'Employé lié',
        'placeholder' => '— Aucun —',
        'choice_label' => fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()),
        'query_builder' => function (EntityRepository $er) use ($entite) {
          $qb = $er->createQueryBuilder('u')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

          if ($entite) {
            $qb->innerJoin('u.utilisateurEntites', 'ue')
              ->andWhere('ue.entite = :entite')
              ->setParameter('entite', $entite);
          }

          return $qb;
        },
        'attr' => $sel,
        'row_attr' => ['class' => 'mb-3'],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => TransactionCarteAlx::class,
      'entite' => null, // optionnel : pour filtrer engins / employés
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
