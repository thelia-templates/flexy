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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** An accordion trigger is a heading whose level the page picks, so that the outline of the page has no gap. */
final class AccordionTriggerLevelTest extends KernelTestCase
{
    public function testItIsAThirdLevelHeadingByDefault(): void
    {
        self::assertSame('h3', $this->headingOf(''));
    }

    public function testThePageChoosesTheLevel(): void
    {
        self::assertSame('h2', $this->headingOf(' level="2"'));
    }

    #[DataProvider('levelsOutsideTheOutline')]
    public function testALevelOutsideTheOutlineFallsBackToTheDefault(string $level): void
    {
        self::assertSame('h3', $this->headingOf(' level="'.$level.'"'));
    }

    /** @return iterable<string, array{string}> */
    public static function levelsOutsideTheOutline(): iterable
    {
        yield 'zero' => ['0'];
        yield 'seven' => ['7'];
    }

    private function headingOf(string $attributes): string
    {
        $template = self::getContainer()->get('twig')->createTemplate(
            '<twig:Molecules:Accordion:Base id="a"><twig:Molecules:Accordion:Item value="v">'
            .'<twig:Molecules:Accordion:Trigger'.$attributes.'>Title</twig:Molecules:Accordion:Trigger>'
            .'</twig:Molecules:Accordion:Item></twig:Molecules:Accordion:Base>'
        );

        self::assertSame(1, preg_match('/<(h[1-6])\b[^>]*>\s*<button/', $template->render(), $match));

        return $match[1];
    }
}
