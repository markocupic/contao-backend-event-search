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

namespace Markocupic\ContaoBackendEventSearch\Security;

/**
 * The permission is stored in tl_user.backendEventSearchp and
 * tl_user_group.backendEventSearchp. Contao merges both values
 * ($GLOBALS['TL_PERMISSIONS']) and the BackendAccessVoter of the
 * core decides about "contao_user.*" attributes. Administrators
 * are always granted.
 */
final class BackendEventSearchPermissions
{
    public const USER_CAN_SEARCH_EVENTS = 'contao_user.backendEventSearchp.search';
}
