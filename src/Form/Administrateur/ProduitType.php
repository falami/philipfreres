<?php

namespace App\Form\Administrateur;

use App\Entity\Produit;
use App\Enum\CategorieProduit;
use App\Enum\SousCategorieProduit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{EnumType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProduitType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b
      ->add('categorieProduit', EnumType::class, [
        'class' => CategorieProduit::class,
        'label' => '*Catégorie',
        'required' => true,
        'choice_label' => static fn(CategorieProduit $c) => $c->label(),
        'choice_value' => static fn(?CategorieProduit $c) => $c?->value, // ✅ important
        'attr' => ['class' => 'form-select form-select-lg shadow-sm'],
        'row_attr' => ['class' => 'mb-4'],
      ])

      ->add('sousCategorieProduit', EnumType::class, [
        'class' => SousCategorieProduit::class,
        'label' => '*Sous-catégorie',
        'required' => true,
        'choice_label' => static fn(SousCategorieProduit $s) => $s->label(),
        'choice_value' => static fn(?SousCategorieProduit $s) => $s?->value, // ✅ important
        'attr' => ['class' => 'form-select form-select-lg shadow-sm'],
        'row_attr' => ['class' => 'mb-3'],
        'help' => 'Astuce : garde une sous-catégorie cohérente avec la catégorie (ton enum possède getParentCategory()).',
        'help_attr' => ['class' => 'form-text text-muted small'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults(['data_class' => Produit::class]);
  }
}
