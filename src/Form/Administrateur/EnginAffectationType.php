<?php
// src/Form/Administrateur/EnginAffectationType.php

namespace App\Form\Administrateur;

use App\Entity\{Utilisateur};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EnginAffectationType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $entite = $o['entite'];

    $b
      ->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'choice_label' => fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()),
        'query_builder' => function (EntityRepository $r) use ($entite) {
          // si tes utilisateurs ont un champ entite direct, tu peux filtrer dessus.
          // sinon, adapte sur UtilisateurEntite.
          return $r->createQueryBuilder('u')
            ->andWhere('u.entite = :e')->setParameter('e', $entite)
            ->orderBy('u.nom', 'ASC');
        },
        'placeholder' => 'Choisir un employé…',
        'attr' => ['class' => 'form-select js-tomselect'],
      ])
      ->add('dateDebut', DateType::class, [
        'widget' => 'single_text',
        'attr' => ['class' => 'form-control js-flatpickr'],
      ])
      ->add('dateFin', DateType::class, [
        'required' => false,
        'widget' => 'single_text',
        'attr' => ['class' => 'form-control js-flatpickr'],
        'help' => 'Laisse vide pour “toujours actif”.',
      ])
      ->add('note', TextType::class, [
        'required' => false,
        'label' => 'Note',
      ]);;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'entite' => null,
    ]);
  }
}
