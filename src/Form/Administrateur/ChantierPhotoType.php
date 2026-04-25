<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierPhoto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class ChantierPhotoType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('titre', TextType::class, [
        'label' => 'Titre / zone photo',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex : Avaloir zone nord',
        ],
      ])
      ->add('ordre', HiddenType::class, [
        'required' => false,
        'empty_data' => '0',
        'attr' => [
          'class' => 'js-photo-ordre',
        ],
      ])

      ->add('avantFile', FileType::class, [
        'mapped' => false,
        'required' => false,
        'label' => false,
        'constraints' => [new Image(maxSize: '16M')],
        'attr' => [
          'class' => 'd-none js-photo-file js-photo-file-avant',
          'accept' => 'image/*',
        ],
      ])
      ->add('datePriseVueAvant', DateType::class, [
        'label' => 'Date de prise de vue avant',
        'widget' => 'single_text',
        'required' => false,
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-photo-date-avant',
          'data-flatpickr' => '1',
          'placeholder' => 'jj/mm/aaaa',
        ],
      ])
      ->add('adresseAvant', TextType::class, [
        'label' => 'Adresse photo avant',
        'required' => false,
        'attr' => [
          'class' => 'form-control js-photo-address-avant',
          'placeholder' => 'Adresse ou lieu de prise de vue',
        ],
      ])
      ->add('latitudeAvant', HiddenType::class, [
        'required' => false,
        'attr' => ['class' => 'js-photo-lat-avant'],
      ])
      ->add('longitudeAvant', HiddenType::class, [
        'required' => false,
        'attr' => ['class' => 'js-photo-lng-avant'],
      ])
      ->add('sourceLocalisationAvant', HiddenType::class, [
        'required' => false,
        'attr' => ['class' => 'js-photo-source-avant'],
      ])

      ->add('apresFile', FileType::class, [
        'mapped' => false,
        'required' => false,
        'label' => false,
        'constraints' => [new Image(maxSize: '16M')],
        'attr' => [
          'class' => 'd-none js-photo-file js-photo-file-apres',
          'accept' => 'image/*',
        ],
      ])
      ->add('datePriseVueApres', DateType::class, [
        'label' => 'Date de prise de vue après',
        'widget' => 'single_text',
        'required' => false,
        'html5' => false,
        'attr' => [
          'class' => 'form-control js-photo-date-apres',
          'data-flatpickr' => '1',
          'placeholder' => 'jj/mm/aaaa',
        ],
      ])
      ->add('adresseApres', TextType::class, [
        'label' => 'Adresse photo après',
        'required' => false,
        'attr' => [
          'class' => 'form-control js-photo-address-apres',
          'placeholder' => 'Adresse ou lieu de prise de vue',
        ],
      ])
      ->add('latitudeApres', HiddenType::class, [
        'required' => false,
        'attr' => ['class' => 'js-photo-lat-apres'],
      ])
      ->add('longitudeApres', HiddenType::class, [
        'required' => false,
        'attr' => ['class' => 'js-photo-lng-apres'],
      ])
      ->add('sourceLocalisationApres', HiddenType::class, [
        'required' => false,
        'attr' => ['class' => 'js-photo-source-apres'],
      ])

      ->add('commentaire', TextType::class, [
        'label' => 'Commentaire',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Optionnel',
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ChantierPhoto::class,
    ]);
  }
}
