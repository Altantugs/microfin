<?php
declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'И-мэйл',
                'constraints' => [
                    new Assert\NotBlank(message: 'И-мэйл заавал.'),
                    new Assert\Email(message: 'Зөв и-мэйл оруулна уу.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Нууц үг',
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Нууц үг заавал.'),
                    new Assert\Length(min: 8, minMessage: 'Хамгийн багадаа 8 тэмдэгт.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}

