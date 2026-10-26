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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads the events from the database and turns them into Loupe documents.
 *
 * Every document contains these attributes:
 *
 *   id, pid, title, startDate, calendarTitle, instructors, releaseLevel, releaseLevelTitle
 *
 * plus every field configured in "searchable_fields".
 */
class EventDocumentProvider
{
    /**
     * Virtual field holding the names of all instructors of an event.
     */
    public const INSTRUCTORS_FIELD = 'instructors';

    /**
     * Attributes that are always part of a document and
     * therefore cannot be configured as searchable fields.
     */
    public const RESERVED_FIELDS = ['id', 'pid', 'startDate', 'calendarTitle', 'releaseLevel', 'releaseLevelTitle'];

    private const INSTRUCTOR_TABLE = 'tl_calendar_events_instructor';

    private const PARTICIPANT_TABLE = 'tl_calendar_events_member';

    private const RELEASE_LEVEL_TABLE = 'tl_event_release_level_policy';

    /**
     * @var array<string, string>|null
     */
    private array|null $eventColumns = null;

    private bool|null $hasInstructorTable = null;

    private bool|null $hasParticipantTable = null;

    private bool|null $hasReleaseLevel = null;

    /**
     * @param list<string> $searchableFields
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $searchableFields,
    ) {
    }

    /**
     * The configured searchable fields that really exist
     * (columns of tl_calendar_events or the virtual instructors field).
     *
     * @return list<string>
     */
    public function getSearchableFields(): array
    {
        $fields = [];

        foreach ($this->searchableFields as $field) {
            if (self::INSTRUCTORS_FIELD === $field) {
                if ($this->hasInstructorTable()) {
                    $fields[] = $field;
                }

                continue;
            }

            if (isset($this->getEventColumns()[strtolower($field)])) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Configured fields that could not be found in the database.
     *
     * @return list<string>
     */
    public function getInvalidFields(): array
    {
        return array_values(array_diff($this->searchableFields, $this->getSearchableFields()));
    }

    /**
     * Returns the documents of all events.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllDocuments(): array
    {
        return $this->getDocuments(null);
    }

    /**
     * Returns the documents of the given events. Ids of events that
     * do not exist (anymore) are simply missing in the result.
     *
     * @param list<int>|null $eventIds null for all events
     *
     * @return list<array<string, mixed>>
     */
    public function getDocuments(array|null $eventIds): array
    {
        if (null !== $eventIds && [] === $eventIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder()
            ->select('e.id', 'e.pid', 'e.title', 'e.startDate', 'c.title AS calendarTitle')
            ->from('tl_calendar_events', 'e')
            ->leftJoin('e', 'tl_calendar', 'c', 'c.id = e.pid')
            ->orderBy('e.id')
        ;

        // Release level (Freigabestufe) of the Event-Tool
        if ($this->hasReleaseLevel()) {
            $qb
                ->addSelect('r.level AS releaseLevel', 'r.title AS releaseLevelTitle')
                ->leftJoin('e', self::RELEASE_LEVEL_TABLE, 'r', 'r.id = e.eventReleaseLevel')
            ;
        }

        $columnFields = array_values(array_diff($this->getSearchableFields(), ['title', self::INSTRUCTORS_FIELD]));

        foreach ($columnFields as $field) {
            $qb->addSelect('e.'.$this->connection->quoteIdentifier($this->getEventColumns()[strtolower($field)]).' AS '.$this->connection->quoteIdentifier($field));
        }

        if (null !== $eventIds) {
            $qb->where('e.id IN (:ids)')->setParameter('ids', $eventIds, ArrayParameterType::INTEGER);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $instructors = $this->getInstructorNames(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $documents = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $document = [
                'id' => $id,
                'pid' => (int) $row['pid'],
                'title' => self::normalize($row['title']) ?? '',
                'startDate' => (int) $row['startDate'],
                'calendarTitle' => self::normalize($row['calendarTitle']) ?? '',
                self::INSTRUCTORS_FIELD => isset($instructors[$id]) ? implode(', ', $instructors[$id]) : null,
                'releaseLevel' => isset($row['releaseLevel']) ? (int) $row['releaseLevel'] : null,
                'releaseLevelTitle' => isset($row['releaseLevelTitle']) ? self::normalize($row['releaseLevelTitle']) : null,
            ];

            foreach ($columnFields as $field) {
                $document[$field] = self::normalize($row[$field]);
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * Ids of all events a back end user is an instructor of.
     *
     * @return list<int>
     */
    public function getEventIdsByInstructor(int $userId): array
    {
        if (!$this->hasInstructorTable()) {
            return [];
        }

        return array_map(
            intval(...),
            $this->connection->fetchFirstColumn('SELECT DISTINCT pid FROM '.self::INSTRUCTOR_TABLE.' WHERE userId = ?', [$userId]),
        );
    }

    /**
     * Ids of all events of a calendar.
     *
     * @return list<int>
     */
    public function getEventIdsByCalendar(int $calendarId): array
    {
        return array_map(
            intval(...),
            $this->connection->fetchFirstColumn('SELECT id FROM tl_calendar_events WHERE pid = ?', [$calendarId]),
        );
    }

    /**
     * Turns a database value into a plain, searchable string.
     * Serialized arrays are flattened, HTML is stripped.
     */
    public static function normalize(mixed $value): string|null
    {
        if (null === $value) {
            return null;
        }

        $value = (string) $value;

        // Serialized arrays (e.g. multiple select fields)
        if (str_starts_with($value, 'a:')) {
            $array = @unserialize($value, ['allowed_classes' => false]);

            if (\is_array($array)) {
                $flat = [];

                array_walk_recursive(
                    $array,
                    static function (mixed $item) use (&$flat): void {
                        if (\is_scalar($item)) {
                            $flat[] = (string) $item;
                        }
                    },
                );

                $value = implode(' ', $flat);
            }
        }

        // Keep words separated when stripping block level tags like <p>, <li> or <br>
        $value = str_replace('<', ' <', $value);
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $value ? null : $value;
    }

    /**
     * @param list<int> $eventIds
     *
     * @return array<int, list<string>> event id => instructor names (main instructor first)
     */
    private function getInstructorNames(array $eventIds): array
    {
        if (!$this->hasInstructorTable()) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.pid, u.name FROM '.self::INSTRUCTOR_TABLE.' i INNER JOIN tl_user u ON u.id = i.userId WHERE i.pid IN (?) ORDER BY i.isMainInstructor DESC, u.name',
            [$eventIds],
            [ArrayParameterType::INTEGER],
        );

        $names = [];

        foreach ($rows as $row) {
            $name = self::normalize($row['name']);

            if (null !== $name) {
                $names[(int) $row['pid']][] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, string> lowercase column name => column name
     */
    private function getEventColumns(): array
    {
        if (null === $this->eventColumns) {
            $this->eventColumns = [];

            foreach ($this->connection->createSchemaManager()->listTableColumns('tl_calendar_events') as $column) {
                $this->eventColumns[strtolower($column->getName())] = $column->getName();
            }
        }

        return $this->eventColumns;
    }

    /**
     * True if the participant list of the Event-Tool (tl_calendar_events_member) is available.
     */
    public function hasParticipantList(): bool
    {
        if (null === $this->hasParticipantTable) {
            $this->hasParticipantTable = $this->connection->createSchemaManager()->tablesExist([self::PARTICIPANT_TABLE]);
        }

        return $this->hasParticipantTable;
    }

    /**
     * True if the release levels of the Event-Tool are available
     * (tl_calendar_events.eventReleaseLevel and tl_event_release_level_policy).
     */
    private function hasReleaseLevel(): bool
    {
        if (null === $this->hasReleaseLevel) {
            $this->hasReleaseLevel = isset($this->getEventColumns()['eventreleaselevel'])
                && $this->connection->createSchemaManager()->tablesExist([self::RELEASE_LEVEL_TABLE]);
        }

        return $this->hasReleaseLevel;
    }

    private function hasInstructorTable(): bool
    {
        if (null === $this->hasInstructorTable) {
            $this->hasInstructorTable = $this->connection->createSchemaManager()->tablesExist([self::INSTRUCTOR_TABLE]);
        }

        return $this->hasInstructorTable;
    }
}
