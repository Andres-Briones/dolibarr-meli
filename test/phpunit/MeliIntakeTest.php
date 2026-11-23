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
 * \file    test/phpunit/MeliIntakeTest.php
 * \ingroup meli
 * \brief   What the module takes from Mercado Libre without calling it: listings as returned, notifications.
 */

require_once __DIR__.'/bootstrap.inc.php';

/**
 * Listings parsing and notification intake
 */
class MeliIntakeTest extends MeliTestCase
{
	/**
	 * An item of the User Products model is one listing, with its SKU attribute.
	 *
	 * @return	void
	 */
	public function testUserProductItem()
	{
		$listings = MeliLink::listingsOf(array(
			'id' => 'MLA1', 'user_product_id' => 'MLAU9', 'family_name' => 'Taza', 'title' => 'Taza roja', 'status' => 'active', 'available_quantity' => 4,
			'attributes' => array(array('id' => 'COLOR', 'value_name' => 'Rojo'), array('id' => 'SELLER_SKU', 'value_name' => ' TAZA-R ')),
		));
		$this->assertSame(array(array(
			'item_id' => 'MLA1', 'variation_id' => 0, 'user_product_id' => 'MLAU9', 'family_name' => 'Taza', 'title' => 'Taza roja', 'sku' => 'TAZA-R', 'status' => 'active', 'quantity' => 4,
		)), $listings);
	}

	/**
	 * A legacy item with variations is one listing per variation, each with its own SKU and quantity.
	 *
	 * @return	void
	 */
	public function testLegacyVariations()
	{
		$listings = MeliLink::listingsOf(array(
			'id' => 'MLA2', 'title' => 'Remera', 'status' => 'paused', 'available_quantity' => 9, 'seller_custom_field' => 'IGNORED',
			'variations' => array(
				array('id' => 11, 'available_quantity' => 2, 'attribute_combinations' => array(array('value_name' => 'M'), array('value_name' => 'Azul')), 'attributes' => array(array('id' => 'SELLER_SKU', 'value_name' => 'REM-M-AZ'))),
				array('id' => 12, 'available_quantity' => 0, 'seller_custom_field' => 'REM-L-AZ'),
				array('id' => 'not a number'),
			),
		));
		$this->assertCount(2, $listings);
		$this->assertSame(array(11, 'REM-M-AZ', 2, 'Remera M / Azul', 'paused'), array($listings[0]['variation_id'], $listings[0]['sku'], $listings[0]['quantity'], $listings[0]['title'], $listings[0]['status']));
		$this->assertSame(array(12, 'REM-L-AZ', 0), array($listings[1]['variation_id'], $listings[1]['sku'], $listings[1]['quantity']), 'old seller_custom_field when there is no attribute');
		$this->assertSame(array(), MeliLink::listingsOf(array('title' => 'no id')));
	}

	/**
	 * Notifications are stored as received, only for this application and account's topics; malformed ones are refused.
	 *
	 * @return	void
	 */
	public function testNotificationIntake()
	{
		/** @var DoliDB $db */
		global $db;

		meli_test_set_global('MELI_APP_ID', '4242');
		$inbox = new MeliInbox($db);
		$resource = '/items/'.$this->fixtures->tag;
		try {
			$this->assertSame(400, $inbox->receive('not json'));
			$this->assertSame(400, $inbox->receive((string) json_encode(array('topic' => 'items', 'resource' => 'no-slash'))));
			$this->assertSame(400, $inbox->receive((string) json_encode(array('topic' => 'items', 'resource' => '/items/<script>'))));
			$this->assertSame(200, $inbox->receive((string) json_encode(array('topic' => 'items', 'resource' => $resource.'X', 'application_id' => 999))), 'another application: answered, not stored');
			$this->assertSame(200, $inbox->receive((string) json_encode(array('topic' => 'questions', 'resource' => $resource.'Y', 'application_id' => 4242))), 'topic not used: answered, not stored');
			$body = (string) json_encode(array('topic' => 'items', 'resource' => $resource, 'application_id' => 4242, 'user_id' => 0, 'attempts' => 2, 'sent' => '2026-11-23T05:00:00.000Z'));
			$this->assertSame(200, $inbox->receive($body));

			$rows = meli_test_rows("SELECT i.topic, i.resource, i.status, i.attempts_ml, i.body FROM ".$db->prefix()."meli_inbox as i WHERE i.resource LIKE '/items/".$db->escape($this->fixtures->tag)."%'");
			$this->assertSame(array(array('topic' => 'items', 'resource' => $resource, 'status' => 'new', 'attempts_ml' => '2', 'body' => $body)), $rows);
		} finally {
			$db->query("DELETE FROM ".$db->prefix()."meli_inbox WHERE resource LIKE '/items/".$db->escape($this->fixtures->tag)."%'");
		}
	}

	/**
	 * Bodies of notifications older than 30 days are erased, the rows kept; newer ones are untouched.
	 *
	 * @return	void
	 */
	public function testPurge()
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$table = $db->prefix().'meli_inbox';
		$resource = '/items/'.$db->escape($this->fixtures->tag);
		try {
			foreach (array(array('OLD', 31, 'done'), array('NEW', 29, 'done'), array('ANCIENT', 91, 'done'), array('WAITING', 91, 'new')) as $case) {
				$db->query("INSERT INTO ".$table." (entity, topic, resource, date_creation, status, body) VALUES (".((int) $conf->entity).", 'items', '".$resource.$case[0]."', '".$db->idate(dol_now() - $case[1] * 86400)."', '".$case[2]."', '{\"x\":1}')");
			}
			(new MeliInbox($db))->purge();
			$rows = meli_test_rows("SELECT i.resource, i.body FROM ".$table." as i WHERE i.resource LIKE '".$resource."%' ORDER BY i.resource");
			$this->assertSame(array(
				array('resource' => $resource.'NEW', 'body' => '{"x":1}'),
				array('resource' => $resource.'OLD', 'body' => ''),
				array('resource' => $resource.'WAITING', 'body' => ''),
			), $rows, 'old bodies erased; rows done for 90 days deleted; a row still waiting is kept');
		} finally {
			$db->query("DELETE FROM ".$table." WHERE resource LIKE '".$resource."%'");
		}
	}
}
