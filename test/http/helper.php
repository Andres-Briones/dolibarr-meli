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
 * \file    test/http/helper.php
 * \ingroup meli
 * \brief   Database side of the HTTP scripts: settings saved and put back, fixtures of the run.
 *
 * Usage: php helper.php setup <fake url> <work dir>   prints JSON {tag, refs, redirect}
 *        php helper.php secret                        prints "encrypted" when the stored secret is not plain
 *        php helper.php teardown <work dir>     <work dir>/items lists the fake's item ids
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
$dev = getenv('DEV') ?: dirname(__DIR__, 3).'/dev';
require $dev.'/tools/vendor/autoload.php';
require dirname(__DIR__).'/phpunit/bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */
$command = $argv[1] ?? '';
$saved = array_merge(MELI_TEST_CONSTANTS, array('MELI_TIMEOUT'));

if ($command === 'setup') {
	$fake = (string) ($argv[2] ?? '');
	$work = (string) ($argv[3] ?? '');
	$links = meli_test_rows("SELECT COUNT(l.rowid) as nb FROM ".$db->prefix()."meli_link as l");
	if ($links[0]['nb'] !== '0') {
		fwrite(STDERR, "this instance has listing links: a run would mix with them\n");
		exit(1);
	}
	$account = (new MeliAccount($db))->fetch();
	if ($account !== null && $account['status'] !== MeliAccount::DISCONNECTED) {
		fwrite(STDERR, "an account is connected on this instance: not touching it\n");
		exit(1);
	}
	$backup = array();
	foreach ($saved as $name) {
		$rows = meli_test_rows("SELECT value FROM ".$db->prefix()."const WHERE name = '".$db->escape($name)."' AND entity = ".((int) $conf->entity));
		$backup[$name] = empty($rows) ? null : $rows[0]['value'];
	}
	$backup['__account'] = ($account === null ? null : 'disconnected');
	file_put_contents($work.'/consts.json', json_encode($backup));
	dolibarr_set_const($db, 'MELI_API_URL', $fake, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'MELI_AUTH_URL', $fake, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'MELI_APP_ID', '', 'chaine', 0, '', $conf->entity);
	dolibarr_del_const($db, 'MELI_APP_SECRET', $conf->entity);

	$fixtures = new MeliFixtures();
	$refs = array();
	for ($i = 0; $i < 3; $i++) {
		$refs[] = (string) $fixtures->product()->ref;
	}
	file_put_contents($work.'/tag', $fixtures->tag);
	print json_encode(array('tag' => $fixtures->tag, 'refs' => $refs, 'redirect' => MeliConfig::redirectUrl()))."\n";
	exit(0);
}

if ($command === 'secret') {
	$rows = meli_test_rows("SELECT value FROM ".$db->prefix()."const WHERE name = 'MELI_APP_SECRET' AND entity = ".((int) $conf->entity));
	$stored = empty($rows) ? '' : $rows[0]['value'];
	print ($stored !== '' && $stored !== 'fake-client-secret' && dolDecrypt($stored) === 'fake-client-secret' ? 'encrypted' : 'NOT ENCRYPTED')."\n";
	exit(0);
}

if ($command === 'teardown') {
	$work = (string) ($argv[2] ?? '');
	$status = 0;
	// Links of the fake's listings: item ids of that fake only (those without a product carry no marker).
	$items = is_file($work.'/items') ? file($work.'/items', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
	foreach (is_array($items) ? $items : array() as $item) {
		if (preg_match('/^MLA\d+$/', $item)) {
			$db->query("DELETE FROM ".$db->prefix()."meli_link WHERE item_id = '".$db->escape($item)."'");
		}
	}
	$tag = is_file($work.'/tag') ? trim((string) file_get_contents($work.'/tag')) : '';
	if ($tag !== '') {
		$fixtures = new MeliFixtures();
		$fixtures->takeOver($tag);
		try {
			$fixtures->cleanup();
		} catch (RuntimeException $e) {
			fwrite(STDERR, $e->getMessage()."\n");
			$status = 1;
		}
	}
	if (is_file($work.'/consts.json')) {
		$backup = json_decode((string) file_get_contents($work.'/consts.json'), true);
		if (is_array($backup)) {
			// The account the run connected, at the fake: removed; a disconnected row found before is left disconnected.
			if (!array_key_exists('__account', $backup) || $backup['__account'] === null) {
				$db->query("DELETE FROM ".$db->prefix()."meli_account WHERE entity = ".((int) $conf->entity));
			} else {
				(new MeliAccount($db))->disconnect();
			}
			unset($backup['__account']);
			foreach ($backup as $name => $value) {
				if ($value === null) {
					dolibarr_del_const($db, (string) $name, $conf->entity);
				} else {
					dolibarr_set_const($db, (string) $name, is_scalar($value) ? (string) $value : '', 'chaine', 0, '', $conf->entity);
				}
			}
		}
	}
	exit($status);
}

fwrite(STDERR, "usage: helper.php setup <fake url> <work dir> | secret | teardown <work dir>\n");
exit(1);
