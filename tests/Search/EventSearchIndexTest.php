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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Markocupic\ContaoBackendEventSearch\Search\EventDocumentProvider;
use Markocupic\ContaoBackendEventSearch\Search\EventSearchIndex;
use Markocupic\ContaoBackendEventSearch\Search\Highlighter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Integration test: indexes events from an SQLite database with Loupe.
 */
class EventSearchIndexTest extends TestCase
{
    private Connection $connection;

    private string $indexDir;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->indexDir = sys_get_temp_dir().'/backend_event_search_test_'.bin2hex(random_bytes(4));

        $this->connection->executeStatement('CREATE TABLE tl_calendar (id INTEGER PRIMARY KEY, title TEXT)');
        $this->connection->executeStatement('CREATE TABLE tl_calendar_events (id INTEGER PRIMARY KEY, pid INTEGER, title TEXT, teaser TEXT, startDate INTEGER)');
        $this->connection->executeStatement('CREATE TABLE tl_user (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement('CREATE TABLE tl_calendar_events_instructor (id INTEGER PRIMARY KEY, pid INTEGER, userId INTEGER, isMainInstructor INTEGER)');

        $this->connection->insert('tl_calendar', ['id' => 1, 'title' => 'Touren 2027']);
        $this->connection->insert('tl_calendar', ['id' => 2, 'title' => 'Kurse 2027']);

        $this->connection->insert('tl_user', ['id' => 1, 'name' => 'Hans Muster']);
        $this->connection->insert('tl_user', ['id' => 2, 'name' => 'Erika Bergmann']);

        $this->connection->insert('tl_calendar_events', ['id' => 10, 'pid' => 1, 'title' => 'Piz Palü Ostgipfel', 'teaser' => '<p>Hochtour mit Gletscher</p>', 'startDate' => 1800000000]);
        $this->connection->insert('tl_calendar_events', ['id' => 11, 'pid' => 2, 'title' => 'Lawinenkurs Grundlagen', 'teaser' => 'Kurs für Einsteiger', 'startDate' => 1790000000]);
        $this->connection->insert('tl_calendar_events', ['id' => 12, 'pid' => 1, 'title' => 'Skitour Pilatus', 'teaser' => null, 'startDate' => 1795000000]);

        $this->connection->insert('tl_calendar_events_instructor', ['pid' => 10, 'userId' => 1, 'isMainInstructor' => 1]);
        $this->connection->insert('tl_calendar_events_instructor', ['pid' => 10, 'userId' => 2, 'isMainInstructor' => 0]);
        $this->connection->insert('tl_calendar_events_instructor', ['pid' => 11, 'userId' => 2, 'isMainInstructor' => 1]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->indexDir);
    }

    public function testBuildsTheDocuments(): void
    {
        $provider = new EventDocumentProvider($this->connection, ['title', 'instructors', 'teaser', 'doesNotExist']);

        $this->assertSame(['title', 'instructors', 'teaser'], $provider->getSearchableFields());
        $this->assertSame(['doesNotExist'], $provider->getInvalidFields());

        $documents = $provider->getDocuments([10]);

        $this->assertSame(
            [
                'id' => 10,
                'pid' => 1,
                'title' => 'Piz Palü Ostgipfel',
                'startDate' => 1800000000,
                'calendarTitle' => 'Touren 2027',
                'instructors' => 'Hans Muster, Erika Bergmann',
                'releaseLevel' => null,
                'releaseLevelTitle' => null,
                'teaser' => 'Hochtour mit Gletscher',
            ],
            $documents[0],
        );
    }

    public function testAddsTheReleaseLevel(): void
    {
        $this->connection->executeStatement('ALTER TABLE tl_calendar_events ADD COLUMN eventReleaseLevel INTEGER DEFAULT 0');
        $this->connection->executeStatement('CREATE TABLE tl_event_release_level_policy (id INTEGER PRIMARY KEY, level INTEGER, title TEXT)');
        $this->connection->insert('tl_event_release_level_policy', ['id' => 5, 'level' => 3, 'title' => 'Freigabe durch Tourenchef']);
        $this->connection->update('tl_calendar_events', ['eventReleaseLevel' => 5], ['id' => 10]);

        $documents = (new EventDocumentProvider($this->connection, ['title']))->getDocuments([10, 11]);

        $this->assertSame(3, $documents[0]['releaseLevel']);
        $this->assertSame('Freigabe durch Tourenchef', $documents[0]['releaseLevelTitle']);

        // Event without release level
        $this->assertNull($documents[1]['releaseLevel']);
        $this->assertNull($documents[1]['releaseLevelTitle']);
    }

