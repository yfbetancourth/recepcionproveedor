<?php
declare(strict_types=1);

namespace PrestaShop\Module\RecepcionProveedor\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

/** Cabecera de la recepcion: proveedor, numero y fecha del recibo, notas. */
class ReceptionHeaderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('supplier_search', TextType::class, [
                'label' => 'Proveedor',
                'required' => false,
                'mapped' => false,
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('id_supplier', HiddenType::class, [
                'required' => false,
            ])
            ->add('receipt_number', TextType::class, [
                'label' => 'Numero de recibo',
                'required' => false,
                'constraints' => [new Length(max: 64)],
            ])
            ->add('receipt_date', DateType::class, [
                'label' => 'Fecha del recibo',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [
                    new LessThanOrEqual(value: 'today', message: 'La fecha del recibo no puede ser futura.'),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'constraints' => [new Length(max: 2000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'Modules.Recepcionproveedor.Admin',
        ]);
    }
}