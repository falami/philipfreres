<?php

namespace App\Form\Administrateur;

use App\Entity\ChantierDechet;
use App\Entity\DechetType;
use App\Entity\Entite;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityRepository;

class ChantierDechetType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    /** @var Entite $entite */
    $entite = $o['entite'];

    $b
      ->add('typeDechet', EntityType::class, [
        'class' => DechetType::class,
        'label' => 'Type de déchet',
        'choice_label' => fn(DechetType $d) => $d->getNom() . ' (' . $d->getUnite() . ')',
        'query_builder' => fn(EntityRepository $er) => $er->createQueryBuilder('d')
          ->andWhere('d.entite = :entite')
          ->setParameter('entite', $entite)
          ->orderBy('d.nom', 'ASC'),
        'placeholder' => 'Sélectionner',
        'attr' => ['class' => 'form-select'],
      ])
      ->add('poidsTotal', NumberType::class, [
        'label' => 'Poids total',
        'required' => false,
        'scale' => 2,
        'html5' => true,
        'attr' => ['class' => 'form-control', 'step' => '0.01', 'min' => '0'],
      ])
      ->add('nouveauType', TextType::class, [
        'mapped' => false,
        'required' => false,
        'label' => 'Ou créer un nouveau type à la volée',
        'attr' => ['class' => 'form-control', 'placeholder' => 'Ex : Gravats, ferraille, plastique...'],
      ]);

    $b->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($entite) {
      $form = $event->getForm();
      $data = $event->getData();
      if (!$data instanceof ChantierDechet) {
        return;
      }

      $nouveauType = trim((string) $form->get('nouveauType')->getData());
      if ($nouveauType === '') {
        return;
      }

      $type = new DechetType();
      $type->setNom($nouveauType);
      $type->setUnite('kg');
      $type->setEntite($entite);

      $data->setTypeDechet($type);
    });
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ChantierDechet::class,
      'entite' => null,
    ]);

    $r->setAllowedTypes('entite', ['null', Entite::class]);
  }
}
