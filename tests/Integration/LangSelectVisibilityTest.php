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

namespace FlexyBundle\Tests\Integration;

use FlexyBundle\Components\Organisms\LangSelect\Base as LangSelect;
use Thelia\Model\Lang;
use Thelia\Test\IntegrationTestCase;

/**
 * The language selector offers the languages the shop shows on the front: an active language
 * the merchant left out of "Visible on front" is not offered. The languages added here are
 * removed by the test transaction.
 */
final class LangSelectVisibilityTest extends IntegrationTestCase
{
    public function testAnActiveLanguageHiddenFromTheFrontIsNotOffered(): void
    {
        $this->addLang('xh', 'xh_XX', visible: 0, position: 98);
        $this->addLang('xv', 'xv_XX', visible: 1, position: 99);

        $locales = array_column($this->getService(LangSelect::class)->getLangs(), 'locale');

        self::assertContains('xv_XX', $locales);
        self::assertNotContains('xh_XX', $locales);
    }

    private function addLang(string $code, string $locale, int $visible, int $position): void
    {
        (new Lang())
            ->setCode($code)
            ->setLocale($locale)
            ->setTitle($locale)
            ->setActive(1)
            ->setVisible($visible)
            ->setPosition($position)
            ->save($this->getPropelConnection());
    }
}
