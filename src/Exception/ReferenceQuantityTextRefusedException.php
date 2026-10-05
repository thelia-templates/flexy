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

namespace FlexyBundle\Exception;

/**
 * The pasted or imported text is refused as a whole: longer, or with more rows, than a
 * quick order table takes. Nothing of it is read.
 */
final class ReferenceQuantityTextRefusedException extends \RuntimeException
{
    public static function tooLarge(int $maxBytes): self
    {
        return new self(\sprintf('The text is longer than %d bytes.', $maxBytes));
    }

    public static function tooManyLines(int $maxLines): self
    {
        return new self(\sprintf('The text holds more than %d rows.', $maxLines));
    }
}
