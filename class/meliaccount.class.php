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
 * \file    class/meliaccount.class.php
 * \ingroup meli
 * \brief   The connected Mercado Libre account: authorisation (OAuth with PKCE) and its tokens.
 */

require_once __DIR__.'/melihttp.class.php';
require_once __DIR__.'/melilock.class.php';

/**
 * Connected account
 *
 * Mercado Libre's refresh tokens are single-use: each refresh returns a new one and the previous
 * stops working. Two processes refreshing at once would leave one of them with a dead token and
 * force the seller to authorise again, so refreshing happens under a lease, and whoever gets the
 * lease reads the row again first (someone may have refreshed in the meantime).
 */
class MeliAccount
{
	const DISCONNECTED = 'disconnected';
	const CONNECTED = 'connected';
	const RECONNECT = 'reconnect_needed';

	/**
	 * Seconds before its expiry at which an access token is renewed
	 */
	const MARGIN = 300;

	/**
	 * @var string	Short code of the last failure: not_connected, reconnect, busy, network, timeout, refused, database
	 */
	public $error = '';

	/**
	 * @var string	Detail of the last failure, safe to show and log
	 */
	public $message = '';

	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * The account row of the current entity, without its secrets in clear.
	 *
	 * @return	array<string, string>|null	Columns; null when the entity never connected an account
	 */
	public function fetch()
	{
		/** @var Conf $conf */
		global $conf;

		$sql = "SELECT a.rowid, a.status, a.ml_user_id, a.nickname, a.site_id, a.access_token, a.refresh_token, a.expires_at, a.scopes, a.tags,";
		$sql .= " a.oauth_state, a.oauth_verifier, a.oauth_user, a.oauth_expires_at, a.last_call_at, a.last_error";
		$sql .= " FROM ".$this->db->prefix()."meli_account as a WHERE a.entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql || $resql === true) {
			return null;
		}
		$raw = $this->db->fetch_array($resql);
		if (!is_array($raw)) {
			return null;
		}
		$row = array();
		foreach ($raw as $column => $value) {
			if (is_string($column)) {
				$row[$column] = is_scalar($value) ? (string) $value : '';
			}
		}

