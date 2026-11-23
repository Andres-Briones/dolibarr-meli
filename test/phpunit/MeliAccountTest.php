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
 * \file    test/phpunit/MeliAccountTest.php
 * \ingroup meli
 * \brief   Connection to Mercado Libre: authorisation with PKCE, token renewal, API client behaviour.
 */

require_once __DIR__.'/bootstrap.inc.php';

/**
 * Account and API client tests
 */
class MeliAccountTest extends MeliFakeTestCase
{
	/**
	 * The authorisation stores the seller and encrypted tokens; its state is single use.
	 *
	 * @return	void
	 */
	public function testConnect()
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$account = new MeliAccount($db);
		$url = $account->authorizationUrl($user);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
		$this->assertSame('S256', $query['code_challenge_method'] ?? null);
		$this->assertSame(self::REDIRECT, $query['redirect_uri'] ?? null);
		$this->assertArrayNotHasKey('client_secret', $query);
		$back = MeliFakeClient::redirectParams($this->fake()->request('GET', substr($url, strlen($this->fake()->authUrl()))));

		$this->assertTrue($account->finishAuthorization($user, $back['state'], $back['code']), $account->message);
		$row = $account->fetch();
		$this->assertNotNull($row);
		$this->assertSame(array(MeliAccount::CONNECTED, '123456789', 'FAKESELLER', ''), array($row['status'], $row['ml_user_id'], $row['nickname'], $row['oauth_state']));

		$grant = $this->grant();
		$this->assertNotSame($grant['access_token'], $row['access_token'], 'stored encrypted');
		$this->assertSame($grant['access_token'], dolDecrypt($row['access_token']));
		$this->assertSame($grant['refresh_token'], dolDecrypt($row['refresh_token']));

