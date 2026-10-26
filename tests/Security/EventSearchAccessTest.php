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

namespace Markocupic\ContaoBackendEventSearch\Tests\Security;

use Contao\CalendarBundle\Security\ContaoCalendarPermissions;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Markocupic\ContaoBackendEventSearch\Security\BackendEventSearchPermissions;
use Markocupic\ContaoBackendEventSearch\Security\EventSearchAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class EventSearchAccessTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // pid = calendar container of the Event-Tool
        $this->connection->executeStatement('CREATE TABLE tl_calendar (id INTEGER PRIMARY KEY, pid INTEGER, title TEXT)');
        $this->connection->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY, pid INTEGER, title TEXT)');

        $this->connection->insert('tl_calendar', ['id' => 1, 'pid' => 100, 'title' => 'Touren']);
        $this->connection->insert('tl_calendar', ['id' => 2, 'pid' => 200, 'title' => 'Kurse']);
        $this->connection->insert('tl_calendar', ['id' => 3, 'pid' => 100, 'title' => 'Intern']);

        $this->connection->insert('tl_calendar_events', ['id' => 10, 'pid' => 1, 'title' => 'Piz Palü']);
        $this->connection->insert('tl_calendar_events', ['id' => 11, 'pid' => 2, 'title' => 'Lawinenkurs']);
        $this->connection->insert('tl_calendar_events', ['id' => 12, 'pid' => 1, 'title' => 'Gesperrtes Event']);
    }

    public function testAdminsMaySearchEverything(): void
    {
        $access = $this->createAccess(static fn (string $attribute): bool => 'ROLE_ADMIN' === $attribute);

        $this->assertTrue($access->canSearch());
        $this->assertNull($access->getAllowedCalendarIds());
        $this->assertSame([['id' => 12]], $access->filterReadableEvents([['id' => 12]]));
    }

    public function testRequiresThePermissionAndTheModule(): void
    {
        $access = $this->createAccess(static fn (string $attribute): bool => BackendEventSearchPermissions::USER_CAN_SEARCH_EVENTS === $attribute);
        $this->assertFalse($access->canSearch());

        $access = $this->createAccess(static fn (string $attribute, mixed $subject): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'calendar' === $subject);
        $this->assertFalse($access->canSearch());

        $access = $this->createAccess(
            static fn (string $attribute, mixed $subject): bool => BackendEventSearchPermissions::USER_CAN_SEARCH_EVENTS === $attribute
                || (ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'calendar' === $subject),
        );
        $this->assertTrue($access->canSearch());
    }

    public function testOnlyReturnsCalendarsTheUserCanReadAndEdit(): void
    {
        $access = $this->createAccess(
            static function (string $attribute, mixed $subject): bool {
                // Read access to the calendar: only calendars in container 100 (like the Event-Tool)
                if (ContaoCorePermissions::DC_PREFIX.'tl_calendar' === $attribute) {
                    return $subject instanceof ReadAction && '100' === $subject->getCurrentPid();
                }

                // tl_user.calendars: calendars 1 and 2
                if (ContaoCalendarPermissions::USER_CAN_EDIT_CALENDAR === $attribute) {
                    return \in_array($subject, [1, 2], true);
                }

                return false;
            },
        );

        // Calendar 2: wrong container, calendar 3: not in tl_user.calendars
        $this->assertSame([1], $access->getAllowedCalendarIds());
    }

    public function testFiltersTheEventsTheUserMayNotRead(): void
    {
        $access = $this->createAccess(
            static fn (string $attribute, mixed $subject): bool => ContaoCorePermissions::DC_PREFIX.'tl_calendar_events' === $attribute
                && $subject instanceof ReadAction
                && '12' !== $subject->getCurrentId(),
        );

        $hits = [['id' => 10, 'title' => 'a'], ['id' => 12, 'title' => 'b'], ['id' => 999, 'title' => 'deleted']];

        $this->assertSame([['id' => 10, 'title' => 'a']], $access->filterReadableEvents($hits));
    }

    private function createAccess(\Closure $isGranted): EventSearchAccess
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker
            ->method('isGranted')
            ->willReturnCallback(static fn (mixed $attribute, mixed $subject = null): bool => $isGranted($attribute, $subject))
        ;

        return new EventSearchAccess($authorizationChecker, $this->connection, 'calendar');
    }
}
