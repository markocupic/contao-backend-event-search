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

namespace Markocupic\ContaoBackendEventSearch\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Markocupic\ContaoBackendEventSearch\Search\EventDocumentProvider;
use Markocupic\ContaoBackendEventSearch\Search\EventSearchIndex;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the search index up to date when events, calendars or back end users
 * are edited in the back end. Changes made elsewhere (e.g. directly in the
 * database) are picked up by the hourly cron job.
 *
 * A failing index update must never prevent a record from being saved,
 * therefore all exceptions are caught and logged.
 */
class UpdateSearchIndexListener
{
    public function __construct(
        private readonly EventSearchIndex $searchIndex,
        private readonly EventDocumentProvider $documentProvider,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $contaoErrorLogger,
    ) {
    }

    /**
     * Runs as late as possible, so the instructors have already been
     * written to tl_calendar_events_instructor by the Event-Tool.
     */
    #[AsCallback(table: 'tl_calendar_events', target: 'config.onsubmit', priority: -255)]
    public function onSubmitEvent(DataContainer $dc): void
    {
        $this->index(fn () => $this->searchIndex->indexEvents([(int) $dc->id]));
    }

    #[AsCallback(table: 'tl_calendar_events', target: 'config.oncopy', priority: -255)]
    public function onCopyEvent(int|string $insertId, DataContainer $dc): void
    {
        $this->index(fn () => $this->searchIndex->indexEvents([(int) $insertId]));
    }

    #[AsCallback(table: 'tl_calendar_events', target: 'config.ondelete', priority: -255)]
    public function onDeleteEvent(DataContainer $dc): void
    {
        $this->index(fn () => $this->searchIndex->deleteEvents([(int) $dc->id]));
    }

    /**
     * The calendar title is part of every document.
     */
    #[AsCallback(table: 'tl_calendar', target: 'config.onsubmit', priority: -255)]
    public function onSubmitCalendar(DataContainer $dc): void
    {
        $this->index(fn () => $this->searchIndex->indexEvents($this->documentProvider->getEventIdsByCalendar((int) $dc->id)));
    }

    /**
     * Deleting a calendar deletes its events too (ctable),
     * but without triggering their ondelete callbacks.
     */
    #[AsCallback(table: 'tl_calendar', target: 'config.ondelete', priority: -255)]
    public function onDeleteCalendar(DataContainer $dc): void
    {
        $this->index(fn () => $this->searchIndex->deleteEvents($this->documentProvider->getEventIdsByCalendar((int) $dc->id)));
    }

    /**
     * The instructor names come from tl_user.name.
     */
    #[AsCallback(table: 'tl_user', target: 'config.onsubmit', priority: -255)]
    public function onSubmitUser(DataContainer $dc): void
    {
        $this->index(fn () => $this->searchIndex->indexEvents($this->documentProvider->getEventIdsByInstructor((int) $dc->id)));
    }

    private function index(\Closure $closure): void
    {
        try {
            $closure();
        } catch (\Throwable $e) {
            $this->contaoErrorLogger->error('Backend event search: Could not update the search index: '.$e->getMessage());
        }
    }
}
