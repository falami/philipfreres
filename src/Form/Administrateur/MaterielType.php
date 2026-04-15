<?php

declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\Materiel;
use App\Enum\MaterielCategorie;
use App\Enum\MaterielStatut;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  EnumType,
  FileType,
  TextType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

final class MaterielType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('nom', TextType::class, [
        'label' => 'Nom',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex. Tronçonneuse, Débroussailleuse',
        ],
      ])

      ->add('categorie', EnumType::class, [
        'class' => MaterielCategorie::class,
        'label' => 'Catégorie',
        'placeholder' => 'Choisir une catégorie',
        'required' => false,
        'choice_label' => static fn(MaterielCategorie $choice): string => $choice->label(),
        'attr' => [
          'class' => 'form-select',
        ],
      ])

      ->add('statut', EnumType::class, [
        'class' => MaterielStatut::class,
        'label' => 'Statut',
        'choice_label' => static fn(MaterielStatut $choice): string => $choice->label(),
        'attr' => [
          'class' => 'form-select',
        ],
      ])

      ->add('numeroSerie', TextType::class, [
        'label' => 'Numéro de série',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex. SN-2024-000123',
        ],
      ])

      ->add('photo', FileType::class, [
        'label' => 'Photo de couverture',
        'mapped' => false,
        'required' => false,
        'constraints' => [
          new Image(
            maxSize: '8M',
            mimeTypesMessage: 'Le fichier doit être une image valide.'
          ),
        ],
        'attr' => [
          'accept' => 'image/*',
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => Materiel::class,
      'is_edit' => false,
    ]);
  }
}
