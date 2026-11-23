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
 * \file    test/fake/FakeSelfTest.php
 * \ingroup meli
 * \brief   Standalone PHPUnit test of the fake Mercado Libre (no Dolibarr needed).
 *
 * Run with: cd dev/tools && vendor/bin/phpunit -c phpunit.xml <module>/test/fake/FakeSelfTest.php
 */

require_once __DIR__.'/FakeClient.php';

/**
 * The fake Mercado Libre answers what the module's tests expect, in the shape of the documented examples.
 *
 * @phpstan-import-type Response from MeliFakeClient
 * @phpstan-import-type Tokens from MeliFakeClient
 */
class FakeSelfTest extends PHPUnit\Framework\TestCase
{
	const SELLER = 123456789;
	const NEW_FORMAT = 'x-format-new: true';

	/**
	 * @var MeliFakeClient|null Fake started for the class
	 */
	private static $fake = null;

	/**
	 * @var MeliFakeWebhook|null Stand-in for the module's webhook
	 */
	private static $hook = null;

	/**
	 * Start the fake and the webhook.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		self::$fake = MeliFakeClient::start(4);
		self::$hook = MeliFakeWebhook::start(2);
	}

	/**
	 * Stop them, workers included.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		if (self::$fake !== null) {
			self::$fake->stop();
			self::$fake = null;
		}
		if (self::$hook !== null) {
			self::$hook->stop();
			self::$hook = null;
		}
	}

	/**
	 * Fresh fake with the default configuration.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->assertSame(200, $this->fake()->reset()['_status']);
	}

	// ----------------------------------------------------------------------------------------------
	// Helpers

	/**
	 * The fake.
	 *
	 * @return MeliFakeClient
	 */
	private function fake()
	{
		if (self::$fake === null) {
			throw new RuntimeException('fake not started');
		}
		return self::$fake;
	}

	/**
	 * The webhook.
	 *
	 * @return MeliFakeWebhook
	 */
	private function hook()
	{
		if (self::$hook === null) {
			throw new RuntimeException('webhook not started');
		}
		return self::$hook;
	}

	/**
	 * Value at a dotted path in decoded JSON (e.g. "order_items.0.item.id").
	 *
	 * @param	mixed	$data	Data
	 * @param	string	$path	Path
	 * @return	mixed			Null when absent
	 */
	private static function path($data, $path)
	{
		foreach (explode('.', $path) as $key) {
			if (!is_array($data) || !array_key_exists($key, $data)) {
				return null;
			}
			$data = $data[$key];
		}
		return $data;
	}

	/**
	 * Value as a list.
	 *
	 * @param	mixed	$value	Value
	 * @return	list<mixed>
	 */
	private static function lst($value)
	{
		return is_array($value) ? array_values($value) : array();
	}

	/**
	 * Value as a string.
	 *
	 * @param	mixed	$value	Value
	 * @return	string
	 */
	private static function str($value)
	{
		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * Connected seller.
	 *
	 * @return	Tokens
	 */
	private function connect()
	{
		return $this->fake()->connect();
	}

	/**
	 * GET with a token, decoded.
	 *
	 * @param	string					$path		Path
	 * @param	string					$token		Token
	 * @param	array<string,string|int>	$query	Query
	 * @param	list<string>			$headers	Headers
	 * @return	array<string,mixed>
	 */
	private function getJson($path, $token, array $query = array(), array $headers = array())
	{
		$response = $this->fake()->api('GET', $path, $token, null, $headers, $query);
		$this->assertSame(200, $response['status'], $path.': '.$response['body']);
		return MeliFakeClient::decode($response);
	}

	/**
	 * A key is present and null (as in Mercado Libre's answers, e.g. pack_id of an order without pack).
	 *
	 * @param	array<string,mixed>	$data	Decoded object
	 * @param	string				$key	Key
	 * @return	void
	 */
	private function assertNullField(array $data, $key)
	{
		$this->assertArrayHasKey($key, $data);
		$this->assertNull($data[$key]);
	}

	/**
	 * Check a minimal PDF: header, trailer, cross-reference offsets, page count.
	 *
	 * @param	string	$pdf	Content
	 * @param	int		$pages	Expected pages
	 * @return	void
	 */
	private function assertValidPdf($pdf, $pages)
	{
		$this->assertStringStartsWith('%PDF-1.', $pdf);
		$this->assertSame("%%EOF\n", substr($pdf, -6));
		if (!preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m)) {
			$this->fail('no startxref');
		}
		$xref = (int) $m[1];
		$this->assertSame('xref', substr($pdf, $xref, 4));
		if (!preg_match('/^xref\n0 (\d+)\n/', substr($pdf, $xref), $h)) {
			$this->fail('no xref header');
		}
		$size = (int) $h[1];
		$entries = explode("\n", substr($pdf, $xref + strlen($h[0])));
		for ($number = 1; $number < $size; $number++) {
			if (!preg_match('/^(\d{10}) 00000 n $/', $entries[$number] ?? '', $e)) {
				$this->fail('bad xref entry '.$number);
			}
			$this->assertSame($number.' 0 obj', substr($pdf, (int) $e[1], strlen($number.' 0 obj')), 'offset of object '.$number);
		}
		$this->assertSame($pages, preg_match_all('#/Type /Page /#', $pdf));
		$this->assertStringContainsString('/Count '.$pages.' ', $pdf);
	}

	// ----------------------------------------------------------------------------------------------
	// OAuth

	/**
	 * Authorization code + PKCE S256: redirect with code and state, exchange, then a call with the token.
	 *
	 * @return void
	 */
	public function testCodeFlowWithPkce()
	{
		$pkce = MeliFakeClient::pkce();
		$redirect = $this->fake()->authorize($pkce['challenge'], 'abc123');
		$this->assertSame(302, $redirect['status']);
		$this->assertStringStartsWith('https://shop.example.test/custom/meli/public/oauth.php?', $redirect['headers']['location'] ?? '');
		$params = MeliFakeClient::redirectParams($redirect);
		$this->assertSame('abc123', $params['state'] ?? null);
		$this->assertMatchesRegularExpression('/^TG-[0-9a-f]+-'.self::SELLER.'$/', $params['code'] ?? '');

		$answer = $this->fake()->token($this->fake()->codeForm($params['code'] ?? '', $pkce['verifier']));
		$this->assertSame(200, $answer['status'], $answer['body']);
		$data = MeliFakeClient::decode($answer);
		$this->assertSame('Bearer', $data['token_type'] ?? null);
		$this->assertSame(21600, $data['expires_in'] ?? null);
		$this->assertSame(self::SELLER, $data['user_id'] ?? null);
		$this->assertSame('offline_access read write', $data['scope'] ?? null);
		$this->assertMatchesRegularExpression('/^APP_USR-/', self::str($data['access_token'] ?? null));

		$me = $this->getJson('/users/me', self::str($data['access_token'] ?? null));
		$this->assertSame(self::SELLER, $me['id'] ?? null);
		$this->assertSame(array('normal', 'user_product_seller'), $me['tags'] ?? null);
	}

