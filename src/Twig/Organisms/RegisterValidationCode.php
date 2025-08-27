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

namespace FlexyBundle\Twig\Organisms;

use FlexyBundle\Form\Type\CodeGroupType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(template: '@components/Organisms/RegisterValidationCode/RegisterValidationCode.html.twig')]
class RegisterValidationCode extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    public const CODE_CHARSETS_COUNT = 6;

    public ?int $nbChars = 0;

    public function __construct()
    {
    }

    protected function instantiateForm(): FormInterface
    {
        $formBuilder = $this->createFormBuilder(null);

        $formBuilder->add('code', CodeGroupType::class,['attr' => ['class' => 'mt-4']]);;

        for ($i = 1; $i <= self::CODE_CHARSETS_COUNT; ++$i) {
            $formBuilder->get('code')->add($formBuilder->create('code'.$i, TextType::class));
        }

        return $formBuilder->getForm();
    }

    #[LiveAction]
    public function save(): void
    {
        dd('form processing');
    }
}
