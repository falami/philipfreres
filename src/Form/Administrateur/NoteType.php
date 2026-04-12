<?php

namespace App\Form\Administrateur;

use App\Entity\Entite;
use App\Entity\Engin;
use App\Entity\Note;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NoteType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    /** @var Entite|null $entite */
    $entite = $options['entite'] ?? null;

    $control = static function (string $placeholder = '', array $attr = []): array {
      return [
        'attr' => array_merge([
          'class' => 'form-control',
          'placeholder' => $placeholder,
        ], $attr),
      ];
    };

    $textarea = static function (string $placeholder = '', array $attr = []): array {
      return [
        'attr' => array_merge([
          'class' => 'form-control',
          'rows' => 4,
          'placeholder' => $placeholder,
        ], $attr),
      ];
    };

    $money = static function (string $placeholder = '0,00', array $attr = []): array {
      return [
        'attr' => array_merge([
          'class' => 'form-control',
          'placeholder' => $placeholder,
          'inputmode' => 'decimal',
          'autocomplete' => 'off',
        ], $attr),
      ];
    };

    $builder
      ->add('dateTransaction', DateType::class, [
        'required' => false,
        'label' => 'Date',
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'html5' => false,
        'format' => 'dd/MM/yyyy',
        'invalid_message' => 'Veuillez entrer une date valide au format JJ/MM/AAAA.',
        'attr' => [
          'class' => 'form-control flatpickr-date',
          'placeholder' => 'JJ/MM/AAAA',
          'autocomplete' => 'off',
        ],
      ])

      ->add('libelle', TextType::class, array_merge([
        'label' => 'Libellé',
        'required' => true,
      ], $control('Ex : ajustement, achat manuel, régularisation…')))

      ->add('commentaire', TextareaType::class, array_merge([
        'label' => 'Commentaire',
        'required' => false,
      ], $textarea('Saisie libre…')))

      ->add('quantite', NumberType::class, array_merge([
        'required' => false,
        'label' => 'Quantité',
        'scale' => 3,
      ], $control('0', [
        'inputmode' => 'decimal',
        'autocomplete' => 'off',
      ])))

      ->add('prixUnitaireEur', NumberType::class, array_merge([
        'required' => false,
        'label' => 'Prix unitaire',
        'scale' => 4,
      ], $money('0,0000')))

      ->add('tauxTvaPercent', NumberType::class, array_merge([
        'required' => false,
        'label' => 'TVA (%)',
        'scale' => 2,
      ], $control('20,00', [
        'inputmode' => 'decimal',
        'autocomplete' => 'off',
      ])))

      ->add('montantRemiseEur', NumberType::class, array_merge([
        'required' => false,
        'label' => 'Remise',
        'scale' => 2,
      ], $money('0,00')))

      ->add('montantHtEur', NumberType::class, array_merge([
        'required' => false,
        'label' => 'Montant HT',
        'scale' => 2,
      ], $money('0,00')))

      ->add('montantTvaEur', NumberType::class, array_merge([
        'required' => false,
        'label' => 'Montant TVA',
        'scale' => 2,
      ], $money('0,00')))

      ->add('montantTtcEur', NumberType::class, array_merge([
        'required' => false,
        'label' => 'Montant TTC',
        'scale' => 2,
      ], $money('0,00')))

      ->add('engin', EntityType::class, [
        'class' => Engin::class,
        'required' => false,
        'label' => 'Engin lié',
        'placeholder' => '- Aucun -',
        'choice_label' => static fn(Engin $engin): string => trim(sprintf(
          '%s%s%s',
          $engin->getNom() ?? ('Engin #' . $engin->getId()),
          $engin->getType()?->label() ? ' (' . $engin->getType()->label() . ')' : '',
          $engin->getImmatriculation() ? ' - ' . $engin->getImmatriculation() : ''
        )),
        'query_builder' => static function (EntityRepository $repository) use ($entite) {
          $qb = $repository->createQueryBuilder('e')
            ->orderBy('e.nom', 'ASC');

          if ($entite instanceof Entite) {
            $qb->andWhere('e.entite = :entite')
              ->setParameter('entite', $entite);
          } else {
            $qb->andWhere('1 = 0');
          }

          return $qb;
        },
        'attr' => [
          'class' => 'form-select js-tomselect',
          'data-placeholder' => '- Aucun -',
        ],
      ])

      ->add('utilisateur', EntityType::class, [
        'class' => Utilisateur::class,
        'required' => false,
        'label' => 'Employé lié',
        'placeholder' => '- Aucun -',
        'choice_label' => static fn(Utilisateur $utilisateur): string => trim(
          ($utilisateur->getPrenom() ?? '') . ' ' . ($utilisateur->getNom() ?? '')
        ),
        'query_builder' => static function (EntityRepository $repository) use ($entite) {
          $qb = $repository->createQueryBuilder('u')
            ->innerJoin('u.utilisateurEntites', 'ue')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

          if ($entite instanceof Entite) {
            $qb->andWhere('ue.entite = :entite')
              ->setParameter('entite', $entite);
          } else {
            $qb->andWhere('1 = 0');
          }

          return $qb;
        },
        'attr' => [
          'class' => 'form-select js-tomselect',
          'data-placeholder' => '- Aucun -',
        ],
      ])

      ->add('produit', EntityType::class, [
        'class' => Produit::class,
        'required' => false,
        'label' => 'Produit lié',
        'placeholder' => '- Aucun -',
        'choice_label' => static fn(Produit $produit): string => sprintf(
          '%s - %s',
          $produit->getCategorieProduit()->label(),
          $produit->getSousCategorieProduit()->label()
        ),
        'query_builder' => static function (EntityRepository $repository) use ($entite) {
          $qb = $repository->createQueryBuilder('p')
            ->orderBy('p.categorieProduit', 'ASC')
            ->addOrderBy('p.sousCategorieProduit', 'ASC')
            ->addOrderBy('p.id', 'ASC');

          if ($entite instanceof Entite) {
            $qb->andWhere('p.entite = :entite')
              ->setParameter('entite', $entite);
          } else {
            $qb->andWhere('1 = 0');
          }

          return $qb;
        },
        'attr' => [
          'class' => 'form-select js-tomselect',
          'data-placeholder' => '- Aucun -',
        ],
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => Note::class,
      'entite' => null,
    ]);

    $resolver->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
