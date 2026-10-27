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

namespace Markocupic\ContaoBackendEventSearch\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Markocupic\ContaoBackendEventSearch\Search\EventSearchIndex;

/**
 * Safety net: rebuilds the index every hour, so changes that did not
 * pass through the back end callbacks are found as well.
 */
#[AsCronJob('hourly')]
class ReindexEventsCron
{
    public function __construct(private readonly EventSearchIndex $searchIndex)
    {
    }

    public function __invoke(): void
    {
        $this->searchIndex->reindexAll();
    }
}
