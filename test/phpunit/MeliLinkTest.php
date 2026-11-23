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
 * \file    test/phpunit/MeliLinkTest.php
 * \ingroup meli
 * \brief   Listings read from the fake Mercado Libre and linked to products by SKU.
 */

require_once __DIR__.'/bootstrap.inc.php';

/**
 * Link tests
 */
class MeliLinkTest extends MeliFakeTestCase
{
	/**
	 * A sync marks every link it did not see as gone: refuse to run where links exist that are not ours.
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
	}

	/**
	 * User Products items and legacy variations are linked by SKU; the others say why not.
	 *
	 * @return	void
	 */
	public function testSyncBySku()
	{
		/** @var DoliDB $db */
		global $db;

		$mate = $this->fixtures->product();
		$red = $this->fixtures->product();
		$blue = $this->fixtures->product();
		$up = $this->fake()->seedItem(array('model' => 'up', 'sku' => $mate->ref, 'family_name' => 'Mate', 'quantity' => 3));
		$legacy = $this->fake()->seedItem(array('model' => 'legacy', 'title' => 'Poncho', 'variations' => array(
			array('sku' => $red->ref, 'quantity' => 2, 'attribute_combinations' => array(array('id' => 'COLOR', 'value_name' => 'Rojo'))),
			array('sku' => $blue->ref, 'quantity' => 0, 'attribute_combinations' => array(array('id' => 'COLOR', 'value_name' => 'Azul'))),
		)));
		$nosku = $this->fake()->seedItem(array('model' => 'up', 'sku' => '', 'quantity' => 1));
		$unknown = $this->fake()->seedItem(array('model' => 'up', 'sku' => $this->fixtures->tag.'-NONE', 'quantity' => 1));

		$counts = (new MeliLink($db))->sync();
		$this->assertSame(array('listings' => 5, 'linked' => 3, 'unlinked' => 2, 'gone' => 0), $counts);

		$links = $this->links();
		$this->assertSame(array((int) $mate->id, '', '3'), array($links[meli_test_str($up['id'])][0], $links[meli_test_str($up['id'])][1], $links[meli_test_str($up['id'])][2]));
		$variations = array();
		foreach ($links as $key => $link) {
			if (strpos($key, meli_test_str($legacy['id']).'/') === 0) {
				$variations[] = $link[0];
			}
		}
		sort($variations);
		$this->assertSame(array((int) $red->id, (int) $blue->id), $variations, 'one link per variation');
		$this->assertSame(array(null, MeliLink::NO_SKU), array_slice($links[meli_test_str($nosku['id'])], 0, 2));
		$this->assertSame(array(null, MeliLink::NO_PRODUCT), array_slice($links[meli_test_str($unknown['id'])], 0, 2));
		$this->assertSame(array(), $this->calls() === array() ? array('none') : array_values(array_filter($this->calls(), function ($call) {
			return strpos($call, 'PUT ') === 0 || strpos($call, 'POST /items') === 0;
		})), 'reading writes nothing');
	}

