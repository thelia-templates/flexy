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

namespace FlexyBundle\Components\Organisms\QuantityAdjustments;

use FlexyBundle\EventListener\QuantityAdjustedFlashListener;
use FlexyBundle\Service\PrefetchRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Tells the shopper which lines of the cart the core brought back to the rules of their product
 * (QuantityAdjustedFlashListener), once: reading them empties the flash bag.
 */
#[AsTwigComponent]
final class Base
{
    /**
     * The lines to tell, given by the caller (a story); null reads them from the flash bag.
     *
     * @var list<array{product: string, message: string}>|null
     */
    public ?array $adjustments = null;

    /** @var list<array{product: string, message: string}>|null */
    private ?array $read = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @return list<array{product: string, message: string}>
     */
    public function getAdjustmentsToTell(): array
    {
        return $this->read ??= $this->adjustments ?? $this->fromTheFlashBag();
    }

    /**
     * @return list<array{product: string, message: string}>
     */
    private function fromTheFlashBag(): array
    {
        $request = $this->requestStack->getMainRequest();

        // A page fetched ahead of a click is seen by nobody: reading the flash bag here would spend
        // the message the page that is really opened has to show.
        if (null === $request || PrefetchRequest::is($request) || !$request->hasSession()) {
            return [];
        }

        $session = $request->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return [];
        }

        $adjustments = [];

        foreach ($session->getFlashBag()->get(QuantityAdjustedFlashListener::FLASH_KEY) as $adjustment) {
            if (\is_array($adjustment) && \is_string($adjustment['message'] ?? null)) {
                $adjustments[] = ['product' => (string) ($adjustment['product'] ?? ''), 'message' => $adjustment['message']];
            }
        }

        return $adjustments;
    }
}