		return $row;
	}

	/**
	 * Start an authorisation: the address to send the administrator to.
	 *
	 * @param	User	$admin	Administrator starting it; only they can finish it
	 * @return	string			Address at Mercado Libre, '' if the application is not configured
	 */
	public function authorizationUrl(User $admin)
	{
		if (MeliConfig::appId() === '' || MeliConfig::appSecret() === '') {
			$this->fail('not_configured', 'Application id or secret missing');
			return '';
		}
		$state = bin2hex(random_bytes(16));
		$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
		$saved = $this->save(array(
			'oauth_state' => $state, 'oauth_verifier' => dolEncrypt($verifier), 'oauth_user' => (int) $admin->id,
			'oauth_expires_at' => $this->db->idate(dol_now() + 600),
		));
		if (!$saved) {
			return '';
		}

		return MeliConfig::authUrl().'/authorization?'.http_build_query(array(
			'response_type' => 'code', 'client_id' => MeliConfig::appId(), 'redirect_uri' => MeliConfig::redirectUrl(),
			'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
		));
	}

	/**
	 * Finish an authorisation with the code Mercado Libre gave back.
	 *
	 * @param	User	$admin	Administrator finishing it: must be the one who started it
	 * @param	string	$state	State given back
	 * @param	string	$code	Authorisation code
	 * @return	bool			False on failure: see $error and $message
	 */
	public function finishAuthorization(User $admin, $state, $code)
	{
		$row = $this->fetch();
		if ($row === null || $row['oauth_state'] === '' || !hash_equals($row['oauth_state'], $state) || (int) $row['oauth_user'] !== (int) $admin->id
			|| $this->db->jdate($row['oauth_expires_at']) < dol_now() || $code === '') {
			$this->fail('refused', 'Unknown, expired or foreign authorisation');
			return false;
		}
		// The state is single use, whatever happens next.
		$this->save(array('oauth_state' => null, 'oauth_verifier' => null, 'oauth_user' => null, 'oauth_expires_at' => null));

		$answer = $this->tokenRequest(array(
			'grant_type' => 'authorization_code', 'client_id' => MeliConfig::appId(), 'client_secret' => MeliConfig::appSecret(),
			'code' => $code, 'redirect_uri' => MeliConfig::redirectUrl(), 'code_verifier' => (string) dolDecrypt($row['oauth_verifier']),
		));
		if ($answer === null || !$this->storeTokens($answer, true)) {
			return false;
		}
		// Who was connected: shown on the setup page, and the seller id the notifications must carry.
		$token = is_string($answer['access_token'] ?? null) ? $answer['access_token'] : '';
		$http = MeliHttp::send('GET', MeliConfig::apiUrl().'/users/me', array('Authorization' => 'Bearer '.$token), '');
		$me = ($http['status'] === 200) ? json_decode($http['body'], true) : null;
		if (is_array($me)) {
			$tags = (isset($me['tags']) && is_array($me['tags'])) ? implode(',', array_filter($me['tags'], 'is_string')) : '';
			$this->save(array_filter(array(
				'ml_user_id' => is_numeric($me['id'] ?? null) ? (int) $me['id'] : null, 'nickname' => is_string($me['nickname'] ?? null) ? dol_trunc($me['nickname'], 120, 'right', 'UTF-8', 1) : null,
				'site_id' => is_string($me['site_id'] ?? null) ? substr($me['site_id'], 0, 8) : MeliConfig::SITE, 'tags' => dol_trunc($tags, 250, 'right', 'UTF-8', 1),
			), function ($value) {
				return $value !== null;
			}));
		}

		return true;
	}

	/**
	 * A valid access token, renewed when it has expired or is about to.
	 *
	 * @param	bool	$force	True to renew even if it looks valid (Mercado Libre answered 401)
	 * @return	string			'' on failure: see $error and $message
	 */
	public function accessToken($force = false)
	{
		$row = $this->fetch();
		if ($row === null || $row['status'] === self::DISCONNECTED || $row['refresh_token'] === '') {
			$this->fail('not_connected', 'No Mercado Libre account is connected');
			return '';
		}
		if ($row['status'] === self::RECONNECT) {
			$this->fail('reconnect', 'The authorisation was lost: connect the account again');
			return '';
		}
		if (!$force && $this->db->jdate($row['expires_at']) > dol_now() + self::MARGIN) {
			return (string) dolDecrypt($row['access_token']);
		}
		$stale = $row['access_token'];

		$lock = new MeliLock($this->db);
		$key = 'token:'.((int) $row['rowid']);
		// Another process renewing the token holds the lease for one call to Mercado Libre: wait for
		// it, then use what it stored. Refreshing in parallel would burn the single-use refresh token.
		$waited = 0;
		while (!$lock->acquire($key, 60)) {
			if ($waited >= 15000) {
				$this->fail('busy', 'Another process is renewing the token');
				return '';
			}
			usleep(250000);
			$waited += 250;
		}
		$token = '';
		$row = $this->fetch();
		if ($row !== null && $row['status'] === self::CONNECTED && $row['access_token'] !== $stale && $this->db->jdate($row['expires_at']) > dol_now() + self::MARGIN) {
			// Renewed by another process while this one waited.
			$token = (string) dolDecrypt($row['access_token']);
		} elseif ($row !== null && $row['status'] === self::CONNECTED) {
			$answer = $this->tokenRequest(array(
				'grant_type' => 'refresh_token', 'client_id' => MeliConfig::appId(), 'client_secret' => MeliConfig::appSecret(),
				'refresh_token' => (string) dolDecrypt($row['refresh_token']),
			));
			if ($answer !== null && $this->storeTokens($answer, false)) {
				$token = is_string($answer['access_token'] ?? null) ? $answer['access_token'] : '';
			}
		} else {
			$this->fail('reconnect', 'The authorisation was lost: connect the account again');
		}
		$lock->release($key);

		return $token;
	}

	/**
	 * Forget the account's tokens.
	 *
	 * @return	bool
	 */
	public function disconnect()
	{
		return $this->save(array('status' => self::DISCONNECTED, 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'last_error' => null));
	}

	/**
	 * Call the token endpoint.
	 *
	 * @param	array<string, string>	$fields	Form fields, sent in the body
	 * @return	array<mixed>|null		Decoded answer; null on failure
	 */
	private function tokenRequest(array $fields)
	{
		$http = MeliHttp::send('POST', MeliConfig::apiUrl().'/oauth/token', array('Content-Type' => 'application/x-www-form-urlencoded'), http_build_query($fields));
		if ($http['error'] !== '') {
			$this->fail($http['error'], 'Token: '.$http['message']);
			return null;
		}
		$answer = json_decode($http['body'], true);
		if ($http['status'] === 200 && is_array($answer) && is_string($answer['access_token'] ?? null) && is_string($answer['refresh_token'] ?? null)) {
			return $answer;
		}
		$code = (is_array($answer) && is_string($answer['error'] ?? null)) ? $answer['error'] : 'http_'.$http['status'];
		if ($code === 'invalid_grant' && ($fields['grant_type'] ?? '') === 'refresh_token') {
			// The refresh token is gone: only the seller can authorise again.
			$this->save(array('status' => self::RECONNECT, 'last_error' => 'invalid_grant'));
			$this->fail('reconnect', 'Mercado Libre refused the refresh token: connect the account again');
			return null;
		}
		$this->fail('refused', 'Token: '.$code);

		return null;
	}

	/**
	 * Keep the tokens of a token answer.
	 *
	 * @param	array<mixed>			$answer		Token answer
	 * @param	bool					$newGrant	True after an authorisation: the user and its tags are read again
	 * @return	bool
	 */
	private function storeTokens(array $answer, $newGrant)
	{
		$expires = (is_numeric($answer['expires_in'] ?? null) ? (int) $answer['expires_in'] : 0);
		$fields = array(
			'status' => self::CONNECTED,
			'access_token' => dolEncrypt(is_string($answer['access_token'] ?? null) ? $answer['access_token'] : ''),
			'refresh_token' => dolEncrypt(is_string($answer['refresh_token'] ?? null) ? $answer['refresh_token'] : ''),
			// Taken from the answer: the documentation and its own examples disagree on the lifetime.
			'expires_at' => $this->db->idate(dol_now() + max(60, $expires)),
			'scopes' => is_string($answer['scope'] ?? null) ? dol_trunc($answer['scope'], 250, 'right', 'UTF-8', 1) : null,
			'last_error' => null,
		);
		if ($newGrant && is_numeric($answer['user_id'] ?? null)) {
			$fields['ml_user_id'] = (int) $answer['user_id'];
		}
		if (!$this->save($fields)) {
			// Mercado Libre already invalidated the previous refresh token: losing this one means re-authorising.
			$this->fail('database', 'The new tokens could not be stored: '.$this->db->lasterror());
			return false;
		}

		return true;
	}

	/**
	 * Write columns of the account row, creating it the first time.
	 *
	 * @param	array<string, string|int|null>	$fields	Column => value; null writes NULL
	 * @return	bool
	 */
	public function save(array $fields)
	{
		/** @var Conf $conf */
		global $conf;

		$table = $this->db->prefix().'meli_account';
		if ($this->fetch() === null) {
			$this->db->query("INSERT INTO ".$table." (entity, date_creation) VALUES (".((int) $conf->entity).", '".$this->db->idate(dol_now())."')", 1);
		}
		$sets = array();
		foreach ($fields as $column => $value) {
			if (!preg_match('/^[a-z_]+$/', $column)) {
				continue;
			}
			$sets[] = $column.' = '.($value === null ? 'NULL' : (is_int($value) ? (string) $value : "'".$this->db->escape($value)."'"));
		}
		if (empty($sets)) {
			return true;
		}

		return (bool) $this->db->query("UPDATE ".$table." SET ".implode(', ', $sets)." WHERE entity = ".((int) $conf->entity));
	}

	/**
	 * Record a failure.
	 *
	 * @param	string	$error		Short code
	 * @param	string	$message	Detail
	 * @return	void
	 */
	private function fail($error, $message)
	{
		$this->error = $error;
		$this->message = $message;
		dol_syslog(get_class($this).' '.$error.': '.$message, LOG_WARNING);
	}
}
