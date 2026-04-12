<?php

declare(strict_types=1);

namespace App\Form\Administrateur;

use App\Entity\{Entite, Engin, Utilisateur, TransactionCarteEdenred};
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
  DateTimeType,
  DateType,
  IntegerType,
  MoneyType,
  NumberType,
  TextType,
  TextareaType
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TransactionCarteEdenredType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite|null $entite */
    $entite = $o['entite'];

    $rowAttr = ['row_attr' => ['class' => 'mb-3']];
    $ctrl    = ['class' => 'form-control'];
    $sel     = ['class' => 'form-select js-tomselect'];

    // ✅ Tous les champs "editables" (on exclut id / entite / provider par défaut)
    $b
      // --- Meta import
      ->add('importKey', TextType::class, [
        'required' => false,
        'label' => 'Import key',
        'help' => 'Attention : sert d’anti-doublon.',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('sourceFilename', TextType::class, [
        'required' => false,
        'label' => 'Fichier source',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('sourceRow', IntegerType::class, [
        'required' => false,
        'label' => 'Ligne source',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('importedAt', DateTimeType::class, [
        'required' => false,
        'label' => 'Importé le',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'attr' => $ctrl,
      ] + $rowAttr)

      // --- Station / site
      ->add('enseigne', TextType::class, ['required' => false, 'label' => 'Enseigne', 'attr' => $ctrl] + $rowAttr)
      ->add('siteCodeSite', TextType::class, ['required' => false, 'label' => 'Site — code site', 'attr' => $ctrl] + $rowAttr)
      ->add('siteNumeroTerminal', TextType::class, ['required' => false, 'label' => 'Site — n° terminal', 'attr' => $ctrl] + $rowAttr)
      ->add('siteLibelle', TextType::class, ['required' => false, 'label' => 'Site — libellé', 'attr' => $ctrl] + $rowAttr)
      ->add('siteLibelleCourt', TextType::class, ['required' => false, 'label' => 'Site — libellé court', 'attr' => $ctrl] + $rowAttr)
      ->add('siteType', TextType::class, ['required' => false, 'label' => 'Site — type', 'attr' => $ctrl] + $rowAttr)

      // --- Client
      ->add('clientReference', TextType::class, ['required' => false, 'label' => 'Client — référence', 'attr' => $ctrl] + $rowAttr)
      ->add('clientNom', TextType::class, ['required' => false, 'label' => 'Client — nom', 'attr' => $ctrl] + $rowAttr)

      // --- Carte
      ->add('carteType', TextType::class, ['required' => false, 'label' => 'Carte — type', 'attr' => $ctrl] + $rowAttr)
      ->add('carteNumero', TextType::class, ['required' => false, 'label' => 'Carte — n°', 'attr' => $ctrl] + $rowAttr)
      ->add('carteValidite', TextType::class, ['required' => false, 'label' => 'Carte — validité', 'attr' => $ctrl, 'help' => 'Ex: 01/26'] + $rowAttr)

      // --- Télécollecte / transaction
      ->add('numeroTlc', TextType::class, ['required' => false, 'label' => 'N° TLC', 'attr' => $ctrl] + $rowAttr)
      ->add('dateTelecollecte', DateType::class, [
        'required' => false,
        'label' => 'Date télécollecte',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'attr' => $ctrl,
      ] + $rowAttr)

      ->add('typeTransaction', TextType::class, ['required' => false, 'label' => 'Type transaction', 'attr' => $ctrl] + $rowAttr)
      ->add('numeroTransaction', TextType::class, ['required' => false, 'label' => 'N° transaction', 'attr' => $ctrl] + $rowAttr)
      ->add('dateTransaction', DateType::class, [
        'required' => false,
        'label' => 'Date transaction',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('referenceTransaction', TextType::class, ['required' => false, 'label' => 'Référence transaction', 'attr' => $ctrl] + $rowAttr)

      // --- Produit / montants
      ->add('codeDevise', TextType::class, ['required' => false, 'label' => 'Devise', 'attr' => $ctrl] + $rowAttr)
      ->add('codeProduit', TextType::class, ['required' => false, 'label' => 'Code produit', 'attr' => $ctrl] + $rowAttr)
      ->add('produit', TextType::class, ['required' => false, 'label' => 'Produit', 'attr' => $ctrl] + $rowAttr)

      ->add('prixUnitaire', NumberType::class, [
        'required' => false,
        'label' => 'Prix unitaire',
        'scale' => 4,
        'attr' => $ctrl + ['step' => '0.0001'],
      ] + $rowAttr)
      ->add('quantite', NumberType::class, [
        'required' => false,
        'label' => 'Quantité',
        'scale' => 3,
        'attr' => $ctrl + ['step' => '0.001'],
      ] + $rowAttr)

      ->add('montantTtc', MoneyType::class, [
        'required' => false,
        'label' => 'Montant TTC',
        'currency' => 'EUR',
        'attr' => $ctrl,
      ] + $rowAttr)
      ->add('montantHt', MoneyType::class, [
        'required' => false,
        'label' => 'Montant HT',
        'currency' => 'EUR',
        'attr' => $ctrl,
      ] + $rowAttr)

      // --- Véhicule / chauffeur
      ->add('codeVehicule', TextType::class, ['required' => false, 'label' => 'Code véhicule', 'attr' => $ctrl] + $rowAttr)
      ->add('codeChauffeur', TextType::class, ['required' => false, 'label' => 'Code chauffeur', 'attr' => $ctrl] + $rowAttr)
      ->add('kilometrage', TextType::class, ['required' => false, 'label' => 'Kilométrage (texte)', 'attr' => $ctrl] + $rowAttr)
      ->add('immatriculation', TextType::class, ['required' => false, 'label' => 'Immatriculation', 'attr' => $ctrl] + $rowAttr)

      // --- Réponse / autorisations
      ->add('codeReponse', TextType::class, ['required' => false, 'label' => 'Code réponse', 'attr' => $ctrl] + $rowAttr)
      ->add('numeroOpposition', TextType::class, ['required' => false, 'label' => 'N° opposition', 'attr' => $ctrl] + $rowAttr)
      ->add('numeroAutorisation', TextType::class, ['required' => false, 'label' => 'N° autorisation', 'attr' => $ctrl] + $rowAttr)
      ->add('motifAutorisation', TextareaType::class, ['required' => false, 'label' => 'Motif autorisation', 'attr' => $ctrl] + $rowAttr)

      // --- Mode / facturation
      ->add('modeTransaction', TextType::class, ['required' => false, 'label' => 'Mode transaction', 'attr' => $ctrl] + $rowAttr)
      ->add('modeVente', TextType::class, ['required' => false, 'label' => 'Mode vente', 'attr' => $ctrl] + $rowAttr)
      ->add('modeValidation', TextType::class, ['required' => false, 'label' => 'Mode validation', 'attr' => $ctrl] + $rowAttr)
      ->add('facturationClient', TextType::class, ['required' => false, 'label' => 'Facturation client', 'attr' => $ctrl] + $rowAttr)
      ->add('facturationSite', TextType::class, ['required' => false, 'label' => 'Facturation site', 'attr' => $ctrl] + $rowAttr)

      ->add('soldeApres', MoneyType::class, [
        'required' => false,
        'label' => 'Solde après',
        'currency' => 'EUR',
        'attr' => $ctrl,
      ] + $rowAttr)

      ->add('numeroFacture', TextType::class, ['required' => false, 'label' => 'N° facture', 'attr' => $ctrl] + $rowAttr)
      ->add('avoirGerant', TextType::class, ['required' => false, 'label' => 'Avoir gérant', 'attr' => $ctrl] + $rowAttr)

      // --- Liaisons (engin / utilisateur)
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
        'query_builder' => function (EntityRepository $er) use ($entite) {
          $qb = $er->createQueryBuilder('e')->orderBy('e.nom', 'ASC');
          if ($entite) {
            $qb->andWhere('e.entite = :entite')->setParameter('entite', $entite);
          }
          return $qb;
        },
        'attr' => $sel,
        'row_attr' => ['class' => 'mb-3'],
      ])
      ->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'required' => false,
        'label' => 'Employé lié',
        'placeholder' => '— Aucun —',
        'choice_label' => fn(Utilisateur $u) => trim($u->getPrenom() . ' ' . $u->getNom()),
        'query_builder' => function (EntityRepository $er) use ($entite) {
          $qb = $er->createQueryBuilder('u')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

          if ($entite) {
            $qb->innerJoin('u.utilisateurEntites', 'ue')
              ->andWhere('ue.entite = :entite')
              ->setParameter('entite', $entite);
          }

          return $qb;
        },
        'attr' => $sel,
        'row_attr' => ['class' => 'mb-3'],
      ])
    ;
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => TransactionCarteEdenred::class,
      'entite' => null, // optionnel : pour filtrer engins / employés
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
