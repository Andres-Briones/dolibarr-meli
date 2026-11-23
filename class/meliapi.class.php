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
 * \file    class/meliapi.class.php
 * \ingroup meli
 * \brief   Calls to Mercado Libre's API with the connected account's token.
 */

require_once __DIR__.'/meliaccount.class.php';

/**
 * API client
 *
 * Every method returns array{ok, status, data, error, message, headers, warnings}:
 *  - ok: the call succeeded (2xx);
 *  - error: '' when ok, else a short code: auth (no usable token), reconnect, http (an error
 *    status, see status), timeout, network, bad_answer (not JSON);
 *  - data: the decoded JSON body (array), empty when there is none;
 *  - warnings: what Mercado Libre attached to a successful answer (a write may be accepted with
 *    part of it ignored).
 * Nothing here throws.
 */
class MeliApi
{
	/**
	 * Most attempts for one call when Mercado Libre asks to slow down (429) or fails (5xx)
	 */
	const MAX_ATTEMPTS = 4;

	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * @var MeliAccount
	 */
	private $account;

	/**
	 * @var int	Total seconds this object may still spend waiting between attempts
	 */
	private $waitBudget;

	/**
	 * Constructor
	 *
	 * @param DoliDB	$db				Database handler
	 * @param int		$waitBudget		Seconds this client may spend waiting to retry, over all its calls
	 */
	public function __construct(DoliDB $db, $waitBudget = 30)
	{
		$this->db = $db;
		$this->account = new MeliAccount($db);
		$this->waitBudget = max(0, $waitBudget);
	}

	/**
	 * GET a resource.
	 *
	 * @param	string					$path		Path with its query, e.g. /orders/123
	 * @param	array<string, string>	$headers	Extra headers
	 * @return	array{ok: bool, status: int, data: array<mixed>, error: string, message: string, headers: array<string, string>, warnings: array<mixed>}
	 */
	public function get($path, array $headers = array())
	{
		$result = $this->call('GET', $path, null, $headers);
		unset($result['raw']);

		return $result;
	}

	/**
	 * PUT a JSON body.
	 *
	 * @param	string					$path		Path
	 * @param	array<mixed>			$body		Body, sent as JSON
	 * @param	array<string, string>	$headers	Extra headers
	 * @return	array{ok: bool, status: int, data: array<mixed>, error: string, message: string, headers: array<string, string>, warnings: array<mixed>}
	 */
	public function put($path, array $body, array $headers = array())
	{
		$result = $this->call('PUT', $path, (string) json_encode($body), $headers + array('Content-Type' => 'application/json'));
		unset($result['raw']);

		return $result;
	}

	/**
	 * GET a file (a label, for instance).
	 *
	 * @param	string					$path		Path with its query
	 * @param	array<string, string>	$headers	Extra headers
	 * @return	array{ok: bool, status: int, body: string, error: string, message: string}
	 */
	public function download($path, array $headers = array())
	{
		$raw = $this->call('GET', $path, null, $headers, true);

		return array('ok' => $raw['ok'], 'status' => $raw['status'], 'body' => $raw['raw'], 'error' => $raw['error'], 'message' => $raw['message']);
	}

