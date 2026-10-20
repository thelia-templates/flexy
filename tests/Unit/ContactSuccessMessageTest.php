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

namespace FlexyBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** The confirmation of the contact form reads as a sentence, and every language that translates it keeps up with the key. */
final class ContactSuccessMessageTest extends TestCase
{
    private const string MESSAGE = 'Thanks for your message, we will contact you as soon as possible.';

    public function testTheTemplateAsksForTheCorrectSentence(): void
    {
        self::assertStringContainsString("'".self::MESSAGE."'|trans", (string) file_get_contents(\dirname(__DIR__, 2).'/contact-success.html.twig'));
    }

    public function testTheEnglishCatalogSaysTheSame(): void
    {
        $catalog = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.en_US.yaml');

        self::assertSame(self::MESSAGE, $catalog[self::MESSAGE] ?? null);
    }

    public function testNoCatalogKeepsTheOldKey(): void
    {
        foreach (glob(\dirname(__DIR__, 2).'/translations/messages.*.yaml') ?: [] as $file) {
            self::assertArrayNotHasKey('Thanks for your message, we will contact as soon as possible.', Yaml::parseFile($file), $file);
        }
    }
}
