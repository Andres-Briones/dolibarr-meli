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
 * \file    test/phpunit/MeliPushTest.php
 * \ingroup meli
 * \brief   Quantities sent to the fake Mercado Libre from the outbox.
 */

require_once __DIR__.'/bootstrap.inc.php';

/**
 * Stock push tests
 */
class MeliPushTest extends MeliFakeTestCase
{
	/**
	 * @var int	Warehouse offered on Mercado Libre
	 */
	private $warehouse = 0;

	/**
	 * Connected, pushing, one warehouse offered, no buffer nor cap.
	 *
	 * @return	void
	 */
	protected function setUp(): void
	{
		/** @var DoliDB $db */
		global $db;

		parent::setUp();
		$rows = meli_test_rows("SELECT COUNT(l.rowid) as nb FROM ".$db->prefix()."meli_link as l");
		if ($rows[0]['nb'] !== '0') {
			$this->markTestSkipped('this instance has listing links of its own; a sync would mark them gone');
		}
		$this->connect();
		$this->warehouse = $this->fixtures->warehouse();
		meli_test_set_global('MELI_WAREHOUSE_IDS', (string) $this->warehouse);
		meli_test_set_global('MELI_PUSH_STOCK', '1');
		meli_test_set_global('MELI_BUFFER', '0');
		meli_test_set_global('MELI_MAX_QTY', '0');
		meli_test_set_global('STOCK_CALCULATE_ON_VALIDATE_ORDER', '0');
	}

