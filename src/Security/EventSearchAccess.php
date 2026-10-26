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

namespace Markocupic\ContaoBackendEventSearch\Security;

use Contao\CalendarBundle\Security\ContaoCalendarPermissions;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Decides who may use the event search and which events they may find.
 *
 * For non-admins the same permission checks are used as in the back end
 * list views (data container voters "contao_dc.<table>"). This way all
 * rules apply automatically: the calendar permissions of the user and its
 * groups (tl_user.calendars) and the rules of other bundles, e.g. the
 * calendar containers of the Event-Tool (tl_user.calendar_containers).
 */
class EventSearchAccess
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly Connection $connection,
        private readonly string $backendModule,
    ) {
    }

    /**
     * Administrators and users with the "backendEventSearchp" permission
     * (set in the user or in one of its groups) who can access the
     * calendar back end module.
     */
    public function canSearch(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->authorizationChecker->isGranted(BackendEventSearchPermissions::USER_CAN_SEARCH_EVENTS)
            && $this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $this->backendModule);
    }

    /**
     * Returns the ids of the calendars whose events the user may read
     * or null if the user may search all calendars (administrators).
     *
     * The search index is filtered by these calendars, so the number
     * of results is correct and the limit is not wasted on events
     * the user may not see.
     *
     * @return list<int>|null
     */
    public function getAllowedCalendarIds(): array|null
    {
        if ($this->isAdmin()) {
            return null;
        }

        $calendarIds = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_calendar') as $calendar) {
            // Read access to the calendar itself (e.g. the calendar container of the Event-Tool)
            $canReadCalendar = $this->authorizationChecker->isGranted(
                ContaoCorePermissions::DC_PREFIX.'tl_calendar',
                new ReadAction('tl_calendar', $calendar),
            );

            // Access to the events of the calendar (tl_user.calendars)
            $canEditCalendar = $this->authorizationChecker->isGranted(ContaoCalendarPermissions::USER_CAN_EDIT_CALENDAR, (int) $calendar['id']);

            if ($canReadCalendar && $canEditCalendar) {
                $calendarIds[] = (int) $calendar['id'];
            }
        }

        return $calendarIds;
    }

    /**
     * Final check on event level: removes all events the user may not read.
     *
     * @param list<array<string, mixed>> $hits search hits, each containing at least the "id"
     *
     * @return list<array<string, mixed>>
     */
    public function filterReadableEvents(array $hits): array
    {
        if ($this->isAdmin() || [] === $hits) {
            return $hits;
        }

        $ids = array_map(static fn (array $hit): int => (int) $hit['id'], $hits);

        // Pass the complete database record, so every voter gets the fields it needs
        $records = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_calendar_events WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $record) {
            $records[(int) $record['id']] = $record;
        }

        return array_values(array_filter(
            $hits,
            fn (array $hit): bool => isset($records[(int) $hit['id']]) && $this->authorizationChecker->isGranted(
                ContaoCorePermissions::DC_PREFIX.'tl_calendar_events',
                new ReadAction('tl_calendar_events', $records[(int) $hit['id']]),
            ),
        ));
    }

    private function isAdmin(): bool
    {
        return $this->authorizationChecker->isGranted('ROLE_ADMIN');
    }
}
