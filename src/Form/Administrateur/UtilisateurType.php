<?php

namespace App\Form\Administrateur;

use App\Entity\UtilisateurEntite;
use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  CheckboxType,
  ChoiceType,
  EmailType,
  TextType,
  FileType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\{
  Image,
};
use App\Form\DataTransformer\FrenchToDateTransformer;

final class UtilisateurType extends AbstractType
{
  public function __construct(
    private FrenchToDateTransformer $dateFr,
  ) {}

  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $locked = $o['locked'] ?? false;
    $entite = $o['entite'] ?? null;
    /** @var Utilisateur $utilisateur */
    $utilisateur = $b->getData();

    // ✅ Rôle “dans l’entité” (UtilisateurEntite.role)
    $choices = [
      'Employé'      => UtilisateurEntite::TENANT_EMPLOYE,
    ];

    if (($o['can_set_high_roles'] ?? false) === true) {
      $choices['Administrateur'] = UtilisateurEntite::TENANT_ADMIN;
    }



    $b
      ->add('civilite', ChoiceType::class, [
        'label' => 'Civilité',
        'required' => false,
        'choices' => ['-' => null, 'Monsieur' => 'Monsieur', 'Madame' => 'Madame'],
        'attr' => ['class' => 'form-select']
      ])
      ->add('prenom', TextType::class, [
        'label' => 'Prénom',
        'disabled' => $locked,
        'attr' => ['class' => 'form-control']
      ])
      ->add('nom', TextType::class, [
        'disabled' => $locked,
        'attr' => ['class' => 'form-control']
      ])
      ->add('email', EmailType::class, [
        'disabled' => $locked,
        'attr' => ['class' => 'form-control']
      ])

      ->add('photo', FileType::class, [
        'mapped' => false,
        'required' => false,
        'label'  => 'Photo de profil',
        'constraints' => [new Image(maxSize: '8M', mimeTypesMessage: 'Image invalide')],
        'attr' => ['accept' => 'image/*']
      ])
      ->add('dateNaissance', TextType::class, [
        'required' => false,
        'disabled' => $locked,
        'attr' => ['class' => 'form-control js-flatpickr-date']
      ])

      ->add('telephone', TextType::class, [
        'label' => 'Téléphone',
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('adresse', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('complement', TextType::class, [
        'label' => 'Complément',
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('codePostal', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('ville', TextType::class, [
        'required' => false,
        'attr' => ['class' => 'form-control']
      ])
      ->add('isVerified', CheckboxType::class, [
        'required' => false,
        'disabled' => $locked,
      ])
      ->add('newsletter', CheckboxType::class, [
        'required' => false,
        'disabled' => $locked,
      ])


      ->add('ueRoles', ChoiceType::class, [
        'label' => 'Rôles',
        'mapped' => false,
        'required' => true,
        'multiple' => true,
        'expanded' => false,
        'data' => $o['ueRoles'] ?? [UtilisateurEntite::TENANT_EMPLOYE],
        'choices' => $choices,
        'attr' => ['class' => 'form-select js-ts-ueroles'],
      ]);
    $b->get('dateNaissance')->addModelTransformer($this->dateFr);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => Utilisateur::class,
      'locked' => false,
      'entite' => null,
      'ueRoles' => [UtilisateurEntite::TENANT_EMPLOYE],
      'can_set_high_roles' => false,
    ]);
  }
}
