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

namespace Markocupic\ContaoBackendEventSearch\Tests\Event;

use Markocupic\ContaoBackendEventSearch\Event\BuildSearchHitOperationsEvent;
use PHPUnit\Framework\TestCase;

class BuildSearchHitOperationsEventTest extends TestCase
{
    public function testUsesTheDefaultOperationsUnlessAListenerSetsItsOwn(): void
    {
        $event = new BuildSearchHitOperationsEvent(5, ['id' => 5, 'pid' => 2, 'title' => 'Piz Palü'], 'calendar');

        $this->assertSame(5, $event->getEventId());
        $this->assertSame(['id' => 5, 'pid' => 2, 'title' => 'Piz Palü'], $event->getDocument());
        $this->assertSame('calendar', $event->getBackendModule());
        $this->assertNull($event->getOperationsHtml());

        $event->setOperationsHtml('<div class="ops"></div>');
        $this->assertSame('<div class="ops"></div>', $event->getOperationsHtml());

        $event->setOperationsHtml(null);
        $this->assertNull($event->getOperationsHtml());
    }

    public function testReplacesTheLinkOfTheEventTitle(): void
    {
        $event = new BuildSearchHitOperationsEvent(5, ['id' => 5], 'calendar');

        $this->assertFalse($event->hasEditUrl());

        // The user may not open the edit form: no link
        $event->setEditUrl(null, 'Event: keine Berechtigung');

        $this->assertTrue($event->hasEditUrl());
        $this->assertNull($event->getEditUrl());
        $this->assertSame('Event: keine Berechtigung', $event->getEditTitle());
    }
}
