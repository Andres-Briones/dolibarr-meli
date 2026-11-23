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
 * \file    test/fake/router.php
 * \ingroup meli
 * \brief   Fake Mercado Libre Argentina API (OAuth, users, items, stock, orders, shipments, labels, notifications)
 *          for the tests. Never used by the module itself, never talks to Mercado Libre.
 *
 * Run with: MELI_FAKE_STATE=/path/state.json PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:<port> test/fake/router.php
 *
 * The same router serves the AUTH base (GET /authorization) and the API base (everything else).
 * Test only: /__test/... (see README.md).
 */

/**
 * Fake Mercado Libre. One instance handles one request against the state loaded from the state file.
 *
 * @phpstan-type FakeDelivery array{url:string,body:string,delay_ms:int,timeout_ms:int}
 * @phpstan-type FakeDeliveryResult array{url:string,body:string,status:int,duration_ms:int,error:string}
 * @phpstan-type FakeReply array{status:int,headers:array<string,string>,body:string,delay_ms:int,drop:bool,deliveries:list<FakeDelivery>}
 * @phpstan-type FakeRow array<string,mixed>
 */
class MeliFakeRouter
{
	const TIMEZONE = 'America/Argentina/Buenos_Aires';
	const SITE = 'MLA';

	/**
	 * @var list<string> Injection modes
	 */
	const MODES = array('http_429', 'http_500', 'http_503', 'timeout_before', 'timeout_after', 'drop_after', 'forbidden_policies', 'warnings', 'bump_version_after');

	/**
	 * @var list<string> Item statuses accepted by PUT /items/{id}
	 */
	const ITEM_STATUSES = array('active', 'paused', 'closed');

	/**
	 * @var list<string> Shipment statuses accepted by the control endpoint
	 */
	const SHIPMENT_STATUSES = array('pending', 'handling', 'ready_to_ship', 'shipped', 'delivered', 'not_delivered', 'cancelled');

	/**
	 * @var array<string,mixed> State of the fake, saved by the caller after the request
	 */
	private $state;

	/**
	 * @var array<string,mixed> Entry of the request log for the current request
	 */
	private $log = array();

	/**
	 * @var list<FakeDelivery> Notifications to POST to the webhook once the state lock is released
	 */
	private $deliveries = array();

	/**
	 * @var array<string,string> Request headers, names in lower case
	 */
	private $headers = array();

	/**
	 * @var array<string,string> Query string parameters (names kept as sent: dots are not turned into underscores)
	 */
	private $query = array();

	/**
	 * @var int Mercado Libre user id of the access token of the current request
	 */
	private $userId = 0;

	/**
	 * Constructor.
	 *
	 * @param	array<string,mixed>	$state	State loaded from the state file
	 */
	public function __construct(array $state)
	{
		$this->state = $state;
	}

	/**
	 * State after the request, to be saved.
	 *
	 * @return	array<string,mixed>
	 */
	public function getState()
	{
		return $this->state;
	}

	/**
	 * Configuration defaults. All values are fake: no real credential is ever needed.
	 *
	 * @return	array<string,mixed>
	 */
	public static function defaultConfig()
	{
		return array(
			'client_id' => '1234567890123456',
			'client_secret' => 'fake-client-secret',
			'app_id' => '1234567890123456',
			'redirect_uri' => 'https://shop.example.test/custom/meli/public/oauth.php',
			'user' => array('id' => 123456789, 'nickname' => 'FAKESELLER', 'tags' => array('normal', 'user_product_seller')),
			'expires_in' => 21600,
			'refresh_ttl' => 15552000,
			'code_ttl' => 600,
			'pkce_required' => true,
			'authorize_outcome' => 'allow',
			'revoke_on_reuse' => true,
			'webhook_url' => '',
			'webhook_max_ms' => 500,
			'webhook_timeout_ms' => 5000,
			'missed_feeds_retention' => 172800,
			'test_users_max' => 10,
		);
	}

	// ----------------------------------------------------------------------------------------------
	// Typed access to decoded JSON

	/**
	 * Read a value of unknown type as a string.
	 *
	 * @param	mixed	$value	Value
	 * @return	string
	 */
	public static function str($value)
	{
		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * Read a value of unknown type as an integer.
	 *
	 * @param	mixed	$value	Value
	 * @return	int
	 */
	public static function int($value)
	{
		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Read a value of unknown type as a float.
	 *
	 * @param	mixed	$value	Value
	 * @return	float
	 */
	public static function float($value)
	{
		return is_numeric($value) ? (float) $value : 0.0;
	}

	/**
	 * Read a value of unknown type as an array with string keys.
	 *
	 * @param	mixed	$value	Value
	 * @return	array<string,mixed>
	 */
	public static function map($value)
	{
		$result = array();
		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$result[(string) $key] = $item;
			}
		}
		return $result;
	}

	/**
	 * Read a value of unknown type as a list.
	 *
	 * @param	mixed	$value	Value
	 * @return	list<mixed>
	 */
	public static function lst($value)
	{
		return is_array($value) ? array_values($value) : array();
	}

	/**
	 * Read a value of unknown type as a list of strings.
	 *
	 * @param	mixed	$value	Value
	 * @return	list<string>
	 */
	public static function strs($value)
	{
		$result = array();
		foreach (self::lst($value) as $item) {
			$result[] = self::str($item);
		}
		return $result;
	}

	/**
	 * Parse a query string or a form-encoded body without PHP's renaming of dots and spaces to underscores.
	 *
	 * @param	string	$query	Query string
	 * @return	array<string,string>
	 */
	public static function parseQuery($query)
	{
		$result = array();
		foreach (explode('&', $query) as $pair) {
			if ($pair === '') {
				continue;
			}
			$parts = explode('=', $pair, 2);
			$result[urldecode($parts[0])] = urldecode($parts[1] ?? '');
		}
		return $result;
	}

	/**
	 * Split a comma-separated list, trimmed, without empty entries.
	 *
	 * @param	string	$value	List
	 * @return	list<string>
	 */
	public static function csv($value)
	{
		$result = array();
		foreach (explode(',', $value) as $part) {
			if (trim($part) !== '') {
				$result[] = trim($part);
			}
		}
		return $result;
	}

	/**
	 * Configuration of the fake.
	 *
	 * @return	array<string,mixed>
	 */
	private function config()
	{
		return array_merge(self::defaultConfig(), self::map($this->state['config'] ?? null));
	}

	/**
	 * One configuration value.
	 *
	 * @param	string	$key	Key
	 * @return	mixed
	 */
	private function cfg($key)
	{
		$config = $this->config();
		return $config[$key] ?? null;
	}

	/**
	 * The configured seller.
	 *
	 * @return	array{id:int,nickname:string,tags:list<string>}
	 */
	private function seller()
	{
		$user = array_merge(self::map(self::defaultConfig()['user']), self::map($this->cfg('user')));
		return array('id' => self::int($user['id'] ?? 0), 'nickname' => self::str($user['nickname'] ?? ''), 'tags' => self::strs($user['tags'] ?? null));
	}

	/**
	 * A table of the state: rows by id.
	 *
	 * @param	string	$name	Table
	 * @return	array<string,FakeRow>
	 */
	private function table($name)
	{
		$rows = array();
		foreach (self::map($this->state[$name] ?? null) as $id => $row) {
			$rows[$id] = self::map($row);
		}
		return $rows;
	}

	/**
	 * One row of a table.
	 *
	 * @param	string		$name	Table
	 * @param	string		$id		Id
	 * @return	FakeRow|null
	 */
	private function row($name, $id)
	{
		$rows = $this->table($name);
		return $rows[$id] ?? null;
	}

	/**
	 * Write one row of a table.
	 *
	 * @param	string	$name	Table
	 * @param	string	$id		Id
	 * @param	FakeRow		$row	FakeRow
	 * @return	void
	 */
	private function put($name, $id, array $row)
	{
		$rows = $this->table($name);
		$rows[$id] = $row;
		$this->state[$name] = $rows;
	}

	/**
	 * Next number of the fake's id sequence.
	 *
	 * @return	int
	 */
	private function nextId()
	{
		$next = self::int($this->state['seq'] ?? 0) + 1;
		$this->state['seq'] = $next;
		return $next;
	}

	/**
	 * Random lower-case hexadecimal string.
	 *
	 * @param	int<1,max>	$bytes	Random bytes
	 * @return	string
	 */
	private static function hex($bytes)
	{
		return bin2hex(random_bytes($bytes));
	}

	// ----------------------------------------------------------------------------------------------
	// Fake time

	/**
	 * Current fake time (real time plus what advance_time added).
	 *
	 * @return	int
	 */
	private function now()
	{
		return time() + self::int($this->state['time_offset'] ?? 0);
	}

	/**
	 * Date in the format of Mercado Libre's resources (Argentina offset).
	 *
	 * @param	int		$timestamp	Unix time
	 * @return	string
	 */
	public static function iso($timestamp)
	{
		return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d\TH:i:s.vP');
	}

	/**
	 * Date in the format of the notifications (UTC, Z).
	 *
	 * @param	int		$timestamp	Unix time
	 * @return	string
	 */
	private static function isoUtc($timestamp)
	{
		return gmdate('Y-m-d\TH:i:s', $timestamp).'.000Z';
	}

	// ----------------------------------------------------------------------------------------------
	// Answers

	/**
	 * Build an answer.
	 *
	 * @param	int						$status		HTTP status
	 * @param	string					$type		Content type
	 * @param	string					$body		Body
	 * @param	array<string,string>	$headers	Extra headers
	 * @return	FakeReply
	 */
	private static function reply($status, $type, $body, array $headers = array())
	{
		$headers['Content-Type'] = $type;
		return array('status' => $status, 'headers' => $headers, 'body' => $body, 'delay_ms' => 0, 'drop' => false, 'deliveries' => array());
	}

