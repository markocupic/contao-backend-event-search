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

namespace Markocupic\ContaoBackendEventSearch\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Image;
use Markocupic\ContaoBackendEventSearch\Controller\BackendEventSearchController;
use Markocupic\ContaoBackendEventSearch\Security\EventSearchAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Places the search box at the top of the back end start page (be_welcome).
 * No core template is overridden.
 */
#[AsHook('parseBackendTemplate')]
class AddSearchToWelcomeScreenListener
{
    private const ASSET_PATH = 'bundles/markocupiccontaobackendeventsearch';

    public function __construct(
        private readonly EventSearchAccess $eventSearchAccess,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function __invoke(string $buffer, string $template): string
    {
        if ('be_welcome' !== $template || !$this->eventSearchAccess->canSearch()) {
            return $buffer;
        }

        $GLOBALS['TL_CSS'][] = self::ASSET_PATH.'/backend-event-search.css|static';
        $GLOBALS['TL_JAVASCRIPT'][] = self::ASSET_PATH.'/backend-event-search.js|static';

        $searchBox = $this->twig->render('@Contao/backend/backend_event_search.html.twig', [
            'search_url' => $this->router->generate(BackendEventSearchController::class),
            'min_query_length' => BackendEventSearchController::MIN_QUERY_LENGTH,
            'list_icon' => Image::getPath('children.svg'),
            'participants_icon' => Image::getPath('mgroup.svg'),
        ]);

        // Insert the search box as first element of the overview
        $count = 0;
        $buffer = preg_replace_callback(
            '/<div id="tl_soverview"[^>]*>/',
            static fn (array $matches): string => $matches[0]."\n".$searchBox,
            $buffer,
            1,
            $count,
        );

        // Fallback, if a custom be_welcome template does not contain #tl_soverview
        return $count > 0 ? (string) $buffer : $searchBox.$buffer;
    }
}
