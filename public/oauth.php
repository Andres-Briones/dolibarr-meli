<?php
/* Copyright (C) 2026 Briones Andres
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    public/oauth.php
 * \ingroup meli
 * \brief   Where Mercado Libre sends the administrator back after the authorisation.
 *
 * Not an anonymous page: the administrator who started the authorisation must be logged in, and the
 * state must be the one stored for them.
 */

require __DIR__.'/../lib/meliloader.inc.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once __DIR__.'/../class/meliaccount.class.php';

$langs->loadLangs(array('admin', 'meli@meli'));

if (!isModEnabled('meli') || !$user->admin) {
	accessforbidden();
}

$state = GETPOST('state', 'aZ09');
$code = GETPOST('code', 'alphanohtml');
$refusal = GETPOST('error', 'aZ09');
$setup = dol_buildpath('/meli/admin/setup.php', 1);

$account = new MeliAccount($db);
if (is_string($refusal) && $refusal !== '') {
	setEventMessages($langs->trans('MeliConnectRefused', $refusal), null, 'errors');
} elseif ($account->finishAuthorization($user, is_string($state) ? $state : '', is_string($code) ? $code : '')) {
	setEventMessages($langs->trans('MeliConnected'), null, 'mesgs');
} else {
	setEventMessages($langs->trans('MeliConnectFailed', $account->message), null, 'errors');
}

$db->close();
header('Location: '.$setup);
exit;
