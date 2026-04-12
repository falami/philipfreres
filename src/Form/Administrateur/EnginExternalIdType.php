<?php
// src/Form/Administrateur/EnginExternalIdType.php

namespace App\Form\Administrateur;

use App\Entity\EnginExternalId;
use App\Enum\ExternalProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextareaType, TextType};
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EnginExternalIdType extends AbstractType
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
        'help' => 'Sélectionne le système externe (ALX, Edenred, Total…).',
        'help_attr' => [
          'class' => 'form-text text-muted small',
        ],
      ])

      ->add('value', TextType::class, [
        'label' => 'Identifiant externe',
        'required' => true,
        'attr' => [
          'class' => 'form-control form-control-lg shadow-sm',
          'placeholder' => 'Ex: EDENRED-123456 / ALX-XXXX',
          'autocomplete' => 'off',
        ],
        'row_attr' => [
          'class' => 'mb-4',
        ],
        'help' => 'Valeur exacte utilisée par le fournisseur (normalisée automatiquement).',
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
          'placeholder' => 'Contexte, date d’attribution, précision interne…',
        ],
        'row_attr' => [
          'class' => 'mb-3',
        ],
        'help' => 'Information interne visible uniquement en back-office.',
        'help_attr' => [
          'class' => 'form-text text-muted small',
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => EnginExternalId::class,
    ]);
  }
}
