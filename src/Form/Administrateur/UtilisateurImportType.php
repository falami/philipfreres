<?php

declare(strict_types=1);

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UtilisateurImportType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder->add('file', FileType::class, [
      'label' => 'Fichier Excel',
      'mapped' => false,
      'required' => true,
      'attr' => [
        'accept' => '.xlsx,.xls,.csv',
        'class' => 'form-control',
      ],
      'constraints' => [
        new Assert\NotNull(message: 'Veuillez sélectionner un fichier.'),
        new Assert\File([
          'maxSize' => '20M',
          'mimeTypes' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel',                                         // xls
            'text/csv',
            'text/plain',
            'application/csv',
          ],
          'mimeTypesMessage' => 'Format invalide. Utilisez .xlsx, .xls ou .csv',
        ]),

      ],
    ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([]);
  }
}
