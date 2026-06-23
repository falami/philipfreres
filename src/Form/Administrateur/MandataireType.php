<?php

namespace App\Form\Administrateur;

use App\Entity\Mandataire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class MandataireType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('societe', TextType::class, [
        'label' => 'Société',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Nom de la société',
        ],
      ])
      ->add('nom', TextType::class, [
        'label' => 'Nom du mandataire',
        'required' => true,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Nom du mandataire',
        ],
      ])
      ->add('email', EmailType::class, [
        'label' => 'Email',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'adresse@email.fr',
          'autocomplete' => 'email',
        ],
        'constraints' => [
          new Assert\Email(message: 'L’adresse email renseignée n’est pas valide. Exemple attendu : contact@societe.fr.'),
          new Assert\Length(
            max: 180,
            maxMessage: 'L’adresse email ne peut pas dépasser {{ limit }} caractères.'
          ),
        ],
      ])

      ->add('telephone', TextType::class, [
        'label' => 'Téléphone',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => '06 00 00 00 00',
          'autocomplete' => 'tel',
          'inputmode' => 'tel',
        ],
        'constraints' => [
          new Assert\Regex(
            pattern: '/^(?:(?:\+33|0033)\s?[1-9](?:[\s.-]?\d{2}){4}|0[1-9](?:[\s.-]?\d{2}){4})$/',
            message: 'Le numéro de téléphone n’est pas valide. Exemple attendu : 06 12 34 56 78 ou +33 6 12 34 56 78.'
          ),
        ],
      ])
      ->add('adresse', TextType::class, [
        'label' => 'Adresse',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Adresse complète',
        ],
      ])
      ->add('codePostal', TextType::class, [
        'label' => 'Code postal',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => '30100',
        ],
      ])
      ->add('ville', TextType::class, [
        'label' => 'Ville',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ville',
        ],
      ])
      ->add('commentaire', TextareaType::class, [
        'label' => 'Commentaire',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'rows' => 4,
          'placeholder' => 'Notes internes, informations complémentaires...',
        ],
      ])
      ->add('actif', CheckboxType::class, [
        'label' => 'Mandataire actif',
        'required' => false,
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Mandataire::class,
    ]);
  }
}
