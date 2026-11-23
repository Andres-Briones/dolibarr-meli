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
 * \file    test/phpunit/MeliStockTest.php
 * \ingroup meli
 * \brief   Quantity offered on Mercado Libre: the same sellable stock as the online shop, the buffer, the cap.
 */

require_once __DIR__.'/bootstrap.inc.php';

/**
 * Stock tests
 */
class MeliStockTest extends MeliTestCase
{
	/**
	 * The buffer is taken off, the cap applies, nothing below zero, products without stock control get the cap.
	 *
	 * @return	void
	 */
	public function testPublishedQuantity()
	{
		meli_test_set_global('MELI_BUFFER', '0');
		meli_test_set_global('MELI_MAX_QTY', '0');
		$this->assertSame(3, MeliStock::published(3.7));
		$this->assertSame(0, MeliStock::published(-2.0));
		$this->assertSame(99, MeliStock::published(null));
		$this->assertSame(2, MeliStock::published(3.0, 1), 'buffer of the listing');

		meli_test_set_global('MELI_BUFFER', '1');
		$this->assertSame(0, MeliStock::published(1.0), 'unique piece kept off Mercado Libre');
		$this->assertSame(1, MeliStock::published(1.0, 0), 'a listing buffer of 0 overrides the general one');

		meli_test_set_global('MELI_MAX_QTY', '5');
		$this->assertSame(5, MeliStock::published(40.0));
		$this->assertSame(5, MeliStock::published(null));
	}

	/**
	 * Physical stock of the chosen warehouses, minus what validated orders still have to take.
	 *
	 * @return	void
	 */
	public function testSellable()
	{
		/** @var DoliDB $db */
		global $db;

		$offered = $this->fixtures->warehouse();
		$other = $this->fixtures->warehouse();
		$product = $this->fixtures->product();
		$empty = $this->fixtures->product();
		$unmanaged = $this->fixtures->product(array('stockable' => false));
		$service = isModEnabled('service') ? $this->fixtures->product(array('type' => 1)) : null;
		$this->fixtures->stock($product, $offered, 5);
		$this->fixtures->stock($product, $other, 7);
		$this->fixtures->order(array((int) $product->id => 2));
		$this->fixtures->order(array((int) $product->id => 1), false);

		meli_test_set_global('MELI_WAREHOUSE_IDS', (string) $offered);
		meli_test_set_global('STOCK_CALCULATE_ON_VALIDATE_ORDER', '0');
		meli_test_set_global('STOCK_CALCULATE_ON_SHIPMENT', '0');
		meli_test_set_global('STOCK_CALCULATE_ON_SHIPMENT_CLOSE', '0');
		meli_test_set_global('STOCK_SUPPORTS_SERVICES', '0');
		$ids = array((int) $product->id, (int) $empty->id, (int) $unmanaged->id, 999999999);
		if ($service !== null) {
			$ids[] = (int) $service->id;
		}
		$sellable = (new MeliStock($db))->sellable($ids);

		$this->assertEquals(3.0, $sellable[(int) $product->id], 'other warehouse and draft order ignored, validated order held');
		$this->assertEquals(0.0, $sellable[(int) $empty->id]);
		$this->assertNull($sellable[(int) $unmanaged->id]);
		if ($service !== null) {
			$this->assertNull($sellable[(int) $service->id]);
		}
		$this->assertArrayNotHasKey(999999999, $sellable);

		meli_test_set_global('STOCK_CALCULATE_ON_VALIDATE_ORDER', '1');
		$sellable = (new MeliStock($db))->sellable($ids);
		$this->assertEquals(5.0, $sellable[(int) $product->id], 'with the decrement at validation, the order is already out of the stock');
	}

