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

namespace FlexyBundle\DTO;

/**
 * What a pasted or imported text held: the rows that could be read, and the lines
 * that could not, each with its number in the text and why.
 */
final readonly class ParsedReferenceQuantities
{
    /**
     * @param list<array{reference: string, quantity: int}>           $rows
     * @param list<array{line: int, content: string, reason: string}> $rejected
     */
    public function __construct(
        public array $rows,
        public array $rejected,
    ) {
    }
}
