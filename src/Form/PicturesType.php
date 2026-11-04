<?php

namespace App\Form;

use App\Entity\Album;
use App\Entity\Pictures;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class PicturesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'empty_data' => '',
                'attr' => ['maxlength' => 25],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 25),
                ],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
                'empty_data' => '',
                'attr' => ['maxlength' => 255],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('metadata', TextType::class, [
                'label' => 'Metadata',
                'empty_data' => '',
                'attr' => ['maxlength' => 255],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('uploadDate', DateType::class, [
                'label' => 'Upload date',
                'widget' => 'single_text',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('album', EntityType::class, [
                'class' => Album::class,
                'choice_label' => 'name',
                'placeholder' => 'Choose an album',
                'required' => false,
            ])
            ->add('imageFile', FileType::class, [
                'label' => 'Photo',
                'mapped' => false,
                'required' => $options['require_file'],
                'constraints' => [
                    ...($options['require_file'] ? [new Assert\NotNull()] : []),
                    new Assert\Image(
                        mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Pictures::class,
            'require_file' => true,
        ]);
    }
}
