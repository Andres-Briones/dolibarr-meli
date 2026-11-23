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
 * \file    test/phpunit/MeliShippingTest.php
 * \ingroup meli
 * \brief   Notifications worked through the inbox, shipment status, labels.
 */

require_once __DIR__.'/MeliSalesTestCase.php';

/**
 * Shipping tests
 */
class MeliShippingTest extends MeliSalesTestCase
{
	/**
	 * @var list<string>	Inbox resources this test stored
	 */
	private $resources = array();

	/**
	 * A sale notified, then its shipment: both worked from the inbox, each resource read again.
	 *
	 * @return	void
	 */
	public function testNotificationsThroughTheInbox()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)));
		$inbox = new MeliInbox($db);
		$this->assertSame(200, $this->notify($inbox, 'orders_v2', '/orders/'.$this->orderId($sale)));
		$this->assertSame(200, $this->notify($inbox, 'orders_v2', '/orders/'.$this->orderId($sale)), 'a duplicate');
		$counts = $inbox->process(1000);
		$this->assertSame(1, $counts[MeliOrders::IMPORTED] ?? 0, 'the duplicate is not worked twice');
		$this->sync($sale);
		$row = $this->row($sale);
		$this->assertSame('ready_to_ship', $row['shipment_status']);

		$shipment = meli_test_str($sale['shipment_id'] ?? '');
		$this->fake()->setShipment(array('id' => (int) $shipment, 'status' => 'shipped', 'substatus' => null));
		$this->assertSame(200, $this->notify($inbox, 'shipments', '/shipments/'.$shipment));
		$inbox->process(1000);
		$this->assertSame('shipped', $this->row($sale)['shipment_status']);
		$this->assertSame(array(), meli_test_rows("SELECT i.rowid FROM ".$db->prefix()."meli_inbox as i WHERE i.status = 'new' AND i.resource IN ('".implode("','", array_map(array($db, 'escape'), $this->resources))."')"), 'all worked');
	}

	/**
	 * A shipment change whose notification was lost is caught by reading open shipments again.
	 *
	 * @return	void
	 */
	public function testOpenShipmentsRead()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)));
		$this->sync($sale);
		$this->fake()->setShipment(array('id' => (int) meli_test_str($sale['shipment_id'] ?? ''), 'status' => 'delivered', 'substatus' => null));

		$this->assertGreaterThanOrEqual(1, (new MeliShipping($db))->refreshOpen());
		$this->assertSame('delivered', $this->row($sale)['shipment_status']);
	}

	/**
	 * The label of a shipment ready to print is saved, as Mercado Libre gives it, in the order's documents.
	 *
	 * @return	void
	 */
	public function testLabel()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)));
		$this->sync($sale);
		$row = $this->row($sale);
		$path = (new MeliShipping($db))->label(array((int) $row['fk_commande']));
		try {
			$this->assertNotSame('', $path);
			$this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
			$order = new Commande($db);
			$order->fetch((int) $row['fk_commande']);
			$this->assertStringContainsString('/'.dol_sanitizeFileName((string) $order->ref).'/', $path);
			$this->assertSame(basename($path), $this->row($sale)['label_file']);
			$this->assertContains('GET /shipment_labels', $this->calls());
		} finally {
			if ($path !== '' && is_file($path)) {
				unlink($path);
			}
		}
	}

	/**
	 * No label before the shipment is ready: Mercado Libre refuses, nothing is saved.
	 *
	 * @return	void
	 */
	public function testNoLabelBeforeReady()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$sale = $this->sell(array(array('item_id' => $this->listing($product), 'quantity' => 1, 'unit_price' => 100.0)));
		$this->sync($sale);
		$this->fake()->setShipment(array('id' => (int) meli_test_str($sale['shipment_id'] ?? ''), 'status' => 'pending', 'substatus' => null));
		$row = $this->row($sale);
		$shipping = new MeliShipping($db);
		$this->assertSame('', $shipping->label(array((int) $row['fk_commande'])));
		$this->assertNotSame('', $shipping->error);
		$this->assertSame('', $this->row($sale)['label_file']);
	}

	/**
	 * Store a notification as Mercado Libre would send it for the connected account.
	 *
	 * @param	MeliInbox	$inbox		Inbox
	 * @param	string		$topic		Topic
	 * @param	string		$resource	Resource
	 * @return	int						HTTP status answered
	 */
	private function notify(MeliInbox $inbox, $topic, $resource)
	{
		$this->resources[] = $resource;

		return $inbox->receive((string) json_encode(array('topic' => $topic, 'resource' => $resource, 'user_id' => 123456789, 'application_id' => (int) self::APP_ID, 'attempts' => 1)));
	}

	/**
	 * Remove this test's notifications.
	 *
	 * @return	void
	 */
	protected function tearDown(): void
	{
		/** @var DoliDB $db */
		global $db;

		if (!empty($this->resources)) {
			$db->query("DELETE FROM ".$db->prefix()."meli_inbox WHERE resource IN ('".implode("','", array_map(array($db, 'escape'), $this->resources))."')");
			$this->resources = array();
		}
		parent::tearDown();
	}
}
