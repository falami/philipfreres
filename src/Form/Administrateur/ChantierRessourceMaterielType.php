<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierRessourceMateriel;
use App\Entity\Materiel;
use App\Entity\Entite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

class ChantierRessourceMaterielType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('materiel', EntityType::class, [
        'class' => Materiel::class,
        'label' => 'Matériel',
        'choice_label' => 'nom',
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('m')
          ->andWhere('m.entite = :entite')
          ->setParameter('entite', $entite)
          ->orderBy('m.nom', 'ASC'),
        'attr' => ['class' => 'form-select'],
      ])
      ->add('quantite', IntegerType::class, [
        'label' => 'Qté',
        'required' => false,
        'attr' => ['class' => 'form-control', 'min' => 1],
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
      'data_class' => ChantierRessourceMateriel::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
