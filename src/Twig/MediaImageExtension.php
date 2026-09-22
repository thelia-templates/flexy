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

namespace FlexyBundle\Twig;

use TheliaLibrary\Service\ImageService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Renders one `<img>` for a media resized by a Liip filter set, with exactly the attributes
 * it is given.
 *
 * `getImages()` from TheliaLibrary cannot be used where the alternative text matters. It
 * drops every attribute whose value is empty, so an image the merchant declared decorative
 * comes out with no `alt` at all — which makes a screen reader read its file name instead of
 * skipping it, the opposite of what decorative means. It also adds a `title` built from the
 * image title, which then announces the very text the merchant asked to silence.
 *
 * So the address is read through the same service `getImages()` reads it through, and the
 * tag is written here. One filter set, therefore one source and one `<img>`: a media served
 * through a `<picture>` at several breakpoints still belongs to `getImages()`.
 */
final class MediaImageExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageService $imageService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_image', $this->mediaImage(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array<string, mixed> $params     as taken by getImages(): img_id, source_type, filters, placeholder
     * @param array<string, mixed> $attributes written out as given; an empty string is an attribute, null is not
     */
    public function mediaImage(array $params, array $attributes = []): string
    {
        $images = $this->imageService->getImages($params);
        $source = $images[0]['sources'][0]['url'] ?? null;

        // No file and no placeholder: nothing to show, and an <img> with an empty src would
        // make the browser re-request the page itself.
        if (!\is_string($source) || '' === $source) {
            return '';
        }

        $rendered = '<img src="'.self::escape($source).'"';

        foreach ($attributes as $name => $value) {
            if (null === $value || false === $value) {
                continue;
            }

            $rendered .= ' '.preg_replace('/[^A-Za-z0-9_:.-]/', '', $name).'="'.self::escape((string) $value).'"';
        }

        return $rendered.'>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
