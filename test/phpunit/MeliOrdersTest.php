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
 * \file    test/phpunit/MeliOrdersTest.php
 * \ingroup meli
 * \brief   Sales of the fake Mercado Libre imported as orders, invoiced and paid; cancellations.
 */

require_once __DIR__.'/MeliSalesTestCase.php';

/**
 * Sales tests
 */
class MeliOrdersTest extends MeliSalesTestCase
{
	/**
	 * A paid sale becomes a validated order, then a validated invoice paid with what the buyer paid,
	 * shipping included, with the payment mode "Mercado Libre" on the chosen account.
	 *
	 * @return	void
	 */
	public function testImportAndInvoice()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product(array('price' => 15000));
		$item = $this->listing($product);
		$this->fixtures->stock($product, $this->warehouse, 5);
		$sale = $this->sell(array(array('item_id' => $item, 'quantity' => 2, 'unit_price' => 15000.0, 'sale_fee' => 1950.0)));

		$this->assertSame(MeliOrders::IMPORTED, $this->sync($sale));
		$row = $this->row($sale);
		$order = new Commande($db);
		$this->assertSame(1, $order->fetch((int) $row['fk_commande']));
		$this->assertSame(Commande::STATUS_VALIDATED, (int) $order->status);
		$this->assertSame('meli', $order->module_source);
		$this->assertSame('meli:'.$row['pack_id'], $order->ref_ext);
		$this->assertEqualsWithDelta(33500.0, (float) $order->total_ttc, 0.001, '2 x 15000 + 3500 shipping');
		$this->assertSame(1, (int) $order->billed);

		$invoice = new Facture($db);
		$this->assertSame(1, $invoice->fetch((int) $row['fk_facture']));
		$this->assertSame(Facture::STATUS_CLOSED, (int) $invoice->status, 'paid');
		$this->assertSame('meli', $invoice->module_source);
		$payments = meli_test_rows("SELECT p.amount, c.code, b.fk_account FROM ".$db->prefix()."paiement_facture as pf JOIN ".$db->prefix()."paiement as p ON p.rowid = pf.fk_paiement JOIN ".$db->prefix()."c_paiement as c ON c.id = p.fk_paiement JOIN ".$db->prefix()."bank as b ON b.rowid = p.fk_bank WHERE pf.fk_facture = ".((int) $invoice->id));
		$this->assertCount(1, $payments);
		$this->assertEqualsWithDelta(33500.0, (float) $payments[0]['amount'], 0.001);
		$this->assertSame(array('MELI', (string) $this->bank), array($payments[0]['code'], $payments[0]['fk_account']));
		$this->assertEqualsWithDelta((float) $row['total_paid'], 33500.0, 0.001, 'what Mercado Libre says the buyer paid');

		$thirdparty = new Societe($db);
		$thirdparty->fetch((int) $order->socid);
		$this->assertSame($this->fixtures->tag.' Prueba', $thirdparty->name);
		$this->assertSame($this->document(), $thirdparty->idprof1);