	/**
	 * JSON answer.
	 *
	 * @param	int						$status		HTTP status
	 * @param	mixed					$data		Data
	 * @param	array<string,string>	$headers	Extra headers
	 * @return	FakeReply
	 */
	private static function json($status, $data, array $headers = array())
	{
		return self::reply($status, 'application/json;charset=UTF-8', (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $headers);
	}

	/**
	 * Error in Mercado Libre's usual shape {message, error, status, cause}.
	 *
	 * @param	int			$status		HTTP status
	 * @param	string		$error		Error code
	 * @param	string		$message	Message
	 * @param	list<mixed>	$cause		Causes
	 * @return	FakeReply
	 */
	private static function error($status, $error, $message, array $cause = array())
	{
		return self::json($status, array('message' => $message, 'error' => $error, 'status' => $status, 'cause' => $cause));
	}

	/**
	 * Decode a JSON body into a map, null when it is not a JSON object.
	 *
	 * @param	string	$rawbody	Body
	 * @return	array<string,mixed>|null
	 */
	private static function jsonBody($rawbody)
	{
		$data = json_decode($rawbody, true);
		return is_array($data) && !array_is_list($data) ? self::map($data) : (is_array($data) && $data === array() ? array() : null);
	}

	/**
	 * Copy of a row without the fake's internal keys (those starting with an underscore).
	 *
	 * @param	FakeRow		$row	FakeRow
	 * @return	FakeRow
	 */
	private static function visible(array $row)
	{
		$result = array();
		foreach ($row as $key => $value) {
			if (strpos($key, '_') !== 0) {
				$result[$key] = $value;
			}
		}
		return $result;
	}

	// ----------------------------------------------------------------------------------------------
	// Request handling

	/**
	 * Handle one request.
	 *
	 * @param	string					$method		HTTP method
	 * @param	string					$path		Path without query string
	 * @param	array<string,string>	$query		Query string parameters
	 * @param	array<string,string>	$headers	Request headers, names in lower case
	 * @param	string					$rawbody	Body
	 * @return	FakeReply
	 */
	public function handle($method, $path, array $query, array $headers, $rawbody)
	{
		$this->query = $query;
		$this->headers = $headers;
		if (strpos($path, '/__test/') === 0) {
			$reply = $this->control($method, $path, $rawbody);
			$reply['deliveries'] = $this->deliveries;
			return $reply;
		}

		$this->log = array('time' => self::iso($this->now()), 'method' => $method, 'path' => $path, 'auth_header' => isset($headers['authorization']));
		$injection = $this->takeInjection($method, $path);
		$mode = self::str($injection['mode'] ?? '');
		$reply = $this->early($mode, $injection ?? array());
		if ($reply === null) {
			$reply = $this->route($method, $path, $rawbody);
			if ($mode === 'timeout_after') {
				$reply['delay_ms'] = max(0, self::int($injection['delay_ms'] ?? 2000));
			} elseif ($mode === 'drop_after') {
				$reply['drop'] = true;
			} elseif ($mode === 'warnings') {
				$reply = self::addWarnings($reply, $injection ?? array());
			} elseif ($mode === 'bump_version_after') {
				$this->bumpAfter($path, $injection ?? array());
			}
		}
		$this->log['status'] = $reply['status'];
		$log = self::lst($this->state['log'] ?? null);
		$log[] = $this->log;
		$this->state['log'] = $log;
		return $reply;
	}

	/**
	 * Take the first queued injection that matches a request.
	 *
	 * @param	string	$method		Method
	 * @param	string	$path		Path
	 * @return	array<string,mixed>|null
	 */
	private function takeInjection($method, $path)
	{
		$queue = self::lst($this->state['injections'] ?? null);
		foreach ($queue as $index => $item) {
			$injection = self::map($item);
			$targetMethod = strtoupper(self::str($injection['method'] ?? '*'));
			$targetPath = self::str($injection['path'] ?? '*');
			if (($targetMethod === '*' || $targetMethod === $method) && ($targetPath === '*' || strpos($path, $targetPath) === 0)) {
				array_splice($queue, $index, 1);
				$this->state['injections'] = $queue;
				$this->log['injected'] = self::str($injection['mode'] ?? '');
				return $injection;
			}
		}
		return null;
	}

	/**
	 * After answering a request about a user product, change it as a sale on Mercado Libre's side
	 * would: its version goes up (and, with "sell", its selling_address quantity goes down). The
	 * client's next write with the version it just read is then refused with 409.
	 *
	 * @param	string				$path		Path of the request, /user-products/{id}/...
	 * @param	array<string,mixed>	$injection	Injection, with optional "sell" (units)
	 * @return	void
	 */
	private function bumpAfter($path, array $injection)
	{
		if (!preg_match('#^/user-products/([^/]+)#', $path, $match)) {
			return;
		}
		$up = $this->row('user_products', $match[1]);
		if ($up === null) {
			return;
		}
		$sell = max(0, self::int($injection['sell'] ?? 0));
		if ($sell > 0) {
			$selling = 0;
			foreach (self::lst($up['locations'] ?? null) as $location) {
				$location = self::map($location);
				if (self::str($location['type'] ?? '') === 'selling_address') {
					$selling = self::int($location['quantity'] ?? 0);
				}
			}
			// setUpStock() raises the version too.
			$this->setUpStock($match[1], 'selling_address', max(0, $selling - $sell));
			return;
		}
		$up['version'] = self::int($up['version'] ?? 0) + 1;
		$this->put('user_products', $match[1], $up);
	}

	/**
	 * Answer of an injection that stops the request before it is processed.
	 *
	 * @param	string				$mode		Injection mode, '' for none
	 * @param	array<string,mixed>	$injection	Injection
	 * @return	FakeReply|null						Null when the request must be processed
	 */
	private function early($mode, array $injection)
	{
		if ($mode === 'http_429') {
			$headers = array();
			if (isset($injection['retry_after'])) {
				$headers['Retry-After'] = self::str($injection['retry_after']);
			}
			if (!empty($injection['empty_body'])) {
				return self::reply(429, 'application/json;charset=UTF-8', '', $headers);
			}
			$reply = self::error(429, 'too_many_requests', 'Too Many Requests');
			$reply['headers'] = array_merge($reply['headers'], $headers);
			return $reply;
		}
		if ($mode === 'http_500') {
			return self::error(500, 'internal_server_error', 'Internal server error');
		}
		if ($mode === 'http_503') {
			return self::error(503, 'service_unavailable', 'Service unavailable');
		}
		if ($mode === 'forbidden_policies') {
			return self::error(403, 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES', 'At least one policy returned UNAUTHORIZED.', array('PA_UNAUTHORIZED_RESULT_FROM_POLICIES'));
		}
		if ($mode === 'timeout_before') {
			$reply = self::error(504, 'gateway_timeout', 'Gateway timeout (fake: not processed)');
			$reply['delay_ms'] = max(0, self::int($injection['delay_ms'] ?? 2000));
			return $reply;
		}
		return null;
	}

	/**
	 * Add a warnings array to a successful JSON object answer.
	 *
	 * @param	FakeReply				$reply		Answer
	 * @param	array<string,mixed>	$injection	Injection, may carry its own warnings list
	 * @return	FakeReply
	 */
	private static function addWarnings(array $reply, array $injection)
	{
		$data = json_decode($reply['body'], true);
		if ($reply['status'] >= 300 || !is_array($data) || array_is_list($data)) {
			return $reply;
		}
		$warnings = self::lst($injection['warnings'] ?? null);
		if (!count($warnings)) {
			$warnings = array(array(
				'department' => 'items', 'cause_id' => 0, 'type' => 'warning', 'code' => 'item.fake.warning',
				'references' => array(), 'message' => 'Fake warning: the request was accepted but part of it was ignored.',
			));
		}
		$data['warnings'] = $warnings;
		$reply['body'] = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		return $reply;
	}

	/**
	 * Route a Mercado Libre request.
	 *
	 * @param	string	$method		Method
	 * @param	string	$path		Path
	 * @param	string	$rawbody	Body
	 * @return	FakeReply
	 */
	private function route($method, $path, $rawbody)
	{
		$m = array();
		if ($path === '/authorization') {
			return $method === 'GET' ? $this->authorize() : self::error(405, 'method_not_allowed', 'Method not allowed');
		}
		if ($path === '/oauth/token') {
			return $method === 'POST' ? $this->token($rawbody) : self::error(405, 'method_not_allowed', 'Method not allowed');
		}

		$denied = $this->authenticate();
		if ($denied !== null) {
			return $denied;
		}

		if ($method === 'GET') {
			if ($path === '/users/me') {
				return $this->getUser($this->userId);
			}
			if (preg_match('#^/users/(\d+)/items/search$#', $path, $m)) {
				return $this->searchItems((int) $m[1]);
			}
			if (preg_match('#^/users/(\d+)$#', $path, $m)) {
				return $this->getUser((int) $m[1]);
			}
			if ($path === '/items/bulk') {
				return $this->bulkItems();
			}
			if ($path === '/items') {
				return self::error(400, 'deprecated', 'The multiget /items?ids= is deprecated, use /items/bulk?ids=');
			}
			if (preg_match('#^/items/([A-Z]{3}\d+)$#', $path, $m)) {
				$item = $this->row('items', $m[1]);
				return $item !== null ? self::json(200, $item) : self::error(404, 'not_found', 'Item with id '.$m[1].' not found');
			}
			if (preg_match('#^/user-products/([A-Z]{4}\d+)/stock$#', $path, $m)) {
				return $this->getStock($m[1]);
			}
			if ($path === '/orders/search') {
				return $this->searchOrders();
			}
			if (preg_match('#^/orders/billing-info/([A-Z]{3})/(\w+)$#', $path, $m)) {
				return $this->billingInfo($m[1], $m[2]);
			}
			if (preg_match('#^/orders/(\d+)$#', $path, $m)) {
				return $this->getOrder($m[1]);
			}
			if (preg_match('#^/packs/(\d+)$#', $path, $m)) {
				return $this->getPack($m[1]);
			}
			if (preg_match('#^/shipments/(\d+)(/costs)?$#', $path, $m)) {
				return $this->getShipment($m[1], isset($m[2]));
			}
			if ($path === '/shipment_labels') {
				return $this->labels();
			}
			if ($path === '/missed_feeds') {
				return $this->missedFeeds();
			}
		}
		if ($method === 'POST' && $path === '/users/test_user') {
			return $this->createTestUser($rawbody);
		}
		if ($method === 'PUT' && preg_match('#^/items/([A-Z]{3}\d+)$#', $path, $m)) {
			return $this->putItem($m[1], $rawbody);
		}
		if ($method === 'PUT' && preg_match('#^/user-products/([A-Z]{4}\d+)/stock/type/(\w+)$#', $path, $m)) {
			return $this->putStock($m[1], $m[2], $rawbody);
		}
		return self::error(404, 'not_found', 'Resource '.$path.' not found');
	}

	// ----------------------------------------------------------------------------------------------
	// OAuth

	/**
	 * Check the access token of the request (Authorization: Bearer only) and remember its user.
	 *
	 * @return	FakeReply|null	Null when the token is valid
	 */
	private function authenticate()
	{
		if (isset($this->query['access_token'])) {
			return self::error(401, 'unauthorized', 'access_token in the query string is not accepted, send it in the Authorization header');
		}
		$header = $this->headers['authorization'] ?? '';
		if (!preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
			return self::error(401, 'unauthorized', 'authorization value not present');
		}
		$token = $this->row('access_tokens', $m[1]);
		$grant = $token !== null ? $this->row('grants', self::str($token['grant'] ?? '')) : null;
		if ($token === null || $grant === null || !empty($grant['revoked']) || self::int($token['expires_at'] ?? 0) <= $this->now()) {
			return self::error(401, 'not_found', 'invalid_token');
		}
		$this->userId = self::int($token['user_id'] ?? 0);
		return null;
	}

	/**
	 * GET /authorization: the consent page, answered at once with a redirect back to the application.
	 *
	 * @return	FakeReply
	 */
	private function authorize()
	{
		$q = $this->query;
		$redirect = $q['redirect_uri'] ?? '';
		if (($q['client_id'] ?? '') !== self::str($this->cfg('client_id'))) {
			return self::reply(400, 'text/html;charset=UTF-8', '<html><body>Fake Mercado Libre: unknown client_id</body></html>');
		}
		if ($redirect !== self::str($this->cfg('redirect_uri'))) {
			return self::reply(400, 'text/html;charset=UTF-8', '<html><body>Fake Mercado Libre: redirect_uri does not match the application</body></html>');
		}
		$state = $q['state'] ?? '';
		$back = array();
		if (($q['response_type'] ?? '') !== 'code') {
			$back = array('error' => 'unsupported_response_type', 'error_description' => 'response_type must be code');
		} elseif (!empty($this->cfg('pkce_required')) && ($q['code_challenge'] ?? '') === '') {
			$back = array('error' => 'invalid_request', 'error_description' => 'code_challenge is required');
		} elseif (isset($q['code_challenge']) && !in_array($q['code_challenge_method'] ?? 'plain', array('S256', 'plain'), true)) {
			$back = array('error' => 'invalid_request', 'error_description' => 'code_challenge_method must be S256 or plain');
		} elseif (self::str($this->cfg('authorize_outcome')) === 'deny') {
			$back = array('error' => 'access_denied', 'error_description' => 'The user denied the authorization');
		} else {
			$seller = $this->seller();
			$code = 'TG-'.self::hex(12).'-'.$seller['id'];
			$this->put('codes', $code, array(
				'client_id' => $q['client_id'], 'redirect_uri' => $redirect, 'user_id' => $seller['id'], 'used' => false,
				'challenge' => $q['code_challenge'] ?? '', 'method' => $q['code_challenge_method'] ?? 'plain',
				'expires_at' => $this->now() + self::int($this->cfg('code_ttl')),
			));
			$back = array('code' => $code);
		}
		if ($state !== '') {
			$back['state'] = $state;
		}
		$location = $redirect.(strpos($redirect, '?') === false ? '?' : '&').http_build_query($back);
		return self::reply(302, 'text/html;charset=UTF-8', '', array('Location' => $location));
	}

	/**
	 * Error of the token endpoint.
	 *
	 * @param	string	$error		OAuth error
	 * @param	string	$message	Message
	 * @param	int		$status		HTTP status
	 * @return	FakeReply
	 */
	private static function oauthError($error, $message, $status = 400)
	{
		return self::json($status, array('error_description' => $message, 'error' => $error, 'status' => $status, 'cause' => array()));
	}

	/**
	 * POST /oauth/token: authorization_code and refresh_token grants, parameters in a form-encoded body only.
	 *
	 * @param	string	$rawbody	Body
	 * @return	FakeReply
	 */
	private function token($rawbody)
	{
		foreach (array('grant_type', 'client_id', 'client_secret', 'code', 'refresh_token', 'code_verifier', 'redirect_uri') as $name) {
			if (isset($this->query[$name])) {
				return self::oauthError('invalid_request', 'OAuth parameters must be sent in the body, not in the query string');
			}
		}
		$type = strtolower($this->headers['content-type'] ?? '');
		if (strpos($type, 'application/x-www-form-urlencoded') !== 0) {
			return self::oauthError('invalid_request', 'Content-Type must be application/x-www-form-urlencoded');
		}
		$form = self::parseQuery($rawbody);
		if (($form['client_id'] ?? '') !== self::str($this->cfg('client_id')) || ($form['client_secret'] ?? '') !== self::str($this->cfg('client_secret'))) {
			return self::oauthError('invalid_client', 'invalid client_id or client_secret');
		}
		$grantType = $form['grant_type'] ?? '';
		if ($grantType === 'authorization_code') {
			return $this->exchangeCode($form);
		}
		if ($grantType === 'refresh_token') {
			return $this->refresh($form);
		}
		return self::oauthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token');
	}

	/**
	 * Exchange an authorization code.
	 *
	 * @param	array<string,string>	$form	Body
	 * @return	FakeReply
	 */
	private function exchangeCode(array $form)
	{
		$codeValue = $form['code'] ?? '';
		$code = $this->row('codes', $codeValue);
		if ($code === null || !empty($code['used']) || self::int($code['expires_at'] ?? 0) <= $this->now()) {
			return self::oauthError('invalid_grant', 'Error validating grant. Your authorization code or refresh token may be expired or it was already used');
		}
		$code['used'] = true;
		$this->put('codes', $codeValue, $code);
		if (($form['redirect_uri'] ?? '') !== self::str($code['redirect_uri'] ?? '')) {
			return self::oauthError('invalid_grant', 'the redirect_uri does not match the one of the authorization request');
		}
		$challenge = self::str($code['challenge'] ?? '');
		if ($challenge !== '') {
			$verifier = $form['code_verifier'] ?? '';
			$computed = self::str($code['method'] ?? '') === 'S256' ? rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=') : $verifier;
			if ($verifier === '' || !hash_equals($challenge, $computed)) {
				return self::oauthError('invalid_grant', 'code_verifier does not match the code_challenge');
			}
		}
		$grantId = 'G'.$this->nextId();
		$userId = self::int($code['user_id'] ?? 0);
		$this->put('grants', $grantId, array('user_id' => $userId, 'revoked' => false, 'created' => $this->now()));
		return self::json(200, $this->issue($grantId, $userId));
	}

	/**
	 * Refresh: the refresh token is single use; reusing one is answered invalid_grant and, when configured, revokes the grant.
	 *
	 * @param	array<string,string>	$form	Body
	 * @return	FakeReply
	 */
	private function refresh(array $form)
	{
		$value = $form['refresh_token'] ?? '';
		$refresh = $this->row('refresh_tokens', $value);
		$invalid = self::oauthError('invalid_grant', 'Error validating grant. Your authorization code or refresh token may be expired or it was already used');
		if ($refresh === null) {
			return $invalid;
		}
		$grantId = self::str($refresh['grant'] ?? '');
		$grant = $this->row('grants', $grantId);
		if ($grant === null || !empty($grant['revoked']) || self::int($refresh['expires_at'] ?? 0) <= $this->now()) {
			return $invalid;
		}
		if (!empty($refresh['used'])) {
			if (!empty($this->cfg('revoke_on_reuse'))) {
				$grant['revoked'] = true;
				$grant['revoked_reason'] = 'refresh_token_reused';
				$this->put('grants', $grantId, $grant);
			}
			return $invalid;
		}
		$refresh['used'] = true;
		$this->put('refresh_tokens', $value, $refresh);
		return self::json(200, $this->issue($grantId, self::int($grant['user_id'] ?? 0)));
	}

	/**
	 * Issue a new access token and refresh token for a grant.
	 *
	 * @param	string	$grantId	Grant
	 * @param	int		$userId		User
	 * @return	array<string,mixed>	Token endpoint answer
	 */
	private function issue($grantId, $userId)
	{
		$expiresIn = self::int($this->cfg('expires_in'));
		$access = 'APP_USR-'.self::str($this->cfg('client_id')).'-'.gmdate('mdHi', $this->now()).'-'.self::hex(16).'-'.$userId;
		$refresh = 'TG-'.self::hex(12).'-'.$userId;
		$this->put('access_tokens', $access, array('grant' => $grantId, 'user_id' => $userId, 'expires_at' => $this->now() + $expiresIn));
		$this->put('refresh_tokens', $refresh, array('grant' => $grantId, 'used' => false, 'expires_at' => $this->now() + self::int($this->cfg('refresh_ttl'))));
		return array(
			'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $expiresIn,
			'scope' => 'offline_access read write', 'user_id' => $userId, 'refresh_token' => $refresh,
		);
	}

	// ----------------------------------------------------------------------------------------------
	// Users

	/**
	 * GET /users/me and /users/{id}.
	 *
	 * @param	int		$id		User id
	 * @return	FakeReply
	 */
	private function getUser($id)
	{
		$seller = $this->seller();
		if ($id === $seller['id']) {
			return self::json(200, array(
				'id' => $seller['id'], 'nickname' => $seller['nickname'], 'registration_date' => '2020-01-15T10:00:00.000-03:00',
				'first_name' => 'Fake', 'last_name' => 'Seller', 'country_id' => 'AR', 'site_id' => self::SITE, 'user_type' => 'normal',
				'tags' => $seller['tags'], 'permalink' => 'http://perfil.mercadolibre.com.ar/'.$seller['nickname'],
				'seller_reputation' => array('level_id' => null, 'power_seller_status' => null, 'transactions' => array('total' => 0, 'completed' => 0, 'canceled' => 0)),
				'status' => array('site_status' => 'active'),
			));
		}
		$test = $this->row('test_users', (string) $id);
		if ($test !== null) {
			return self::json(200, array(
				'id' => $id, 'nickname' => $test['nickname'] ?? '', 'country_id' => 'AR', 'site_id' => self::SITE, 'user_type' => 'normal',
				'tags' => array('normal', 'test_user'), 'status' => array('site_status' => 'active'),
			));
		}
		return self::error(404, 'not_found', 'User not found');
	}

	/**
	 * POST /users/test_user.
	 *
	 * @param	string	$rawbody	Body {"site_id":"MLA"}
	 * @return	FakeReply
	 */
	private function createTestUser($rawbody)
	{
		$body = self::jsonBody($rawbody);
		if ($body === null || self::str($body['site_id'] ?? '') === '') {
			return self::error(400, 'bad_request', 'site_id is required');
		}
		if (count($this->table('test_users')) >= self::int($this->cfg('test_users_max'))) {
			return self::error(400, 'bad_request', 'You have reached the maximum number of test users');
		}
		$id = 1000000000 + $this->nextId();
		$nickname = 'TESTUSER'.$id;
		$this->put('test_users', (string) $id, array('nickname' => $nickname, 'site_id' => self::str($body['site_id'])));
		return self::json(201, array(
			'id' => $id, 'nickname' => $nickname, 'password' => 'qatest'.random_int(1000, 9999),
			'site_status' => 'active', 'email' => 'test_user_'.$id.'@testuser.com',
		));
	}

	// ----------------------------------------------------------------------------------------------
	// Items and stock

	/**
	 * Value of the SELLER_SKU attribute in a list of attributes.
	 *
	 * @param	mixed	$attributes		Attributes
	 * @return	string|null
	 */
	private static function sku($attributes)
	{
		foreach (self::lst($attributes) as $attribute) {
			$attribute = self::map($attribute);
			if (self::str($attribute['id'] ?? '') === 'SELLER_SKU') {
				return self::str($attribute['value_name'] ?? '');
			}
		}
		return null;
	}

	/**
	 * SELLER_SKU attribute.
	 *
	 * @param	string	$sku	SKU
	 * @return	array<string,mixed>
	 */
	private static function skuAttribute($sku)
	{
		return array('id' => 'SELLER_SKU', 'name' => 'SKU', 'value_id' => null, 'value_name' => $sku, 'values' => array(array('id' => null, 'name' => $sku, 'struct' => null)), 'value_type' => 'string');
	}

	/**
	 * Pause an item at quantity 0 and reactivate it when stock comes back (only when it was paused for that reason).
	 *
	 * @param	FakeRow		$item	Item
	 * @return	FakeRow
	 */
	private static function autoStatus(array $item)
	{
		$qty = self::int($item['available_quantity'] ?? 0);
		$status = self::str($item['status'] ?? '');
		if ($qty === 0 && $status === 'active') {
			$item['status'] = 'paused';
			$item['sub_status'] = array('out_of_stock');
		} elseif ($qty > 0 && $status === 'paused' && in_array('out_of_stock', self::strs($item['sub_status'] ?? null), true)) {
			$item['status'] = 'active';
			$item['sub_status'] = array();
		}
		return $item;
	}

	/**
	 * Total quantity of a user product over its stock locations.
	 *
	 * @param	FakeRow		$up		User product
	 * @return	int
	 */
	private static function upTotal(array $up)
	{
		$total = 0;
		foreach (self::lst($up['locations'] ?? null) as $location) {
			$total += self::int(self::map($location)['quantity'] ?? 0);
		}
		return $total;
	}

	/**
	 * Set one stock location of a user product, bump its version and propagate the total to its items.
	 *
	 * @param	string	$upId		User product id
	 * @param	string	$type		Location type
	 * @param	int		$quantity	Quantity
	 * @return	void
	 */
	private function setUpStock($upId, $type, $quantity)
	{
		$up = $this->row('user_products', $upId);
		if ($up === null) {
			return;
		}
		$locations = array();
		$found = false;
		foreach (self::lst($up['locations'] ?? null) as $location) {
			$location = self::map($location);
			if (self::str($location['type'] ?? '') === $type) {
				$location['quantity'] = $quantity;
				$found = true;
			}
			$locations[] = $location;
		}
		if (!$found) {
			$locations[] = array('type' => $type, 'quantity' => $quantity);
		}
		$up['locations'] = $locations;
		$up['version'] = self::int($up['version'] ?? 0) + 1;
		$this->put('user_products', $upId, $up);
		$total = self::upTotal($up);
		foreach ($this->table('items') as $id => $item) {
			if (self::str($item['user_product_id'] ?? '') === $upId && self::str($item['status'] ?? '') !== 'closed') {
				$item['available_quantity'] = $total;
				$item['last_updated'] = self::iso($this->now());
				$this->put('items', $id, self::autoStatus($item));
			}
		}
	}

	/**
	 * Build an item from a seed request.
	 *
	 * @param	array<string,mixed>	$body	{model: up|legacy, id?, sku?, title?, family_name?, price?, quantity?, status?,
	 *										user_product_id?, category_id?, variations?: [{id?, sku, quantity, attribute_combinations?}]}
	 * @return	FakeRow
	 */
	private function buildItem(array $body)
	{
		$seller = $this->seller();
		$model = self::str($body['model'] ?? 'up');
		$id = self::str($body['id'] ?? '');
		if ($id === '') {
			$id = self::SITE.(1000000000 + $this->nextId());
		}
		$title = self::str($body['title'] ?? 'Item de Prueba - Por favor, NO OFERTAR');
		$sku = self::str($body['sku'] ?? '');
		$price = isset($body['price']) ? self::float($body['price']) : 1000.0;
		$quantity = isset($body['quantity']) ? self::int($body['quantity']) : 1;
		$attributes = $sku !== '' ? array(self::skuAttribute($sku)) : array();
		$variations = array();
		$upId = null;
		$family = null;
		if ($model === 'legacy') {
			foreach (self::lst($body['variations'] ?? null) as $index => $variation) {
				$variation = self::map($variation);
				$vsku = self::str($variation['sku'] ?? '');
				$combinations = self::lst($variation['attribute_combinations'] ?? null);
				if (!count($combinations)) {
					$combinations = array(array('id' => 'COLOR', 'name' => 'Color', 'value_id' => null, 'value_name' => 'Variante '.($index + 1)));
				}
				$variations[] = array(
					'id' => isset($variation['id']) ? self::int($variation['id']) : 170000000000 + $this->nextId(),
					'price' => $price, 'attribute_combinations' => $combinations,
					'available_quantity' => self::int($variation['quantity'] ?? 1), 'sold_quantity' => 0,
					'attributes' => $vsku !== '' ? array(self::skuAttribute($vsku)) : array(), 'picture_ids' => array(),
					'user_product_id' => null,
				);
			}
			if (count($variations)) {
				$quantity = 0;
				foreach ($variations as $variation) {
					$quantity += $variation['available_quantity'];
				}
			}
		} else {
			$upId = self::str($body['user_product_id'] ?? '');
			if ($upId === '') {
				$upId = self::SITE.'U'.(100000000 + $this->nextId());
			}
			$family = self::str($body['family_name'] ?? $title);
			$up = $this->row('user_products', $upId);
			if ($up === null) {
				$up = array('id' => $upId, 'user_id' => $seller['id'], 'version' => 1, 'locations' => array(array('type' => 'selling_address', 'quantity' => $quantity)));
				$this->put('user_products', $upId, $up);
			}
			$quantity = self::upTotal($up);
		}
		$now = self::iso($this->now());
		return array(
			'id' => $id, 'site_id' => self::SITE, 'title' => $title, 'family_name' => $family, 'user_product_id' => $upId,
			'seller_id' => $seller['id'], 'category_id' => self::str($body['category_id'] ?? 'MLA1574'), 'price' => $price,
			'currency_id' => 'ARS', 'initial_quantity' => $quantity, 'available_quantity' => $quantity, 'sold_quantity' => 0,
			'buying_mode' => 'buy_it_now', 'listing_type_id' => 'gold_special', 'condition' => 'new',
			'permalink' => 'https://articulo.mercadolibre.com.ar/'.substr($id, 0, 3).'-'.substr($id, 3).'-fake', 'status' => self::str($body['status'] ?? 'active'),
			'sub_status' => array(), 'tags' => self::strs($body['tags'] ?? null), 'attributes' => $model === 'legacy' && count($variations) ? array() : $attributes,
			'variations' => $variations, 'inventory_id' => null, 'date_created' => $now, 'last_updated' => $now,
		);
	}

	/**
	 * GET /users/{id}/items/search: ids of the seller's items, with offset paging or search_type=scan.
	 *
	 * @param	int		$id		Seller id
	 * @return	FakeReply
	 */
	private function searchItems($id)
	{
		if ($id !== $this->userId) {
			return self::error(403, 'forbidden', 'Caller is not the owner of the items');
		}
		$q = $this->query;
		$statuses = isset($q['status']) && $q['status'] !== '' ? explode(',', $q['status']) : array('active', 'paused', 'inactive', 'under_review');
		$limit = isset($q['limit']) ? (int) $q['limit'] : 50;
		if ($limit < 1 || $limit > 100) {
			return self::error(400, 'bad_request', 'limit must be between 1 and 100');
		}
		$ids = array();
		foreach ($this->table('items') as $itemId => $item) {
			if (self::int($item['seller_id'] ?? 0) !== $id || !in_array(self::str($item['status'] ?? ''), $statuses, true)) {
				continue;
			}
			if (isset($q['seller_sku']) && !self::itemHasSku($item, $q['seller_sku'])) {
				continue;
			}
			$ids[] = $itemId;
		}
		sort($ids);
		$total = count($ids);
		$answer = array('seller_id' => (string) $id, 'query' => null, 'orders' => array(array('id' => 'stop_time_asc', 'name' => 'Order by stop time asc')), 'available_orders' => array());
		if (($q['search_type'] ?? '') === 'scan') {
			$offset = isset($q['scroll_id']) ? (int) base64_decode(substr($q['scroll_id'], 5)) : 0;
			$answer['results'] = array_slice($ids, $offset, $limit);
			$answer['scroll_id'] = 'scan:'.base64_encode((string) ($offset + $limit));
			$answer['paging'] = array('limit' => $limit, 'total' => $total);
			return self::json(200, $answer);
		}
		$offset = isset($q['offset']) ? (int) $q['offset'] : 0;
		if ($offset < 0 || $offset + $limit > 1000) {
			return self::error(400, 'bad_request', 'offset + limit must not exceed 1000, use search_type=scan');
		}
		$answer['results'] = array_slice($ids, $offset, $limit);
		$answer['paging'] = array('limit' => $limit, 'offset' => $offset, 'total' => $total);
		return self::json(200, $answer);
	}

	/**
	 * Whether an item or one of its variations carries a SELLER_SKU.
	 *
	 * @param	FakeRow		$item	Item
	 * @param	string	$sku	SKU
	 * @return	bool
	 */
	private static function itemHasSku(array $item, $sku)
	{
		if (self::sku($item['attributes'] ?? null) === $sku) {
			return true;
		}
		foreach (self::lst($item['variations'] ?? null) as $variation) {
			if (self::sku(self::map($variation)['attributes'] ?? null) === $sku) {
				return true;
			}
		}
		return false;
	}

	/**
	 * GET /items/bulk?ids=: at most 20 items, each as {code, body}.
	 *
	 * @return	FakeReply
	 */
	private function bulkItems()
	{
		$ids = self::csv($this->query['ids'] ?? '');
		if (!count($ids)) {
			return self::error(400, 'bad_request', 'ids is required');
		}
		if (count($ids) > 20) {
			return self::error(400, 'bad_request', 'The number of ids must not exceed 20');
		}
		$answer = array();
		foreach ($ids as $id) {
			$item = $this->row('items', trim($id));
			if ($item === null) {
				$answer[] = array('code' => 404, 'body' => array('message' => 'Item with id '.$id.' not found', 'error' => 'not_found', 'status' => 404, 'cause' => array()));
			} else {
				$answer[] = array('code' => 200, 'body' => $item);
			}
		}
		return self::json(200, $answer);
	}

	/**
	 * PUT /items/{id}: available_quantity (item, or per variation for legacy items), status, price.
	 *
	 * @param	string	$id			Item id
	 * @param	string	$rawbody	Body
	 * @return	FakeReply
	 */
	private function putItem($id, $rawbody)
	{
		$item = $this->row('items', $id);
		if ($item === null) {
			return self::error(404, 'not_found', 'Item with id '.$id.' not found');
		}
		$body = self::jsonBody($rawbody);
		if ($body === null || !count($body)) {
			return self::error(400, 'bad_request', 'Invalid body');
		}
		if (self::int($item['seller_id'] ?? 0) !== $this->userId) {
			return self::error(403, 'forbidden', 'Caller is not the owner of the item');
		}
		if (self::str($item['status'] ?? '') === 'closed') {
			return self::error(400, 'field_not_updatable', 'Cannot update item '.$id.' [status:closed]', array(array('code' => 'item.status.closed', 'message' => 'Closed items cannot be modified')));
		}
		$variations = self::lst($item['variations'] ?? null);
		if (array_key_exists('variations', $body)) {
			if (!count($variations)) {
				$message = self::str($item['family_name'] ?? '') !== '' ? 'The field variations is invalid with family name' : 'Item '.$id.' has no variations';
				return self::error(400, 'validation_error', $message, array(array('code' => 'item.variations.invalid', 'message' => $message)));
			}
			$changes = array();
			foreach (self::lst($body['variations']) as $change) {
				$change = self::map($change);
				$changes[self::str($change['id'] ?? '')] = $change;
			}
			$total = 0;
			$updated = array();
			foreach ($variations as $variation) {
				$variation = self::map($variation);
				$variationId = self::str($variation['id'] ?? '');
				if (isset($changes[$variationId])) {
					if (array_key_exists('available_quantity', $changes[$variationId])) {
						$qty = $changes[$variationId]['available_quantity'];
						if (!is_int($qty) || $qty < 0) {
							return self::error(400, 'validation_error', 'available_quantity must be a non-negative integer');
						}
						$variation['available_quantity'] = $qty;
					}
					unset($changes[$variationId]);
				}
				$total += self::int($variation['available_quantity'] ?? 0);
				$updated[] = $variation;
			}
			if (count($changes)) {
				return self::error(400, 'validation_error', 'Variation '.implode(',', array_keys($changes)).' not found in item '.$id);
			}
			$item['variations'] = $updated;
			$item['available_quantity'] = $total;
		}
		if (array_key_exists('available_quantity', $body)) {
			$qty = $body['available_quantity'];
			if (count($variations)) {
				return self::error(400, 'validation_error', 'item.available_quantity.invalid: the item has variations, send variations[].available_quantity');
			}
			if (!is_int($qty) || $qty < 0) {
				return self::error(400, 'validation_error', 'available_quantity must be a non-negative integer');
			}
			$upId = self::str($item['user_product_id'] ?? '');
			if ($upId !== '' && $this->row('user_products', $upId) !== null) {
				// ML propagates a quantity written on one item to every item of the same user product.
				$this->setUpStock($upId, 'selling_address', $qty);
				$item = $this->row('items', $id) ?? $item;
			} else {
				$item['available_quantity'] = $qty;
			}
		}
		if (array_key_exists('price', $body)) {
			if (!is_numeric($body['price']) || self::float($body['price']) <= 0) {
				return self::error(400, 'validation_error', 'price must be a positive number');
			}
			$item['price'] = self::float($body['price']);
		}
		if (array_key_exists('status', $body)) {
			$status = self::str($body['status']);
			if (!in_array($status, self::ITEM_STATUSES, true)) {
				return self::error(400, 'validation_error', 'status must be one of '.implode(', ', self::ITEM_STATUSES));
			}
			$item['status'] = $status;
			$item['sub_status'] = array();
		}
		$item = self::autoStatus($item);
		$item['last_updated'] = self::iso($this->now());
		$this->put('items', $id, $item);
		return self::json(200, $item);
	}

	/**
	 * Stock answer of a user product.
	 *
	 * @param	FakeRow		$up		User product
	 * @return	FakeReply
	 */
	private static function stockReply(array $up)
	{
		$body = array('locations' => self::lst($up['locations'] ?? null), 'user_id' => self::int($up['user_id'] ?? 0), 'id' => self::str($up['id'] ?? ''));
		return self::json(200, $body, array('x-version' => (string) self::int($up['version'] ?? 0)));
	}

	/**
	 * GET /user-products/{id}/stock.
	 *
	 * @param	string	$upId	User product id
	 * @return	FakeReply
	 */
	private function getStock($upId)
	{
		$up = $this->row('user_products', $upId);
		if ($up === null) {
			return self::error(404, 'not_found', 'User product '.$upId.' not found');
		}
		if (self::int($up['user_id'] ?? 0) !== $this->userId) {
			return self::error(403, 'forbidden', 'Caller is not the owner of the user product');
		}
		return self::stockReply($up);
	}

	/**
	 * PUT /user-products/{id}/stock/type/selling_address with the x-version header (optimistic locking).
	 *
	 * @param	string	$upId		User product id
	 * @param	string	$type		Location type
	 * @param	string	$rawbody	Body {"quantity": n}
	 * @return	FakeReply
	 */
	private function putStock($upId, $type, $rawbody)
	{
		$up = $this->row('user_products', $upId);
		if ($up === null) {
			return self::error(404, 'not_found', 'User product '.$upId.' not found');
		}
		if (self::int($up['user_id'] ?? 0) !== $this->userId) {
			return self::error(403, 'forbidden', 'Caller is not the owner of the user product');
		}
		if ($type !== 'selling_address') {
			return self::error(400, 'bad_request', 'The fake only writes the selling_address location');
		}
		$version = $this->headers['x-version'] ?? '';
		if ($version === '' || !ctype_digit($version)) {
			return self::error(400, 'bad_request', 'x-version header is required');
		}
		if ((int) $version !== self::int($up['version'] ?? 0)) {
			return self::error(409, 'conflict', 'Version mismatch: the stock was modified, read it again');
		}
		$body = self::jsonBody($rawbody);
		$qty = $body['quantity'] ?? null;
		if (!is_int($qty) || $qty < 0) {
			return self::error(400, 'validation_error', 'quantity must be a non-negative integer');
		}
		$this->setUpStock($upId, 'selling_address', $qty);
		return self::stockReply($this->row('user_products', $upId) ?? $up);
	}

	// ----------------------------------------------------------------------------------------------
	// Orders, packs, billing info

	/**
	 * GET /orders/{id}.
	 *
	 * @param	string	$id		Order id
	 * @return	FakeReply
	 */
	private function getOrder($id)
	{
		$order = $this->row('orders', $id);
		if ($order === null) {
			return self::error(404, 'not_found', 'Order not found');
		}
		if (self::int(self::map($order['seller'] ?? null)['id'] ?? 0) !== $this->userId) {
			return self::error(403, 'forbidden', 'Caller is not the seller of the order');
		}
		return self::json(200, $order);
	}

	/**
	 * GET /packs/{id}. An order without pack is served as a pack of one under its own id.
	 *
	 * @param	string	$id		Pack id (or order id)
	 * @return	FakeReply
	 */
	private function getPack($id)
	{
		$pack = $this->row('packs', $id);
		if ($pack === null) {
			$order = $this->row('orders', $id);
			if ($order === null || ($order['pack_id'] ?? null) !== null) {
				return self::error(404, 'not_found', 'Pack not found');
			}
			$pack = array('id' => (int) $id, '_orders' => array((int) $id), 'shipment' => array('id' => self::map($order['shipping'] ?? null)['id'] ?? null),
				'buyer' => array('id' => self::map($order['buyer'] ?? null)['id'] ?? null), 'date_created' => $order['date_created'] ?? null);
		}
		$orders = array();
		$cancelled = 0;
		foreach (self::lst($pack['_orders'] ?? null) as $orderId) {
			$order = $this->row('orders', self::str($orderId));
			$orders[] = array('id' => self::int($orderId));
			if ($order !== null && self::str($order['status'] ?? '') === 'cancelled') {
				$cancelled++;
			}
		}
		$pack['orders'] = $orders;
		$pack['status'] = $cancelled === count($orders) ? 'cancelled' : 'released';
		$pack['status_detail'] = null;
		$pack['family_pack_id'] = null;
		$pack['last_updated'] = self::iso($this->now());
		return self::json(200, self::visible($pack));
	}

	/**
	 * GET /orders/search?seller=&order.date_last_updated.from=&order.date_last_updated.to=&offset=&limit=.
	 *
	 * @return	FakeReply
	 */
	private function searchOrders()
	{
		$q = $this->query;
		if (!isset($q['seller']) || (int) $q['seller'] !== $this->userId) {
			return self::error(403, 'forbidden', 'seller must be the caller');
		}
		$limit = isset($q['limit']) ? (int) $q['limit'] : 50;
		$offset = isset($q['offset']) ? (int) $q['offset'] : 0;
		if ($limit < 1 || $limit > 51 || $offset < 0) {
			return self::error(400, 'bad_request', 'limit must be between 1 and 51');
		}
		$from = isset($q['order.date_last_updated.from']) ? strtotime($q['order.date_last_updated.from']) : null;
		$to = isset($q['order.date_last_updated.to']) ? strtotime($q['order.date_last_updated.to']) : null;
		if ($from === false || $to === false) {
			return self::error(400, 'bad_request', 'Invalid date in order.date_last_updated');
		}
		$found = array();
		foreach ($this->table('orders') as $order) {
			$updated = (int) strtotime(self::str($order['date_last_updated'] ?? ''));
			if (($from !== null && $updated < $from) || ($to !== null && $updated > $to)) {
				continue;
			}
			if (isset($q['order.status']) && self::str($order['status'] ?? '') !== $q['order.status']) {
				continue;
			}
			$found[] = array($updated, $order);
		}
		usort($found, array(self::class, 'compareFirst'));
		$results = array();
		foreach (array_slice($found, $offset, $limit) as $entry) {
			$results[] = $entry[1];
		}
		return self::json(200, array(
			'query' => null, 'display' => 'complete', 'results' => $results, 'filters' => array(),
			'sort' => array('id' => 'date_asc', 'name' => 'Date ascending'), 'available_sorts' => array(),
			'paging' => array('total' => count($found), 'offset' => $offset, 'limit' => $limit),
		));
	}

	/**
	 * Comparison of two entries by their first element, then by the order id.
	 *
	 * @param	array{0:int,1:FakeRow}	$a	Entry
	 * @param	array{0:int,1:FakeRow}	$b	Entry
	 * @return	int
	 */
	public static function compareFirst(array $a, array $b)
	{
		return $a[0] <=> $b[0] ?: self::int($a[1]['id'] ?? 0) <=> self::int($b[1]['id'] ?? 0);
	}

	/**
	 * GET /orders/billing-info/{site}/{billing_info_id}.
	 *
	 * @param	string	$site	Site
	 * @param	string	$id		Billing info id
	 * @return	FakeReply
	 */
	private function billingInfo($site, $id)
	{
		$billing = $this->row('billing', $id);
		if ($site !== self::SITE || $billing === null) {
			return self::error(404, 'not_found', 'Billing info not found');
		}
		return self::json(200, array(
			'site_id' => self::SITE,
			'buyer' => array('cust_id' => $billing['_buyer_id'] ?? null, 'billing_info' => self::visible($billing)),
			'seller' => array('cust_id' => $this->seller()['id']),
		));
	}

	/**
	 * Decrement the stock of a sale, as Mercado Libre does when an order is created.
	 *
	 * @param	string		$itemId			Item
	 * @param	int|null	$variationId	Variation of a legacy item
	 * @param	int			$quantity		Units sold
	 * @return	void
	 */
	private function sell($itemId, $variationId, $quantity)
	{
		$item = $this->row('items', $itemId);
		if ($item === null) {
			return;
		}
		$upId = self::str($item['user_product_id'] ?? '');
		$up = $upId !== '' ? $this->row('user_products', $upId) : null;
		if ($up !== null) {
			$selling = 0;
			foreach (self::lst($up['locations'] ?? null) as $location) {
				$location = self::map($location);
				if (self::str($location['type'] ?? '') === 'selling_address') {
					$selling = self::int($location['quantity'] ?? 0);
				}
			}
			$this->setUpStock($upId, 'selling_address', max(0, $selling - $quantity));
			$item = $this->row('items', $itemId) ?? $item;
		} elseif ($variationId !== null) {
			$variations = array();
			$total = 0;
			foreach (self::lst($item['variations'] ?? null) as $variation) {
				$variation = self::map($variation);
				if (self::int($variation['id'] ?? 0) === $variationId) {
					$variation['available_quantity'] = max(0, self::int($variation['available_quantity'] ?? 0) - $quantity);
					$variation['sold_quantity'] = self::int($variation['sold_quantity'] ?? 0) + $quantity;
				}
				$total += self::int($variation['available_quantity'] ?? 0);
				$variations[] = $variation;
			}
			$item['variations'] = $variations;
			$item['available_quantity'] = $total;
		} else {
			$item['available_quantity'] = max(0, self::int($item['available_quantity'] ?? 0) - $quantity);
		}
		$item['sold_quantity'] = self::int($item['sold_quantity'] ?? 0) + $quantity;
		$this->put('items', $itemId, self::autoStatus($item));
	}

	/**
	 * POST /__test/order: a sale (one order per line, a pack when there are several lines), with buyer, billing info and shipment.
	 *
	 * @param	array<string,mixed>	$body	Request (see README.md)
	 * @return	FakeReply
	 */
	private function seedOrder(array $body)
	{
		$seller = $this->seller();
		$lines = self::lst($body['items'] ?? null);
		if (!count($lines)) {
			return self::json(400, array('error' => 'items is required: [{item_id, variation_id?, quantity?, unit_price?, sale_fee?}]'));
		}
		$now = $this->now();
		$created = isset($body['date_created']) ? (int) strtotime(self::str($body['date_created'])) : $now;
		$status = self::str($body['status'] ?? 'paid');
		$buyer = self::map($body['buyer'] ?? null);
		$buyerId = isset($buyer['id']) ? self::int($buyer['id']) : 300000000 + $this->nextId();
		$nickname = self::str($buyer['nickname'] ?? 'TESTBUYER'.$buyerId);

		$billingId = null;
		if (($body['billing'] ?? array()) !== false) {
			$billingId = (string) (9000000000 + $this->nextId());
			$this->put('billing', $billingId, $this->buildBilling($billingId, $buyerId, self::map($body['billing'] ?? null)));
		}

		$withPack = isset($body['pack']) ? !empty($body['pack']) : count($lines) > 1;
		$packId = $withPack ? 2000000000000000 + $this->nextId() : null;

		$shipmentId = null;
		$receiverCost = 0.0;
		$shipmentSpec = array_key_exists('shipment', $body) ? $body['shipment'] : array();
		if ($shipmentSpec !== false && $shipmentSpec !== null) {
			$shipmentId = 40000000000 + $this->nextId();
			$spec = self::map($body['shipment'] ?? null);
			$receiverCost = self::float($spec['receiver_cost'] ?? 0);
		}

		$orderIds = array();
		foreach ($lines as $index => $line) {
			$line = self::map($line);
			$itemId = self::str($line['item_id'] ?? '');
			$item = $this->row('items', $itemId);
			if ($item === null) {
				return self::json(400, array('error' => 'unknown item '.$itemId));
			}
			$variationId = isset($line['variation_id']) ? self::int($line['variation_id']) : null;
			$sku = self::sku($item['attributes'] ?? null);
			$variationAttributes = array();
			foreach (self::lst($item['variations'] ?? null) as $variation) {
				$variation = self::map($variation);
				if ($variationId !== null && self::int($variation['id'] ?? 0) === $variationId) {
					$sku = self::sku($variation['attributes'] ?? null);
					$variationAttributes = self::lst($variation['attribute_combinations'] ?? null);
				}
			}
			$quantity = isset($line['quantity']) ? self::int($line['quantity']) : 1;
			$unitPrice = isset($line['unit_price']) ? self::float($line['unit_price']) : self::float($item['price'] ?? 0);
			$saleFee = isset($line['sale_fee']) ? self::float($line['sale_fee']) : round($unitPrice * 0.13, 2);
			$total = round($unitPrice * $quantity, 2);
			$shippingShare = $index === 0 ? $receiverCost : 0.0;
			$orderId = 2000000000000000 + $this->nextId();
			$orderIds[] = $orderId;
			$tags = $packId !== null ? array('pack_order', 'not_delivered') : array('not_delivered');
			if ($status === 'paid') {
				$tags[] = 'paid';
			}
			$this->put('orders', (string) $orderId, array(
				'id' => $orderId, 'status' => $status, 'status_detail' => null,
				'date_created' => self::iso($created), 'date_closed' => self::iso($created), 'last_updated' => self::iso($now), 'date_last_updated' => self::iso($now),
				'pack_id' => $packId, 'fulfilled' => null, 'buying_mode' => 'buy_equals_pay',
				'order_items' => array(array(
					'item' => array(
						'id' => $itemId, 'title' => self::str($item['title'] ?? ''), 'category_id' => self::str($item['category_id'] ?? ''),
						'variation_id' => $variationId, 'seller_sku' => $sku, 'seller_custom_field' => null, 'variation_attributes' => $variationAttributes,
					),
					'quantity' => $quantity, 'unit_price' => $unitPrice, 'full_unit_price' => $unitPrice, 'currency_id' => 'ARS',
					'sale_fee' => $saleFee, 'listing_type_id' => self::str($item['listing_type_id'] ?? 'gold_special'),
				)),
				'total_amount' => $total, 'paid_amount' => $status === 'paid' ? round($total + $shippingShare, 2) : 0.0, 'currency_id' => 'ARS',
				'payments' => array(array(
					'id' => 80000000000 + $this->nextId(), 'order_id' => $orderId, 'payer_id' => $buyerId, 'collector' => array('id' => $seller['id']),
					'currency_id' => 'ARS', 'status' => $status === 'paid' ? 'approved' : 'pending', 'status_detail' => $status === 'paid' ? 'accredited' : 'pending_waiting_payment',
					'transaction_amount' => $total, 'shipping_cost' => $shippingShare, 'total_paid_amount' => round($total + $shippingShare, 2),
					'date_approved' => $status === 'paid' ? self::iso($created) : null, 'date_created' => self::iso($created), 'date_last_modified' => self::iso($now),
					'payment_method_id' => 'visa', 'payment_type' => 'credit_card', 'installments' => 1,
				)),
				'shipping' => array('id' => $shipmentId), 'seller' => array('id' => $seller['id']),
				'buyer' => array('id' => $buyerId, 'nickname' => $nickname, 'billing_info' => $billingId !== null ? array('id' => $billingId) : null),
				'tags' => $tags, 'cancel_detail' => null, 'taxes' => array('amount' => null, 'currency_id' => null, 'id' => null),
				'context' => array('channel' => 'marketplace', 'site' => self::SITE, 'flows' => array()),
			));
			if ($status === 'paid' && ($body['decrement_stock'] ?? true) !== false) {
				$this->sell($itemId, $variationId, $quantity);
			}
		}

		if ($packId !== null) {
			$this->put('packs', (string) $packId, array(
				'id' => $packId, '_orders' => $orderIds, 'shipment' => array('id' => $shipmentId),
				'buyer' => array('id' => $buyerId), 'date_created' => self::iso($created),
			));
		}
		if ($shipmentId !== null) {
			$this->put('shipments', (string) $shipmentId, $this->buildShipment($shipmentId, $buyerId, $orderIds, self::map($body['shipment'] ?? null), $created));
		}
		if (!empty($body['notify'])) {
			foreach ($orderIds as $orderId) {
				$this->queueNotification('orders_v2', '/orders/'.$orderId, 1, 1, 0);
			}
		}
		return self::json(200, array('pack_id' => $packId, 'order_ids' => $orderIds, 'shipment_id' => $shipmentId, 'billing_info_id' => $billingId, 'buyer_id' => $buyerId));
	}

	/**
	 * Billing info of a buyer.
	 *
	 * @param	string				$id			Billing info id
	 * @param	int					$buyerId	Buyer
	 * @param	array<string,mixed>	$spec		{name, last_name, doc_type, doc_number, taxpayer_type, address{...}}
	 * @return	FakeRow
	 */
	private function buildBilling($id, $buyerId, array $spec)
	{
		$address = array_merge(array(
			'street_name' => 'Calle Falsa', 'street_number' => '123', 'city_name' => 'Rosario', 'state' => array('code' => 'AR-S', 'name' => 'Santa Fe'),
			'zip_code' => '2000', 'country_id' => 'AR', 'comment' => null,
		), self::map($spec['address'] ?? null));
		return array(
			'id' => $id, '_buyer_id' => $buyerId,
			'name' => self::str($spec['name'] ?? 'Juana'), 'last_name' => self::str($spec['last_name'] ?? 'Prueba'),
			'identification' => array('type' => self::str($spec['doc_type'] ?? 'DNI'), 'number' => self::str($spec['doc_number'] ?? '30111222')),
			'taxes' => array('taxpayer_type' => array('description' => self::str($spec['taxpayer_type'] ?? 'Consumidor Final'))),
			'address' => $address,
		);
	}

	/**
	 * A shipment.
	 *
	 * @param	int					$id			Shipment id
	 * @param	int					$buyerId	Receiver
	 * @param	list<int>			$orderIds	Orders shipped
	 * @param	array<string,mixed>	$spec		{status, substatus, logistic_type, mode, receiver_cost, sender_cost, address{...}}
	 * @param	int					$created	Creation time
	 * @return	FakeRow
	 */
	private function buildShipment($id, $buyerId, array $orderIds, array $spec, $created)
	{
		$seller = $this->seller();
		$address = array_merge(array(
			'address_line' => 'Avenida Siempreviva 742', 'street_name' => 'Avenida Siempreviva', 'street_number' => '742',
			'zip_code' => '5000', 'city' => array('id' => null, 'name' => 'Cordoba'), 'state' => array('id' => 'AR-X', 'name' => 'Cordoba'),
			'country' => array('id' => 'AR', 'name' => 'Argentina'), 'comment' => 'Timbre 2',
		), self::map($spec['address'] ?? null));
		return array(
			'id' => $id, 'status' => self::str($spec['status'] ?? 'ready_to_ship'), 'substatus' => isset($spec['substatus']) ? self::str($spec['substatus']) : 'ready_to_print',
			'date_created' => self::iso($created), 'last_updated' => self::iso($created), 'declared_value' => null,
			'tracking_number' => 'FAKE'.$id, 'tracking_method' => 'MEL Distribution',
			'logistic' => array('mode' => self::str($spec['mode'] ?? 'me2'), 'type' => self::str($spec['logistic_type'] ?? 'drop_off'), 'direction' => 'forward'),
			'origin' => array('type' => 'selling_address', 'sender_id' => $seller['id'], 'shipping_address' => array('city' => array('name' => 'Rosario'), 'zip_code' => '2000')),
			'destination' => array('type' => 'buying_address', 'receiver_id' => $buyerId, 'receiver_name' => 'Juana Prueba', 'shipping_address' => $address),
			'lead_time' => array('cost' => self::float($spec['receiver_cost'] ?? 0), 'currency_id' => 'ARS', 'shipping_method' => array('id' => 515282, 'name' => 'Normal a domicilio')),
			'source' => array('site_id' => self::SITE, 'market_place' => 'MELI'), 'tags' => array(),
			'_orders' => $orderIds, '_receiver_cost' => self::float($spec['receiver_cost'] ?? 0), '_sender_cost' => self::float($spec['sender_cost'] ?? 0),
		);
	}

	// ----------------------------------------------------------------------------------------------
	// Shipments and labels

	/**
	 * GET /shipments/{id} and /shipments/{id}/costs (header x-format-new: true required).
	 *
	 * @param	string	$id		Shipment id
	 * @param	bool	$costs	Costs sub-resource
	 * @return	FakeReply
	 */
	private function getShipment($id, $costs)
	{
		if (strtolower($this->headers['x-format-new'] ?? '') !== 'true') {
			return self::error(400, 'bad_request', 'The header x-format-new: true is required');
		}
		$shipment = $this->row('shipments', $id);
		if ($shipment === null) {
			return self::error(404, 'not_found', 'Shipment not found');
		}
		if (self::int(self::map($shipment['origin'] ?? null)['sender_id'] ?? 0) !== $this->userId) {
			return self::error(403, 'forbidden', 'Caller is not the sender of the shipment');
		}
		$buyerId = self::int(self::map($shipment['destination'] ?? null)['receiver_id'] ?? 0);
		if ($costs) {
			$receiver = self::float($shipment['_receiver_cost'] ?? 0);
			$sender = self::float($shipment['_sender_cost'] ?? 0);
			return self::json(200, array(
				'gross_amount' => round($receiver + $sender, 2),
				'receiver' => array('user_id' => $buyerId, 'cost' => $receiver, 'compensation' => 0, 'save' => 0, 'discounts' => array()),
				'senders' => array(array('user_id' => $this->userId, 'cost' => $sender, 'compensation' => 0, 'save' => 0, 'discounts' => array())),
			));
		}
		$paid = true;
		foreach (self::lst($shipment['_orders'] ?? null) as $orderId) {
			$order = $this->row('orders', self::str($orderId));
			if ($order === null || self::str($order['status'] ?? '') !== 'paid') {
				$paid = false;
			}
		}
		$visible = self::visible($shipment);
		if (!$paid) {
			// The destination address is hidden until the payment is confirmed.
			$destination = self::map($visible['destination'] ?? null);
			$destination['shipping_address'] = null;
			$visible['destination'] = $destination;
		}
		return self::json(200, $visible);
	}

	/**
	 * GET /shipment_labels?shipment_ids=a,b&response_type=pdf|zpl2.
	 *
	 * @return	FakeReply
	 */
	private function labels()
	{
		$ids = array_values(array_unique(self::csv($this->query['shipment_ids'] ?? '')));
		$format = $this->query['response_type'] ?? 'pdf';
		if (!count($ids)) {
			return self::error(400, 'bad_request', 'shipment_ids is required');
		}
		if (count($ids) > 50) {
			return self::error(400, 'bad_request', 'The maximum number of shipment_ids is 50');
		}
		if ($format !== 'pdf' && $format !== 'zpl2') {
			return self::error(400, 'bad_request', 'response_type must be pdf or zpl2');
		}
		$causes = array();
		$shipments = array();
		foreach ($ids as $id) {
			$shipment = $this->row('shipments', $id);
			if ($shipment === null || self::int(self::map($shipment['origin'] ?? null)['sender_id'] ?? 0) !== $this->userId) {
				$causes[] = array('code' => 'NOT_FOUND', 'message' => 'Shipment '.$id.' not found', 'shipment_id' => (int) $id);
				continue;
			}
			$status = self::str($shipment['status'] ?? '');
			$substatus = self::str($shipment['substatus'] ?? '');
			$type = self::str(self::map($shipment['logistic'] ?? null)['type'] ?? '');
			if ($type === 'fulfillment') {
				$causes[] = array('code' => 'NOT_PRINTABLE_LOGISTIC_TYPE', 'message' => 'Shipment '.$id.' is fulfilled by Mercado Libre, no label', 'shipment_id' => (int) $id);
			} elseif ($status !== 'ready_to_ship' || !in_array($substatus, array('ready_to_print', 'printed'), true)) {
				$causes[] = array('code' => 'NOT_PRINTABLE_STATUS', 'message' => 'Shipment '.$id.' status '.$status.'/'.$substatus.' is not printable', 'shipment_id' => (int) $id);
			}
			$shipments[$id] = $shipment;
		}
		if (count($causes)) {
			return self::error(400, 'bad_request', 'Unable to print labels for the requested shipments', $causes);
		}
		foreach ($shipments as $id => $shipment) {
			if (self::str($shipment['substatus'] ?? '') === 'ready_to_print') {
				$shipment['substatus'] = 'printed';
				$shipment['last_updated'] = self::iso($this->now());
				$this->put('shipments', (string) $id, $shipment);
			}
		}
		if ($format === 'zpl2') {
			$zpl = '';
			foreach (array_keys($shipments) as $id) {
				$zpl .= "^XA^FO50,50^ADN,36,20^FDFake Mercado Libre shipment ".$id."^FS^XZ\n";
			}
			return self::reply(200, 'text/plain;charset=UTF-8', $zpl);
		}
		$texts = array();
		foreach (array_keys($shipments) as $id) {
			$texts[] = 'Fake Mercado Libre label - shipment '.$id;
		}
		return self::reply(200, 'application/pdf', self::pdf($texts), array('Content-Disposition' => 'attachment; filename="Etiqueta de envio.pdf"'));
	}

	/**
	 * Small valid PDF with one page per text (Helvetica, correct cross-reference table).
	 *
	 * @param	list<string>	$texts	One line of text per page
	 * @return	string
	 */
	public static function pdf(array $texts)
	{
		$count = count($texts);
		$fontObj = 3 + 2 * $count;
		$objects = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids = array();
		foreach ($texts as $index => $text) {
			$pageObj = 3 + 2 * $index;
			$kids[] = $pageObj.' 0 R';
			$stream = 'BT /F1 14 Tf 20 380 Td ('.strtr($text, array('\\' => '\\\\', '(' => '\\(', ')' => '\\)')).') Tj ET';
			$objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 288 432] /Contents '.($pageObj + 1).' 0 R /Resources << /Font << /F1 '.$fontObj.' 0 R >> >> >>';
			$objects[$pageObj + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
		}
		$objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
		$objects[$fontObj] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
		ksort($objects);
		$pdf = "%PDF-1.4\n";
		$offsets = array();
		foreach ($objects as $number => $content) {
			$offsets[$number] = strlen($pdf);
			$pdf .= $number." 0 obj\n".$content."\nendobj\n";
		}
		$xref = strlen($pdf);
		$pdf .= "xref\n0 ".($fontObj + 1)."\n0000000000 65535 f \n";
		for ($number = 1; $number <= $fontObj; $number++) {
			$pdf .= sprintf('%010d 00000 n ', $offsets[$number] ?? 0)."\n";
		}
		$pdf .= "trailer\n<< /Size ".($fontObj + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
		return $pdf;
	}

	// ----------------------------------------------------------------------------------------------
	// Notifications

	/**
	 * Queue a notification for the webhook; it is POSTed once the state lock is released.
	 *
	 * @param	string	$topic		Topic
	 * @param	string	$resource	Resource
	 * @param	int		$attempts	Value of attempts
	 * @param	int		$copies		Identical deliveries (1 + duplicates)
	 * @param	int		$delayMs	Wait before the first delivery
	 * @return	void
	 */
	private function queueNotification($topic, $resource, $attempts, $copies, $delayMs)
	{
		$now = $this->now();
		$body = (string) json_encode(array(
			'_id' => sprintf('%s-%s-%s-%s-%s', self::hex(4), self::hex(2), self::hex(2), self::hex(2), self::hex(6)),
			'resource' => $resource, 'user_id' => $this->seller()['id'], 'topic' => $topic,
			'application_id' => self::int($this->cfg('app_id')), 'attempts' => $attempts,
			'sent' => self::isoUtc($now), 'received' => self::isoUtc($now),
		), JSON_UNESCAPED_SLASHES);
		$copies = max(1, $copies);
		for ($copy = 0; $copy < $copies; $copy++) {
			$this->deliveries[] = array(
				'url' => self::str($this->cfg('webhook_url')), 'body' => $body,
				'delay_ms' => $copy === 0 ? max(0, $delayMs) : 0, 'timeout_ms' => max(1, self::int($this->cfg('webhook_timeout_ms'))),
			);
		}
	}

	/**
	 * POST the queued notifications to the webhook and time each answer. Runs without the state lock.
	 *
	 * @param	list<FakeDelivery>	$deliveries		Notifications
	 * @return	list<FakeDeliveryResult>
	 */
	public static function deliver(array $deliveries)
	{
		$results = array();
		foreach ($deliveries as $delivery) {
			if ($delivery['delay_ms'] > 0) {
				usleep($delivery['delay_ms'] * 1000);
			}
			$result = array('url' => $delivery['url'], 'body' => $delivery['body'], 'status' => 0, 'duration_ms' => 0, 'error' => 'no webhook_url configured');
			$curl = $delivery['url'] !== '' ? curl_init($delivery['url']) : false;
			if ($curl !== false) {
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, $delivery['body']);
				curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_TIMEOUT_MS, $delivery['timeout_ms']);
				$start = hrtime(true);
				curl_exec($curl);
				$result['duration_ms'] = (int) round((hrtime(true) - $start) / 1e6);
				$result['status'] = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
				$result['error'] = curl_error($curl);
				curl_close($curl);
			}
			$results[] = $result;
		}
		return $results;
	}

	/**
	 * Record the outcome of the deliveries (a delivery not answered 200 within webhook_max_ms goes to missed_feeds) and
	 * complete the answer of the control call that sent them.
	 *
	 * @param	list<FakeDeliveryResult>	$results	Outcomes
	 * @param	FakeReply					$reply		Answer of the control call
	 * @return	FakeReply
	 */
	public function recordDeliveries(array $results, array $reply)
	{
		$notifications = self::lst($this->state['notifications'] ?? null);
		$summary = array();
		foreach ($results as $result) {
			$missed = $result['status'] !== 200 || $result['duration_ms'] > self::int($this->cfg('webhook_max_ms'));
			$notification = self::map(json_decode($result['body'], true));
			$notification['_http_code'] = $result['status'];
			$notification['_duration_ms'] = $result['duration_ms'];
			$notification['_missed'] = $missed;
			$notification['_at'] = $this->now();
			$notification['_url'] = $result['url'];
			$notifications[] = $notification;
			$summary[] = array('status' => $result['status'], 'duration_ms' => $result['duration_ms'], 'missed' => $missed, 'error' => $result['error'],
				'topic' => $notification['topic'] ?? null, 'resource' => $notification['resource'] ?? null);
		}
		$this->state['notifications'] = $notifications;
		$data = self::map(json_decode($reply['body'], true));
		$data['notifications'] = $summary;
		if (count($summary)) {
			$data['status'] = $summary[0]['status'];
			$data['duration_ms'] = $summary[0]['duration_ms'];
			$data['missed'] = $summary[0]['missed'];
		}
		$reply['body'] = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		$reply['deliveries'] = array();
		return $reply;
	}

	/**
	 * GET /missed_feeds?app_id=&topic=&offset=&limit=: notifications not answered 200 in time, kept two days.
	 *
	 * @return	FakeReply
	 */
	private function missedFeeds()
	{
		$q = $this->query;
		if (($q['app_id'] ?? '') !== self::str($this->cfg('app_id'))) {
			return self::error(403, 'forbidden', 'app_id does not belong to the caller');
		}
		$limit = isset($q['limit']) ? (int) $q['limit'] : 10;
		$offset = isset($q['offset']) ? (int) $q['offset'] : 0;
		if ($limit < 1 || $limit > 50 || $offset < 0) {
			return self::error(400, 'bad_request', 'limit must be between 1 and 50');
		}
		$oldest = $this->now() - self::int($this->cfg('missed_feeds_retention'));
		$messages = array();
		foreach (self::lst($this->state['notifications'] ?? null) as $notification) {
			$notification = self::map($notification);
			if (empty($notification['_missed']) || self::int($notification['_at'] ?? 0) < $oldest) {
				continue;
			}
			if (isset($q['topic']) && $q['topic'] !== '' && self::str($notification['topic'] ?? '') !== $q['topic']) {
				continue;
			}
			$message = self::visible($notification);
			$message['_id'] = $notification['_id'] ?? null;
			$message['request'] = array('url' => $notification['_url'] ?? '', 'headers' => array('accept' => 'application/json', 'content-type' => 'application/json'));
			$message['response'] = array('req_time' => self::int($notification['_duration_ms'] ?? 0), 'http_code' => self::int($notification['_http_code'] ?? 0));
			$messages[] = $message;
		}
		return self::json(200, array('messages' => array_slice($messages, $offset, $limit), 'total' => count($messages), 'offset' => $offset, 'limit' => $limit));
	}

	// ----------------------------------------------------------------------------------------------
	// Control endpoints

	/**
	 * Handle a /__test/ endpoint.
	 *
	 * @param	string	$method		HTTP method
	 * @param	string	$path		Path
	 * @param	string	$rawbody	JSON body
	 * @return	FakeReply
	 */
	private function control($method, $path, $rawbody)
	{
		if ($path === '/__test/state' && $method === 'GET') {
			$state = $this->state;
			$state['config'] = $this->config();
			$state['now'] = self::iso($this->now());
			foreach (array('items', 'user_products', 'orders', 'packs', 'billing', 'shipments', 'codes', 'grants', 'access_tokens', 'refresh_tokens', 'test_users') as $key) {
				$state[$key] = (object) $this->table($key);
			}
			foreach (array('log', 'injections', 'notifications') as $key) {
				$state[$key] = self::lst($state[$key] ?? null);
			}
			return self::json(200, $state);
		}
		if ($method !== 'POST') {
			return self::json(405, array('error' => 'POST expected'));
		}
		$body = self::jsonBody($rawbody) ?? array();
		switch ($path) {
			case '/__test/reset':
				$config = $this->state['config'] ?? array();
				$this->state = array();
				if (!empty($body['keep_config'])) {
					$this->state['config'] = $config;
				}
				return self::json(200, array('ok' => true));
			case '/__test/config':
				$config = self::map($this->state['config'] ?? null);
				foreach ($body as $key => $value) {
					if (!array_key_exists($key, self::defaultConfig())) {
						return self::json(400, array('error' => 'unknown config key '.$key, 'keys' => array_keys(self::defaultConfig())));
					}
					$config[$key] = $key === 'user' ? array_merge($this->seller(), self::map($value)) : $value;
				}
				$this->state['config'] = $config;
				return self::json(200, array('ok' => true, 'config' => $this->config()));
			case '/__test/item':
				$item = $this->buildItem($body);
				$this->put('items', self::str($item['id']), self::autoStatus($item));
				return self::json(200, $this->row('items', self::str($item['id'])));
			case '/__test/stock':
				return $this->controlStock($body);
			case '/__test/order':
				return $this->seedOrder($body);
			case '/__test/shipment':
				return $this->controlShipment($body);
			case '/__test/cancel_order':
			case '/__test/fraud':
				return $this->controlOrder($path === '/__test/fraud', $body);
			case '/__test/change_item_id':
				return $this->changeItemId($body);
			case '/__test/revoke':
				$revoked = 0;
				foreach ($this->table('grants') as $id => $grant) {
					if (empty($grant['revoked'])) {
						$grant['revoked'] = true;
						$grant['revoked_reason'] = 'revoked_by_test';
						$this->put('grants', $id, $grant);
						$revoked++;
					}
				}
				return self::json(200, array('ok' => true, 'revoked' => $revoked));
			case '/__test/notify':
				$topic = self::str($body['topic'] ?? '');
				$resource = self::str($body['resource'] ?? '');
				if ($topic === '' || $resource === '') {
					return self::json(400, array('error' => 'topic and resource are required'));
				}
				$this->queueNotification($topic, $resource, max(1, self::int($body['attempts'] ?? 1)), 1 + max(0, self::int($body['duplicates'] ?? 0)), self::int($body['delay_ms'] ?? 0));
				return self::json(200, array('ok' => true));
			case '/__test/advance_time':
				$this->state['time_offset'] = self::int($this->state['time_offset'] ?? 0) + self::int($body['seconds'] ?? 0);
				return self::json(200, array('ok' => true, 'now' => self::iso($this->now()), 'offset' => $this->state['time_offset']));
			case '/__test/inject':
				return $this->controlInject($body);
		}
		return self::json(404, array('error' => 'unknown control endpoint'));
	}

	/**
	 * POST /__test/stock: change stock on Mercado Libre's side (a sale or an edit made in ML's UI).
	 *
	 * @param	array<string,mixed>	$body	{user_product_id, quantity, type?} or {item_id, variation_id?, quantity}
	 * @return	FakeReply
	 */
	private function controlStock(array $body)
	{
		$quantity = self::int($body['quantity'] ?? 0);
		$upId = self::str($body['user_product_id'] ?? '');
		if ($upId !== '') {
			if ($this->row('user_products', $upId) === null) {
				return self::json(404, array('error' => 'unknown user product'));
			}
			$this->setUpStock($upId, self::str($body['type'] ?? 'selling_address'), $quantity);
			return self::json(200, $this->row('user_products', $upId));
		}
		$itemId = self::str($body['item_id'] ?? '');
		$item = $this->row('items', $itemId);
		if ($item === null) {
			return self::json(404, array('error' => 'unknown item'));
		}
		$variationId = isset($body['variation_id']) ? self::int($body['variation_id']) : null;
		$variations = array();
		$total = 0;
		foreach (self::lst($item['variations'] ?? null) as $variation) {
			$variation = self::map($variation);
			if (self::int($variation['id'] ?? 0) === $variationId) {
				$variation['available_quantity'] = $quantity;
			}
			$total += self::int($variation['available_quantity'] ?? 0);
			$variations[] = $variation;
		}
		$item['variations'] = $variations;
		$item['available_quantity'] = count($variations) ? $total : $quantity;
		$this->put('items', $itemId, self::autoStatus($item));
		return self::json(200, $this->row('items', $itemId));
	}

	/**
	 * POST /__test/shipment: set status and substatus of a shipment.
	 *
	 * @param	array<string,mixed>	$body	{id, status, substatus, logistic_type?, notify?}
	 * @return	FakeReply
	 */
	private function controlShipment(array $body)
	{
		$id = self::str($body['id'] ?? '');
		$shipment = $this->row('shipments', $id);
		if ($shipment === null) {
			return self::json(404, array('error' => 'unknown shipment'));
		}
		if (isset($body['status'])) {
			if (!in_array(self::str($body['status']), self::SHIPMENT_STATUSES, true)) {
				return self::json(400, array('error' => 'unknown status', 'statuses' => self::SHIPMENT_STATUSES));
			}
			$shipment['status'] = self::str($body['status']);
		}
		if (array_key_exists('substatus', $body)) {
			$shipment['substatus'] = $body['substatus'] === null ? null : self::str($body['substatus']);
		}
		if (isset($body['logistic_type'])) {
			$logistic = self::map($shipment['logistic'] ?? null);
			$logistic['type'] = self::str($body['logistic_type']);
			$shipment['logistic'] = $logistic;
		}
		$shipment['last_updated'] = self::iso($this->now());
		$this->put('shipments', $id, $shipment);
		if (!empty($body['notify'])) {
			$this->queueNotification('shipments', '/shipments/'.$id, 1, 1, 0);
		}
		return self::json(200, self::visible($shipment));
	}

	/**
	 * POST /__test/cancel_order and /__test/fraud: change an order after it was sold.
	 *
	 * @param	bool				$fraud	Flag fraud instead of cancelling
	 * @param	array<string,mixed>	$body	{order_id, requested_by?, code?, description?, notify?}
	 * @return	FakeReply
	 */
	private function controlOrder($fraud, array $body)
	{
		$id = self::str($body['order_id'] ?? '');
		$order = $this->row('orders', $id);
		if ($order === null) {
			return self::json(404, array('error' => 'unknown order'));
		}
		$tags = self::strs($order['tags'] ?? null);
		if ($fraud) {
			$tags[] = 'fraud_risk_detected';
			$order['fraud_risk_detected'] = true;
		} else {
			$order['status'] = 'cancelled';
			$order['status_detail'] = null;
			$order['cancel_detail'] = array(
				'group' => 'buyer', 'code' => self::str($body['code'] ?? 'buyer_cancel_express'),
				'description' => self::str($body['description'] ?? 'The buyer cancelled the purchase'),
				'requested_by' => self::str($body['requested_by'] ?? 'buyer'), 'date' => self::iso($this->now()), 'application_id' => null,
			);
			$payments = array();
			foreach (self::lst($order['payments'] ?? null) as $payment) {
				$payment = self::map($payment);
				$payment['status'] = 'refunded';
				$payment['status_detail'] = 'refunded';
				$payments[] = $payment;
			}
			$order['payments'] = $payments;
			$tags = array_values(array_diff($tags, array('paid')));
		}
		$order['tags'] = array_values(array_unique($tags));
		$order['last_updated'] = self::iso($this->now());
		$order['date_last_updated'] = self::iso($this->now());
		$this->put('orders', $id, $order);
		if (!empty($body['notify'])) {
			$this->queueNotification('orders_v2', '/orders/'.$id, 1, 1, 0);
		}
		return self::json(200, $order);
	}

	/**
	 * POST /__test/change_item_id: UPtin migration. The old item is closed; a new item is created with the same SKU (one
	 * per variation for a legacy item with variations), and an items notification is sent for each of them.
	 *
	 * @param	array<string,mixed>	$body	{item_id, new_ids?: [..]}
	 * @return	FakeReply
	 */
	private function changeItemId(array $body)
	{
		$oldId = self::str($body['item_id'] ?? '');
		$old = $this->row('items', $oldId);
		if ($old === null) {
			return self::json(404, array('error' => 'unknown item'));
		}
		$newIds = self::strs($body['new_ids'] ?? null);
		$specs = array();
		$variations = self::lst($old['variations'] ?? null);
		if (count($variations)) {
			foreach ($variations as $variation) {
				$variation = self::map($variation);
				$specs[] = array('sku' => self::sku($variation['attributes'] ?? null) ?? '', 'quantity' => self::int($variation['available_quantity'] ?? 0));
			}
		} else {
			$specs[] = array('sku' => self::sku($old['attributes'] ?? null) ?? '', 'quantity' => self::int($old['available_quantity'] ?? 0), 'user_product_id' => self::str($old['user_product_id'] ?? ''));
		}
		$created = array();
		foreach ($specs as $index => $spec) {
			$item = $this->buildItem(array(
				'model' => 'up', 'id' => $newIds[$index] ?? '', 'sku' => $spec['sku'], 'quantity' => $spec['quantity'],
				'title' => self::str($old['title'] ?? ''), 'price' => self::float($old['price'] ?? 0), 'category_id' => self::str($old['category_id'] ?? ''),
				'user_product_id' => $spec['user_product_id'] ?? '', 'tags' => array('variations_migration_uptin'),
			));
			$this->put('items', self::str($item['id']), self::autoStatus($item));
			$created[] = self::str($item['id']);
		}
		$old['status'] = 'closed';
		$old['sub_status'] = array();
		$old['last_updated'] = self::iso($this->now());
		$this->put('items', $oldId, $old);
		$this->queueNotification('items', '/items/'.$oldId, 1, 1, 0);
		foreach ($created as $id) {
			$this->queueNotification('items', '/items/'.$id, 1, 1, 0);
		}
		return self::json(200, array('ok' => true, 'closed' => $oldId, 'created' => $created));
	}

	/**
	 * POST /__test/inject: queue one-shot faults.
	 *
	 * @param	array<string,mixed>	$body	{mode, method?, path?, ...} or {injections: [...]}
	 * @return	FakeReply
	 */
	private function controlInject(array $body)
	{
		$items = isset($body['mode']) ? array($body) : self::lst($body['injections'] ?? null);
		if (!count($items)) {
			return self::json(400, array('error' => 'give {mode, method?, path?, ...} or {injections: [...]}'));
		}
		$queue = self::lst($this->state['injections'] ?? null);
		foreach ($items as $item) {
			$item = self::map($item);
			if (!in_array(self::str($item['mode'] ?? ''), self::MODES, true)) {
				return self::json(400, array('error' => 'unknown mode', 'modes' => self::MODES));
			}
			$item['method'] = strtoupper(self::str($item['method'] ?? '*'));
			$item['path'] = self::str($item['path'] ?? '*');
			$queue[] = $item;
		}
		$this->state['injections'] = $queue;
		return self::json(200, array('ok' => true, 'queued' => count($queue)));
	}

	/**
	 * Send an answer, honouring the delay and drop of an injection.
	 *
	 * @param	FakeReply	$reply	Answer
	 * @return	void
	 */
	public static function send(array $reply)
	{
		if ($reply['delay_ms'] > 0) {
			usleep($reply['delay_ms'] * 1000);
		}
		http_response_code($reply['status']);
		foreach ($reply['headers'] as $name => $value) {
			header($name.': '.$value);
		}
		if ($reply['drop']) {
			// php -S cannot reset the socket; announcing a longer body than sent makes the client see a cut connection.
			header('Content-Length: '.(strlen($reply['body']) + 1024));
			return;
		}
		print $reply['body'];
	}
}


/**
 * Open and lock the state file and load a router on its content.
 *
 * @param	string	$file	State file
 * @return	array{0:resource,1:MeliFakeRouter}|null		Locked handle and router, null when the file cannot be used
 */
function meliFakeLock($file)
{
	$handle = fopen($file, 'c+');
	if ($handle === false || !flock($handle, LOCK_EX)) {
		return null;
	}
	return array($handle, new MeliFakeRouter(MeliFakeRouter::map(json_decode((string) stream_get_contents($handle), true))));
}

/**
 * Write the state and release the lock.
 *
 * @param	resource		$handle		Locked handle
 * @param	MeliFakeRouter	$router		Router
 * @return	void
 */
function meliFakeUnlock($handle, MeliFakeRouter $router)
{
	ftruncate($handle, 0);
	rewind($handle);
	fwrite($handle, (string) json_encode($router->getState(), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);
}


if (PHP_SAPI !== 'cli-server') {
	fwrite(STDERR, "This script is a router for PHP's built-in web server\n");
	exit(1);
}

$melifakefile = getenv('MELI_FAKE_STATE');
if (!is_string($melifakefile) || $melifakefile === '') {
	http_response_code(500);
	print 'MELI_FAKE_STATE is not set';
	return true;
}

$melifakeheaders = array();
foreach ($_SERVER as $melifakekey => $melifakevalue) {
	if (is_string($melifakekey) && strpos($melifakekey, 'HTTP_') === 0) {
		$melifakeheaders[strtolower(strtr(substr($melifakekey, 5), '_', '-'))] = MeliFakeRouter::str($melifakevalue);
	}
}
if (isset($_SERVER['CONTENT_TYPE'])) {
	$melifakeheaders['content-type'] = MeliFakeRouter::str($_SERVER['CONTENT_TYPE']);
}
$melifakeuri = MeliFakeRouter::str($_SERVER['REQUEST_URI'] ?? '/');
$melifakebody = (string) file_get_contents('php://input');

// One request at a time: read, change and write the state under an exclusive lock, released before any injected delay
// and before notifications are POSTed, so that other requests (the test's control calls, the webhook's own calls) go on.
$melifakelock = meliFakeLock($melifakefile);
if ($melifakelock === null) {
	http_response_code(500);
	print 'state file not usable';
	return true;
}
list($melifakehandle, $melifakerouter) = $melifakelock;
$melifakereply = $melifakerouter->handle(
	MeliFakeRouter::str($_SERVER['REQUEST_METHOD'] ?? 'GET'),
	MeliFakeRouter::str(parse_url($melifakeuri, PHP_URL_PATH)),
	MeliFakeRouter::parseQuery(MeliFakeRouter::str(parse_url($melifakeuri, PHP_URL_QUERY))),
	$melifakeheaders,
	$melifakebody
);
meliFakeUnlock($melifakehandle, $melifakerouter);

if (count($melifakereply['deliveries'])) {
	$melifakeresults = MeliFakeRouter::deliver($melifakereply['deliveries']);
	$melifakelock = meliFakeLock($melifakefile);
	if ($melifakelock !== null) {
		$melifakereply = $melifakelock[1]->recordDeliveries($melifakeresults, $melifakereply);
		meliFakeUnlock($melifakelock[0], $melifakelock[1]);
	}
}

MeliFakeRouter::send($melifakereply);

return true;
