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

namespace FlexyBundle\Tests\Unit\Twig;

use FlexyBundle\Twig\MediaImageExtension;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use TheliaLibrary\Service\ImageService;

/**
 * The address of a visual fetched on demand, such as the zoom of the product gallery. It must
 * not go through getImages(), which writes the image and its modern formats at page render.
 */
final class MediaImageUrlTest extends TestCase
{
    private const PARAMS = ['img_id' => 7, 'source_type' => 'product', 'filters' => 'product_zoom'];

    public function testTheAddressIsLeftForItsFirstRequestToGenerate(): void
    {
        $images = $this->createMock(ImageService::class);
        $images->method('getImageDataWithType')->with(self::PARAMS)->willReturn([['path' => '/product/sofa.jpg']]);
        $images->expects(self::never())->method('getImages');

        $cache = $this->createMock(CacheManager::class);
        $cache->expects(self::once())
            ->method('getBrowserPath')
            ->with('/product/sofa.jpg', 'product_zoom')
            ->willReturn('/media/cache/resolve/product_zoom/product/sofa.jpg');

        self::assertSame(
            '/media/cache/resolve/product_zoom/product/sofa.jpg',
            (new MediaImageExtension($images, $cache))->mediaImageUrl(self::PARAMS),
        );
    }

    /** No visual, or a row without a file: no address, rather than one that answers 404. */
    public function testAMediaWithoutAFileHasNoAddress(): void
    {
        $cache = $this->createMock(CacheManager::class);
        $cache->expects(self::never())->method('getBrowserPath');

        foreach ([[], [['path' => '']]] as $rows) {
            $images = self::createStub(ImageService::class);
            $images->method('getImageDataWithType')->willReturn($rows);

            self::assertNull((new MediaImageExtension($images, $cache))->mediaImageUrl(self::PARAMS));
        }
    }
}
