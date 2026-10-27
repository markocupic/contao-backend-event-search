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

namespace Markocupic\ContaoBackendEventSearch\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched for every search result. Listeners can replace the default
 * operations (event list, participant list) with their own markup and the link
 * of the event title, e.g. the SAC Event-Tool (markocupic/sac-event-tool-bundle).
 *
 * The markup is inserted into the back end as it is: listeners must escape
 * all values themselves (e.g. by rendering a Twig template).
 */
class BuildSearchHitOperationsEvent extends Event
{
    private string|null $operationsHtml = null;

    private bool $hasEditUrl = false;

    private string|null $editUrl = null;

    private string|null $editTitle = null;

    /**
     * @param array<string, mixed> $document The search document (id, pid, title, startDate, calendarTitle, …)
     */
    public function __construct(
        private readonly int $eventId,
        private readonly array $document,
        private readonly string $backendModule,
    ) {
    }

    public function getEventId(): int
    {
        return $this->eventId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(): array
    {
        return $this->document;
    }

    /**
     * The back end module the links should point to (configuration "backend_module").
     */
    public function getBackendModule(): string
    {
        return $this->backendModule;
    }

    public function getOperationsHtml(): string|null
    {
        return $this->operationsHtml;
    }

    /**
     * Replaces the default operations of the search result. Pass null to restore the default operations.
     */
    public function setOperationsHtml(string|null $operationsHtml): void
    {
        $this->operationsHtml = $operationsHtml;
    }

    /**
     * Replaces the link of the event title (default: the edit form of the event).
     * Pass null as URL, if the user may not open the edit form: the title is then
     * displayed without a link and the title attribute explains why.
     */
    public function setEditUrl(string|null $editUrl, string|null $editTitle = null): void
    {
        $this->hasEditUrl = true;
        $this->editUrl = $editUrl;
        $this->editTitle = $editTitle;
    }

    public function hasEditUrl(): bool
    {
        return $this->hasEditUrl;
    }

    public function getEditUrl(): string|null
    {
        return $this->editUrl;
    }

    public function getEditTitle(): string|null
    {
        return $this->editTitle;
    }
}
