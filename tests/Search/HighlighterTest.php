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

namespace Markocupic\ContaoBackendEventSearch\Tests\Search;

use Markocupic\ContaoBackendEventSearch\Search\Highlighter;
use PHPUnit\Framework\TestCase;

class HighlighterTest extends TestCase
{
    public function testEscapesTheValueAndAddsMarkTags(): void
    {
        $value = '<script>alert(1)</script> '.Highlighter::START_MARKER.'Piz'.Highlighter::END_MARKER.' Palü & Co';

        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt; <mark>Piz</mark> Palü &amp; Co',
            Highlighter::toHtml($value),
        );
    }

    public function testHandlesEmptyValues(): void
    {
        $this->assertSame('', Highlighter::toHtml(null));
        $this->assertSame('', Highlighter::toHtml(''));
    }

    public function testDetectsMatches(): void
    {
        $this->assertTrue(Highlighter::containsMatch('a '.Highlighter::START_MARKER.'b'.Highlighter::END_MARKER));
        $this->assertFalse(Highlighter::containsMatch('a b'));
        $this->assertFalse(Highlighter::containsMatch(null));
    }
}