	/**
	 * A User Products item gets the sellable quantity through its stock, with the version read first.
	 *
	 * @return	void
	 */
	public function testUserProductPush()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $product->ref, 'quantity' => 9));
		(new MeliLink($db))->sync();
		$this->fixtures->stock($product, $this->warehouse, 4);
		$this->fixtures->order(array((int) $product->id => 1));

		$counts = (new MeliStock($db))->push(null, 1000);
		$this->assertSame(1, $counts['pushed']);
		$up = $this->upStock(meli_test_str($item['user_product_id'] ?? ''));
		$this->assertSame(3, $up['quantity'], 'stock 4 minus 1 held by a validated order');
		$this->assertSame(array('GET /user-products/'.$up['id'].'/stock', 'PUT /user-products/'.$up['id'].'/stock/type/selling_address'), array_values(array_filter($this->calls(), function ($call) {
			return strpos($call, '/user-products/') !== false;
		})));
		$this->assertSame(array(), $this->outbox((int) $product->id), 'outbox emptied');
		$this->assertSame('3', $this->link((int) $product->id)['last_pushed_qty']);
	}

	/**
	 * A sale on Mercado Libre between our read of the stock and our write: the write is refused
	 * (409), the stock read again with its new version, and written then.
	 *
	 * @return	void
	 */
	public function testVersionConflictRereads()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $product->ref, 'quantity' => 9));
		$upId = meli_test_str($item['user_product_id'] ?? '');
		(new MeliLink($db))->sync();
		$this->fixtures->stock($product, $this->warehouse, 4);
		$this->fake()->inject(array('mode' => 'bump_version_after', 'method' => 'GET', 'path' => '/user-products/'.$upId.'/stock', 'sell' => 1));

		$counts = (new MeliStock($db))->push(null, 1000);
		$this->assertSame(1, $counts['pushed']);
		$calls = array_values(array_filter($this->calls(), function ($call) {
			return strpos($call, '/user-products/') !== false;
		}));
		$get = 'GET /user-products/'.$upId.'/stock';
		$put = 'PUT /user-products/'.$upId.'/stock/type/selling_address';
		$this->assertSame(array($get, $put, $get, $put), $calls);
		$statuses = array();
		foreach ($this->fake()->log() as $entry) {
			if (meli_test_str($entry['method'] ?? '').' '.meli_test_str($entry['path'] ?? '') === $put) {
				$statuses[] = meli_test_str($entry['status'] ?? '');
			}
		}
		$this->assertSame(array('409', '200'), $statuses);
		$this->assertSame(4, $this->upStock($upId)['quantity'], 'Dolibarr\'s quantity written with the new version');
	}

	/**
	 * A legacy variation gets its quantity through the item; the same value is not sent again the same day.
	 *
	 * @return	void
	 */
	public function testLegacyVariationAndUnchanged()
	{
		/** @var DoliDB $db */
		global $db;

		$red = $this->fixtures->product();
		$blue = $this->fixtures->product();
		$item = $this->fake()->seedItem(array('model' => 'legacy', 'title' => 'Poncho', 'variations' => array(
			array('sku' => $red->ref, 'quantity' => 5), array('sku' => $blue->ref, 'quantity' => 5),
		)));
		(new MeliLink($db))->sync();
		$this->fixtures->stock($red, $this->warehouse, 2);
		$this->fixtures->stock($blue, $this->warehouse, 1);
		$stock = new MeliStock($db);
		$stock->push(null, 1000);

		$quantities = array();
		$state = $this->fake()->state();
		$stored = (isset($state['items']) && is_array($state['items'])) ? $state['items'][meli_test_str($item['id'])] : array();
		foreach ((is_array($stored) && is_array($stored['variations'] ?? null)) ? $stored['variations'] : array() as $variation) {
			if (is_array($variation)) {
				$quantities[] = (int) meli_test_str($variation['available_quantity'] ?? '');
			}
		}
		$this->assertSame(array(2, 1), $quantities);

		$puts = count(array_keys($this->calls(), 'PUT /items/'.meli_test_str($item['id'])));
		$stock->enqueue(array((int) $red->id), 'again');
		$counts = $stock->push(null, 1000);
		$this->assertSame(1, $counts['unchanged']);
		$this->assertCount($puts, array_keys($this->calls(), 'PUT /items/'.meli_test_str($item['id'])), 'nothing sent for an unchanged quantity');
	}

	/**
	 * The listing's buffer is held back; a unique piece with a buffer of 1 is not offered.
	 *
	 * @return	void
	 */
	public function testBuffer()
	{
		/** @var DoliDB $db */
		global $db;

		$piece = $this->fixtures->product();
		$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $piece->ref, 'quantity' => 1));
		(new MeliLink($db))->sync();
		$this->fixtures->stock($piece, $this->warehouse, 1);
		$db->query("UPDATE ".$db->prefix()."meli_link SET buffer = 1 WHERE fk_product = ".((int) $piece->id));
		(new MeliStock($db))->push(null, 1000);
		$this->assertSame(0, $this->upStock(meli_test_str($item['user_product_id'] ?? ''))['quantity']);
	}

	/**
	 * A refused write stays in the outbox, due later, with the error on the link.
	 *
	 * @return	void
	 */
	public function testFailureBacksOff()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$this->fake()->seedItem(array('model' => 'up', 'sku' => $product->ref, 'quantity' => 1));
		(new MeliLink($db))->sync();
		$this->fixtures->stock($product, $this->warehouse, 2);
		$this->fake()->inject(array('mode' => 'forbidden_policies', 'method' => 'PUT', 'path' => '/user-products/'));

		$counts = (new MeliStock($db))->push(null, 1000);
		$this->assertSame(1, $counts['failed']);
		$outbox = $this->outbox((int) $product->id);
		$this->assertCount(1, $outbox);
		$this->assertSame('1', $outbox[0]['attempts']);
		$this->assertGreaterThan(dol_now(), $db->jdate($outbox[0]['date_due']));
		$this->assertNotSame('', $this->link((int) $product->id)['last_error']);
		$this->assertSame(array('pushed' => 0, 'unchanged' => 0, 'failed' => 0, 'skipped' => 0), (new MeliStock($db))->push(null, 1000), 'not due yet');
	}

	/**
	 * Stock of a user product at the fake.
	 *
	 * @param	string	$id	User product id
	 * @return	array{id: string, quantity: int}
	 */
	private function upStock($id)
	{
		$state = $this->fake()->state();
		$up = (isset($state['user_products']) && is_array($state['user_products']) && is_array($state['user_products'][$id] ?? null)) ? $state['user_products'][$id] : array();
		$quantity = -1;
		foreach (is_array($up['locations'] ?? null) ? $up['locations'] : array() as $location) {
			if (is_array($location) && ($location['type'] ?? '') === 'selling_address') {
				$quantity = (int) meli_test_str($location['quantity'] ?? '');
			}
		}

		return array('id' => $id, 'quantity' => $quantity);
	}

	/**
	 * Outbox rows of a product.
	 *
	 * @param	int	$product	Product id
	 * @return	list<array<string, string>>
	 */
	private function outbox($product)
	{
		/** @var DoliDB $db */
		global $db;

		return meli_test_rows("SELECT o.attempts, o.date_due FROM ".$db->prefix()."meli_outbox as o WHERE o.fk_product = ".((int) $product));
	}

	/**
	 * Link row of a product.
	 *
	 * @param	int	$product	Product id
	 * @return	array<string, string>
	 */
	private function link($product)
	{
		/** @var DoliDB $db */
		global $db;

		$rows = meli_test_rows("SELECT l.last_pushed_qty, l.last_error FROM ".$db->prefix()."meli_link as l WHERE l.fk_product = ".((int) $product));
		$this->assertCount(1, $rows);

		return $rows[0];
	}

	/**
	 * Remove the links made from the fake's items.
	 *
	 * @return	void
	 */
	protected function tearDown(): void
	{
		/** @var DoliDB $db */
		global $db;

		$state = $this->fake()->state();
		$items = array();
		foreach ((isset($state['items']) && is_array($state['items'])) ? array_keys($state['items']) : array() as $id) {
			$items[] = "'".$db->escape((string) $id)."'";
		}
		if (!empty($items)) {
			$db->query("DELETE FROM ".$db->prefix()."meli_link WHERE item_id IN (".implode(',', $items).")");
		}
		parent::tearDown();
	}
}