		$this->assertSame(MeliOrders::UPDATED, $this->sync($sale), 'a second notification updates');
		$this->assertCount(1, meli_test_rows("SELECT o.rowid FROM ".$db->prefix()."meli_order as o WHERE o.pack_id = '".$db->escape($row['pack_id'])."'"));
		$this->assertCount(1, meli_test_rows("SELECT f.rowid FROM ".$db->prefix()."facture as f WHERE f.fk_soc = ".((int) $order->socid)));
	}

	/**
	 * A cart purchase (pack of several orders) is one Dolibarr order.
	 *
	 * @return	void
	 */
	public function testPackIsOneOrder()
	{
		/** @var DoliDB $db */
		global $db;

		$first = $this->fixtures->product();
		$second = $this->fixtures->product();
		$sale = $this->sell(array(
			array('item_id' => $this->listing($first), 'quantity' => 1, 'unit_price' => 1000.0),
			array('item_id' => $this->listing($second), 'quantity' => 3, 'unit_price' => 500.0),
		));
		$this->assertNotSame('', meli_test_str($sale['pack_id'] ?? ''));
		$orderIds = is_array($sale['order_ids'] ?? null) ? $sale['order_ids'] : array();
		$this->assertCount(2, $orderIds);

		$this->assertSame(MeliOrders::IMPORTED, $this->sync($sale));
		$this->assertSame(MeliOrders::UPDATED, (new MeliOrders($db))->sync(meli_test_str($orderIds[1])), 'the other order of the pack');
		$row = $this->row($sale);
		$this->assertSame(meli_test_str($sale['pack_id']), $row['pack_id']);
		$lines = meli_test_rows("SELECT cd.fk_product, cd.qty FROM ".$db->prefix()."commandedet as cd WHERE cd.fk_commande = ".((int) $row['fk_commande'])." AND cd.fk_product IS NOT NULL ORDER BY cd.fk_product");
		$this->assertSame(array(array('fk_product' => (string) $first->id, 'qty' => '1'), array('fk_product' => (string) $second->id, 'qty' => '3')), $lines);
	}

	/**
	 * A sale not paid yet is not imported: it may never be, and it would hold stock.
	 *
	 * @return	void
	 */
	public function testUnpaidWaits()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)), array('status' => 'payment_required'));
		$this->assertSame(MeliOrders::WAITING, $this->sync($sale));
		$this->assertCount(0, meli_test_rows("SELECT o.rowid FROM ".$db->prefix()."meli_order as o WHERE o.order_ids LIKE '%".$db->escape($this->orderId($sale))."%'"));
	}

	/**
	 * Another channel took the last unit first: the paid sale is imported anyway and flagged.
	 *
	 * @return	void
	 */
	public function testOversold()
	{
		$product = $this->fixtures->product();
		$item = $this->listing($product);
		$this->fixtures->stock($product, $this->warehouse, 1);
		$this->fixtures->order(array((int) $product->id => 1));
		$sale = $this->sell(array(array('item_id' => $item, 'quantity' => 1, 'unit_price' => 100.0)));

		$this->assertSame(MeliOrders::IMPORTED, $this->sync($sale));
		$row = $this->row($sale);
		$this->assertSame(array('1', '0'), array($row['oversold'], $row['needs_review']));
		$this->assertGreaterThan(0, (int) $row['fk_facture'], 'the buyer paid: invoiced all the same');
	}

	/**
	 * When Dolibarr refuses orders without stock, an oversold sale stays in draft for a person, not invoiced.
	 *
	 * @return	void
	 */
	public function testOversoldWithStrictStockStaysDraft()
	{
		/** @var DoliDB $db */
		global $db;

		meli_test_set_global('STOCK_MUST_BE_ENOUGH_FOR_ORDER', '1');
		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)));

		$this->assertSame(MeliOrders::IMPORTED, $this->sync($sale));
		$row = $this->row($sale);
		$this->assertSame(array('1', '1', ''), array($row['oversold'], $row['needs_review'], $row['fk_facture']));
		$order = new Commande($db);
		$order->fetch((int) $row['fk_commande']);
		$this->assertSame(Commande::STATUS_DRAFT, (int) $order->status);
	}

	/**
	 * A listing whose product is unknown: the sale is kept, in draft, with a free line, for a person.
	 *
	 * @return	void
	 */
	public function testUnknownProduct()
	{
		/** @var DoliDB $db */
		global $db;

		$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $this->fixtures->tag.'-NONE', 'quantity' => 2));
		$sale = $this->sell(array(array('item_id' => meli_test_str($item['id']), 'quantity' => 1, 'unit_price' => 700.0)));
		$this->assertSame(MeliOrders::IMPORTED, $this->sync($sale));
		$row = $this->row($sale);
		$this->assertSame(array('1', ''), array($row['needs_review'], $row['fk_facture']));
		$lines = meli_test_rows("SELECT cd.fk_product, cd.description FROM ".$db->prefix()."commandedet as cd WHERE cd.fk_commande = ".((int) $row['fk_commande']));
		$this->assertSame('', $lines[0]['fk_product']);
		$this->assertStringContainsString($this->fixtures->tag.'-NONE', $lines[0]['description']);
	}

	/**
	 * Cancelled on Mercado Libre before invoicing: the order is cancelled, its product sent again.
	 *
	 * @return	void
	 */
	public function testCancelledBeforeInvoice()
	{
		/** @var DoliDB $db */
		global $db;

		meli_test_set_global('MELI_INVOICE_ORDERS', '0');
		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)));
		$this->sync($sale);
		$db->query("DELETE FROM ".$db->prefix()."meli_outbox WHERE fk_product = ".((int) $product->id));
		$this->fake()->cancelOrder($this->orderId($sale));

		$this->assertSame(MeliOrders::UPDATED, $this->sync($sale));
		$row = $this->row($sale);
		$order = new Commande($db);
		$order->fetch((int) $row['fk_commande']);
		$this->assertSame(Commande::STATUS_CANCELED, (int) $order->status);
		$this->assertSame(array('cancelled', '0'), array($row['ml_status'], $row['needs_review']));
		$this->assertCount(1, meli_test_rows("SELECT o.rowid FROM ".$db->prefix()."meli_outbox as o WHERE o.fk_product = ".((int) $product->id)));
	}

	/**
	 * Cancelled after invoicing: flagged for a credit note; the invoice is left as it is.
	 *
	 * @return	void
	 */
	public function testCancelledAfterInvoice()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)));
		$this->sync($sale);
		$this->fake()->cancelOrder($this->orderId($sale));

		$this->assertSame(MeliOrders::UPDATED, $this->sync($sale));
		$row = $this->row($sale);
		$this->assertSame(array('cancelled', '1'), array($row['ml_status'], $row['needs_review']));
		$invoice = new Facture($db);
		$invoice->fetch((int) $row['fk_facture']);
		$this->assertSame(Facture::STATUS_CLOSED, (int) $invoice->status);
	}
}
