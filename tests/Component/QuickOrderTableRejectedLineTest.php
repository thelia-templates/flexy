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

namespace FlexyBundle\Tests\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Catalog\DTO\ReferenceQuantityLines;

/** A pasted line whose quantity is out of range is refused with the bound the core keeps, written in the reason. */
final class QuickOrderTableRejectedLineTest extends KernelTestCase
{
    public function testTheReasonOfARefusedQuantityStatesTheMaximum(): void
    {
        $request = Request::create('/account/quick-order');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        $html = self::getContainer()->get('twig')->createTemplate(
            '<twig:Organisms:QuickOrderTable:Base :rejectedLines="[{line: 1, content: \'ref;0\', reason: \'quantity\'}]" />'
        )->render();

        self::assertStringContainsString(
            'the quantity is not a whole number from 1 to '.number_format(ReferenceQuantityLines::MAX_QUANTITY),
            html_entity_decode($html),
        );
    }
}