		$this->assertFalse($account->finishAuthorization($user, $back['state'], $back['code']), 'state used once');
		$this->assertSame(array('GET /authorization', 'POST /oauth/token', 'GET /users/me'), $this->calls(), 'no second token request');
	}

	/**
	 * A state that was not issued, or issued to another administrator, is refused before any call.
	 *
	 * @return	void
	 */
	public function testForeignAuthorisationRefused()
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$account = new MeliAccount($db);
		$account->authorizationUrl($user);
		$this->assertFalse($account->finishAuthorization($user, 'forged', 'TG-1'));
		$other = new User($db);
		$other->id = (int) $user->id + 100000;
		$row = $account->fetch();
		$this->assertFalse($account->finishAuthorization($other, $row === null ? '' : $row['oauth_state'], 'TG-1'));
		$this->assertSame(array(), $this->calls());
	}

	/**
	 * An expired token is renewed with the refresh token, which is replaced.
	 *
	 * @return	void
	 */
	public function testRenewal()
	{
		/** @var DoliDB $db */
		global $db;

		$this->connect();
		$before = $this->grant();
		$this->expire();

		$token = (new MeliAccount($db))->accessToken();
		$after = $this->grant();
		$this->assertSame($after['access_token'], $token);
		$this->assertNotSame($before['refresh_token'], $after['refresh_token']);
		$row = (new MeliAccount($db))->fetch();
		$this->assertSame($after['refresh_token'], dolDecrypt($row === null ? '' : $row['refresh_token']));
	}

	/**
	 * Two processes needing a new token at once: one renews, the other waits and uses it. A second
	 * renewal would reuse the single-use refresh token, which revokes the grant.
	 *
	 * @return	void
	 */
	public function testConcurrentRenewal()
	{
		/** @var DoliDB $db */
		global $db;

		$this->connect();
		$this->expire();
		$this->fake()->inject(array('mode' => 'timeout_after', 'method' => 'POST', 'path' => '/oauth/token', 'delay_ms' => 1500));
		$settings = (string) json_encode(array(
			'MELI_API_URL' => $this->fake()->apiUrl(), 'MELI_AUTH_URL' => $this->fake()->authUrl(), 'MELI_APP_ID' => self::APP_ID,
			'MELI_APP_SECRET' => dolEncrypt(self::APP_SECRET), 'MELI_REDIRECT_URL' => self::REDIRECT,
		));
		$processes = array();
		for ($i = 0; $i < 2; $i++) {
			$pipes = array();
			// Same database socket as this process: the instances are reached through it only.
			$command = array(PHP_BINARY, '-d', 'mysqli.default_socket='.ini_get('mysqli.default_socket'), __DIR__.'/worker_token.php', $settings);
			$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
			$this->assertIsResource($process);
			$processes[] = array($process, $pipes);
		}
		$hashes = array();
		foreach ($processes as $entry) {
			$hashes[] = trim((string) stream_get_contents($entry[1][1]));
			$errors = (string) stream_get_contents($entry[1][2]);
			proc_close($entry[0]);
			$this->assertSame('', trim($errors));
		}

		$grant = $this->grant();
		$this->assertSame(array(hash('sha256', $grant['access_token']), hash('sha256', $grant['access_token'])), $hashes, 'both processes got the renewed token');
		$this->assertSame(1, count(array_keys($this->calls(), 'POST /oauth/token')) - 1, 'one renewal after the authorisation');
		$row = (new MeliAccount($db))->fetch();
		$this->assertSame(MeliAccount::CONNECTED, $row === null ? '' : $row['status']);
	}

	/**
	 * A revoked grant makes the account ask to be connected again, and stops the calls.
	 *
	 * @return	void
	 */
	public function testRevokedGrant()
	{
		/** @var DoliDB $db */
		global $db;

		$this->connect();
		$this->fake()->revoke();
		$api = new MeliApi($db, 5);
		$result = $api->get('/users/me');
		$this->assertFalse($result['ok']);
		$this->assertSame('reconnect', $result['error']);
		$row = (new MeliAccount($db))->fetch();
		$this->assertSame(MeliAccount::RECONNECT, $row === null ? '' : $row['status']);

		$count = count($this->calls());
		$this->assertSame('reconnect', $api->get('/users/me')['error']);
		$this->assertCount($count, $this->calls(), 'nothing sent while the account must be connected again');
	}

	/**
	 * The token travels in the Authorization header only; the call log keeps no token, no query string.
	 *
	 * @return	void
	 */
	public function testBearerAndCallLog()
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$this->connect();
		$resql = $db->query("SELECT MAX(c.rowid) as last FROM ".$db->prefix()."meli_call as c");
		$last = ($resql && $resql !== true) ? $db->fetch_array($resql) : null;
		$after = (is_array($last) && is_numeric($last['last'])) ? (int) $last['last'] : 0;
		$result = (new MeliApi($db))->get('/users/me?attributes=id,nickname');
		$this->assertTrue($result['ok']);
		$entry = $this->fake()->log();
		$last = end($entry);
		$this->assertTrue(is_array($last) && $last['auth_header'] === true);

		$token = $this->grant()['access_token'];
		$rows = meli_test_rows("SELECT c.method, c.path, c.status, c.error FROM ".$db->prefix()."meli_call as c WHERE c.entity = ".((int) $conf->entity)." AND c.rowid > ".$after);
		$this->assertNotEmpty($rows);
		$this->assertSame(array('method' => 'GET', 'path' => '/users/me', 'status' => '200', 'error' => ''), $rows[0]);
		$leaks = meli_test_rows("SELECT COUNT(c.rowid) as nb FROM ".$db->prefix()."meli_call as c WHERE c.path LIKE '%".$db->escape($token)."%' OR c.error LIKE '%".$db->escape($token)."%'");
		$this->assertSame('0', $leaks[0]['nb']);
	}

	/**
	 * Too many requests: waits as told and tries again.
	 *
	 * @return	void
	 */
	public function testBackOff()
	{
		/** @var DoliDB $db */
		global $db;

		$this->connect();
		$this->fake()->inject(array('injections' => array(
			array('mode' => 'http_429', 'method' => 'GET', 'path' => '/users/me', 'retry_after' => 1),
			array('mode' => 'http_429', 'method' => 'GET', 'path' => '/users/me', 'empty_body' => true),
			array('mode' => 'http_503', 'method' => 'GET', 'path' => '/users/me'),
		)));
		$started = microtime(true);
		$result = (new MeliApi($db, 20))->get('/users/me');
		$this->assertTrue($result['ok'], $result['message']);
		$this->assertGreaterThanOrEqual(1.0, microtime(true) - $started, 'Retry-After respected');
		$this->assertSame(4, count(array_keys($this->calls(), 'GET /users/me')) - 1);
	}

	/**
	 * A write whose answer was lost is not sent again: it may have been applied.
	 *
	 * @return	void
	 */
	public function testLostWriteNotRepeated()
	{
		/** @var DoliDB $db */
		global $db;

		$this->connect();
		$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $this->fixtures->tag, 'quantity' => 3));
		$id = meli_test_str($item['id'] ?? '');
		$this->fake()->inject(array('mode' => 'timeout_after', 'method' => 'PUT', 'path' => '/items/', 'delay_ms' => 3000));
		meli_test_set_global('MELI_TIMEOUT', '1');
		try {
			$result = (new MeliApi($db, 20))->put('/items/'.$id, array('available_quantity' => 5));
		} finally {
			meli_test_set_global('MELI_TIMEOUT', null);
		}
		$this->assertFalse($result['ok']);
		$this->assertSame('timeout', $result['error']);
		$this->assertSame(1, count(array_keys($this->calls(), 'PUT /items/'.$id)));
	}

	/**
	 * Latest tokens issued by the fake (its tables are keyed by token, in issue order).
	 *
	 * @return	array{access_token: string, refresh_token: string}
	 */
	private function grant()
	{
		$state = $this->fake()->state();
		$access = (isset($state['access_tokens']) && is_array($state['access_tokens'])) ? array_keys($state['access_tokens']) : array();
		$refresh = (isset($state['refresh_tokens']) && is_array($state['refresh_tokens'])) ? array_keys($state['refresh_tokens']) : array();
		$this->assertNotEmpty($access, 'the fake issued a token');

		return array('access_token' => meli_test_str(end($access)), 'refresh_token' => meli_test_str(end($refresh)));
	}

	/**
	 * Make the stored token expired, at the fake and in Dolibarr.
	 *
	 * @return	void
	 */
	private function expire()
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$this->fake()->advanceTime(21601);
		$db->query("UPDATE ".$db->prefix()."meli_account SET expires_at = '".$db->idate(dol_now() - 10)."' WHERE entity = ".((int) $conf->entity));
	}
}
