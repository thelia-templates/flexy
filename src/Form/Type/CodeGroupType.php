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

use Symfony\Component\Form\Extension\Core\Type\FormType;

class CodeGroupType extends FormType
{
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function getBlockPrefix(): string
    {
        return 'code_group';
    }
}
