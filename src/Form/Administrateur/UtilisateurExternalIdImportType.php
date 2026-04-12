<?php

declare(strict_types=1);

namespace App\Form\Administrateur;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class UtilisateurExternalIdImportType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder->add('file', FileType::class, [
      'label' => 'Fichier (CSV)',
      'mapped' => false,
      'required' => true,
      'attr' => [
        'accept' => '.xlsx,.xls,.csv',
        'class' => 'form-control',
      ],
      'constraints' => [
        new Assert\NotNull(message: 'Veuillez sélectionner un fichier.'),
        new Assert\File(
          maxSize: '10M',
          mimeTypes: [
            'text/plain',
            'text/csv',
            'application/csv',
            'application/vnd.ms-excel',
          ],
          mimeTypesMessage: 'Format invalide. Merci d’envoyer un CSV.',
        ),
      ],
    ]);
  }
}
