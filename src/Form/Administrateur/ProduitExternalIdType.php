<?php

namespace App\Form\Administrateur;

use App\Entity\ProduitExternalId;
use App\Enum\ExternalProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{EnumType, TextareaType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProduitExternalIdType extends AbstractType
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
        'attr' => ['class' => 'form-select form-select-lg shadow-sm'],
        'row_attr' => ['class' => 'mb-4'],
        'help' => 'ALX / Total / Edenred…',
        'help_attr' => ['class' => 'form-text text-muted small'],
      ])
      ->add('value', TextType::class, [
        'label' => 'Valeur externe (produit/catégorie/cuvelabel)',
        'required' => true,
        'attr' => [
          'class' => 'form-control form-control-lg shadow-sm',
          'placeholder' => 'Ex: GASOIL / 1 / carburant gazole… (normalisé automatiquement)',
          'autocomplete' => 'off',
        ],
        'row_attr' => ['class' => 'mb-4'],
      ])
      ->add('note', TextareaType::class, [
        'label' => 'Note (optionnel)',
        'required' => false,
        'attr' => [
          'class' => 'form-control shadow-sm',
          'rows' => 3,
          'placeholder' => 'Contexte, règle interne, date, etc.',
        ],
        'row_attr' => ['class' => 'mb-3'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => ProduitExternalId::class]);
  }
}
