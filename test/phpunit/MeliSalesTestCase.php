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
 * \file    test/phpunit/MeliSalesTestCase.php
 * \ingroup meli
 * \brief   Base of the tests about sales: connected to the fake, invoicing on, helpers to sell and import.
 */

require_once __DIR__.'/bootstrap.inc.php';

/**
 * Sales test base
 */
abstract class MeliSalesTestCase extends MeliFakeTestCase
{
	/**
	 * @var int	Warehouse offered
	 */
	protected $warehouse = 0;

	/**
	 * @var int	Bank account of the payments
	 */
	protected $bank = 0;

	/**
	 * Connected, invoicing on, a warehouse and a bank account of this run.
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
		$this->bank = $this->fixtures->bankAccount();
		meli_test_set_global('MELI_WAREHOUSE_IDS', (string) $this->warehouse);
		meli_test_set_global('MELI_USER_ID', '1');
		meli_test_set_global('MELI_BANK_ACCOUNT', (string) $this->bank);
		meli_test_set_global('MELI_INVOICE_ORDERS', '1');
		meli_test_set_global('MELI_PAYMENT_MODE', '0');
		meli_test_set_global('MELI_SHIPPING_PRODUCT_ID', '0');
		meli_test_set_global('MELI_PUSH_STOCK', '0');
		meli_test_set_global('STOCK_CALCULATE_ON_VALIDATE_ORDER', '0');
		meli_test_set_global('STOCK_MUST_BE_ENOUGH_FOR_ORDER', '0');
	}

	/**
	 * A listing of a product, linked.
	 *
	 * @param	Product	$product	Product of this run
	 * @return	string				Item id
	 */
	protected function listing(Product $product)
	{
		/** @var DoliDB $db */
		global $db;

		$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $product->ref, 'quantity' => 5));
		(new MeliLink($db))->sync();

		return meli_test_str($item['id']);
	}

	/**
	 * A sale at the fake, with a buyer of this run.
	 *
	 * @param	list<array<string, mixed>>	$items	Lines
	 * @param	array<string, mixed>		$more	Other fields
	 * @return	array<string, mixed>				Fake's answer
	 */
	protected function sell(array $items, array $more = array())
	{
		return $this->fake()->seedOrder($more + array(
			'items' => $items,
			'buyer' => array('id' => 900000000 + crc32($this->fixtures->tag) % 99999999, 'nickname' => $this->fixtures->tag),
			'billing' => array('name' => $this->fixtures->tag, 'last_name' => 'Prueba', 'doc_type' => 'DNI', 'doc_number' => $this->document(), 'taxpayer_type' => 'Consumidor Final'),
			'shipment' => array('status' => 'ready_to_ship', 'substatus' => 'ready_to_print', 'logistic_type' => 'drop_off', 'receiver_cost' => 3500.0, 'sender_cost' => 1200.0),
		));
	}

	/**
	 * Document number of this run's buyer: unique, so that no other customer is found by it.
	 *
	 * @return	string
	 */
	protected function document()
	{
		return (string) (40000000 + crc32($this->fixtures->tag) % 50000000);
	}

	/**
	 * First order id of a sale.
	 *
	 * @param	array<string, mixed>	$sale	Fake's answer
	 * @return	string
	 */
	protected function orderId(array $sale)
	{
		$ids = is_array($sale['order_ids'] ?? null) ? $sale['order_ids'] : array();

		return meli_test_str($ids[0] ?? '');
	}

	/**
	 * Import a sale, and record what it created as this run's.
	 *
	 * @param	array<string, mixed>	$sale	Fake's answer
	 * @return	string						Outcome
	 */
	protected function sync(array $sale)
	{
		/** @var DoliDB $db */
		global $db;

		$orders = new MeliOrders($db);
		$outcome = $orders->sync($this->orderId($sale));
		$rows = meli_test_rows("SELECT o.fk_commande, o.fk_facture, c.fk_soc FROM ".$db->prefix()."meli_order as o JOIN ".$db->prefix()."commande as c ON c.rowid = o.fk_commande WHERE o.order_ids LIKE '%".$db->escape($this->orderId($sale))."%'");
		foreach ($rows as $row) {
			$this->fixtures->adopt('thirdparty', (int) $row['fk_soc']);
			$this->fixtures->adopt('order', (int) $row['fk_commande']);
			$this->fixtures->adopt('invoice', (int) $row['fk_facture']);
		}
		$this->assertNotSame(MeliOrders::FAILED, $outcome, $orders->error);

		return $outcome;
	}

	/**
	 * Row of an imported sale.
	 *
	 * @param	array<string, mixed>	$sale	Fake's answer
	 * @return	array<string, string>
	 */
	protected function row(array $sale)
	{
		/** @var DoliDB $db */
		global $db;

		$rows = meli_test_rows("SELECT o.* FROM ".$db->prefix()."meli_order as o WHERE o.order_ids LIKE '%".$db->escape($this->orderId($sale))."%'");
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