    public function testDetectsTheParticipantList(): void
    {
        $this->assertFalse((new EventDocumentProvider($this->connection, ['title']))->hasParticipantList());

        $this->connection->executeStatement('CREATE TABLE tl_calendar_events_member (id INTEGER PRIMARY KEY, eventId INTEGER)');

        $this->assertTrue((new EventDocumentProvider($this->connection, ['title']))->hasParticipantList());
    }

    public function testSearchesTitleAndInstructors(): void
    {
        $index = $this->createIndex(['title', 'instructors']);

        // Index is built on the first search
        $this->assertSame([10], $this->search($index, 'palü'));
        $this->assertSame([10, 11], $this->search($index, 'Bergmann'));

        // Typo tolerance and prefix search
        $this->assertSame([10], $this->search($index, 'Mustr'));
        $this->assertSame([12], $this->search($index, 'pila'));

        // The teaser is not configured as searchable field
        $this->assertSame([], $this->search($index, 'Gletscher'));

        $hit = $index->search('Muster', 10)['hits'][0];
        $this->assertSame('<mark>Hans Muster</mark>, Erika Bergmann', str_replace('</mark> <mark>', ' ', Highlighter::toHtml($hit['_formatted']['instructors'])));
    }

    public function testFindsTheEventByIdIfTheQueryIsAnInteger(): void
    {
        $index = $this->createIndex(['title', 'instructors']);

        $result = $index->search('11', 10);

        $this->assertSame(11, (int) $result['hits'][0]['id']);
        $this->assertTrue($result['hits'][0]['_matchedById']);

        // Unknown ids and non-integer queries do not match by id
        foreach (['999', '11a', '-11', '1.5'] as $query) {
            foreach ($index->search($query, 10)['hits'] as $hit) {
                $this->assertArrayNotHasKey('_matchedById', $hit);
            }
        }
    }

    public function testOnlySearchesTheAllowedCalendars(): void
    {
        $index = $this->createIndex(['title', 'instructors']);

        // Erika Bergmann leads events in calendar 1 (id 10) and calendar 2 (id 11)
        $ids = array_map(static fn (array $hit): int => (int) $hit['id'], $index->search('Bergmann', 10, [2])['hits']);
        $this->assertSame([11], $ids);

        // The id search respects the allowed calendars too
        $this->assertSame([], $index->search('10', 10, [2])['hits']);
        $this->assertSame(10, (int) $index->search('10', 10, [1])['hits'][0]['id']);

        // No calendars, no results
        $this->assertSame(['hits' => [], 'totalHits' => 0], $index->search('Bergmann', 10, []));
    }

    public function testChangingTheSearchableFieldsRebuildsTheIndex(): void
    {
        $this->createIndex(['title', 'instructors'])->reindexAll();

        $index = $this->createIndex(['title', 'teaser']);

        $this->assertSame([10], $this->search($index, 'Gletscher'));
        $this->assertSame([], $this->search($index, 'Bergmann'));
    }

    public function testUpdatesAndDeletesEvents(): void
    {
        $index = $this->createIndex(['title', 'instructors']);
        $this->assertSame(3, $index->reindexAll());

        $this->connection->update('tl_calendar_events', ['title' => 'Piz Bernina'], ['id' => 10]);
        $this->connection->delete('tl_calendar_events', ['id' => 11]);

        $index->indexEvents([10, 11]);

        $this->assertSame([10], $this->search($index, 'Bernina'));
        $this->assertSame([], $this->search($index, 'Lawinenkurs'));
        $this->assertSame(2, $index->countDocuments());

        $index->deleteEvents([10]);
        $this->assertSame([], $this->search($index, 'Bernina'));
    }

    /**
     * @param list<string> $fields
     */
    private function createIndex(array $fields): EventSearchIndex
    {
        return new EventSearchIndex(
            new EventDocumentProvider($this->connection, $fields),
            new Filesystem(),
            $this->indexDir,
            ['de'],
        );
    }

    /**
     * @return list<int>
     */
    private function search(EventSearchIndex $index, string $query): array
    {
        $ids = array_map(static fn (array $hit): int => (int) $hit['id'], $index->search($query, 10)['hits']);
        sort($ids);

        return $ids;
    }
}
