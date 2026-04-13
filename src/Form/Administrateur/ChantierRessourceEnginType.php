<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierRessourceEngin;
use App\Entity\Engin;
use App\Entity\Entite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

class ChantierRessourceEnginType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('engin', EntityType::class, [
        'class' => Engin::class,
        'label' => 'Engin',
        'choice_label' => fn(Engin $e) => $e->getNom() . ($e->getImmatriculation() ? ' — ' . $e->getImmatriculation() : ''),
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('e')
          ->andWhere('e.entite = :entite')
          ->setParameter('entite', $entite)
          ->orderBy('e.nom', 'ASC'),
        'attr' => ['class' => 'form-select'],
      ])
      ->add('commentaire', TextType::class, [
        'label' => 'Commentaire',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Optionnel'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ChantierRessourceEngin::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
