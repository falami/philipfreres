<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierDechet;
use App\Entity\Dechet;
use App\Entity\Entite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

class ChantierDechetType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('typeDechet', EntityType::class, [
        'class' => Dechet::class,
        'label' => 'Type de déchet',
        'choice_label' => fn(Dechet $d) => $d->getNom() . ' (' . $d->getUnite() . ')',
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('d')
          ->andWhere('d.entite = :entite')
          ->setParameter('entite', $entite)
          ->orderBy('d.nom', 'ASC'),
        'placeholder' => 'Sélectionner',
        'attr' => ['class' => 'form-select'],
      ])
      ->add('quantite', NumberType::class, [
        'label' => 'Quantité',
        'required' => false,
        'scale' => 2,
        'html5' => true,
        'attr' => [
          'class' => 'form-control',
          'step' => '0.01',
          'min' => '0',
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ChantierDechet::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
