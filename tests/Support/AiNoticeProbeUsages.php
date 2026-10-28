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

namespace FlexyBundle\Tests\Support;

use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiTransparency;
use Thelia\Domain\Ai\AsAiUsage;

/**
 * The usages a module declares, the way a real module does: one a visitor talks with, one a
 * customer talks with, one that only writes for the merchant.
 */
#[AsAiUsage(id: 'flexyprobe.visitor_chat', purpose: 'Chats with visitors.', audience: AiAudience::Public, tier: AiTier::Fast, transparency: AiTransparency::Interactive)]
#[AsAiUsage(id: 'flexyprobe.customer_chat', purpose: 'Chats with signed-in customers.', audience: AiAudience::Customer, tier: AiTier::Fast, transparency: AiTransparency::Interactive)]
#[AsAiUsage(id: 'flexyprobe.writing', purpose: 'Writes texts for the merchant.', audience: AiAudience::Admin, tier: AiTier::Fast, transparency: AiTransparency::Generative)]
final class AiNoticeProbeUsages
{
}
