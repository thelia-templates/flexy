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

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PillType extends ChoiceType
{
    public static function getExtendedTypes(): iterable
    {
        return [ChoiceType::class];
    }

    public function getBlockPrefix(): string
    {
        return 'pill';
    }
}