	/**
	 * A product with variants cannot be linked: its variants are what is sold.
	 *
	 * @return	void
	 */
	public function testVariantParentRefused()
	{
		/**
		 * @var Conf $conf
		 * @var DoliDB $db
		 */
		global $conf, $db;

		$table = $db->prefix().'product_attribute_combination';
		if (!$db->query("SELECT rowid FROM ".$table." WHERE 1 = 0", 1)) {
			$this->markTestSkipped('module variants never enabled here');
		}
		$parent = $this->fixtures->product();
		$child = $this->fixtures->product();
		$db->query("INSERT INTO ".$table." (fk_product_parent, fk_product_child, variation_price, variation_weight, entity) VALUES (".((int) $parent->id).", ".((int) $child->id).", 0, 0, ".((int) $conf->entity).")");
		try {
			$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $parent->ref, 'quantity' => 1));
			(new MeliLink($db))->sync();
			$this->assertSame(array(null, MeliLink::VARIANT_PARENT), array_slice($this->links()[meli_test_str($item['id'])], 0, 2));
			$rows = meli_test_rows("SELECT l.rowid FROM ".$db->prefix()."meli_link as l WHERE l.item_id = '".$db->escape(meli_test_str($item['id']))."'");
			$this->assertFalse((new MeliLink($db))->setProduct((int) $rows[0]['rowid'], (int) $parent->id), 'not by hand either');
		} finally {
			$db->query("DELETE FROM ".$table." WHERE fk_product_parent = ".((int) $parent->id)." AND fk_product_child = ".((int) $child->id));
		}
	}

	/**
	 * A link set by hand, and an ignored listing, stay as they are when the listings are read again.
	 *
	 * @return	void
	 */
	public function testManualAndIgnoredKept()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$other = $this->fixtures->product();
		$item = $this->fake()->seedItem(array('model' => 'up', 'sku' => $product->ref, 'quantity' => 1));
		$ignored = $this->fake()->seedItem(array('model' => 'up', 'sku' => $other->ref, 'quantity' => 1));
		$links = new MeliLink($db);
		$links->sync();
		$ids = $this->linkIds();
		$this->assertTrue($links->setProduct($ids[meli_test_str($item['id'])], (int) $other->id));
		$this->assertTrue($links->setIgnored($ids[meli_test_str($ignored['id'])], true));

		$links->sync();
		$rows = $this->links();
		$this->assertSame((int) $other->id, $rows[meli_test_str($item['id'])][0], 'manual link kept although the SKU says otherwise');
		$linked = array();
		foreach ($links->linksOf(array((int) $product->id, (int) $other->id)) as $link) {
			$linked[] = $link['item_id'];
		}
		$this->assertSame(array(meli_test_str($item['id'])), $linked, 'the ignored listing is not pushed');

		$this->assertTrue($links->setProduct($ids[meli_test_str($item['id'])], 0));
		$links->sync();
		$this->assertSame((int) $product->id, $this->links()[meli_test_str($item['id'])][0], 'back to the SKU');
	}

	/**
	 * Mercado Libre replaces an item by a new one (move to User Products): the old link is gone, the new one linked.
	 *
	 * @return	void
	 */
	public function testItemReplaced()
	{
		/** @var DoliDB $db */
		global $db;

		$product = $this->fixtures->product();
		$item = $this->fake()->seedItem(array('model' => 'legacy', 'title' => 'Bolso', 'variations' => array(array('sku' => $product->ref, 'quantity' => 1))));
		$links = new MeliLink($db);
		$links->sync();
		$change = $this->fake()->changeItemId(meli_test_str($item['id']));
		$created = (isset($change['created']) && is_array($change['created'])) ? $change['created'] : array();
		$this->assertCount(1, $created);

		$counts = $links->sync();
		$this->assertSame(1, $counts === null ? -1 : $counts['gone']);
		$active = array();
		foreach ($links->linksOf(array((int) $product->id)) as $link) {
			$active[] = $link['item_id'];
		}
		$this->assertSame(array(meli_test_str($created[0])), $active);
	}

	/**
	 * Link rows of this test, by "item" or "item/variation".
	 *
	 * @return	array<string, array{0: int|null, 1: string, 2: string}>	Product (null if none), reason, quantity on Mercado Libre
	 */
	private function links()
	{
		/** @var DoliDB $db */
		global $db;

		$links = array();
		foreach (meli_test_rows("SELECT l.item_id, l.variation_id, l.fk_product, l.reason, l.ml_quantity FROM ".$db->prefix()."meli_link as l WHERE l.active = 1") as $row) {
			$key = $row['item_id'].($row['variation_id'] !== '0' ? '/'.$row['variation_id'] : '');
			$links[$key] = array($row['fk_product'] === '' ? null : (int) $row['fk_product'], $row['reason'], $row['ml_quantity']);
		}

		return $links;
	}

	/**
	 * Ids of the link rows, by item.
	 *
	 * @return	array<string, int>
	 */
	private function linkIds()
	{
		/** @var DoliDB $db */
		global $db;

		$ids = array();
		foreach (meli_test_rows("SELECT l.rowid, l.item_id FROM ".$db->prefix()."meli_link as l") as $row) {
			$ids[$row['item_id']] = (int) $row['rowid'];
		}

		return $ids;
	}

	/**
	 * Every link row is this test's (setUp refused to run otherwise): remove them all.
	 *
	 * @return	void
	 */
	protected function tearDown(): void
	{
		/** @var DoliDB $db */
		global $db;

		$items = array();
		$state = $this->fake()->state();
		foreach ((isset($state['items']) && is_array($state['items'])) ? array_keys($state['items']) : array() as $id) {
			$items[] = "'".$db->escape((string) $id)."'";
		}
		if (!empty($items)) {
			$db->query("DELETE FROM ".$db->prefix()."meli_link WHERE item_id IN (".implode(',', $items).")");
		}
		parent::tearDown();
	}
}
