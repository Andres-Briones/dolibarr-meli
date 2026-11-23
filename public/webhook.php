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
 * \file    public/webhook.php
 * \ingroup meli
 * \brief   Notifications callback of the Mercado Libre application. Stores and answers: nothing else.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Do not use GETPOST here, function is not defined and the entity must be known before including main.inc.php
$entity = (!empty($_GET['entity']) && is_numeric($_GET['entity']) ? (int) $_GET['entity'] : 1);
define('DOLENTITY', $entity);

require __DIR__.'/../lib/meliloader.inc.php';
/**
 * @var DoliDB $db
 */
require_once __DIR__.'/../class/meliinbox.class.php';

$status = 405;
$allowed = true;
$sources = trim(getDolGlobalString('MELI_WEBHOOK_IPS'));
if ($sources !== '') {
	$remote = getUserRemoteIP();
	$allowed = in_array($remote, array_map('trim', explode(',', $sources)), true);
}
if (!isModEnabled('meli')) {
	$status = 404;
} elseif (!$allowed) {
	$status = 403;
} elseif (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$body = file_get_contents('php://input', false, null, 0, 16384);
	$status = (new MeliInbox($db))->receive(is_string($body) ? $body : '');
}
$db->close();

http_response_code($status);
header('Content-Type: text/plain; charset=UTF-8');
print ($status === 200 ? 'ok' : 'error');
