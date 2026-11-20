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

/** What the table says of a quantity: the bound the core keeps, the decimals a pasted line takes, the reason the rules of a sale element give. */
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
            'the quantity is not a number above zero, up to '.number_format(ReferenceQuantityLines::MAX_QUANTITY).', with three decimals at most',
            html_entity_decode($html),
        );
    }

    public function testADecimalCommaOnALineSeparatedByCommasSaysToWriteAPoint(): void
    {
        $html = $this->render('<twig:Organisms:QuickOrderTable:Base :rejectedLines="[{line: 2, content: \'flour,1,25\', reason: \'decimal_comma\'}]" />');

        self::assertStringContainsString('with commas between the columns, write the decimals with a point: 1.25', $html);
    }

    public function testTheRulesOfASaleElementSayWhyItsQuantityIsRefused(): void
    {
        $html = $this->render('<twig:Organisms:QuickOrderTable:Base :rows="[{key: \'k1\', reference: \'flour\', quantity: \'1,255\'}]" :lines="[{reference: \'flour\', quantity: 1.255, status: \'quantity_refused\', title: \'Flour\', refusalReason: \'The quantity cannot carry more than 2 decimal places.\', availableQuantity: null, candidates: []}]" />');

        self::assertStringContainsString('The quantity cannot carry more than 2 decimal places.', $html);
    }

    public function testADecimalTypedWithACommaIsTheQuantityTheCheckAnswered(): void
    {
        $html = $this->render('<twig:Organisms:QuickOrderTable:Base :rows="[{key: \'k1\', reference: \'flour\', quantity: \'1,25\'}]" :lines="[{reference: \'flour\', quantity: 1.25, status: \'resolved\', title: \'Flour\', candidates: []}]" />');

        self::assertStringContainsString('Add 1 line(s) to cart', $html);
        self::assertStringNotContainsString('Enter a quantity above zero', $html);
    }

    public function testAQuantityWithMoreThanThreeDecimalsIsLeftOutOfTheCheck(): void
    {
        $html = $this->render('<twig:Organisms:QuickOrderTable:Base :rows="[{key: \'k1\', reference: \'saffron\', quantity: \'0,0005\'}]" />');

        self::assertStringContainsString('Enter a quantity above zero, up to '.number_format(ReferenceQuantityLines::MAX_QUANTITY).', with three decimals at most.', $html);
    }

    public function testTheStockOfARefusedLineIsWrittenAsItIsNotRoundedDown(): void
    {
        $request = Request::create('/account/quick-order');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        $html = self::getContainer()->get('twig')->createTemplate(
            '<twig:Organisms:QuickOrderTable:Base :rows="[{key: \'k1\', reference: \'ecrou-m6\', quantity: 50}]" :lines="[{reference: \'ecrou-m6\', quantity: 50, status: \'quantity_refused\', title: \'Hexagon nut M6\', availableQuantity: 7.5, candidates: []}]" />'
        )->render();

        self::assertStringContainsString('7.5 in stock', html_entity_decode($html));
    }

    private function render(string $template): string
    {
        $request = Request::create('/account/quick-order');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');

        return html_entity_decode(self::getContainer()->get('twig')->createTemplate($template)->render());
    }
}