	/**
	 * Same numbers as the online shop for the same warehouses, under every way of decrementing stock.
	 *
	 * @return	void
	 */
	public function testSameAsTheShop()
	{
		/** @var DoliDB $db */
		global $db;

		$file = dol_buildpath('/tienda/class/tiendastock.class.php', 0);
		if (!is_file($file)) {
			$this->markTestSkipped('module tienda not installed here');
		}
		require_once $file;

		$warehouse = $this->fixtures->warehouse();
		$products = array();
		$kinds = array(array(), array(), array('stockable' => false));
		if (isModEnabled('service')) {
			$kinds[] = array('type' => 1);
		}
		foreach ($kinds as $fields) {
			$products[] = $this->fixtures->product($fields);
		}
		$this->fixtures->stock($products[0], $warehouse, 4);
		$this->fixtures->stock($products[1], $warehouse, 1);
		$this->fixtures->order(array((int) $products[0]->id => 1, (int) $products[1]->id => 1));
		$this->fixtures->order(array((int) $products[0]->id => 2));
		$ids = array();
		foreach ($products as $product) {
			$ids[] = (int) $product->id;
		}

		meli_test_set_global('MELI_WAREHOUSE_IDS', (string) $warehouse);
		meli_test_set_global('TIENDA_WAREHOUSE_IDS', (string) $warehouse);
		meli_test_set_global('TIENDA_OUT_OF_STOCK', 'show');
		$settings = array(
			array('0', '0', '0', '0'), array('1', '0', '0', '0'), array('0', '1', '0', '0'), array('0', '0', '1', '0'), array('0', '0', '0', '1'),
		);
		foreach ($settings as $setting) {
			meli_test_set_global('STOCK_CALCULATE_ON_VALIDATE_ORDER', $setting[0]);
			meli_test_set_global('STOCK_CALCULATE_ON_SHIPMENT', $setting[1]);
			meli_test_set_global('STOCK_CALCULATE_ON_SHIPMENT_CLOSE', $setting[2]);
			meli_test_set_global('STOCK_SUPPORTS_SERVICES', $setting[3]);
			$shop = $this->shopSellable('TiendaStock', $ids);
			$meli = (new MeliStock($db))->sellable($ids);
			ksort($shop);
			ksort($meli);
			$this->assertEquals($shop, $meli, 'settings '.implode(',', $setting));
		}
	}

	/**
	 * Sellable quantities computed by the shop's module, called by name: it is optional, and absent
	 * from the static analysis of this one.
	 *
	 * @param	string		$class	Class of the shop's stock
	 * @param	array<int>	$ids	Product ids
	 * @return	array<mixed>
	 */
	private function shopSellable($class, array $ids)
	{
		/** @var DoliDB $db */
		global $db;

		$callable = array(new $class($db), 'sellable');
		if (!is_callable($callable)) {
			$this->fail($class.'::sellable() not found');
		}
		$answer = call_user_func($callable, $ids);
		if (!is_array($answer)) {
			$this->fail($class.'::sellable() did not return an array');
		}

		return $answer;
	}

	/**
	 * Only linked products enter the outbox, one row each however many times; the push waits while read-only.
	 *
	 * @return	void
	 */
	public function testOutboxAndReadOnly()
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$linked = $this->fixtures->product();
		$alone = $this->fixtures->product();
		$item = $this->fixtures->tag.'1';
		$db->query("INSERT INTO ".$db->prefix()."meli_link (entity, item_id, variation_id, fk_product, seller_sku, active, date_creation) VALUES (".((int) $conf->entity).", '".$item."', 0, ".((int) $linked->id).", '".$db->escape((string) $linked->ref)."', 1, '".$db->idate(dol_now())."')");

		$stock = new MeliStock($db);
		$stock->enqueue(array((int) $linked->id, (int) $alone->id), 'test');
		$stock->enqueue(array((int) $linked->id), 'again');
		$rows = meli_test_rows("SELECT o.fk_product, o.reason FROM ".$db->prefix()."meli_outbox as o WHERE o.fk_product IN (".((int) $linked->id).", ".((int) $alone->id).")");
		$this->assertSame(array(array('fk_product' => (string) $linked->id, 'reason' => 'again')), $rows);

		meli_test_set_global('MELI_PUSH_STOCK', '0');
		$counts = $stock->push(null, 1000);
		$this->assertGreaterThanOrEqual(1, $counts['skipped']);
		$this->assertSame(0, $counts['pushed']);
		$this->assertCount(1, meli_test_rows("SELECT o.rowid FROM ".$db->prefix()."meli_outbox as o WHERE o.fk_product = ".((int) $linked->id)), 'kept for when the push is switched on');
	}

	/**
	 * A stock movement of a linked product puts it in the outbox, through the trigger.
	 *
	 * @return	void
	 */
	public function testStockMovementTrigger()
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$warehouse = $this->fixtures->warehouse();
		$product = $this->fixtures->product();
		$db->query("INSERT INTO ".$db->prefix()."meli_link (entity, item_id, variation_id, fk_product, active, date_creation) VALUES (".((int) $conf->entity).", '".$this->fixtures->tag."2', 0, ".((int) $product->id).", 1, '".$db->idate(dol_now())."')");
		$this->fixtures->stock($product, $warehouse, 2);

		$rows = meli_test_rows("SELECT o.reason FROM ".$db->prefix()."meli_outbox as o WHERE o.fk_product = ".((int) $product->id));
		$this->assertSame(array(array('reason' => 'stock_movement')), $rows);
	}
}
