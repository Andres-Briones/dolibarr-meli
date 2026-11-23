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
 * \file    test/fake/webhook.php
 * \ingroup meli
 * \brief   Stand-in for the module's webhook in the fake's self-test: a router for php -S that records what it receives.
 *
 * Run with: MELI_WEBHOOK_LOG=/path/log.jsonl php -S 127.0.0.1:<port> test/fake/webhook.php
 *
 * POST /ok answers 200, /fail answers 500, /slow?ms=700 waits then answers 200. Each request is appended to the log as
 * one JSON line {path, body}. GET /ok answers 200 (readiness probe).
 */

if (PHP_SAPI !== 'cli-server') {
	fwrite(STDERR, "This script is a router for PHP's built-in web server\n");
	exit(1);
}

$melihookuri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$melihookpath = (string) parse_url($melihookuri, PHP_URL_PATH);
$melihooklog = getenv('MELI_WEBHOOK_LOG');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_string($melihooklog) && $melihooklog !== '') {
	file_put_contents($melihooklog, json_encode(array('path' => $melihookpath, 'body' => (string) file_get_contents('php://input')))."\n", FILE_APPEND | LOCK_EX);
}
if ($melihookpath === '/slow') {
	$melihookquery = array();
	parse_str((string) parse_url($melihookuri, PHP_URL_QUERY), $melihookquery);
	$melihookms = $melihookquery['ms'] ?? '700';
	usleep((is_numeric($melihookms) ? (int) $melihookms : 700) * 1000);
}
http_response_code($melihookpath === '/fail' ? 500 : 200);
header('Content-Type: application/json');
print '{}';

return true;
