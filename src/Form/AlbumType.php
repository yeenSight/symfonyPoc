<?php

namespace App\Form;

use App\Entity\Album;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AlbumType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'empty_data' => '',
                'attr' => ['maxlength' => 25],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 25)],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
                'empty_data' => '',
                'attr' => ['maxlength' => 255],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Album::class]);
    }
}