	/**
	 * A wrong verifier, a missing verifier, a reused code and a different redirect_uri are refused with invalid_grant.
	 *
	 * @return void
	 */
	public function testCodeExchangeRefusals()
	{
		$fake = $this->fake();
		$pkce = MeliFakeClient::pkce();
		$code = MeliFakeClient::redirectParams($fake->authorize($pkce['challenge'], 's'))['code'] ?? '';
		$bad = $fake->token($fake->codeForm($code, 'not-the-verifier'));
		$this->assertSame(400, $bad['status']);
		$this->assertSame('invalid_grant', MeliFakeClient::decode($bad)['error'] ?? null);

		$code = MeliFakeClient::redirectParams($fake->authorize($pkce['challenge'], 's'))['code'] ?? '';
		$this->assertSame('invalid_grant', MeliFakeClient::decode($fake->token($fake->codeForm($code, '')))['error'] ?? null);

		$code = MeliFakeClient::redirectParams($fake->authorize($pkce['challenge'], 's'))['code'] ?? '';
		$this->assertSame(200, $fake->token($fake->codeForm($code, $pkce['verifier']))['status']);
		$reuse = $fake->token($fake->codeForm($code, $pkce['verifier']));
		$this->assertSame(400, $reuse['status']);
		$this->assertSame('invalid_grant', MeliFakeClient::decode($reuse)['error'] ?? null);

		$code = MeliFakeClient::redirectParams($fake->authorize($pkce['challenge'], 's'))['code'] ?? '';
		$other = $fake->token($fake->codeForm($code, $pkce['verifier'], 'https://shop.example.test/other.php'));
		$this->assertSame('invalid_grant', MeliFakeClient::decode($other)['error'] ?? null);

		$secret = $fake->codeForm('x', '');
		$secret['client_secret'] = 'wrong';
		$this->assertSame('invalid_client', MeliFakeClient::decode($fake->token($secret))['error'] ?? null);
	}

	/**
	 * The authorize page: unregistered redirect_uri (no redirect), PKCE required, user denies.
	 *
	 * @return void
	 */
	public function testAuthorizePageRules()
	{
		$fake = $this->fake();
		$wrong = $fake->authorize('c', 's', array('redirect_uri' => 'https://evil.example.test/cb'));
		$this->assertSame(400, $wrong['status']);
		$this->assertArrayNotHasKey('location', $wrong['headers']);

		$noPkce = MeliFakeClient::redirectParams($fake->authorize('', 's1'));
		$this->assertSame('invalid_request', $noPkce['error'] ?? null);
		$this->assertSame('s1', $noPkce['state'] ?? null);

		$fake->config(array('authorize_outcome' => 'deny'));
		$denied = MeliFakeClient::redirectParams($fake->authorize(MeliFakeClient::pkce()['challenge'], 's2'));
		$this->assertSame('access_denied', $denied['error'] ?? null);
		$this->assertSame('s2', $denied['state'] ?? null);
		$this->assertArrayNotHasKey('code', $denied);
	}

	/**
	 * OAuth parameters in the query string, or a JSON body, are refused.
	 *
	 * @return void
	 */
	public function testTokenParametersOnlyInFormBody()
	{
		$fake = $this->fake();
		$form = $fake->refreshForm('TG-x');
		$query = $fake->token($form, array('grant_type' => 'refresh_token', 'refresh_token' => 'TG-x'));
		$this->assertSame(400, $query['status']);
		$this->assertSame('invalid_request', MeliFakeClient::decode($query)['error'] ?? null);

		$json = $fake->request('POST', '/oauth/token', array('json' => $form));
		$this->assertSame('invalid_request', MeliFakeClient::decode($json)['error'] ?? null);
	}

	/**
	 * Each refresh gives a new pair; reusing an old refresh token is invalid_grant and revokes the whole grant.
	 *
	 * @return void
	 */
	public function testRefreshIsSingleUseAndReuseRevokesGrant()
	{
		$fake = $this->fake();
		$first = $this->connect();
		$second = $fake->refresh($first['refresh_token']);
		$this->assertNotSame($first['refresh_token'], $second['refresh_token']);
		$this->assertNotSame($first['access_token'], $second['access_token']);
		$this->getJson('/users/me', $second['access_token']);

		$reuse = $fake->token($fake->refreshForm($first['refresh_token']));
		$this->assertSame(400, $reuse['status']);
		$this->assertSame('invalid_grant', MeliFakeClient::decode($reuse)['error'] ?? null);

		$this->assertSame('invalid_grant', MeliFakeClient::decode($fake->token($fake->refreshForm($second['refresh_token'])))['error'] ?? null);
		$this->assertSame(401, $fake->api('GET', '/users/me', $second['access_token'])['status']);
	}

	/**
	 * With revoke_on_reuse off, a reuse is refused but the latest refresh token still works.
	 *
	 * @return void
	 */
	public function testReuseWithoutRevocation()
	{
		$fake = $this->fake();
		$fake->config(array('revoke_on_reuse' => false));
		$first = $this->connect();
		$second = $fake->refresh($first['refresh_token']);
		$this->assertSame(400, $fake->token($fake->refreshForm($first['refresh_token']))['status']);
		$third = $fake->refresh($second['refresh_token']);
		$this->getJson('/users/me', $third['access_token']);
	}

	/**
	 * The access token expires after expires_in seconds of fake time; a refresh then gives a working one.
	 *
	 * @return void
	 */
	public function testTokenExpiryWithAdvanceTime()
	{
		$fake = $this->fake();
		$fake->config(array('expires_in' => 60));
		$tokens = $this->connect();
		$this->assertSame(60, $tokens['expires_in']);
		$this->getJson('/users/me', $tokens['access_token']);
		$fake->advanceTime(61);
		$expired = $fake->api('GET', '/users/me', $tokens['access_token']);
		$this->assertSame(401, $expired['status']);
		$this->assertSame(array('message' => 'invalid_token', 'error' => 'not_found', 'status' => 401, 'cause' => array()), MeliFakeClient::decode($expired));
		$this->getJson('/users/me', $fake->refresh($tokens['refresh_token'])['access_token']);
	}

