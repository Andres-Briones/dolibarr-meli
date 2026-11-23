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
 * \file    test/fake/FakeClient.php
 * \ingroup meli
 * \brief   Test helper: starts the fake Mercado Libre (and a stand-in webhook), drives its control endpoints and makes
 *          HTTP calls shaped as Mercado Libre's documented examples. Never used by the module itself.
 */

/**
 * Client of the fake Mercado Libre (test/fake/router.php) for the tests.
 *
 * @phpstan-type Response array{status:int,headers:array<string,string>,body:string,errno:int,error:string,seconds:float}
 * @phpstan-type Options array{query?:array<string,string|int>,headers?:list<string>,body?:string,json?:mixed,form?:array<string,string>,token?:string,timeout?:float}
 * @phpstan-type Tokens array{access_token:string,refresh_token:string,user_id:int,expires_in:int}
 */
class MeliFakeClient
{
	/**
	 * @var string Base URL of the fake, e.g. http://127.0.0.1:8123 (API base and AUTH base at once)
	 */
	private $baseUrl;

	/**
	 * @var resource|null Process of the fake when started by this class
	 */
	private $process = null;

	/**
	 * @var string State file of the fake when started by this class
	 */
	private $stateFile = '';

	/**
	 * Constructor.
	 *
	 * @param	string	$baseUrl	Base URL of a running fake
	 */
	public function __construct($baseUrl)
	{
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	// ----------------------------------------------------------------------------------------------
	// Processes

	/**
	 * A free local TCP port.
	 *
	 * @return	int
	 * @throws	RuntimeException	When none can be found
	 */
	public static function freePort()
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		if ($socket === false) {
			throw new RuntimeException('No free local port');
		}
		$address = (string) stream_socket_get_name($socket, false);
		fclose($socket);
		return (int) substr($address, (int) strrpos($address, ':') + 1);
	}

