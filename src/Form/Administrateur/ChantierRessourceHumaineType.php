<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierRessourceHumaine;
use App\Entity\Utilisateur;
use App\Entity\Entite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

class ChantierRessourceHumaineType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'label' => 'Collaborateur',
        'choice_label' => fn(Utilisateur $u) => trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? '')),
        'query_builder' => function (EntityRepository $er) use ($entite) {
          return $er->createQueryBuilder('u')
            ->innerJoin('u.utilisateurEntites', 'ue')
            ->andWhere('ue.entite = :entite')
            ->setParameter('entite', $entite)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');
        },
        'attr' => ['class' => 'form-select'],
      ])
      ->add('fonction', TextType::class, [
        'label' => 'Fonction sur chantier',
        'required' => false,
        'attr' => ['class' => 'form-control', 'placeholder' => 'Chef de chantier, opérateur, conducteur...'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ChantierRessourceHumaine::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
