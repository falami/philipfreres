<?php

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

final class EnginExternalIdImportType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder->add('file', FileType::class, [
      'label' => 'Fichier Excel / CSV',
      'mapped' => false,
      'required' => true,
      'help' => 'Formats acceptés : .xlsx, .xls, .csv',
      'constraints' => [
        new File([
          'maxSize' => '20M',
          'mimeTypes' => [
            'text/plain',
            'text/csv',
            'application/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          ],
          'mimeTypesMessage' => 'Merci d’envoyer un fichier .xlsx, .xls ou .csv',
        ]),
      ],
      'attr' => [
        'accept' => '.xlsx,.xls,.csv',
        'class' => 'form-control',
      ],
    ]);
  }
}
