<?php
// src/Form/Administrateur/UtilisateurExternalIdType.php

namespace App\Form\Administrateur;

use App\Entity\UtilisateurExternalId;
use App\Enum\ExternalProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextareaType, TextType};
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UtilisateurExternalIdType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('provider', EnumType::class, [
        'class' => ExternalProvider::class,
        'label' => 'Fournisseur',
        'placeholder' => '— Choisir un fournisseur —',
        'required' => true,
        'choice_label' => static function (ExternalProvider $p): string {
          return method_exists($p, 'label') ? $p->label() : ucfirst(strtolower($p->name));
        },
        'attr' => [
          'class' => 'form-select form-select-lg shadow-sm js-ts-provider',
        ],
        'row_attr' => [
          'class' => 'mb-4',
        ],
        'help' => 'Système externe lié à cet utilisateur.',
        'help_attr' => [
          'class' => 'form-text text-muted small',
        ],
      ])

      ->add('value', TextType::class, [
        'label' => 'Identifiant externe',
        'required' => true,
        'attr' => [
          'class' => 'form-control form-control-lg shadow-sm',
          'placeholder' => 'Ex: EDENRED-XXXX / Matricule RH',
          'autocomplete' => 'off',
        ],
        'row_attr' => [
          'class' => 'mb-4',
        ],
        'help' => 'Identifiant exact utilisé dans le système fournisseur.',
        'help_attr' => [
          'class' => 'form-text text-muted small',
        ],
      ])

      ->add('note', TextareaType::class, [
        'label' => 'Note (optionnel)',
        'required' => false,
        'attr' => [
          'class' => 'form-control shadow-sm',
          'rows' => 3,
          'placeholder' => 'Commentaire interne, date d’activation, etc.',
        ],
        'row_attr' => [
          'class' => 'mb-3',
        ],
        'help' => 'Visible uniquement en administration.',
        'help_attr' => [
          'class' => 'form-text text-muted small',
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => UtilisateurExternalId::class,
    ]);
  }
}
