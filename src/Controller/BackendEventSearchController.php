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

namespace Markocupic\ContaoBackendEventSearch\Controller;

use Contao\Config;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Date;
use Contao\System;
use Markocupic\ContaoBackendEventSearch\Event\BuildSearchHitOperationsEvent;
use Markocupic\ContaoBackendEventSearch\Search\EventDocumentProvider;
use Markocupic\ContaoBackendEventSearch\Search\EventSearchIndex;
use Markocupic\ContaoBackendEventSearch\Search\Highlighter;
use Markocupic\ContaoBackendEventSearch\Security\EventSearchAccess;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Returns the search results as JSON:
 *
 *   GET /contao/backend-event-search?q=piz
 *
 * Only accessible to administrators and users with the "backendEventSearchp" permission.
 * Non-admins only find events of calendars they have access to.
 */
#[Route(
    '%contao.backend.route_prefix%/backend-event-search',
    name: self::class,
    defaults: ['_scope' => 'backend'],
    methods: ['GET'],
)]
class BackendEventSearchController
{
    public const MIN_QUERY_LENGTH = 2;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly EventSearchAccess $eventSearchAccess,
        private readonly EventSearchIndex $searchIndex,
        private readonly EventDocumentProvider $documentProvider,
        private readonly UrlGeneratorInterface $router,
        private readonly EventDispatcherInterface $eventDispatcher,
        #[Autowire(service: 'contao.csrf.token_manager')]
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $contaoErrorLogger,
        private readonly int $hitsPerPage,
        private readonly string $backendModule,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->eventSearchAccess->canSearch()) {
            return new JsonResponse(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $query = trim((string) $request->query->get('q', ''));

        // Integers (event ids) may be shorter than the minimum query length
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH && !ctype_digit($query)) {
            return new JsonResponse(['query' => $query, 'totalHits' => 0, 'hits' => []]);
        }

        // Loupe limits the number of query tokens anyway, keep the query reasonably short
        $query = mb_substr($query, 0, 200);

        try {
            // Only search the calendars the user has access to
            $result = $this->searchIndex->search($query, $this->hitsPerPage, $this->eventSearchAccess->getAllowedCalendarIds());

            // Final check on event level (same voters as in the back end list view)
            $readableHits = $this->eventSearchAccess->filterReadableEvents($result['hits']);
            $result['totalHits'] -= \count($result['hits']) - \count($readableHits);
            $result['hits'] = $readableHits;
        } catch (\Throwable $e) {
            $this->contaoErrorLogger->error('Backend event search: The search failed: '.$e->getMessage());

            return new JsonResponse(['error' => 'The search failed. Please check the system log.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->framework->initialize();
        $this->framework->getAdapter(System::class)->loadLanguageFile('tl_calendar_events');

        $dateFormat = (string) $this->framework->getAdapter(Config::class)->get('dateFormat');
        $date = $this->framework->getAdapter(Date::class);
        $requestToken = $this->csrfTokenManager->getDefaultTokenValue();

        // The participant list (tl_calendar_events_member) is provided by the Event-Tool
        $hasParticipantList = $this->documentProvider->hasParticipantList();

        $hits = [];

        foreach ($result['hits'] as $hit) {
            $formatted = $hit['_formatted'] ?? $hit;

            // Other bundles may replace the default operations, e.g. the SAC Event-Tool
            $operationsEvent = new BuildSearchHitOperationsEvent((int) $hit['id'], $this->withoutFormatting($hit), $this->backendModule);
            $this->eventDispatcher->dispatch($operationsEvent);

            $hits[] = [
                'id' => (int) $hit['id'],
                'matchedById' => true === ($hit['_matchedById'] ?? false),
                'titleHtml' => Highlighter::toHtml($formatted['title'] ?? $hit['title'] ?? ''),
                'instructorsHtml' => Highlighter::toHtml($formatted[EventDocumentProvider::INSTRUCTORS_FIELD] ?? $hit[EventDocumentProvider::INSTRUCTORS_FIELD] ?? ''),
                'calendarTitle' => (string) ($hit['calendarTitle'] ?? ''),
                'releaseLevel' => isset($hit['releaseLevel']) ? (int) $hit['releaseLevel'] : null,
                'releaseLevelTitle' => (string) ($hit['releaseLevelTitle'] ?? ''),
                'date' => $hit['startDate'] ? $date->parse($dateFormat, (int) $hit['startDate']) : '',
                'snippets' => $this->getSnippets($formatted),
                'listUrl' => $this->router->generate('contao_backend', [
                    'do' => $this->backendModule,
                    'table' => 'tl_calendar_events',
                    'id' => (int) $hit['pid'],
                    'rt' => $requestToken,
                ]),
                'editUrl' => $operationsEvent->hasEditUrl() ? $operationsEvent->getEditUrl() : $this->router->generate('contao_backend', [
                    'do' => $this->backendModule,
                    'table' => 'tl_calendar_events',
                    'id' => (int) $hit['id'],
                    'act' => 'edit',
                    'rt' => $requestToken,
                ]),
                'editTitle' => $operationsEvent->hasEditUrl() ? $operationsEvent->getEditTitle() : null,
                'participantsUrl' => $hasParticipantList ? $this->router->generate('contao_backend', [
                    'do' => $this->backendModule,
                    'table' => 'tl_calendar_events_member',
                    'id' => (int) $hit['id'],
                    'rt' => $requestToken,
                ]) : null,
                'operationsHtml' => $operationsEvent->getOperationsHtml(),
            ];
        }

        return new JsonResponse([
            'query' => $query,
            'totalHits' => $result['totalHits'],
            'hits' => $hits,
        ]);
    }

    /**
     * Removes the highlighting and the internal attributes of Loupe (e.g. "_formatted").
     *
     * @param array<string, mixed> $hit
     *
     * @return array<string, mixed>
     */
    private function withoutFormatting(array $hit): array
    {
        return array_filter($hit, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Shows where the query matched, for all searchable fields
     * except the title and the instructors (they are always shown).
     *
     * @param array<string, mixed> $formatted
     *
     * @return list<array{label: string, html: string}>
     */
    private function getSnippets(array $formatted): array
    {
        $snippets = [];

        foreach ($this->documentProvider->getSearchableFields() as $field) {
            if ('title' === $field || EventDocumentProvider::INSTRUCTORS_FIELD === $field) {
                continue;
            }

            $value = isset($formatted[$field]) && \is_string($formatted[$field]) ? $formatted[$field] : null;

            if (!Highlighter::containsMatch($value)) {
                continue;
            }

            $snippets[] = [
                'label' => (string) ($GLOBALS['TL_LANG']['tl_calendar_events'][$field][0] ?? $field),
                'html' => Highlighter::toHtml($value),
            ];
        }

        return $snippets;
    }
}
