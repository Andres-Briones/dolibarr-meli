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
 * \file    lib/meliloader.inc.php
 * \ingroup meli
 * \brief   Includes Dolibarr's main.inc.php from a page of the module, wherever the module is installed.
 */

$meliloaded = 0;
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT']) && is_string($_SERVER['CONTEXT_DOCUMENT_ROOT']) && is_file(str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php')) {
	$meliloaded = include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
}
// The module directory may be a symbolic link below htdocs/custom: start from the script as the web server sees it.
$meliscript = (isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '');
$melidir = dirname($meliscript);
for ($melilevel = 0; $melilevel < 4 && !$meliloaded && $melidir !== '' && $melidir !== '.' && $melidir !== '/'; $melilevel++) {
	$melidir = dirname($melidir);
	if (is_file($melidir.'/main.inc.php')) {
		$meliloaded = include $melidir.'/main.inc.php';
	}
}
if (!$meliloaded && is_file(__DIR__.'/../../main.inc.php')) {
	$meliloaded = include __DIR__.'/../../main.inc.php';
}
if (!$meliloaded && is_file(__DIR__.'/../../../main.inc.php')) {
	$meliloaded = include __DIR__.'/../../../main.inc.php';
}
if (!$meliloaded) {
	die('Include of main fails');
}