	/**
	 * Only "Authorization: Bearer" is accepted; the token in the query string is refused even with the header.
	 * The request log says whether the header was there but never holds a token.
	 *
	 * @return void
	 */
	public function testBearerOnlyAndLogWithoutTokens()
	{
		$fake = $this->fake();
		$tokens = $this->connect();
		$this->assertSame(401, $fake->request('GET', '/users/me')['status']);
		$this->assertSame(401, $fake->request('GET', '/users/me', array('query' => array('access_token' => $tokens['access_token'])))['status']);
		$this->assertSame(401, $fake->api('GET', '/users/me', $tokens['access_token'], null, array(), array('access_token' => $tokens['access_token']))['status']);
		$this->assertSame(401, $fake->request('GET', '/users/me', array('headers' => array('Authorization: token '.$tokens['access_token'])))['status']);
		$this->assertSame(401, $fake->api('GET', '/users/me', 'APP_USR-unknown')['status']);
		$this->getJson('/users/me', $tokens['access_token']);

		$log = $fake->log();
		$last = $log[count($log) - 1];
		$this->assertSame(array('GET', '/users/me', true, 200), array($last['method'] ?? null, $last['path'] ?? null, $last['auth_header'] ?? null, $last['status'] ?? null));
		$this->assertFalse($log[count($log) - 6]['auth_header'] ?? true);
		$encoded = (string) json_encode($log);
		$this->assertStringNotContainsString($tokens['access_token'], $encoded);
		$this->assertStringNotContainsString($tokens['refresh_token'], $encoded);
		$this->assertStringNotContainsString('APP_USR', $encoded);
	}

	/**
	 * Revoking the grant from the control endpoint stops the access and the refresh token.
	 *
	 * @return void
	 */
	public function testRevokeGrant()
	{
		$fake = $this->fake();
		$tokens = $this->connect();
		$this->assertSame(1, $fake->revoke()['revoked'] ?? null);
		$this->assertSame(401, $fake->api('GET', '/users/me', $tokens['access_token'])['status']);
		$this->assertSame('invalid_grant', MeliFakeClient::decode($fake->token($fake->refreshForm($tokens['refresh_token'])))['error'] ?? null);
	}

	/**
	 * Two processes refresh with the same refresh token at the same time: exactly one gets new tokens.
	 *
	 * @return void
	 */
	public function testConcurrentRefreshOnlyOneWins()
	{
		$fake = $this->fake();
		$tokens = $this->connect();
		$form = $fake->refreshForm($tokens['refresh_token']);
		$options = array('form' => $form);
		$answers = $fake->requestMany(array(array('POST', '/oauth/token', $options), array('POST', '/oauth/token', $options)));
		$statuses = array($answers[0]['status'], $answers[1]['status']);
		sort($statuses);
		$this->assertSame(array(200, 400), $statuses);
		$loser = $answers[0]['status'] === 400 ? $answers[0] : $answers[1];
		$this->assertSame('invalid_grant', MeliFakeClient::decode($loser)['error'] ?? null);
		$tokenCalls = 0;
		foreach ($fake->log() as $entry) {
			$tokenCalls += ($entry['path'] ?? '') === '/oauth/token' ? 1 : 0;
		}
		$this->assertSame(3, $tokenCalls);
	}

	// ----------------------------------------------------------------------------------------------
	// Users

	/**
	 * /users/me tags follow the configuration, /users/{id}, test users with their limit.
	 *
	 * @return void
	 */
	public function testUsers()
	{
		$fake = $this->fake();
		$fake->config(array('user' => array('tags' => array('normal')), 'test_users_max' => 2));
		$token = $this->connect()['access_token'];
		$this->assertSame(array('normal'), $this->getJson('/users/me', $token)['tags'] ?? null);
		$this->assertSame('FAKESELLER', $this->getJson('/users/'.self::SELLER, $token)['nickname'] ?? null);
		$this->assertSame(404, $fake->api('GET', '/users/42', $token)['status']);

		$created = $fake->api('POST', '/users/test_user', $token, array('site_id' => 'MLA'));
		$this->assertSame(201, $created['status']);
		$user = MeliFakeClient::decode($created);
		$this->assertIsInt($user['id'] ?? null);
		$this->assertStringStartsWith('TESTUSER', self::str($user['nickname'] ?? null));
		$this->assertNotSame('', self::str($user['password'] ?? null));
		$this->assertSame(200, $fake->api('GET', '/users/'.self::str($user['id'] ?? null), $token)['status']);
		$this->assertSame(400, $fake->api('POST', '/users/test_user', $token, array())['status']);
		$this->assertSame(201, $fake->api('POST', '/users/test_user', $token, array('site_id' => 'MLA'))['status']);
		$this->assertSame(400, $fake->api('POST', '/users/test_user', $token, array('site_id' => 'MLA'))['status']);
	}

	// ----------------------------------------------------------------------------------------------
	// Items and stock

	/**
	 * Seller's items: search (status filter, paging, scan, SKU), bulk (UP and legacy shapes), deprecated multiget.
	 *
	 * @return void
	 */
	public function testItemsSearchAndBulk()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$up = $fake->seedItem(array('model' => 'up', 'sku' => 'MATE-01', 'family_name' => 'Mate de calabaza', 'quantity' => 3));
		$fake->seedItem(array('model' => 'up', 'sku' => 'MATE-02', 'quantity' => 0));
		$legacy = $fake->seedItem(array('model' => 'legacy', 'title' => 'Poncho', 'variations' => array(
			array('sku' => 'PONCHO-R', 'quantity' => 2, 'attribute_combinations' => array(array('id' => 'COLOR', 'name' => 'Color', 'value_id' => null, 'value_name' => 'Rojo'))),
			array('sku' => 'PONCHO-A', 'quantity' => 1),
		)));
		$fake->seedItem(array('model' => 'up', 'sku' => 'OLD', 'status' => 'closed'));

		$this->assertSame('MATE-01', self::path($up, 'attributes.0.value_name'));
		$this->assertSame('SELLER_SKU', self::path($up, 'attributes.0.id'));
		$this->assertSame('Mate de calabaza', $up['family_name'] ?? null);
		$this->assertMatchesRegularExpression('/^MLAU\d+$/', self::str($up['user_product_id'] ?? null));
		$this->assertSame(array(), $up['variations'] ?? null);
		$this->assertNullField($legacy, 'family_name');
		$this->assertNullField($legacy, 'user_product_id');
		$this->assertSame(3, $legacy['available_quantity'] ?? null);
		$this->assertIsInt(self::path($legacy, 'variations.0.id'));
		$this->assertSame('Rojo', self::path($legacy, 'variations.0.attribute_combinations.0.value_name'));
		$this->assertSame('PONCHO-A', self::path($legacy, 'variations.1.attributes.0.value_name'));

