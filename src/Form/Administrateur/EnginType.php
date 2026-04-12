<?php

namespace App\Form\Administrateur;

use App\Entity\Engin;
use App\Enum\EnginType as EnginTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{
    FileType,
    IntegerType,
    TextType,
    EnumType,
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\{
    Image,
    NotBlank,
    Length,
    Positive,
};

class EnginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $b, array $o): void
    {
        $b
            ->add('nom', TextType::class, [
                'label' => '*Nom de l\'engin',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Pelle Doosan DX140',
                    'maxlength' => 140,
                ],
                'constraints' => [
                    new NotBlank(message: 'Le nom est requis.'),
                    new Length(max: 140, maxMessage: '140 caractères maximum.'),
                ],
            ])
            ->add('type', EnumType::class, [
                'class' => EnginTypeEnum::class,
                'label' => '*Type',
                'placeholder' => false,
                'required' => true,
                'choice_label' => static function (EnginTypeEnum $e): string {
                    return match ($e) {
                        EnginTypeEnum::ABATTEUSE        => 'Abatteuse',
                        EnginTypeEnum::CHARGEUSE        => 'Chargeuse',
                        EnginTypeEnum::PELLE            => 'Pelle',
                        EnginTypeEnum::PORTEUR          => 'Porteur',
                        EnginTypeEnum::TRACTEUR         => 'Tracteur',
                        EnginTypeEnum::NACELLE          => 'Nacelle',
                        EnginTypeEnum::PORTE_OUTIL      => 'Porte-outil',
                        EnginTypeEnum::MATERIEL_FLUVIAL => 'Matériel fluvial',
                        EnginTypeEnum::BROYEUR          => 'Broyeur',
                        EnginTypeEnum::PETIT_ENGIN      => 'Petit engin',
                        EnginTypeEnum::CAMION           => 'Camion',
                        EnginTypeEnum::ACCESSOIRE       => 'Accessoire',
                        default                         => $e->name,
                    };
                },
                'choice_translation_domain' => false,
                'attr' => ['class' => 'form-select'],
            ])


            // ---------- NOUVEAUX CHAMPS : Infos générales ----------
            ->add('annee', IntegerType::class, [
                'label' => 'Année',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1900, 'max' => 2100, 'step' => 1, 'placeholder' => '2025'],
                'empty_data' => '',
                'constraints' => [new Positive(message: 'Doit être > 0.')],
            ])

            ->add('photoCouverture', FileType::class, [
                'mapped' => false,
                'required' => false,
                'label'  => 'Photo de couverture (affichée en plein écran)',
                'constraints' => [new Image(maxSize: '16M', mimeTypesMessage: 'Image invalide')],
                'attr' => ['accept' => 'image/*']
            ])

            ->add('immatriculation', TextType::class, [
                'required' => false,
                'label' => 'Plaque (immatriculation)',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'AA-123-AA',
                    'maxlength' => 12,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $r): void
    {
        $r->setDefaults([
            'data_class' => Engin::class,
        ]);
    }
}
