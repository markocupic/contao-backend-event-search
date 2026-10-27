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

namespace Markocupic\ContaoBackendEventSearch;

use Markocupic\ContaoBackendEventSearch\Search\EventDocumentProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MarkocupicContaoBackendEventSearch extends AbstractBundle
{
    /**
     * Configuration (config/config.yaml):.
     *
     *   markocupic_contao_backend_event_search:
     *       searchable_fields: [title, instructors, teaser]
     *
     * The order of the searchable fields defines their weight in the
     * relevance ranking: fields listed first carry more weight.
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->arrayNode('searchable_fields')
            ->info(\sprintf('Columns of tl_calendar_events to be indexed. "%s" is a virtual field containing the names of the instructors (tl_calendar_events_instructor). The order defines the weight in the relevance ranking.', EventDocumentProvider::INSTRUCTORS_FIELD))
            ->scalarPrototype()
            ->cannotBeEmpty()
            ->validate()
            ->ifTrue(static fn (string $field): bool => !preg_match('/^[A-Za-z0-9_]+$/', $field))
            ->thenInvalid('Invalid field name %s.')
            ->end()
            ->validate()
            ->ifInArray(EventDocumentProvider::RESERVED_FIELDS)
            ->thenInvalid(\sprintf('The field %%s is reserved and cannot be used. Reserved fields: %s.', implode(', ', EventDocumentProvider::RESERVED_FIELDS)))
            ->end()
            ->end()
            ->requiresAtLeastOneElement()
            ->defaultValue(['title', EventDocumentProvider::INSTRUCTORS_FIELD])
            ->end()
            ->arrayNode('languages')
            ->info('Languages used by Loupe for tokenizing and stemming (ISO 639-1).')
            ->scalarPrototype()->end()
            ->defaultValue(['de'])
            ->end()
            ->integerNode('hits_per_page')
            ->info('Maximum number of search results shown on the back end start page.')
            ->min(1)
            ->max(200)
            ->defaultValue(25)
            ->end()
            ->scalarNode('backend_module')
            ->info('The back end module (do=…) the result links point to.')
            ->cannotBeEmpty()
            ->defaultValue('calendar')
            ->end()
            ->scalarNode('index_dir')
            ->info('Directory where Loupe stores the SQLite search index.')
            ->cannotBeEmpty()
            ->defaultValue('%kernel.project_dir%/var/backend_event_search')
            ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');

        $container->parameters()
            ->set('markocupic_contao_backend_event_search.searchable_fields', array_values(array_unique($config['searchable_fields'])))
            ->set('markocupic_contao_backend_event_search.languages', $config['languages'])
            ->set('markocupic_contao_backend_event_search.hits_per_page', $config['hits_per_page'])
            ->set('markocupic_contao_backend_event_search.backend_module', $config['backend_module'])
            ->set('markocupic_contao_backend_event_search.index_dir', $config['index_dir'])
        ;
    }
}