		$search = $this->getJson('/users/'.self::SELLER.'/items/search', $token, array('status' => 'active,paused'));
		$this->assertCount(3, self::lst($search['results'] ?? null));
		$this->assertSame(3, self::path($search, 'paging.total'));
		$page = $this->getJson('/users/'.self::SELLER.'/items/search', $token, array('status' => 'active,paused', 'offset' => 2, 'limit' => 2));
		$this->assertCount(1, self::lst($page['results'] ?? null));
		$this->assertSame(400, $fake->api('GET', '/users/'.self::SELLER.'/items/search', $token, null, array(), array('offset' => 990, 'limit' => 20))['status']);
		$this->assertSame(403, $fake->api('GET', '/users/42/items/search', $token)['status']);
		$sku = $this->getJson('/users/'.self::SELLER.'/items/search', $token, array('seller_sku' => 'PONCHO-A'));
		$this->assertSame(array($legacy['id'] ?? null), $sku['results'] ?? null);

		$scanned = array();
		$scroll = null;
		for ($round = 0; $round < 5; $round++) {
			$query = array('status' => 'active,paused', 'search_type' => 'scan', 'limit' => 2);
			if ($scroll !== null) {
				$query['scroll_id'] = $scroll;
			}
			$chunk = $this->getJson('/users/'.self::SELLER.'/items/search', $token, $query);
			$results = self::lst($chunk['results'] ?? null);
			if (!count($results)) {
				break;
			}
			$scanned = array_merge($scanned, $results);
			$scroll = self::str($chunk['scroll_id'] ?? null);
		}
		$this->assertSame($search['results'] ?? null, $scanned);

