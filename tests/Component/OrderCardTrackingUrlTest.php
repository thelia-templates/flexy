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

use FlexyBundle\Components\Organisms\OrderCard\Base as OrderCard;
use FlexyBundle\Service\OrderProductResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Thelia\Api\Service\DataAccess\DataAccessService;

/**
 * The order list offers the carrier page on a shipped order only: a tracking number
 * may be typed before the parcel has left.
 */
final class OrderCardTrackingUrlTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $order
     */
    #[DataProvider('orders')]
    public function testTheTrackingLinkIsOfferedOnAShippedOrderOnly(array $order, ?string $expected): void
    {
        self::assertSame($expected, $this->cardFor($order)->getTrackingUrl());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function orders(): iterable
    {
        $url = 'https://carrier.example/6A12';

        yield 'shipped' => [['orderStatus' => ['code' => 'sent'], 'deliveryTrackingUrl' => $url], $url];
        yield 'custom status equivalent to shipped' => [['orderStatus' => ['code' => 'handed_to_carrier', 'equivalentCode' => 'sent'], 'deliveryTrackingUrl' => $url], $url];
        yield 'paid, number already typed' => [['orderStatus' => ['code' => 'paid'], 'deliveryTrackingUrl' => $url], null];
        yield 'in preparation' => [['orderStatus' => ['code' => 'processing', 'equivalentCode' => null], 'deliveryTrackingUrl' => $url], null];
        yield 'shipped without link' => [['orderStatus' => ['code' => 'sent']], null];
        yield 'shipped with an empty link' => [['orderStatus' => ['code' => 'sent'], 'deliveryTrackingUrl' => ''], null];
        yield 'no status' => [['deliveryTrackingUrl' => $url], null];
    }

    /**
     * @param array<string, mixed> $order
     */
    private function cardFor(array $order): OrderCard
    {
        $dataAccess = $this->createMock(DataAccessService::class);
        $dataAccess->method('resources')->willReturn($order);

        $card = new OrderCard($dataAccess, self::getContainer()->get(OrderProductResolver::class));
        $card->mount(1);

        return $card;
    }
}
