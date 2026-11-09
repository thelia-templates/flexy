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

namespace FlexyBundle\Components\Molecules\PriceBlock;

use FlexyBundle\DTO\DisplayedPrice;
use FlexyBundle\DTO\DisplayedPriceTier;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The one place a product sheet, a card of a listing and a line of the cart say what a buyer is
 * told about a price: the price itself, an invitation to sign in to see it, an invitation to ask
 * for it, or that the item is not on sale to this buyer: it keeps its public price, with the mention that
 * it cannot be bought here and no table of tiers.
 *
 * It decides nothing about prices. The core publishes, for every sale element, who is shown the
 * price (`priceDisplayMode`), whether the caller may buy it (`purchasable`) and the price itself,
 * absent when the caller is not shown it: the component only reads those fields and picks the
 * wording. The request for a price carries the references of the item and never an amount.
 */
#[AsTwigComponent]
class Base
{
    public const string PRICE = 'price';

    public const string HIDDEN = 'hidden';

    public const string ON_REQUEST = 'on_request';

    public const string UNAVAILABLE = 'unavailable';

    public const string NONE = 'none';

    private const string CONTACT_ROUTE = 'contact_submit';

    public const string LOGGED_IN_MODE = 'logged_in';

    public const string ON_REQUEST_MODE = 'on_request';

    /** `visible`, `logged_in` or `on_request`, as the front API publishes it: anything else reads as visible. */
    public string $mode = 'visible';

    /** What the buyer pays, as they read it; null when the API did not show it to them. */
    public ?DisplayedPrice $price = null;

    /** The catalog price struck through beside the price paid, when the sale is to be shown. */
    public ?DisplayedPrice $originalPrice = null;

    /** Whether the caller may put the item in a cart, as the API publishes it. */
    public bool $purchasable = true;

    /**
     * The tiers of the price list that prices the item for the buyer, in their display.
     *
     * @var list<DisplayedPriceTier>
     */
    public array $tiers = [];

    /** `sheet` (product page) or `card` (listing): each keeps the class names its host styles. */
    public string $variant = 'sheet';

    /** Class of the struck price of the `sheet` variant, owned by the host's stylesheet. */
    public string $originalClass = '';

    /** The reference of the sale element, handed to the contact page with the request for a price. */
    public ?string $reference = null;

    /** The reference of the product, handed to the contact page with the request for a price. */
    public ?string $productReference = null;

    /**
     * Whether the invitation to sign in and the request for a price come with their button. A host
     * that is itself a link (a suggestion row) keeps the wording and drops the button: a link does
     * not nest in a link.
     */
    public bool $actions = true;

    /** The page to come back to after signing in. The page being served when not given. */
    public ?string $returnUrl = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getState(): string
    {
        if (self::ON_REQUEST_MODE === $this->mode) {
            return self::ON_REQUEST;
        }

        if (null === $this->price) {
            // The core refuses the purchase to the visitor it hides the price from, and to nobody else: a
            // signed-in customer whose sale element has no price in the currency browsed is not asked to sign in.
            return self::LOGGED_IN_MODE === $this->mode && !$this->purchasable ? self::HIDDEN : self::NONE;
        }

        return $this->purchasable ? self::PRICE : self::UNAVAILABLE;
    }

    /**
     * The table of tiers is worth printing when the price changes with the quantity, which a
     * single tier from one unit does not.
     */
    public function getShowsTiers(): bool
    {
        if ([] === $this->tiers) {
            return false;
        }

        return \count($this->tiers) > 1 || $this->tiers[0]->minQuantity > 1;
    }

    public function getLoginUrl(): string
    {
        return $this->urlGenerator->generate('customer_login', ['redirect' => $this->returnPath()]);
    }

    /**
     * The contact page, told which item the buyer asks about: the references only, no price.
     */
    public function getRequestUrl(): string
    {
        $parameters = array_filter(
            ['product_ref' => $this->productReference, 'pse_ref' => $this->reference],
            static fn (?string $value): bool => null !== $value && '' !== $value,
        );

        try {
            // The route of the contact form carries the path of the page the theme serves: it keeps the
            // folder a shop installed in a sub-directory lives in.
            $path = $this->urlGenerator->generate(self::CONTACT_ROUTE);
        } catch (RouteNotFoundException) {
            $path = '/contact';
        }

        return $path.([] === $parameters ? '' : '?'.http_build_query($parameters));
    }

    /**
     * Where signing in leads back: the page being served, or the page that asked for this one when
     * the block is redrawn by a live component (its own address is an endpoint, not a page). Only
     * the path and the query: the core checks the destination against the host again before use.
     */
    private function returnPath(): string
    {
        if (null !== $this->returnUrl && '' !== $this->returnUrl) {
            return $this->returnUrl;
        }

        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return '/';
        }

        if (!str_starts_with($request->getPathInfo(), '/_components/')) {
            return $request->getRequestUri();
        }

        $referer = $request->headers->get('referer');
        $parts = \is_string($referer) ? parse_url($referer) : false;

        if (false === $parts || ($parts['host'] ?? $request->getHost()) !== $request->getHost()) {
            return '/';
        }

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
