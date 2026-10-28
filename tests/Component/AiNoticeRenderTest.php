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

use FlexyBundle\Tests\Support\AiNoticeProbeUsages;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Service\AiUsageRegistry;
use Thelia\Model\AiUsageSetting;
use Thelia\Model\AiUsageSettingQuery;
use Twig\Error\RuntimeError;

/**
 * The notice "you are talking with an AI" of a module's chat: the theme shows what the core says
 * is due, in the language asked, and shows nothing else.
 */
final class AiNoticeRenderTest extends KernelTestCase
{
    private const string VISITOR_CHAT = 'flexyprobe.visitor_chat';

    protected function setUp(): void
    {
        parent::setUp();

        require_once \dirname(__DIR__).'/Support/AiNoticeProbeUsages.php';

        // The usages a module declares are collected when the container is compiled, and the theme has
        // no module to compile: the real registry, already built and shared with Twig, is given the
        // attributes of the probe class the way it reads them from a module's service.
        $usages = [];
        foreach ((new \ReflectionClass(AiNoticeProbeUsages::class))->getAttributes(AsAiUsage::class) as $attribute) {
            $usage = $attribute->newInstance();
            $usages[$usage->id] = $usage;
        }
        $registry = self::getContainer()->get(AiUsageRegistryInterface::class);
        (new \ReflectionProperty(AiUsageRegistry::class, 'usages'))->setValue($registry, $usages);

        $this->forgetSettings();
    }

    protected function tearDown(): void
    {
        $this->forgetSettings();

        parent::tearDown();
    }

    /** The rendered markup of the notice, as a module's template includes it. */
    private function notice(string $usage, ?string $locale = 'fr_FR', array $attributes = []): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return trim($renderer->createAndRender('Molecules:AiNotice:Base', ['usage' => $usage, 'locale' => $locale] + $attributes));
    }

    private function setting(string $usageId, bool $notice, string $budget = '5'): void
    {
        (new AiUsageSetting())->setUsageId($usageId)->setMonthlyBudget($budget)->setTransparencyNotice($notice)->save();
    }

    private function forgetSettings(): void
    {
        AiUsageSettingQuery::create()->filterByUsageId('flexyprobe.%', Criteria::LIKE)->delete();
    }

    public function testAnInteractivePublicUsageShowsTheNoticeInFrench(): void
    {
        $this->setting(self::VISITOR_CHAT, true);

        $html = $this->notice(self::VISITOR_CHAT, 'fr_FR');

        self::assertStringContainsString('Vous échangez avec un assistant IA. Ses réponses peuvent comporter des erreurs.', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    public function testTheNoticeFollowsTheLanguageAsked(): void
    {
        $this->setting(self::VISITOR_CHAT, true);

        self::assertStringContainsString('You are talking with an AI assistant. Its answers can contain mistakes.', $this->notice(self::VISITOR_CHAT, 'en_US'));
    }

    public function testAnInteractiveCustomerUsageShowsTheNotice(): void
    {
        $this->setting('flexyprobe.customer_chat', true);

        self::assertStringContainsString('Vous échangez avec un assistant IA', $this->notice('flexyprobe.customer_chat'));
    }

    public function testAUsageThatIsNotInteractiveShowsNothing(): void
    {
        $this->setting('flexyprobe.writing', true);

        self::assertSame('', $this->notice('flexyprobe.writing'));
    }

    public function testTheNoticeTurnedOffByTheMerchantShowsNothing(): void
    {
        $this->setting(self::VISITOR_CHAT, false);

        self::assertSame('', $this->notice(self::VISITOR_CHAT));
    }

    public function testAnInactiveUsageShowsNothing(): void
    {
        // A public usage stays inactive until it is given a budget.
        $this->setting(self::VISITOR_CHAT, true, '0');

        self::assertSame('', $this->notice(self::VISITOR_CHAT));
    }

    public function testTheClassOfTheHostLandsOnTheNotice(): void
    {
        $this->setting(self::VISITOR_CHAT, true);

        $html = $this->notice(self::VISITOR_CHAT, 'fr_FR', ['class' => 'mt-4', 'id' => 'chat-ai-notice']);

        self::assertMatchesRegularExpression('/<div[^>]*class="[^"]*\bmt-4\b[^"]*"[^>]*role="status"|<div[^>]*role="status"[^>]*class="[^"]*\bmt-4\b/', $html);
        self::assertStringContainsString('id="chat-ai-notice"', $html);
    }

    public function testAnUnknownUsageIsNotASilentMissingNotice(): void
    {
        $this->expectException(RuntimeError::class);

        $this->notice('flexyprobe.typo');
    }
}
