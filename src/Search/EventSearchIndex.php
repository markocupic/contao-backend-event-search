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

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Wraps the Loupe search index (SQLite) containing all events.
 */
class EventSearchIndex
{
    private Loupe|null $loupe = null;

    /**
     * @param list<string> $languages
     */
    public function __construct(
        private readonly EventDocumentProvider $documentProvider,
        private readonly Filesystem $filesystem,
        private readonly string $indexDir,
        private readonly array $languages,
    ) {
    }

    /**
     * Searches the index. Builds the index first, if it is empty or
     * if the configuration (e.g. the searchable fields) has changed.
     *
     * @param list<int>|null $calendarIds only search these calendars, null for all calendars
     *
     * @return array{hits: list<array<string, mixed>>, totalHits: int}
     */
    public function search(string $query, int $limit, array|null $calendarIds = null): array
    {
        if (null !== $calendarIds && [] === $calendarIds) {
            return ['hits' => [], 'totalHits' => 0];
        }

        $this->ensureIndexIsUpToDate();

        $searchableFields = $this->documentProvider->getSearchableFields();

        $parameters = SearchParameters::create()
            ->withQuery($query)
            ->withHitsPerPage($limit)
            ->withSort(['_relevance:desc', 'startDate:desc'])
            ->withAttributesToHighlight($searchableFields, Highlighter::START_MARKER, Highlighter::END_MARKER)
            ->withAttributesToCrop(array_values(array_diff($searchableFields, ['title', EventDocumentProvider::INSTRUCTORS_FIELD])), 20)
        ;

        if (null !== $calendarIds) {
            $parameters = $parameters->withFilter(\sprintf('pid IN (%s)', implode(', ', array_map(intval(...), $calendarIds))));
        }

        $result = $this->getLoupe()->search($parameters)->toArray();

        $hits = $result['hits'];
        $totalHits = (int) $result['totalHits'];

        // If the query is an integer, the event with this id is always the first hit
        $idHit = $this->findById($query);

        if (null !== $idHit && null !== $calendarIds && !\in_array((int) $idHit['pid'], array_map(intval(...), $calendarIds), true)) {
            $idHit = null;
        }

        if (null !== $idHit) {
            $hits = array_values(array_filter($hits, static fn (array $hit): bool => (int) $hit['id'] !== (int) $idHit['id']));

            if (\count($hits) === \count($result['hits'])) {
                ++$totalHits;
            }

            array_unshift($hits, $idHit);
            $hits = \array_slice($hits, 0, $limit);
        }

        return [
            'hits' => $hits,
            'totalHits' => $totalHits,
        ];
    }

    /**
     * Returns the event with the given id, if the query is a positive integer.
     *
     * @return array<string, mixed>|null
     */
    private function findById(string $query): array|null
    {
        $query = trim($query);

        if (!preg_match('/^[1-9]\d{0,9}$/', $query) || (int) $query > 4294967295) {
            return null;
        }

        $document = $this->getLoupe()->getDocument((int) $query);

        if (null === $document) {
            return null;
        }

        $document['_matchedById'] = true;

        return $document;
    }

    /**
     * Rebuilds the whole index. The new index is built in a temporary
     * directory, so searches keep working while the index is rebuilt.
     *
     * @return int number of indexed events
     */
    public function reindexAll(): int
    {
        $documents = $this->documentProvider->getAllDocuments();

        $tmpDir = $this->indexDir.'_tmp_'.bin2hex(random_bytes(4));

        $loupe = $this->createLoupe($tmpDir);

        if ([] !== $documents) {
            $loupe->addDocuments($documents);
        }

        // Close the SQLite connections before moving the directories (required on Windows)
        unset($loupe);
        $this->loupe = null;
        gc_collect_cycles();

        $this->filesystem->remove($this->indexDir);
        $this->filesystem->rename($tmpDir, $this->indexDir);

        return \count($documents);
    }

    /**
     * Adds or updates the given events. Ids of events
     * that no longer exist are removed from the index.
     *
     * @param list<int> $eventIds
     */
    public function indexEvents(array $eventIds): void
    {
        $eventIds = array_values(array_unique(array_map(intval(...), $eventIds)));

        if ([] === $eventIds) {
            return;
        }

        if ($this->needsFullReindex()) {
            $this->reindexAll();

            return;
        }

        $documents = $this->documentProvider->getDocuments($eventIds);

        if ([] !== $documents) {
            $this->getLoupe()->addDocuments($documents);
        }

        $deletedIds = array_values(array_diff($eventIds, array_column($documents, 'id')));

        if ([] !== $deletedIds) {
            $this->getLoupe()->deleteDocuments($deletedIds);
        }
    }

    /**
     * @param list<int> $eventIds
     */
    public function deleteEvents(array $eventIds): void
    {
        $eventIds = array_values(array_unique(array_map(intval(...), $eventIds)));

        if ([] === $eventIds || !$this->indexExists()) {
            return;
        }

        $this->getLoupe()->deleteDocuments($eventIds);
    }

    public function countDocuments(): int
    {
        if (!$this->indexExists()) {
            return 0;
        }

        return $this->getLoupe()->countDocuments();
    }

    public function getConfiguration(): Configuration
    {
        return Configuration::create()
            ->withPrimaryKey('id')
            ->withSearchableAttributes($this->documentProvider->getSearchableFields())
            // Changing the configuration changes Loupe's index hash and triggers a rebuild
            ->withFilterableAttributes(['pid', 'releaseLevel'])
            ->withSortableAttributes(['startDate'])
            ->withLanguages($this->languages)
            ->withMinTokenLengthForPrefixSearch(2)
        ;
    }

    private function ensureIndexIsUpToDate(): void
    {
        if ($this->needsFullReindex()) {
            $this->reindexAll();
        }
    }

    private function needsFullReindex(): bool
    {
        if (!$this->indexExists()) {
            return true;
        }

        // An empty index is cheap to rebuild and might just have been created
        return 0 === $this->getLoupe()->countDocuments() || $this->getLoupe()->needsReindex();
    }

    private function indexExists(): bool
    {
        return is_dir($this->indexDir) && [] !== glob($this->indexDir.'/*');
    }

    private function getLoupe(): Loupe
    {
        return $this->loupe ??= $this->createLoupe($this->indexDir);
    }

    private function createLoupe(string $dir): Loupe
    {
        $this->filesystem->mkdir($dir);

        return (new LoupeFactory())->create($dir, $this->getConfiguration());
    }
}
