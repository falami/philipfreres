<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierRessourceMateriel;
use App\Entity\Entite;
use App\Entity\Materiel;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChantierRessourceMaterielType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'];

    $b
      ->add('materiel', EntityType::class, [
        'class' => Materiel::class,
        'label' => 'Matériel',
        'placeholder' => 'Choisir un matériel',
        'choice_label' => static function (Materiel $materiel): string {
          $categorie = $materiel->getCategorie()?->label() ?? 'Sans catégorie';
          $nom = $materiel->getNom() ?? '';

          return $categorie . ' - ' . $nom;
        },
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('m')
          ->andWhere('m.entite = :entite')
          ->setParameter('entite', $entite)
          ->orderBy('m.categorie', 'ASC')
          ->addOrderBy('m.nom', 'ASC'),
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