	/**
	 * Start php -S with a router script on a free port and wait until a GET on a probe path answers 200.
	 *
	 * @param	string					$script		Router script
	 * @param	array<string,string>	$env		Extra environment
	 * @param	int						$workers	PHP_CLI_SERVER_WORKERS
	 * @param	string					$probe		Path answering 200 once started
	 * @return	array{0:resource,1:string}			Process and base URL
	 * @throws	RuntimeException					When it cannot be started
	 */
	public static function launch($script, array $env, $workers, $probe)
	{
		$port = self::freePort();
		$environment = array_merge(getenv(), $env);
		$environment['PHP_CLI_SERVER_WORKERS'] = (string) max(1, $workers);
		$pipes = array();
		$process = proc_open(
			array(PHP_BINARY, '-S', '127.0.0.1:'.$port, $script),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
			$pipes,
			null,
			$environment
		);
		if (!is_resource($process)) {
			throw new RuntimeException('php -S not started for '.$script);
		}
		$url = 'http://127.0.0.1:'.$port;
		for ($try = 0; $try < 100; $try++) {
			$curl = curl_init($url.$probe);
			if ($curl !== false) {
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_TIMEOUT_MS, 500);
				$answered = curl_exec($curl) !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200;
				curl_close($curl);
				if ($answered) {
					return array($process, $url);
				}
			}
			usleep(50000);
		}
		self::terminate($process);
		throw new RuntimeException('php -S does not answer on port '.$port.' for '.$script);
	}

	/**
	 * Stop a php -S process and its workers.
	 *
	 * @param	resource	$process	Process from launch()
	 * @return	void
	 */
	public static function terminate($process)
	{
		$status = proc_get_status($process);
		// php -S does not stop its workers when the master is terminated: they are killed one by one.
		$pids = self::childPids((int) $status['pid']);
		proc_terminate($process);
		foreach ($pids as $pid) {
			if (function_exists('posix_kill')) {
				posix_kill($pid, 15);
			} else {
				exec('kill '.$pid);
			}
		}
		proc_close($process);
	}

	/**
	 * Processes whose parent is a process (Linux /proc).
	 *
	 * @param	int		$parent		Parent process id
	 * @return	list<int>
	 */
	private static function childPids($parent)
	{
		$pids = array();
		foreach ((array) glob('/proc/[0-9]*/stat') as $file) {
			$stat = is_string($file) && is_readable($file) ? (string) file_get_contents($file) : '';
			$close = strrpos($stat, ')');
			if ($close === false) {
				continue;
			}
			$fields = explode(' ', trim(substr($stat, $close + 2)));
			if (isset($fields[1]) && (int) $fields[1] === $parent) {
				$pids[] = (int) substr($stat, 0, (int) strpos($stat, ' '));
			}
		}
		return $pids;
	}

	/**
	 * Start the fake on a free local port with a fresh state file in the temporary directory.
	 *
	 * @param	int		$workers	Worker processes of php -S (an injected delay or a slow webhook holds one each)
	 * @return	self
	 */
	public static function start($workers = 4)
	{
		$stateFile = (string) tempnam(sys_get_temp_dir(), 'melifake');
		list($process, $url) = self::launch(__DIR__.'/router.php', array('MELI_FAKE_STATE' => $stateFile), $workers, '/__test/state');
		$client = new self($url);
		$client->process = $process;
		$client->stateFile = $stateFile;
		return $client;
	}

	/**
	 * Stop a fake started by start(), its workers included, and remove its state file.
	 *
	 * @return	void
	 */
	public function stop()
	{
		if (is_resource($this->process)) {
			self::terminate($this->process);
			$this->process = null;
		}
		if ($this->stateFile !== '' && file_exists($this->stateFile)) {
			unlink($this->stateFile);
		}
	}

	/**
	 * Base URL of the fake.
	 *
	 * @return	string
	 */
	public function getBaseUrl()
	{
		return $this->baseUrl;
	}

	/**
	 * What the module's MELI_API_URL must be set to.
	 *
	 * @return	string
	 */
	public function apiUrl()
	{
		return $this->baseUrl;
	}

	/**
	 * What the module's MELI_AUTH_URL must be set to (the same router serves both).
	 *
	 * @return	string
	 */
	public function authUrl()
	{
		return $this->baseUrl;
	}

	// ----------------------------------------------------------------------------------------------
	// HTTP

	/**
	 * Prepare a curl handle.
	 *
	 * @param	non-empty-string	$method		Method
	 * @param	string		$url		Absolute URL
	 * @param	Options		$options	query, headers, body | json | form, token (Bearer), timeout (seconds)
	 * @return	CurlHandle
	 * @throws	RuntimeException		When curl is not usable
	 */
	private static function handle($method, $url, array $options)
	{
		if (isset($options['query']) && count($options['query'])) {
			$url .= (strpos($url, '?') === false ? '?' : '&').http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
		}
		$curl = curl_init($url);
		if ($curl === false) {
			throw new RuntimeException('curl_init failed');
		}
		$headers = $options['headers'] ?? array();
		$body = null;
		if (array_key_exists('json', $options)) {
			$body = (string) json_encode($options['json'], JSON_UNESCAPED_SLASHES);
			$headers[] = 'Content-Type: application/json';
		} elseif (isset($options['form'])) {
			$body = http_build_query($options['form'], '', '&');
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		} elseif (isset($options['body'])) {
			$body = $options['body'];
		}
		if (isset($options['token'])) {
			$headers[] = 'Authorization: Bearer '.$options['token'];
		}
		$headers[] = 'Accept: application/json';
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, 5000);
		curl_setopt($curl, CURLOPT_TIMEOUT_MS, (int) (($options['timeout'] ?? 10.0) * 1000));
		return $curl;
	}

	/**
	 * Result of a finished curl handle.
	 *
	 * @param	CurlHandle			$curl		Handle
	 * @param	string|bool|null	$content	What curl returned (headers and body)
	 * @param	int					$errno		curl error number
	 * @return	Response
	 */
	private static function response($curl, $content, $errno)
	{
		$raw = is_string($content) ? $content : '';
		$size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		$headers = array();
		// Only the last header block counts (a 100 Continue may come first).
		$blocks = preg_split('/\r\n\r\n/', rtrim(substr($raw, 0, $size)));
		$last = is_array($blocks) ? (string) end($blocks) : '';
		foreach (explode("\r\n", $last) as $line) {
			$colon = strpos($line, ':');
			if ($colon !== false) {
				$headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
			}
		}
		return array(
			'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
			'headers' => $headers,
			'body' => (string) substr($raw, $size),
			'errno' => $errno,
			'error' => curl_error($curl),
			'seconds' => (float) curl_getinfo($curl, CURLINFO_TOTAL_TIME),
		);
	}

	/**
	 * Make a request to the fake.
	 *
	 * @param	non-empty-string	$method		GET, POST, PUT...
	 * @param	string		$path		Path on the fake (may carry a query string)
	 * @param	Options		$options	See handle()
	 * @return	Response
	 */
	public function request($method, $path, array $options = array())
	{
		$curl = self::handle($method, $this->baseUrl.$path, $options);
		$content = curl_exec($curl);
		$result = self::response($curl, $content, curl_errno($curl));
		curl_close($curl);
		return $result;
	}

	/**
	 * Make several requests at the same time (curl_multi).
	 *
	 * @param	list<array{0:non-empty-string,1:string,2:Options}>	$requests	Method, path, options
	 * @return	list<Response>										In the order of the requests
	 */
	public function requestMany(array $requests)
	{
		$multi = curl_multi_init();
		$handles = array();
		foreach ($requests as $request) {
			$curl = self::handle($request[0], $this->baseUrl.$request[1], $request[2]);
			curl_multi_add_handle($multi, $curl);
			$handles[] = $curl;
		}
		do {
			$status = curl_multi_exec($multi, $running);
			if ($running) {
				curl_multi_select($multi, 0.1);
			}
		} while ($running && $status === CURLM_OK);
		$errors = array();
		do {
			$info = curl_multi_info_read($multi);
			if (is_array($info) && $info['handle'] instanceof CurlHandle && is_int($info['result'])) {
				$errors[spl_object_id($info['handle'])] = $info['result'];
			}
		} while ($info !== false);
		$results = array();
		foreach ($handles as $curl) {
			$results[] = self::response($curl, curl_multi_getcontent($curl), $errors[spl_object_id($curl)] ?? 0);
			curl_multi_remove_handle($multi, $curl);
			curl_close($curl);
		}
		curl_multi_close($multi);
		return $results;
	}

	/**
	 * API call with a Bearer token.
	 *
	 * @param	non-empty-string		$method		Method
	 * @param	string					$path		Path
	 * @param	string					$token		Access token
	 * @param	mixed					$json		JSON body, null for none
	 * @param	list<string>			$headers	Extra headers, e.g. x-format-new: true
	 * @param	array<string,string|int>	$query	Query string
	 * @return	Response
	 */
	public function api($method, $path, $token, $json = null, array $headers = array(), array $query = array())
	{
		$options = array('token' => $token, 'headers' => $headers, 'query' => $query);
		if ($json !== null) {
			$options['json'] = $json;
		}
		return $this->request($method, $path, $options);
	}

	/**
	 * Decode a JSON object answer.
	 *
	 * @param	Response	$response	Response
	 * @return	array<string,mixed>		Empty when the body is not a JSON object
	 */
	public static function decode(array $response)
	{
		$data = json_decode($response['body'], true);
		$result = array();
		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$result[(string) $key] = $value;
			}
		}
		return $result;
	}

	/**
	 * Decode a JSON list answer (e.g. /items/bulk) into a list of objects.
	 *
	 * @param	Response	$response	Response
	 * @return	list<array<string,mixed>>
	 */
	public static function decodeList(array $response)
	{
		$data = json_decode($response['body'], true);
		$result = array();
		if (is_array($data)) {
			foreach ($data as $entry) {
				$result[] = self::toMap($entry);
			}
		}
		return $result;
	}

	/**
	 * Read a value of unknown type as an array with string keys.
	 *
	 * @param	mixed	$value	Value
	 * @return	array<string,mixed>
	 */
	public static function toMap($value)
	{
		$result = array();
		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$result[(string) $key] = $item;
			}
		}
		return $result;
	}

	// ----------------------------------------------------------------------------------------------
	// Control endpoints

	/**
	 * Call a control endpoint.
	 *
	 * @param	'GET'|'POST'		$method		GET or POST
	 * @param	string				$path		Path under /__test/
	 * @param	array<string,mixed>	$body		JSON body for POST
	 * @return	array<string,mixed>				Decoded answer, with the HTTP status under '_status'
	 * @throws	RuntimeException				When the answer is not JSON
	 */
	public function control($method, $path, array $body = array())
	{
		$response = $method === 'GET' ? $this->request('GET', $path, array('timeout' => 30.0)) : $this->request('POST', $path, array('json' => (object) $body, 'timeout' => 30.0));
		$data = json_decode($response['body'], true);
		if (!is_array($data)) {
			throw new RuntimeException('Control endpoint '.$path.' did not answer JSON: '.$response['error'].' '.$response['body']);
		}
		$result = self::toMap($data);
		$result['_status'] = $response['status'];
		return $result;
	}

	/**
	 * Whole state of the fake.
	 *
	 * @return	array<string,mixed>
	 */
	public function state()
	{
		return $this->control('GET', '/__test/state');
	}

	/**
	 * Configuration with the defaults filled in.
	 *
	 * @return	array<string,mixed>
	 */
	public function currentConfig()
	{
		return self::toMap($this->state()['config'] ?? null);
	}

	/**
	 * Request log (method, path, auth_header, status, injected), never with token values.
	 *
	 * @return	list<array<string,mixed>>
	 */
	public function log()
	{
		$log = array();
		foreach ((array) ($this->state()['log'] ?? array()) as $entry) {
			$log[] = self::toMap($entry);
		}
		return $log;
	}

	/**
	 * Forget everything.
	 *
	 * @param	bool	$keepConfig		Keep the configuration
	 * @return	array<string,mixed>
	 */
	public function reset($keepConfig = false)
	{
		return $this->control('POST', '/__test/reset', array('keep_config' => $keepConfig));
	}

	/**
	 * Change the configuration (keys: see README.md).
	 *
	 * @param	array<string,mixed>	$config		Keys to change
	 * @return	array<string,mixed>
	 */
	public function config(array $config)
	{
		return $this->control('POST', '/__test/config', $config);
	}

	/**
	 * Create a listing: {model: up|legacy, sku, title, family_name, price, quantity, status, user_product_id,
	 * variations: [{id?, sku, quantity, attribute_combinations?}]}.
	 *
	 * @param	array<string,mixed>	$item	Item
	 * @return	array<string,mixed>			The item as GET /items/{id} returns it
	 */
	public function seedItem(array $item)
	{
		return $this->control('POST', '/__test/item', $item);
	}

	/**
	 * Change stock on Mercado Libre's side: {user_product_id, quantity, type?} or {item_id, variation_id?, quantity}.
	 *
	 * @param	array<string,mixed>	$stock	Change
	 * @return	array<string,mixed>
	 */
	public function setStock(array $stock)
	{
		return $this->control('POST', '/__test/stock', $stock);
	}

	/**
	 * A sale: {items: [{item_id, variation_id?, quantity?, unit_price?, sale_fee?}], status?, pack?, buyer?, billing?,
	 * shipment?, decrement_stock?, notify?}.
	 *
	 * @param	array<string,mixed>	$order	Sale
	 * @return	array<string,mixed>			{pack_id, order_ids, shipment_id, billing_info_id, buyer_id}
	 */
	public function seedOrder(array $order)
	{
		return $this->control('POST', '/__test/order', $order);
	}

	/**
	 * Set a shipment's status: {id, status?, substatus?, logistic_type?, notify?}.
	 *
	 * @param	array<string,mixed>	$shipment	Change
	 * @return	array<string,mixed>
	 */
	public function setShipment(array $shipment)
	{
		return $this->control('POST', '/__test/shipment', $shipment);
	}

	/**
	 * Cancel an order after the sale.
	 *
	 * @param	int|string			$orderId	Order
	 * @param	array<string,mixed>	$extra		requested_by, code, description, notify
	 * @return	array<string,mixed>
	 */
	public function cancelOrder($orderId, array $extra = array())
	{
		return $this->control('POST', '/__test/cancel_order', array_merge($extra, array('order_id' => $orderId)));
	}

	/**
	 * Flag an order as fraud after the sale.
	 *
	 * @param	int|string			$orderId	Order
	 * @param	array<string,mixed>	$extra		notify
	 * @return	array<string,mixed>
	 */
	public function flagFraud($orderId, array $extra = array())
	{
		return $this->control('POST', '/__test/fraud', array_merge($extra, array('order_id' => $orderId)));
	}

	/**
	 * UPtin migration of an item: closed, replaced by new item(s) with the same SKU, items notifications sent.
	 *
	 * @param	string			$itemId		Item
	 * @param	list<string>	$newIds		Ids for the new items, generated when empty
	 * @return	array<string,mixed>			{closed, created: [ids], notifications}
	 */
	public function changeItemId($itemId, array $newIds = array())
	{
		return $this->control('POST', '/__test/change_item_id', array('item_id' => $itemId, 'new_ids' => $newIds));
	}

	/**
	 * Revoke every grant (the user removed the app, changed the password...).
	 *
	 * @return	array<string,mixed>
	 */
	public function revoke()
	{
		return $this->control('POST', '/__test/revoke');
	}

	/**
	 * POST a notification to the configured webhook_url: {topic, resource, duplicates?, delay_ms?, attempts?}.
	 *
	 * @param	array<string,mixed>	$notification	Notification
	 * @return	array<string,mixed>					{status, duration_ms, missed, notifications}
	 */
	public function notify(array $notification)
	{
		return $this->control('POST', '/__test/notify', $notification);
	}

	/**
	 * Move the fake's clock forward.
	 *
	 * @param	int		$seconds	Seconds
	 * @return	array<string,mixed>
	 */
	public function advanceTime($seconds)
	{
		return $this->control('POST', '/__test/advance_time', array('seconds' => $seconds));
	}

	/**
	 * Queue a one-shot injection, e.g. array('mode' => 'http_429', 'method' => 'PUT', 'path' => '/items/', 'retry_after' => 1).
	 *
	 * @param	array<string,mixed>	$injection	Injection
	 * @return	array<string,mixed>
	 */
	public function inject(array $injection)
	{
		return $this->control('POST', '/__test/inject', $injection);
	}

	// ----------------------------------------------------------------------------------------------
	// OAuth

	/**
	 * PKCE pair (S256).
	 *
	 * @return	array{verifier:string,challenge:string}
	 */
	public static function pkce()
	{
		$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
		return array('verifier' => $verifier, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
	}

	/**
	 * GET /authorization (not followed) with the configured client_id and redirect_uri unless overridden.
	 *
	 * @param	string					$challenge	S256 code challenge, '' for none
	 * @param	string					$state		state
	 * @param	array<string,string>	$override	Parameters to change or add
	 * @return	Response
	 */
	public function authorize($challenge, $state, array $override = array())
	{
		$config = $this->currentConfig();
		$query = array(
			'response_type' => 'code', 'client_id' => is_scalar($config['client_id'] ?? null) ? (string) $config['client_id'] : '',
			'redirect_uri' => is_scalar($config['redirect_uri'] ?? null) ? (string) $config['redirect_uri'] : '', 'state' => $state,
		);
		if ($challenge !== '') {
			$query['code_challenge'] = $challenge;
			$query['code_challenge_method'] = 'S256';
		}
		return $this->request('GET', '/authorization', array('query' => array_merge($query, $override)));
	}

	/**
	 * Query parameters of the Location of a redirect.
	 *
	 * @param	Response	$response	Response
	 * @return	array<string,string>
	 */
	public static function redirectParams(array $response)
	{
		$query = (string) parse_url($response['headers']['location'] ?? '', PHP_URL_QUERY);
		$result = array();
		foreach (explode('&', $query) as $pair) {
			if ($pair !== '') {
				$parts = explode('=', $pair, 2);
				$result[urldecode($parts[0])] = urldecode($parts[1] ?? '');
			}
		}
		return $result;
	}

	/**
	 * POST /oauth/token with a form body (and optionally parameters in the query string, which the fake refuses).
	 *
	 * @param	array<string,string>	$form	Body
	 * @param	array<string,string>	$query	Query string
	 * @param	float					$timeout	Timeout in seconds
	 * @return	Response
	 */
	public function token(array $form, array $query = array(), $timeout = 10.0)
	{
		return $this->request('POST', '/oauth/token', array('form' => $form, 'query' => $query, 'timeout' => $timeout));
	}

	/**
	 * Body of an authorization_code exchange with the configured credentials.
	 *
	 * @param	string	$code			Code
	 * @param	string	$verifier		code_verifier, '' to leave it out
	 * @param	string	$redirectUri	redirect_uri, null for the configured one
	 * @return	array<string,string>
	 */
	public function codeForm($code, $verifier, $redirectUri = null)
	{
		$config = $this->currentConfig();
		$form = array(
			'grant_type' => 'authorization_code', 'client_id' => is_scalar($config['client_id'] ?? null) ? (string) $config['client_id'] : '',
			'client_secret' => is_scalar($config['client_secret'] ?? null) ? (string) $config['client_secret'] : '', 'code' => $code,
			'redirect_uri' => $redirectUri ?? (is_scalar($config['redirect_uri'] ?? null) ? (string) $config['redirect_uri'] : ''),
		);
		if ($verifier !== '') {
			$form['code_verifier'] = $verifier;
		}
		return $form;
	}

	/**
	 * Body of a refresh with the configured credentials.
	 *
	 * @param	string	$refreshToken	Refresh token
	 * @return	array<string,string>
	 */
	public function refreshForm($refreshToken)
	{
		$config = $this->currentConfig();
		return array(
			'grant_type' => 'refresh_token', 'client_id' => is_scalar($config['client_id'] ?? null) ? (string) $config['client_id'] : '',
			'client_secret' => is_scalar($config['client_secret'] ?? null) ? (string) $config['client_secret'] : '', 'refresh_token' => $refreshToken,
		);
	}

	/**
	 * Read a token endpoint answer.
	 *
	 * @param	Response	$response	Response
	 * @return	Tokens
	 * @throws	RuntimeException		When it is not a token answer
	 */
	public static function tokens(array $response)
	{
		$data = self::decode($response);
		if ($response['status'] !== 200 || !is_string($data['access_token'] ?? null) || !is_string($data['refresh_token'] ?? null)) {
			throw new RuntimeException('No tokens: '.$response['status'].' '.$response['body']);
		}
		return array(
			'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'],
			'user_id' => is_int($data['user_id'] ?? null) ? $data['user_id'] : 0, 'expires_in' => is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 0,
		);
	}

	/**
	 * Whole authorization code flow with PKCE, as the module does it.
	 *
	 * @return	Tokens
	 * @throws	RuntimeException	When the flow fails
	 */
	public function connect()
	{
		$pkce = self::pkce();
		$params = self::redirectParams($this->authorize($pkce['challenge'], 'st'.bin2hex(random_bytes(4))));
		if (!isset($params['code'])) {
			throw new RuntimeException('No code in the redirect');
		}
		return self::tokens($this->token($this->codeForm($params['code'], $pkce['verifier'])));
	}

	/**
	 * Refresh and read the new pair.
	 *
	 * @param	string	$refreshToken	Refresh token
	 * @return	Tokens
	 */
	public function refresh($refreshToken)
	{
		return self::tokens($this->token($this->refreshForm($refreshToken)));
	}
}


