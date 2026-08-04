<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Thelia\Core\Translation\Translator;

class PickupAddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('address', SearchType::class, [
                'label' => Translator::getInstance()->trans('Find a delivery address'),
                'label_attr' => [
                    'for' => 'address',
                ],
                'help' => Translator::getInstance()->trans('e.g. City, Postcode'),
            ]);
    }
}
