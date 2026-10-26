<?php

declare(strict_types=1);

/*
 * This file is part of Contao Backend Event Search.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/contao-backend-event-search
 */

namespace Markocupic\ContaoBackendEventSearch\Search;

use Contao\StringUtil;

/**
 * Loupe returns the highlighted values unescaped. Therefore, the matches are
 * marked with two characters from the Unicode private use area. The value is
 * escaped first and the markers are replaced with <mark> tags afterwards.
 */
class Highlighter
{
    public const START_MARKER = "\u{E000}";

    public const END_MARKER = "\u{E001}";

    /**
     * Returns HTML-safe output with the matches wrapped in <mark> tags.
     */
    public static function toHtml(string|null $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        $html = StringUtil::specialchars($value, false, true);

        return str_replace([self::START_MARKER, self::END_MARKER], ['<mark>', '</mark>'], $html);
    }

    public static function containsMatch(string|null $value): bool
    {
        return null !== $value && str_contains($value, self::START_MARKER);
    }
}
