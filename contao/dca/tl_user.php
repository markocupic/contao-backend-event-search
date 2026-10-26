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

use Contao\CoreBundle\DataContainer\PaletteManipulator;

PaletteManipulator::create()
	->addLegend('backend_event_search_legend', 'calendars_legend', PaletteManipulator::POSITION_AFTER)
	->addField('backendEventSearchp', 'backend_event_search_legend', PaletteManipulator::POSITION_APPEND)
	->applyToPalette('extend', 'tl_user')
	->applyToPalette('custom', 'tl_user')
;

$GLOBALS['TL_DCA']['tl_user']['fields']['backendEventSearchp'] = [
	'inputType' => 'checkbox',
	'options'   => ['search'],
	'reference' => &$GLOBALS['TL_LANG']['tl_user']['backendEventSearchp_options'],
	'eval'      => ['multiple' => true],
	'sql'       => 'blob NULL',
];
