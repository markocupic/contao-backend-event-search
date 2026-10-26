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

namespace Markocupic\ContaoBackendEventSearch\Command;

use Markocupic\ContaoBackendEventSearch\Search\EventDocumentProvider;
use Markocupic\ContaoBackendEventSearch\Search\EventSearchIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'markocupic:backend-event-search:reindex',
    description: 'Rebuilds the search index of the back end event search.',
)]
class ReindexEventsCommand extends Command
{
    public function __construct(
        private readonly EventSearchIndex $searchIndex,
        private readonly EventDocumentProvider $documentProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->documentProvider->getInvalidFields() as $field) {
            $io->warning(\sprintf('The field "%s" does not exist in tl_calendar_events and is ignored.', $field));
        }

        $start = microtime(true);
        $count = $this->searchIndex->reindexAll();

        $io->success(\sprintf(
            'Indexed %d events in %.1f seconds. Searchable fields: %s',
            $count,
            microtime(true) - $start,
            implode(', ', $this->documentProvider->getSearchableFields()),
        ));

        return Command::SUCCESS;
    }
}
