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

namespace FlexyBundle\Components\Layouts\AccountCompanyBuyers;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Thelia\Core\Template\Helper\FormatService;
use Thelia\Domain\Company\Checkout\Service\SpendingAmount;
use Thelia\Model\Currency;

/**
 * The buyers of the company, one editable row each. The rows come from the account API, already
 * paginated and bounded to the company of the signed-in buyer; the roles offered are the ones
 * that buyer may give. A buyer whose role holds more than the signed-in one is shown, not editable.
 */
#[AsTwigComponent]
class Base
{
    /** @var list<array<string, mixed>> */
    public array $buyers = [];

    /** @var array<string, string> the title of each role the signed-in buyer may give, by code */
    public array $roles = [];

    /** @var array<string, string> the title of every role of the company, by code */
    public array $roleTitles = [];

    /** @var array<string, int> */
    public array $pagination = ['totalItems' => 0, 'itemsPerPage' => 10, 'currentPage' => 1];

    public ?int $currentCustomerId = null;

    public function __construct(private readonly FormatService $format)
    {
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getRoleChoices(): array
    {
        $choices = [];

        foreach ($this->roles as $code => $title) {
            $choices[] = ['value' => (string) $code, 'label' => $title];
        }

        return $choices;
    }

    public function isEditable(mixed $buyer): bool
    {
        return \array_key_exists((string) (((array) $buyer)['roleCode'] ?? ''), $this->roles);
    }

    /**
     * A limit as a field holds it: the amount without the zeros the column pads it with.
     */
    public function plain(mixed $amount): string
    {
        return null === $amount || '' === $amount ? '' : SpendingAmount::display((string) $amount);
    }

    /**
     * A limit as the shop writes a price, in the default currency the limits are kept in.
     */
    public function shown(mixed $amount): string
    {
        return $this->format->price((string) $amount, Currency::getDefaultCurrency()->getId());
    }

    public function currencySymbol(): string
    {
        return (string) Currency::getDefaultCurrency()->getSymbol();
    }
}
