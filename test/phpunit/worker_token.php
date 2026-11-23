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
 * \file    test/phpunit/worker_token.php
 * \ingroup meli
 * \brief   A second process asking for the access token, for the test of concurrent renewals.
 *
 * Usage: php worker_token.php <json settings>; prints a hash of the token it got ('' on failure),
 * never the token.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
define('NOSESSION', 1);
$doliSrc = getenv('DOLI_SRC');
if (empty($doliSrc) || !is_file($doliSrc.'/htdocs/master.inc.php')) {
	fwrite(STDERR, "DOLI_SRC must point to a configured Dolibarr source tree\n");
	exit(1);
}
require_once $doliSrc.'/htdocs/master.inc.php';
if (!function_exists('dolEncrypt')) {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
}
require_once dirname(__DIR__, 2).'/class/meliapi.class.php';

/** @var Conf $conf */
$settings = json_decode(isset($argv[1]) ? $argv[1] : '', true);
foreach (is_array($settings) ? $settings : array() as $name => $value) {
	if (is_string($name) && is_string($value)) {
		$conf->global->$name = $value;
	}
}
/** @var DoliDB $db */
$token = (new MeliAccount($db))->accessToken();
print ($token === '' ? '' : hash('sha256', $token))."\n";