/**
 * Stand-in for the module's webhook (test/fake/webhook.php on its own php -S): /ok answers 200, /fail 500, /slow?ms= late.
 */
class MeliFakeWebhook
{
	/**
	 * @var resource|null Process
	 */
	private $process = null;

	/**
	 * @var string Base URL
	 */
	private $baseUrl = '';

	/**
	 * @var string File where the requests received are appended
	 */
	private $logFile = '';

	/**
	 * Start it on a free port.
	 *
	 * @param	int		$workers	Workers
	 * @return	self
	 */
	public static function start($workers = 2)
	{
		$hook = new self();
		$hook->logFile = (string) tempnam(sys_get_temp_dir(), 'melihook');
		list($hook->process, $hook->baseUrl) = MeliFakeClient::launch(__DIR__.'/webhook.php', array('MELI_WEBHOOK_LOG' => $hook->logFile), $workers, '/ok');
		return $hook;
	}

	/**
	 * Stop it and remove its log.
	 *
	 * @return	void
	 */
	public function stop()
	{
		if (is_resource($this->process)) {
			MeliFakeClient::terminate($this->process);
			$this->process = null;
		}
		if ($this->logFile !== '' && file_exists($this->logFile)) {
			unlink($this->logFile);
		}
	}

	/**
	 * URL of one of its behaviours.
	 *
	 * @param	string	$path	/ok, /fail or /slow?ms=700
	 * @return	string
	 */
	public function url($path)
	{
		return $this->baseUrl.$path;
	}

	/**
	 * Notifications received so far, decoded, with the path they were posted to under '_path'.
	 *
	 * @return	list<array<string,mixed>>
	 */
	public function received()
	{
		$result = array();
		$lines = file_exists($this->logFile) ? file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
		foreach ($lines === false ? array() : $lines as $line) {
			$entry = MeliFakeClient::toMap(json_decode($line, true));
			$body = MeliFakeClient::toMap(json_decode(is_string($entry['body'] ?? null) ? $entry['body'] : '', true));
			$body['_path'] = $entry['path'] ?? '';
			$result[] = $body;
		}
		return $result;
	}
}