	/**
	 * Send a request with the token, renewing it once on 401 and retrying on 429 and 5xx.
	 *
	 * @param	'GET'|'POST'|'PUT'|'DELETE'	$method		Method
	 * @param	string					$path		Path with its query
	 * @param	string|null				$body		Body
	 * @param	array<string, string>	$headers	Extra headers
	 * @param	bool					$binary		True when the answer is a file, not JSON
	 * @return	array{ok: bool, status: int, data: array<mixed>, error: string, message: string, headers: array<string, string>, warnings: array<mixed>, raw: string}
	 */
	private function call($method, $path, $body, array $headers, $binary = false)
	{
		$result = array('ok' => false, 'status' => 0, 'data' => array(), 'error' => 'auth', 'message' => '', 'headers' => array(), 'warnings' => array(), 'raw' => '');

		$renewed = false;
		for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
			$token = $this->account->accessToken($renewed);
			if ($token === '') {
				$result['error'] = ($this->account->error === 'reconnect' ? 'reconnect' : 'auth');
				$result['message'] = $this->account->message;
				return $result;
			}
			// The token always travels in the header, never in the address.
			$http = MeliHttp::send($method, MeliConfig::apiUrl().$path, $headers + array('Authorization' => 'Bearer '.$token), $body);
			$this->log($method, $path, $http['status'], $http['duration_ms'], $http['error'] !== '' ? $http['message'] : '');
			$result['status'] = $http['status'];
			$result['headers'] = $http['headers'];
			$result['raw'] = $http['body'];

			if ($http['error'] !== '') {
				$result['error'] = $http['error'];
				$result['message'] = $method.' '.self::bare($path).': '.$http['message'];
				// A write whose answer was lost may have been applied: the caller decides, nothing is repeated here.
				if ($method !== 'GET' || !$this->pause($attempt, 0)) {
					return $result;
				}
				continue;
			}
			if ($http['status'] === 401 && !$renewed) {
				$renewed = true;
				continue;
			}
			if (($http['status'] === 429 || $http['status'] >= 500) && $attempt < self::MAX_ATTEMPTS) {
				$retryAfter = is_numeric($http['headers']['retry-after'] ?? null) ? (int) $http['headers']['retry-after'] : 0;
				if ($this->pause($attempt, $retryAfter)) {
					continue;
				}
			}
			break;
		}

		$decoded = ($binary || $result['raw'] === '') ? array() : json_decode($result['raw'], true);
		if (!$binary && $result['raw'] !== '' && !is_array($decoded)) {
			$result['error'] = 'bad_answer';
			$result['message'] = $method.' '.self::bare($path).': HTTP '.$result['status'].', not JSON';
			return $result;
		}
		$result['data'] = is_array($decoded) ? $decoded : array();
		if ($result['status'] >= 200 && $result['status'] < 300) {
			$result['ok'] = true;
			$result['error'] = '';
			$result['warnings'] = (isset($result['data']['warnings']) && is_array($result['data']['warnings'])) ? $result['data']['warnings'] : array();
			return $result;
		}
		$result['error'] = 'http';
		$code = '';
		foreach (array('error', 'code', 'message') as $field) {
			if (isset($result['data'][$field]) && is_string($result['data'][$field]) && $code === '') {
				$code = $result['data'][$field];
			}
		}
		$result['message'] = $method.' '.self::bare($path).': HTTP '.$result['status'].($code !== '' ? ' '.$code : '');

		return $result;
	}

	/**
	 * Wait before another attempt, within the budget: exponential with jitter, or what Mercado Libre asked.
	 *
	 * @param	int	$attempt		Attempt that just failed, from 1
	 * @param	int	$retryAfter		Seconds asked by Mercado Libre, 0 if none
	 * @return	bool				False when the budget does not allow another attempt
	 */
	private function pause($attempt, $retryAfter)
	{
		$seconds = ($retryAfter > 0 ? $retryAfter : (int) min(8, 2 ** ($attempt - 1)) + random_int(0, 1));
		if ($seconds > $this->waitBudget) {
			return false;
		}
		$this->waitBudget -= $seconds;
		sleep($seconds);

		return true;
	}

	/**
	 * Keep a trace of a call: no token, no query string (it may carry personal data), no body.
	 *
	 * @param	string	$method		Method
	 * @param	string	$path		Path with its query
	 * @param	int		$status		HTTP status, 0 without answer
	 * @param	int		$duration	Milliseconds
	 * @param	string	$error		Transport error
	 * @return	void
	 */
	private function log($method, $path, $status, $duration, $error)
	{
		/** @var Conf $conf */
		global $conf;

		$sql = "INSERT INTO ".$this->db->prefix()."meli_call (entity, date_creation, method, path, status, duration_ms, error) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->idate(dol_now())."', '".$this->db->escape($method)."', '".$this->db->escape(dol_trunc(self::bare($path), 250, 'right', 'UTF-8', 1))."'";
		$sql .= ", ".((int) $status).", ".((int) $duration).", ".($error === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($error, 250, 'right', 'UTF-8', 1))."'").")";
		$this->db->query($sql);
		$this->account->save(array('last_call_at' => $this->db->idate(dol_now())));
	}

	/**
	 * A path without its query string.
	 *
	 * @param	string	$path	Path
	 * @return	string
	 */
	private static function bare($path)
	{
		$position = strpos($path, '?');

		return $position === false ? $path : substr($path, 0, $position);
	}
}
