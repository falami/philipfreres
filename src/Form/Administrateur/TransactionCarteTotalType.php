<?php

namespace App\Form\Administrateur;

use App\Entity\{Entite, Engin, Utilisateur, TransactionCarteTotal};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  DateType,
  TimeType,
  IntegerType,
  TextType,
  TextareaType,
  MoneyType,
  NumberType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TransactionCarteTotalType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'];

    // Helpers Bootstrap
    $c  = fn(string $ph = '', array $attr = []) => ['attr' => array_merge(['class' => 'form-control', 'placeholder' => $ph], $attr)];
    $s  = fn(string $ph = '', array $attr = []) => ['attr' => array_merge(['class' => 'form-select', 'data-placeholder' => $ph], $attr)];
    $ta = fn(string $ph = '', array $attr = []) => ['attr' => array_merge(['class' => 'form-control', 'rows' => 3, 'placeholder' => $ph], $attr)];

    // --- Bloc "Identité / source"
    $b
      ->add('compteClient', TextType::class, array_merge(['label' => 'Compte client'], $c('Ex: 123456')))
      ->add('raisonSociale', TextType::class, array_merge(['label' => 'Raison sociale'], $c('Ex: PHILIP FRERES')))
      ->add('compteSupport', TextType::class, array_merge(['required' => false, 'label' => 'Compte support'], $c()))
      ->add('division', TextType::class, array_merge(['required' => false, 'label' => 'Division'], $c()))
      ->add('typeSupport', TextType::class, array_merge(['required' => false, 'label' => 'Type support'], $c()))
      ->add('numeroCarte', TextType::class, array_merge(['required' => false, 'label' => 'Numéro de carte'], $c('Ex: 1234 5678')))
      ->add('rang', TextType::class, array_merge(['required' => false, 'label' => 'Rang'], $c()))
      ->add('evid', TextType::class, array_merge(['required' => false, 'label' => 'EVID'], $c()))
      ->add('nomPersonnaliseCarte', TextType::class, array_merge(['required' => false, 'label' => 'Nom personnalisé carte'], $c()))
      ->add('informationComplementaire', TextareaType::class, array_merge(['required' => false, 'label' => 'Information complémentaire'], $ta('Notes / contexte…')))
      ->add('codeConducteur', TextType::class, array_merge(['required' => false, 'label' => 'Code conducteur'], $c()))
      ->add('immatriculationVehicule', TextType::class, array_merge(['required' => false, 'label' => 'Immatriculation véhicule'], $c('Ex: AB-123-CD')))
      ->add('nomCollaborateur', TextType::class, array_merge(['required' => false, 'label' => 'Nom collaborateur'], $c()))
      ->add('prenomCollaborateur', TextType::class, array_merge(['required' => false, 'label' => 'Prénom collaborateur'], $c()))
      ->add('kilometrage', IntegerType::class, array_merge(['required' => false, 'label' => 'Kilométrage'], $c('Ex: 125000', ['inputmode' => 'numeric'])));

    // --- Transaction
    $b
      ->add('numeroTransaction', TextType::class, array_merge(['required' => false, 'label' => 'N° transaction'], $c()))
      ->add('dateTransaction', DateType::class, array_merge([
        'required' => false,
        'label' => 'Date transaction',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
      ], $c('', ['class' => 'form-control js-flatpickr-date'])))
      ->add('heureTransaction', TimeType::class, array_merge([
        'required' => false,
        'label' => 'Heure transaction',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
      ], $c('', ['class' => 'form-control js-flatpickr-time'])));

    // --- Localisation
    $b
      ->add('pays', TextType::class, array_merge(['required' => false, 'label' => 'Pays'], $c()))
      ->add('ville', TextType::class, array_merge(['required' => false, 'label' => 'Ville'], $c()))
      ->add('codePostal', TextType::class, array_merge(['required' => false, 'label' => 'Code postal'], $c('Ex: 34000')))
      ->add('adresse', TextType::class, array_merge(['required' => false, 'label' => 'Adresse'], $c()));

    // --- Produit / facture
    $b
      ->add('categorieLibelleProduit', TextType::class, array_merge(['required' => false, 'label' => 'Catégorie produit'], $c()))
      ->add('produit', TextType::class, array_merge(['required' => false, 'label' => 'Produit'], $c()))
      ->add('statut', TextType::class, array_merge(['required' => false, 'label' => 'Statut'], $c()))
      ->add('numeroFacture', TextType::class, array_merge(['required' => false, 'label' => 'N° facture'], $c()));

    // --- Montants (DECIMAL stocké string)
    $b
      ->add('quantite', NumberType::class, array_merge(['required' => false, 'label' => 'Quantité', 'scale' => 3], $c('', ['inputmode' => 'decimal'])))
      ->add('unite', TextType::class, array_merge(['required' => false, 'label' => 'Unité'], $c('Ex: L')))
      ->add('prixUnitaireEur', MoneyType::class, array_merge([
        'required' => false,
        'label' => 'Prix unitaire (€)',
        'currency' => 'EUR',
        'scale' => 4,
      ], $c('', ['inputmode' => 'decimal'])))
      ->add('tauxTvaPercent', NumberType::class, array_merge(['required' => false, 'label' => 'TVA (%)', 'scale' => 2], $c('', ['inputmode' => 'decimal'])))
      ->add('montantRemiseEur', MoneyType::class, array_merge(['required' => false, 'label' => 'Remise (€)', 'currency' => 'EUR', 'scale' => 2], $c('', ['inputmode' => 'decimal'])))
      ->add('montantHtEur', MoneyType::class, array_merge(['required' => false, 'label' => 'Montant HT (€)', 'currency' => 'EUR', 'scale' => 2], $c('', ['inputmode' => 'decimal'])))
      ->add('montantTvaEur', MoneyType::class, array_merge(['required' => false, 'label' => 'Montant TVA (€)', 'currency' => 'EUR', 'scale' => 2], $c('', ['inputmode' => 'decimal'])))
      ->add('montantTtcEur', MoneyType::class, array_merge(['required' => false, 'label' => 'Montant TTC (€)', 'currency' => 'EUR', 'scale' => 2], $c('', ['inputmode' => 'decimal'])));

    // --- Meta import (optionnel mais tu as demandé TOUS)
    $b
      ->add('sourceFilename', TextType::class, array_merge(['required' => false, 'label' => 'Fichier source'], $c()))
      ->add('sourceRow', IntegerType::class, array_merge(['required' => false, 'label' => 'Ligne source'], $c('', ['inputmode' => 'numeric'])))
      ->add('importKey', TextType::class, array_merge(['required' => false, 'label' => 'Import key'], $c()));

    // --- Liens (engin / utilisateur)
    $b
      ->add('engin', EntityType::class, [
        'class' => Engin::class,
        'required' => false,
        'label' => 'Engin lié',
        'placeholder' => '— Aucun —',
        'choice_label' => fn(Engin $e) => trim(sprintf(
          '%s%s',
          $e->getNom() ?? ('Engin #' . $e->getId()),
          $e->getImmatriculation() ? (' — ' . $e->getImmatriculation()) : ''
        )),
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('e')
          ->andWhere('e.entite = :entite')->setParameter('entite', $entite)
          ->orderBy('e.nom', 'ASC'),
        // IMPORTANT: EntityType = form-select (Bootstrap)
        'attr' => ['class' => 'form-select js-tomselect', 'data-placeholder' => '— Aucun —'],
      ])
      ->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'required' => false,
        'label' => 'Employé lié',
        'placeholder' => '— Aucun —',
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('u')
          ->innerJoin('u.utilisateurEntites', 'ue')
          ->andWhere('ue.entite = :entite')->setParameter('entite', $entite)
          ->orderBy('u.nom', 'ASC')
          ->addOrderBy('u.prenom', 'ASC'),
        'choice_label' => fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()),
        'attr' => ['class' => 'form-select js-tomselect', 'data-placeholder' => '— Aucun —'],
      ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => TransactionCarteTotal::class,
      'entite' => null,
    ]);
    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
