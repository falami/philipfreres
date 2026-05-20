<?php

namespace App\Form\Administrateur;

use App\Entity\Engin;
use App\Entity\EnginUsageReleve;
use App\Entity\Entite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{DateType, NumberType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EnginUsageReleveType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('engin', EntityType::class, [
        'class' => Engin::class,
        'label' => '*Engin',
        'placeholder' => 'Sélectionner un engin',
        'choice_label' => fn(Engin $e) => sprintf('%s%s', $e->getNom(), $e->getImmatriculation() ? ' · ' . $e->getImmatriculation() : ''),
        'query_builder' => fn($repo) => $repo->createQueryBuilder('e')
          ->andWhere('e.entite = :entite')
          ->setParameter('entite', $entite)
          ->orderBy('e.nom', 'ASC'),
        'attr' => ['class' => 'form-select tomselect'],
      ])
      ->add('dateReleve', DateType::class, [
        'label' => '*Date',
        'widget' => 'single_text',
        'html5' => false,
        'attr' => ['class' => 'form-control js-flatpickr', 'placeholder' => 'jj/mm/aaaa'],
      ])
      ->add('valeur', NumberType::class, [
        'label' => '*Valeur',
        'scale' => 2,
        'html5' => true,
        'attr' => ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => '8.50 ou 125'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => EnginUsageReleve::class,
      'entite' => null,
    ]);

    $r->setRequired('entite');
    $r->setAllowedTypes('entite', Entite::class);
  }
}
