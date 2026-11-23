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
 * \file    class/melihttp.class.php
 * \ingroup meli
 * \brief   The one place of the module that opens a connection to Mercado Libre.
 */

require_once __DIR__.'/meliconfig.class.php';

/**
 * HTTP transport
 */
class MeliHttp
{
	/**
	 * Send a request.
	 *
	 * @param	'GET'|'POST'|'PUT'|'DELETE'	$method		Method
	 * @param	string					$url		Full address
	 * @param	array<string, string>	$headers	Extra headers, name => value
	 * @param	string|null				$body		Body as sent, null for none
	 * @return	array{status: int, headers: array<string, string>, body: string, error: string, message: string, duration_ms: int}	error is '' when an HTTP answer came back (whatever its status), 'timeout' or 'network' otherwise; header names in lower case
	 */
	public static function send($method, $url, array $headers = array(), $body = null)
	{
		$result = array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'network', 'message' => '', 'duration_ms' => 0);

		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		// Plain HTTP only for the test double.
		if ($url === '' || $method === '' || ($scheme !== 'https' && !($scheme === 'http' && MeliConfig::isFake()))) {
			$result['message'] = 'Refused address';
			return $result;
		}

		$lines = array('Accept: application/json', 'Expect:');
		foreach ($headers as $name => $value) {
			$lines[] = $name.': '.$value;
		}
		$collector = new MeliHeaderCollector();
		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
		}
		curl_setopt($handle, CURLOPT_HTTPHEADER, $lines);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, max(1, getDolGlobalInt('MELI_CONNECT_TIMEOUT', 10)));
		curl_setopt($handle, CURLOPT_TIMEOUT, max(1, getDolGlobalInt('MELI_TIMEOUT', 30)));
		curl_setopt($handle, CURLOPT_USERAGENT, 'Dolibarr-meli');
		curl_setopt($handle, CURLOPT_HEADERFUNCTION, array($collector, 'collect'));
		if ($scheme === 'https') {
			curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
		}
		// Same proxy settings as the rest of Dolibarr.
		$proxy = getDolGlobalString('MAIN_PROXY_HOST');
		if (getDolGlobalString('MAIN_PROXY_USE') && $proxy !== '' && !MeliConfig::isFake()) {
			curl_setopt($handle, CURLOPT_PROXY, $proxy.':'.getDolGlobalString('MAIN_PROXY_PORT'));
			if (getDolGlobalString('MAIN_PROXY_USER') !== '') {
				curl_setopt($handle, CURLOPT_PROXYUSERPWD, getDolGlobalString('MAIN_PROXY_USER').':'.getDolGlobalString('MAIN_PROXY_PASS'));
			}
		}

		$start = microtime(true);
		$response = curl_exec($handle);
		$result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
		$errno = curl_errno($handle);
		$result['status'] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$message = curl_error($handle);
		curl_close($handle);

		if ($errno !== 0 || !is_string($response)) {
			$result['error'] = ($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network');
			$result['message'] = 'curl '.$errno.': '.$message;
			return $result;
		}
		$result['error'] = '';
		$result['body'] = $response;
		$result['headers'] = $collector->headers;

		return $result;
	}
}

/**
 * Collects response headers for curl; a named class because the project does not use closures.
 */
class MeliHeaderCollector
{
	/**
	 * @var array<string, string>	Headers received, lower-case name => value
	 */
	public $headers = array();

	/**
	 * Called by curl for each header line.
	 *
	 * @param	resource|CurlHandle	$handle	Curl handle
	 * @param	string				$line	Header line
	 * @return	int							Length handled
	 */
	public function collect($handle, $line)
	{
		$parts = explode(':', $line, 2);
		if (count($parts) === 2) {
			$this->headers[strtolower(trim($parts[0]))] = trim($parts[1]);
		}

		return strlen($line);
	}
}
