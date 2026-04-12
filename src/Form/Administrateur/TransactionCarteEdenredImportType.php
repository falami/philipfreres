<?php

declare(strict_types=1);

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class TransactionCarteEdenredImportType extends AbstractType
{
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $b->add('file', FileType::class, [
      'mapped' => false,
      'required' => true,
      'label' => 'Fichier EDENRED (.xlsx / .xls / .csv)',
      'constraints' => [
        new File(
          maxSize: '25M',
          mimeTypes: [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
          ],
          mimeTypesMessage: 'Fichier invalide.',
        ),
      ],
      'attr' => [
        'accept' => '.xlsx,.xls,.csv',
        'class' => 'form-control',
      ],
    ]);
  }

  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([]);
  }
}
