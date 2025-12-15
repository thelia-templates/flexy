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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Contracts\Translation\TranslatorInterface;

class PickupAddressType extends AbstractType
{
    public function __construct(
        #[Autowire(service: 'translator')]
        public TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
          ->add('address', SearchType::class, [
              'label' => $this->translator->trans('Find a delivery address'),
              'label_attr' => [
                  'for' => 'address',
              ],
              'help' => $this->translator->trans('e.g. City, Postcode'),
          ]);
    }
}