		$bulk = $fake->api('GET', '/items/bulk', $token, null, array(), array('ids' => self::str($up['id'] ?? null).','.self::str($legacy['id'] ?? null).',MLA999'));
		$this->assertSame(200, $bulk['status']);
		$entries = MeliFakeClient::decodeList($bulk);
		$this->assertSame(array(200, 200, 404), array($entries[0]['code'] ?? null, $entries[1]['code'] ?? null, $entries[2]['code'] ?? null));
		$this->assertSame($up['id'] ?? null, self::path($entries[0], 'body.id'));
		$this->assertSame('PONCHO-R', self::path($entries[1], 'body.variations.0.attributes.0.value_name'));
		$this->assertSame('not_found', self::path($entries[2], 'body.error'));
		$this->assertSame(400, $fake->api('GET', '/items/bulk', $token, null, array(), array('ids' => implode(',', array_fill(0, 21, 'MLA1'))))['status']);
		$old = $fake->api('GET', '/items', $token, null, array(), array('ids' => self::str($up['id'] ?? null)));
		$this->assertSame(400, $old['status']);
		$this->assertSame('deprecated', MeliFakeClient::decode($old)['error'] ?? null);
		$this->assertSame(404, $fake->api('GET', '/items/MLA999', $token)['status']);
	}

	/**
	 * PUT /items/{id}: quantity on a UP item (propagated to the user product and its other items), per variation on a
	 * legacy item, pause at 0 and reactivation, and the refusals.
	 *
	 * @return void
	 */
	public function testPutItemQuantity()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$up = $fake->seedItem(array('model' => 'up', 'sku' => 'A', 'quantity' => 1));
		$upId = self::str($up['user_product_id'] ?? null);
		$sibling = $fake->seedItem(array('model' => 'up', 'sku' => 'A', 'user_product_id' => $upId));
		$id = self::str($up['id'] ?? null);

		$put = $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 5));
		$this->assertSame(200, $put['status'], $put['body']);
		$this->assertSame(5, MeliFakeClient::decode($put)['available_quantity'] ?? null);
		$stock = $fake->api('GET', '/user-products/'.$upId.'/stock', $token);
		$this->assertSame(5, self::path(MeliFakeClient::decode($stock), 'locations.0.quantity'));
		$this->assertSame('2', $stock['headers']['x-version'] ?? null);
		$this->assertSame(5, $this->getJson('/items/'.self::str($sibling['id'] ?? null), $token)['available_quantity'] ?? null);

		$zero = MeliFakeClient::decode($fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 0)));
		$this->assertSame(array('paused', array('out_of_stock')), array($zero['status'] ?? null, $zero['sub_status'] ?? null));
		$back = MeliFakeClient::decode($fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 2)));
		$this->assertSame('active', $back['status'] ?? null);
		$paused = MeliFakeClient::decode($fake->api('PUT', '/items/'.$id, $token, array('status' => 'paused')));
		$this->assertSame('paused', $paused['status'] ?? null);
		$this->assertSame('paused', MeliFakeClient::decode($fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 3)))['status'] ?? null);

		$this->assertSame(400, $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => -1))['status']);
		$this->assertSame(400, $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 1.5))['status']);
		$family = $fake->api('PUT', '/items/'.$id, $token, array('variations' => array(array('id' => 1, 'available_quantity' => 1))));
		$this->assertSame(400, $family['status']);
		$this->assertStringContainsString('invalid with family name', self::str(MeliFakeClient::decode($family)['message'] ?? null));

		$legacy = $fake->seedItem(array('model' => 'legacy', 'variations' => array(array('sku' => 'L1', 'quantity' => 1), array('sku' => 'L2', 'quantity' => 1))));
		$lid = self::str($legacy['id'] ?? null);
		$v2 = self::path($legacy, 'variations.1.id');
		$this->assertSame(400, $fake->api('PUT', '/items/'.$lid, $token, array('available_quantity' => 4))['status']);
		$variation = MeliFakeClient::decode($fake->api('PUT', '/items/'.$lid, $token, array('variations' => array(array('id' => $v2, 'available_quantity' => 4)))));
		$this->assertSame(4, self::path($variation, 'variations.1.available_quantity'));
		$this->assertSame(1, self::path($variation, 'variations.0.available_quantity'));
		$this->assertSame(5, $variation['available_quantity'] ?? null);
		$this->assertSame(400, $fake->api('PUT', '/items/'.$lid, $token, array('variations' => array(array('id' => 1, 'available_quantity' => 1))))['status']);

		$closed = $fake->seedItem(array('model' => 'up', 'status' => 'closed'));
		$this->assertSame(400, $fake->api('PUT', '/items/'.self::str($closed['id'] ?? null), $token, array('available_quantity' => 1))['status']);
		$this->assertSame(404, $fake->api('PUT', '/items/MLA999', $token, array('available_quantity' => 1))['status']);
	}

	/**
	 * x-version stock writes: missing header 400, stale 409, current version accepted and bumped; reflected on the item.
	 *
	 * @return void
	 */
	public function testUserProductStockWithXVersion()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$item = $fake->seedItem(array('model' => 'up', 'sku' => 'B', 'quantity' => 4));
		$upId = self::str($item['user_product_id'] ?? null);
		$path = '/user-products/'.$upId.'/stock/type/selling_address';

		$read = $fake->api('GET', '/user-products/'.$upId.'/stock', $token);
		$this->assertSame(200, $read['status']);
		$this->assertSame(array('locations' => array(array('type' => 'selling_address', 'quantity' => 4)), 'user_id' => self::SELLER, 'id' => $upId), MeliFakeClient::decode($read));
		$version = (int) ($read['headers']['x-version'] ?? 0);

		$this->assertSame(400, $fake->api('PUT', $path, $token, array('quantity' => 2))['status']);
		$this->assertSame(409, $fake->api('PUT', $path, $token, array('quantity' => 2), array('x-version: '.($version - 1)))['status']);
		$this->assertSame(400, $fake->api('PUT', $path, $token, array('quantity' => -2), array('x-version: '.$version))['status']);
		$ok = $fake->api('PUT', $path, $token, array('quantity' => 2), array('x-version: '.$version));
		$this->assertSame(200, $ok['status'], $ok['body']);
		$this->assertSame((string) ($version + 1), $ok['headers']['x-version'] ?? null);
		$this->assertSame(2, self::path(MeliFakeClient::decode($ok), 'locations.0.quantity'));
		$this->assertSame(2, $this->getJson('/items/'.self::str($item['id'] ?? null), $token)['available_quantity'] ?? null);

		$fake->setStock(array('user_product_id' => $upId, 'quantity' => 7));
		$this->assertSame(409, $fake->api('PUT', $path, $token, array('quantity' => 1), array('x-version: '.($version + 1)))['status']);
		$this->assertSame(7, $this->getJson('/items/'.self::str($item['id'] ?? null), $token)['available_quantity'] ?? null);
		$this->assertSame(404, $fake->api('GET', '/user-products/MLAU1/stock', $token)['status']);
	}

	// ----------------------------------------------------------------------------------------------
	// Orders

	/**
	 * A cart sale: pack with two orders, billing info, shipment; a single sale without pack; search by date.
	 *
	 * @return void
	 */
	public function testOrdersPacksSearchAndBillingInfo()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$up = $fake->seedItem(array('model' => 'up', 'sku' => 'MATE', 'quantity' => 5, 'price' => 15000));
		$legacy = $fake->seedItem(array('model' => 'legacy', 'variations' => array(array('sku' => 'PON-R', 'quantity' => 2))));
		$variationId = self::path($legacy, 'variations.0.id');
		$sale = $fake->seedOrder(array(
			'items' => array(array('item_id' => $up['id'] ?? '', 'quantity' => 2, 'sale_fee' => 1950.5), array('item_id' => $legacy['id'] ?? '', 'variation_id' => $variationId)),
			'buyer' => array('nickname' => 'COMPRADOR1'),
			'billing' => array('name' => 'Taller', 'last_name' => 'SRL', 'doc_type' => 'CUIT', 'doc_number' => '30712345671', 'taxpayer_type' => 'IVA Responsable Inscripto'),
			'shipment' => array('receiver_cost' => 3500.0, 'sender_cost' => 1200.0),
		));
		$this->assertSame(200, $sale['_status']);
		$orderIds = self::lst($sale['order_ids'] ?? null);
		$this->assertCount(2, $orderIds);
		$this->assertIsInt($sale['pack_id'] ?? null);

		$pack = $this->getJson('/packs/'.self::str($sale['pack_id'] ?? null), $token);
		$this->assertSame(array(array('id' => $orderIds[0]), array('id' => $orderIds[1])), $pack['orders'] ?? null);
		$this->assertSame($sale['shipment_id'] ?? null, self::path($pack, 'shipment.id'));

		$order = $this->getJson('/orders/'.self::str($orderIds[0]), $token);
		$this->assertSame('paid', $order['status'] ?? null);
		$this->assertSame($sale['pack_id'] ?? null, $order['pack_id'] ?? null);
		$this->assertSame($up['id'] ?? null, self::path($order, 'order_items.0.item.id'));
		$this->assertNull(self::path($order, 'order_items.0.item.variation_id'));
		$this->assertSame('MATE', self::path($order, 'order_items.0.item.seller_sku'));
		$this->assertSame(2, self::path($order, 'order_items.0.quantity'));
		$this->assertSame(15000.0, self::path($order, 'order_items.0.unit_price'));
		$this->assertSame(1950.5, self::path($order, 'order_items.0.sale_fee'));
		$this->assertSame(30000.0, $order['total_amount'] ?? null);
		$this->assertSame(33500.0, $order['paid_amount'] ?? null);
		$this->assertSame('approved', self::path($order, 'payments.0.status'));
		$this->assertSame($sale['shipment_id'] ?? null, self::path($order, 'shipping.id'));
		$this->assertSame('COMPRADOR1', self::path($order, 'buyer.nickname'));
		$this->assertSame($sale['billing_info_id'] ?? null, self::path($order, 'buyer.billing_info.id'));
		$this->assertContains('pack_order', self::lst($order['tags'] ?? null));
		$this->assertNullField($order, 'cancel_detail');
		foreach (array('date_created', 'date_last_updated') as $field) {
			$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}-03:00$/', self::str($order[$field] ?? null));
		}
		$second = $this->getJson('/orders/'.self::str($orderIds[1]), $token);
		$this->assertSame($variationId, self::path($second, 'order_items.0.item.variation_id'));
		$this->assertSame('PON-R', self::path($second, 'order_items.0.item.seller_sku'));

		$this->assertSame(3, $this->getJson('/items/'.self::str($up['id'] ?? null), $token)['available_quantity'] ?? null);
		$this->assertSame(1, $this->getJson('/items/'.self::str($legacy['id'] ?? null), $token)['available_quantity'] ?? null);

		$billing = $this->getJson('/orders/billing-info/MLA/'.self::str($sale['billing_info_id'] ?? null), $token);
		$this->assertSame('Taller', self::path($billing, 'buyer.billing_info.name'));
		$this->assertSame('SRL', self::path($billing, 'buyer.billing_info.last_name'));
		$this->assertSame(array('type' => 'CUIT', 'number' => '30712345671'), self::path($billing, 'buyer.billing_info.identification'));
		$this->assertSame('IVA Responsable Inscripto', self::path($billing, 'buyer.billing_info.taxes.taxpayer_type.description'));
		$this->assertSame('Rosario', self::path($billing, 'buyer.billing_info.address.city_name'));
		$this->assertSame(404, $fake->api('GET', '/orders/billing-info/MLA/1', $token)['status']);

		$single = $fake->seedOrder(array('items' => array(array('item_id' => $up['id'] ?? '')), 'shipment' => false));
		$this->assertNullField($single, 'pack_id');
		$this->assertNullField($single, 'shipment_id');
		$singleId = self::str(self::path($single, 'order_ids.0'));
		$this->assertNull(self::path($this->getJson('/orders/'.$singleId, $token), 'shipping.id'));
		$this->assertSame(array(array('id' => (int) $singleId)), $this->getJson('/packs/'.$singleId, $token)['orders'] ?? null);
		$this->assertSame(404, $fake->api('GET', '/packs/'.self::str($orderIds[0]), $token)['status']);

		$from = MeliFakeRouterTime::iso(time() - 3600);
		$all = $this->getJson('/orders/search', $token, array('seller' => self::SELLER, 'order.date_last_updated.from' => $from, 'order.date_last_updated.to' => MeliFakeRouterTime::iso(time() + 3600)));
		$this->assertSame(3, self::path($all, 'paging.total'));
		$this->assertSame(3, count(self::lst($all['results'] ?? null)));
		$this->assertSame($orderIds[0], self::path($all, 'results.0.id'));
		$page = $this->getJson('/orders/search', $token, array('seller' => self::SELLER, 'order.date_last_updated.from' => $from, 'offset' => 2, 'limit' => 1));
		$this->assertSame(3, self::path($page, 'paging.total'));
		$this->assertSame((int) $singleId, self::path($page, 'results.0.id'));
		$none = $this->getJson('/orders/search', $token, array('seller' => self::SELLER, 'order.date_last_updated.from' => MeliFakeRouterTime::iso(time() + 7200)));
		$this->assertSame(0, self::path($none, 'paging.total'));
		$this->assertSame(403, $fake->api('GET', '/orders/search', $token, null, array(), array('seller' => 42))['status']);
		$this->assertSame(404, $fake->api('GET', '/orders/1', $token)['status']);
	}

	/**
	 * Cancellation and fraud flag after the sale move date_last_updated, so the polling net finds them.
	 *
	 * @return void
	 */
	public function testCancelAndFraudAfterSale()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$item = $fake->seedItem(array('model' => 'up', 'sku' => 'C', 'quantity' => 2));
		$a = self::str(self::path($fake->seedOrder(array('items' => array(array('item_id' => $item['id'] ?? '')))), 'order_ids.0'));
		$b = self::str(self::path($fake->seedOrder(array('items' => array(array('item_id' => $item['id'] ?? '')))), 'order_ids.0'));
		$fake->advanceTime(7200);
		$fake->cancelOrder($a, array('requested_by' => 'seller'));
		$fake->flagFraud($b);

		$cancelled = $this->getJson('/orders/'.$a, $token);
		$this->assertSame('cancelled', $cancelled['status'] ?? null);
		$this->assertSame('seller', self::path($cancelled, 'cancel_detail.requested_by'));
		$this->assertSame('refunded', self::path($cancelled, 'payments.0.status'));
		$this->assertContains('fraud_risk_detected', self::lst($this->getJson('/orders/'.$b, $token)['tags'] ?? null));

		$recent = $this->getJson('/orders/search', $token, array('seller' => self::SELLER, 'order.date_last_updated.from' => MeliFakeRouterTime::iso(time() + 3600)));
		$this->assertSame(2, self::path($recent, 'paging.total'));
		$this->assertSame(404, $fake->cancelOrder('1')['_status']);
	}

	// ----------------------------------------------------------------------------------------------
	// Shipments and labels

	/**
	 * Shipments need x-format-new: true; costs split; the destination is hidden until the order is paid.
	 *
	 * @return void
	 */
	public function testShipments()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$item = $fake->seedItem(array('model' => 'up', 'sku' => 'D', 'quantity' => 3));
		$sale = $fake->seedOrder(array('items' => array(array('item_id' => $item['id'] ?? '')), 'shipment' => array('receiver_cost' => 2500.0, 'sender_cost' => 900.0, 'logistic_type' => 'cross_docking')));
		$path = '/shipments/'.self::str($sale['shipment_id'] ?? null);

		$plain = $fake->api('GET', $path, $token);
		$this->assertSame(400, $plain['status']);
		$this->assertSame(400, $fake->api('GET', $path.'/costs', $token)['status']);
		$shipment = $this->getJson($path, $token, array(), array(self::NEW_FORMAT));
		$this->assertSame(array('ready_to_ship', 'ready_to_print'), array($shipment['status'] ?? null, $shipment['substatus'] ?? null));
		$this->assertSame(array('mode' => 'me2', 'type' => 'cross_docking', 'direction' => 'forward'), $shipment['logistic'] ?? null);
		$this->assertSame('Avenida Siempreviva', self::path($shipment, 'destination.shipping_address.street_name'));
		$this->assertArrayNotHasKey('_orders', $shipment);
		$costs = $this->getJson($path.'/costs', $token, array(), array(self::NEW_FORMAT));
		$this->assertSame(2500.0, self::path($costs, 'receiver.cost'));
		$this->assertSame(900.0, self::path($costs, 'senders.0.cost'));

		$unpaid = $fake->seedOrder(array('items' => array(array('item_id' => $item['id'] ?? '')), 'status' => 'payment_required'));
		$hidden = $this->getJson('/shipments/'.self::str($unpaid['shipment_id'] ?? null), $token, array(), array(self::NEW_FORMAT));
		$this->assertNull(self::path($hidden, 'destination.shipping_address'));
		$this->assertSame(2, $this->getJson('/items/'.self::str($item['id'] ?? null), $token)['available_quantity'] ?? null);
		$this->assertSame(404, $fake->api('GET', '/shipments/1', $token, null, array(self::NEW_FORMAT))['status']);
	}

	/**
	 * Labels: valid PDF with one page per shipment, marks them printed, reprints allowed; status, Full and 50-id rules.
	 *
	 * @return void
	 */
	public function testShipmentLabels()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$item = $fake->seedItem(array('model' => 'up', 'sku' => 'E', 'quantity' => 10));
		$ids = array();
		for ($n = 0; $n < 3; $n++) {
			$ids[] = self::str($fake->seedOrder(array('items' => array(array('item_id' => $item['id'] ?? ''))))['shipment_id'] ?? null);
		}
		$query = array('shipment_ids' => $ids[0].','.$ids[1], 'response_type' => 'pdf');
		$pdf = $fake->api('GET', '/shipment_labels', $token, null, array(), $query);
		$this->assertSame(200, $pdf['status'], $pdf['body']);
		$this->assertSame('application/pdf', $pdf['headers']['content-type'] ?? null);
		$this->assertValidPdf($pdf['body'], 2);
		$this->assertSame('printed', $this->getJson('/shipments/'.$ids[0], $token, array(), array(self::NEW_FORMAT))['substatus'] ?? null);
		$this->assertSame('ready_to_print', $this->getJson('/shipments/'.$ids[2], $token, array(), array(self::NEW_FORMAT))['substatus'] ?? null);
		$this->assertSame(200, $fake->api('GET', '/shipment_labels', $token, null, array(), $query)['status']);

		$fake->setShipment(array('id' => $ids[2], 'status' => 'shipped', 'substatus' => null));
		$refused = $fake->api('GET', '/shipment_labels', $token, null, array(), array('shipment_ids' => $ids[0].','.$ids[2], 'response_type' => 'pdf'));
		$this->assertSame(400, $refused['status']);
		$causes = self::lst(MeliFakeClient::decode($refused)['cause'] ?? null);
		$this->assertCount(1, $causes);
		$this->assertSame('NOT_PRINTABLE_STATUS', self::path($causes, '0.code'));
		$this->assertSame((int) $ids[2], self::path($causes, '0.shipment_id'));

		$fake->setShipment(array('id' => $ids[2], 'status' => 'ready_to_ship', 'substatus' => 'ready_to_print', 'logistic_type' => 'fulfillment'));
		$this->assertSame(400, $fake->api('GET', '/shipment_labels', $token, null, array(), array('shipment_ids' => $ids[2], 'response_type' => 'pdf'))['status']);
		$many = implode(',', range(1, 51));
		$this->assertSame(400, $fake->api('GET', '/shipment_labels', $token, null, array(), array('shipment_ids' => $many, 'response_type' => 'pdf'))['status']);
		$zpl = $fake->api('GET', '/shipment_labels', $token, null, array(), array('shipment_ids' => $ids[1], 'response_type' => 'zpl2'));
		$this->assertStringStartsWith('^XA', $zpl['body']);
	}

	// ----------------------------------------------------------------------------------------------
	// Notifications

	/**
	 * Notifications to the webhook: shape, duplicates; a 500 and a slow 200 go to missed_feeds, kept two days.
	 *
	 * @return void
	 */
	public function testNotifyAndMissedFeeds()
	{
		$fake = $this->fake();
		$hook = $this->hook();
		$token = $this->connect()['access_token'];
		$before = count($hook->received());

		$fake->config(array('webhook_url' => $hook->url('/ok')));
		$ok = $fake->notify(array('topic' => 'orders_v2', 'resource' => '/orders/2000000000000001', 'duplicates' => 2));
		$this->assertSame(array(200, false), array($ok['status'] ?? null, $ok['missed'] ?? null));
		$this->assertLessThan(500, $ok['duration_ms'] ?? 1000);
		$received = array_slice($hook->received(), $before);
		$this->assertCount(3, $received);
		$this->assertSame($received[0], $received[2]);
		$this->assertSame(
			array('_id', 'resource', 'user_id', 'topic', 'application_id', 'attempts', 'sent', 'received', '_path'),
			array_keys($received[0])
		);
		$this->assertSame(array('/orders/2000000000000001', self::SELLER, 'orders_v2', 1234567890123456, 1), array(
			$received[0]['resource'], $received[0]['user_id'], $received[0]['topic'], $received[0]['application_id'], $received[0]['attempts'],
		));

		$fake->config(array('webhook_url' => $hook->url('/fail')));
		$failed = $fake->notify(array('topic' => 'orders_v2', 'resource' => '/orders/2000000000000002'));
		$this->assertSame(array(500, true), array($failed['status'] ?? null, $failed['missed'] ?? null));
		$fake->config(array('webhook_url' => $hook->url('/slow?ms=700')));
		$slow = $fake->notify(array('topic' => 'shipments', 'resource' => '/shipments/40000000001', 'attempts' => 3));
		$this->assertSame(array(200, true), array($slow['status'] ?? null, $slow['missed'] ?? null));
		$this->assertGreaterThan(500, $slow['duration_ms'] ?? 0);

		$feeds = $this->getJson('/missed_feeds', $token, array('app_id' => '1234567890123456'));
		$this->assertSame(2, $feeds['total'] ?? null);
		$this->assertSame('/orders/2000000000000002', self::path($feeds, 'messages.0.resource'));
		$this->assertSame(500, self::path($feeds, 'messages.0.response.http_code'));
		$this->assertSame(3, self::path($feeds, 'messages.1.attempts'));
		$this->assertArrayNotHasKey('_missed', MeliFakeClient::toMap(self::path($feeds, 'messages.0')));
		$shipments = $this->getJson('/missed_feeds', $token, array('app_id' => '1234567890123456', 'topic' => 'shipments'));
		$this->assertSame(1, $shipments['total'] ?? null);
		$this->assertSame(403, $fake->api('GET', '/missed_feeds', $token, null, array(), array('app_id' => '1'))['status']);

		$fake->advanceTime(2 * 86400 + 60);
		$token = $this->connect()['access_token'];
		$this->assertSame(0, $this->getJson('/missed_feeds', $token, array('app_id' => '1234567890123456'))['total'] ?? null);
	}

	/**
	 * With no webhook configured, a notification is missed (status 0).
	 *
	 * @return void
	 */
	public function testNotifyWithoutWebhook()
	{
		$answer = $this->fake()->notify(array('topic' => 'items', 'resource' => '/items/MLA1'));
		$this->assertSame(array(0, true), array($answer['status'] ?? null, $answer['missed'] ?? null));
	}

	/**
	 * UPtin: the legacy item is closed and replaced by one UP item per variation with the same SKU, items notifications sent.
	 *
	 * @return void
	 */
	public function testChangeItemIdNotifiesItems()
	{
		$fake = $this->fake();
		$hook = $this->hook();
		$token = $this->connect()['access_token'];
		$fake->config(array('webhook_url' => $hook->url('/ok')));
		$before = count($hook->received());
		$legacy = $fake->seedItem(array('model' => 'legacy', 'variations' => array(array('sku' => 'V1', 'quantity' => 2), array('sku' => 'V2', 'quantity' => 1))));
		$oldId = self::str($legacy['id'] ?? null);
		$change = $fake->changeItemId($oldId);
		$created = self::lst($change['created'] ?? null);
		$this->assertCount(2, $created);
		$this->assertSame('closed', $this->getJson('/items/'.$oldId, $token)['status'] ?? null);
		$first = $this->getJson('/items/'.self::str($created[0]), $token);
		$this->assertSame('V1', self::path($first, 'attributes.0.value_name'));
		$this->assertSame(2, $first['available_quantity'] ?? null);
		$this->assertContains('variations_migration_uptin', self::lst($first['tags'] ?? null));
		$this->assertNotNull($first['user_product_id'] ?? null);

		$resources = array();
		foreach (array_slice($hook->received(), $before) as $notification) {
			$this->assertSame('items', $notification['topic'] ?? null);
			$resources[] = $notification['resource'] ?? null;
		}
		$this->assertSame(array('/items/'.$oldId, '/items/'.self::str($created[0]), '/items/'.self::str($created[1])), $resources);
		$search = $this->getJson('/users/'.self::SELLER.'/items/search', $token, array('seller_sku' => 'V2', 'status' => 'active'));
		$this->assertSame(array($created[1]), $search['results'] ?? null);
	}

	// ----------------------------------------------------------------------------------------------
	// Injections

	/**
	 * 429 with Retry-After and JSON body, 429 with empty body; one-shot and matched by method and path prefix.
	 *
	 * @return void
	 */
	public function testInjected429()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$fake->inject(array('mode' => 'http_429', 'method' => 'PUT', 'path' => '/items/', 'retry_after' => 3));
		$fake->inject(array('mode' => 'http_429', 'path' => '/users/me', 'empty_body' => true));
		$item = $fake->seedItem(array('model' => 'up', 'quantity' => 1));
		$id = self::str($item['id'] ?? null);

		$this->getJson('/items/'.$id, $token);
		$limited = $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 9));
		$this->assertSame(429, $limited['status']);
		$this->assertSame('3', $limited['headers']['retry-after'] ?? null);
		$this->assertSame('too_many_requests', MeliFakeClient::decode($limited)['error'] ?? null);
		$this->assertSame(1, $this->getJson('/items/'.$id, $token)['available_quantity'] ?? null);
		$this->assertSame(200, $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 9))['status']);

		$empty = $fake->api('GET', '/users/me', $token);
		$this->assertSame(429, $empty['status']);
		$this->assertSame('', $empty['body']);
		$this->assertArrayNotHasKey('retry-after', $empty['headers']);
		$this->getJson('/users/me', $token);
	}

	/**
	 * 500, 503 and the 403 of a missing functional permission; nothing processed.
	 *
	 * @return void
	 */
	public function testInjectedServerErrorsAndPolicies()
	{
		$fake = $this->fake();
		$tokens = $this->connect();
		$fake->inject(array('injections' => array(array('mode' => 'http_500'), array('mode' => 'http_503'), array('mode' => 'forbidden_policies', 'path' => '/orders/'))));
		$this->assertSame(500, $fake->api('GET', '/users/me', $tokens['access_token'])['status']);
		$this->assertSame(503, $fake->token($fake->refreshForm($tokens['refresh_token']))['status']);
		$this->getJson('/users/me', $fake->refresh($tokens['refresh_token'])['access_token']);
		$token = $this->connect()['access_token'];
		$policy = $fake->api('GET', '/orders/1', $token);
		$this->assertSame(403, $policy['status']);
		$this->assertSame('PA_UNAUTHORIZED_RESULT_FROM_POLICIES', MeliFakeClient::decode($policy)['error'] ?? null);
		$this->assertSame(400, $fake->inject(array('mode' => 'nonsense'))['_status']);
	}

	/**
	 * timeout_before: nothing processed (client timeout, or a 504 for a patient client). timeout_after: processed, the
	 * client times out. The fake keeps answering other calls meanwhile.
	 *
	 * @return void
	 */
	public function testInjectedTimeouts()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$id = self::str($fake->seedItem(array('model' => 'up', 'quantity' => 1))['id'] ?? null);

		$fake->inject(array('mode' => 'timeout_before', 'method' => 'PUT', 'delay_ms' => 1500));
		$lost = $fake->request('PUT', '/items/'.$id, array('token' => $token, 'json' => array('available_quantity' => 4), 'timeout' => 0.4));
		$this->assertSame(28, $lost['errno']);
		$this->assertSame(1, $this->getJson('/items/'.$id, $token)['available_quantity'] ?? null);

		$fake->inject(array('mode' => 'timeout_before', 'method' => 'PUT', 'delay_ms' => 300));
		$gateway = $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 4));
		$this->assertSame(504, $gateway['status']);
		$this->assertSame(1, $this->getJson('/items/'.$id, $token)['available_quantity'] ?? null);

		$fake->inject(array('mode' => 'timeout_after', 'method' => 'PUT', 'delay_ms' => 1500));
		$late = $fake->request('PUT', '/items/'.$id, array('token' => $token, 'json' => array('available_quantity' => 4), 'timeout' => 0.4));
		$this->assertSame(28, $late['errno']);
		$this->assertSame(4, $this->getJson('/items/'.$id, $token)['available_quantity'] ?? null);
		usleep(1200000);
	}

	/**
	 * drop_after: processed, then the connection is cut (curl error 18).
	 *
	 * @return void
	 */
	public function testInjectedDropAfter()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$id = self::str($fake->seedItem(array('model' => 'up', 'quantity' => 1))['id'] ?? null);
		$fake->inject(array('mode' => 'drop_after', 'method' => 'PUT'));
		$cut = $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 6));
		$this->assertSame(18, $cut['errno']);
		$this->assertSame(6, $this->getJson('/items/'.$id, $token)['available_quantity'] ?? null);
	}

	/**
	 * warnings: processed, 200 with a warnings array (default or given).
	 *
	 * @return void
	 */
	public function testInjectedWarnings()
	{
		$fake = $this->fake();
		$token = $this->connect()['access_token'];
		$id = self::str($fake->seedItem(array('model' => 'up', 'quantity' => 1))['id'] ?? null);
		$fake->inject(array('mode' => 'warnings', 'method' => 'PUT'));
		$warned = $fake->api('PUT', '/items/'.$id, $token, array('available_quantity' => 2, 'price' => 1234.5));
		$this->assertSame(200, $warned['status']);
		$data = MeliFakeClient::decode($warned);
		$this->assertSame(2, $data['available_quantity'] ?? null);
		$this->assertSame('warning', self::path($data, 'warnings.0.type'));
		$fake->inject(array('mode' => 'warnings', 'warnings' => array(array('code' => 'item.price.ignored', 'message' => 'price automation is active'))));
		$this->assertSame('item.price.ignored', self::path($this->getJson('/items/'.$id, $token), 'warnings.0.code'));
		$this->assertArrayNotHasKey('warnings', $this->getJson('/items/'.$id, $token));
	}
}


/**
 * Dates in the format the fake uses, for the search filters of the test.
 */
class MeliFakeRouterTime
{
	/**
	 * Date with the Argentina offset.
	 *
	 * @param	int		$timestamp	Unix time
	 * @return	string
	 */
	public static function iso($timestamp)
	{
		return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d\TH:i:s.vP');
	}
}
