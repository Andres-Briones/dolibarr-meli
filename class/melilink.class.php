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
 * \file    class/melilink.class.php
 * \ingroup meli
 * \brief   Links between Mercado Libre listings and Dolibarr products, found by SKU.
 */

require_once __DIR__.'/meliapi.class.php';

/**
 * Listing links
 *
 * Each listing that sells one physical product — an item of the User Products model, or a
 * variation of a legacy item — is linked to the product whose ref is its SELLER_SKU. Reading the
 * listings never writes anything to Mercado Libre.
 */
class MeliLink
{
	const NO_SKU = 'no_sku';
	const NO_PRODUCT = 'no_product';
	const SEVERAL = 'several_products';
	const VARIANT_PARENT = 'variant_parent';

	/**
	 * @var string	Why the last sync failed
	 */
	public $error = '';

	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * @var MeliApi
	 */
	private $api;

	/**
	 * Constructor
	 *
	 * @param DoliDB	$db		Database handler
	 * @param MeliApi	$api	API client, a new one when null
	 */
	public function __construct(DoliDB $db, $api = null)
	{
		$this->db = $db;
		$this->api = ($api instanceof MeliApi ? $api : new MeliApi($db));
	}

	/**
	 * Read the seller's listings and link them to products.
	 *
	 * @return	array{listings: int, linked: int, unlinked: int, gone: int}|null	Counts; null on failure (see $error)
	 */
	public function sync()
	{
		$account = (new MeliAccount($this->db))->fetch();
		if ($account === null || (int) $account['ml_user_id'] <= 0) {
			$this->error = 'No connected account';
			return null;
		}

		$ids = array();
		$offset = 0;
		do {
			$page = $this->api->get('/users/'.((int) $account['ml_user_id']).'/items/search?status=active,paused&offset='.$offset.'&limit=50');
			if (!$page['ok']) {
				$this->error = $page['message'];
				return null;
			}
			$results = (isset($page['data']['results']) && is_array($page['data']['results'])) ? $page['data']['results'] : array();
			foreach ($results as $id) {
				if (is_string($id) && $id !== '') {
					$ids[$id] = $id;
				}
			}
			$paging = (isset($page['data']['paging']) && is_array($page['data']['paging'])) ? $page['data']['paging'] : array();
			$total = self::int($paging['total'] ?? 0);
			$offset += 50;
		} while (!empty($results) && $offset < $total && $offset < 10000);

		$listings = array();
		foreach (array_chunk(array_values($ids), 20) as $chunk) {
			$bulk = $this->api->get('/items/bulk?ids='.implode(',', array_map('rawurlencode', $chunk)));
			if (!$bulk['ok']) {
				$this->error = $bulk['message'];
				return null;
			}
			foreach ($bulk['data'] as $entry) {
				if (is_array($entry) && self::int($entry['code'] ?? 0) === 200 && isset($entry['body']) && is_array($entry['body'])) {
					$listings = array_merge($listings, self::listingsOf($entry['body']));
				}
			}
		}

		return $this->store($listings);
	}

	/**
	 * The listings an item stands for: itself, or each of its variations for a legacy item.
	 *
	 * @param	array<mixed>	$item	Item as Mercado Libre returns it
	 * @return	array<int, array{item_id: string, variation_id: int, user_product_id: string, family_name: string, title: string, sku: string, status: string, quantity: int}>
	 */
	public static function listingsOf(array $item)
	{
		$base = array(
			'item_id' => self::str($item['id'] ?? ''),
			'variation_id' => 0,
			'user_product_id' => self::str($item['user_product_id'] ?? ''),
			'family_name' => self::str($item['family_name'] ?? ''),
			'title' => self::str($item['title'] ?? ''),
			'sku' => self::sku($item),
			'status' => self::str($item['status'] ?? ''),
			'quantity' => is_numeric($item['available_quantity'] ?? null) ? (int) $item['available_quantity'] : 0,
		);
		if ($base['item_id'] === '') {
			return array();
		}
		$variations = (isset($item['variations']) && is_array($item['variations'])) ? $item['variations'] : array();
		if (empty($variations)) {
			return array($base);
		}

		$listings = array();
		foreach ($variations as $variation) {
			if (!is_array($variation) || !is_numeric($variation['id'] ?? null)) {
				continue;
			}
			$listing = $base;
			$listing['variation_id'] = (int) $variation['id'];
			$listing['sku'] = self::sku($variation);
			$listing['quantity'] = is_numeric($variation['available_quantity'] ?? null) ? (int) $variation['available_quantity'] : 0;
			$names = array();
			foreach ((isset($variation['attribute_combinations']) && is_array($variation['attribute_combinations'])) ? $variation['attribute_combinations'] : array() as $combination) {
				if (is_array($combination) && is_string($combination['value_name'] ?? null)) {
					$names[] = $combination['value_name'];
				}
			}
			$listing['title'] = trim($base['title'].' '.implode(' / ', $names));
			$listings[] = $listing;
		}

		return $listings;
	}

	/**
	 * Link a listing to a product by hand, or set it back to automatic (product 0).
	 *
	 * @param	int		$linkId		Id of the link row
	 * @param	int		$productId	Product id, 0 to let the SKU decide again
	 * @return	bool
	 */
	public function setProduct($linkId, $productId)
	{
		/** @var Conf $conf */
		global $conf;

		if ($productId > 0 && $this->reasonFor(array($productId)) !== '') {
			return false;
		}
		$sql = "UPDATE ".$this->db->prefix()."meli_link SET fk_product = ".($productId > 0 ? (int) $productId : 'NULL').", manual = ".($productId > 0 ? 1 : 0);
		$sql .= ", reason = ".($productId > 0 ? 'NULL' : "'".self::NO_PRODUCT."'");
		$sql .= " WHERE rowid = ".((int) $linkId)." AND entity = ".((int) $conf->entity);

		return (bool) $this->db->query($sql);
	}

	/**
	 * Keep a listing out of every sync, or take it back.
	 *
	 * @param	int		$linkId		Id of the link row
	 * @param	bool	$ignored	True to ignore it
	 * @return	bool
	 */
	public function setIgnored($linkId, $ignored)
	{
		/** @var Conf $conf */
		global $conf;

		return (bool) $this->db->query("UPDATE ".$this->db->prefix()."meli_link SET ignored = ".($ignored ? 1 : 0)." WHERE rowid = ".((int) $linkId)." AND entity = ".((int) $conf->entity));
	}

	/**
	 * Active, not ignored links of products.
	 *
	 * @param	array<int>	$productIds	Product ids; all linked products when empty
	 * @return	array<int, array{id: int, fk_product: int, item_id: string, variation_id: int, user_product_id: string, buffer: int|null, last_pushed_qty: int|null, last_x_version: string, last_push_at: string}>
	 */
	public function linksOf(array $productIds = array())
	{
		/** @var Conf $conf */
		global $conf;

		$sql = "SELECT l.rowid, l.fk_product, l.item_id, l.variation_id, l.user_product_id, l.buffer, l.last_pushed_qty, l.last_x_version, l.last_push_at";
		$sql .= " FROM ".$this->db->prefix()."meli_link as l WHERE l.entity = ".((int) $conf->entity)." AND l.active = 1 AND l.ignored = 0 AND l.fk_product > 0";
		if (!empty($productIds)) {
			$sql .= " AND l.fk_product IN (".implode(',', array_map('intval', $productIds)).")";
		}
		$sql .= " ORDER BY l.fk_product, l.rowid";
		$links = array();
		$resql = $this->db->query($sql);
		while ($resql && $resql !== true && is_array($row = $this->db->fetch_array($resql))) {
			$links[] = array(
				'id' => self::int($row['rowid']), 'fk_product' => self::int($row['fk_product']), 'item_id' => self::str($row['item_id']), 'variation_id' => self::int($row['variation_id']),
				'user_product_id' => self::str($row['user_product_id']), 'buffer' => is_numeric($row['buffer']) ? self::int($row['buffer']) : null,
				'last_pushed_qty' => is_numeric($row['last_pushed_qty']) ? self::int($row['last_pushed_qty']) : null, 'last_x_version' => self::str($row['last_x_version']),
				'last_push_at' => self::str($row['last_push_at']),
			);
		}

		return $links;
	}

	/**
	 * Save the listings read and link those that are not linked by hand.
	 *
	 * @param	array<int, array{item_id: string, variation_id: int, user_product_id: string, family_name: string, title: string, sku: string, status: string, quantity: int}>	$listings	Listings read
	 * @return	array{listings: int, linked: int, unlinked: int, gone: int}
	 */
	private function store(array $listings)
	{
		/** @var Conf $conf */
		global $conf;

		$table = $this->db->prefix().'meli_link';
		$now = "'".$this->db->idate(dol_now())."'";
		$counts = array('listings' => count($listings), 'linked' => 0, 'unlinked' => 0, 'gone' => 0);
		$seen = array();
		foreach ($listings as $listing) {
			$key = "item_id = '".$this->db->escape($listing['item_id'])."' AND variation_id = ".((int) $listing['variation_id']);
			$resql = $this->db->query("SELECT rowid, fk_product, manual FROM ".$table." WHERE entity = ".((int) $conf->entity)." AND ".$key);
			$existing = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;
			$manual = (is_array($existing) && self::int($existing['manual']) === 1);

			$product = 0;
			$reason = '';
			if ($manual) {
				$product = self::int($existing['fk_product']);
			} else {
				list($product, $reason) = $this->match($listing['sku']);
			}
			$values = array(
				'user_product_id' => $listing['user_product_id'], 'family_name' => $listing['family_name'], 'title' => dol_trunc($listing['title'], 250, 'right', 'UTF-8', 1),
				'seller_sku' => $listing['sku'], 'listing_status' => $listing['status'], 'ml_quantity' => (string) $listing['quantity'],
			);
			$sets = array();
			foreach ($values as $column => $value) {
				$sets[] = $column." = ".($value === '' ? 'NULL' : "'".$this->db->escape($value)."'");
			}
			$sets[] = "fk_product = ".($product > 0 ? $product : 'NULL');
			$sets[] = "reason = ".($reason === '' ? 'NULL' : "'".$reason."'");
			$sets[] = "active = 1";
			$sets[] = "date_seen = ".$now;
			if (is_array($existing)) {
				$this->db->query("UPDATE ".$table." SET ".implode(', ', $sets)." WHERE rowid = ".(self::int($existing['rowid'])));
				$seen[] = self::int($existing['rowid']);
			} else {
				$sql = "INSERT INTO ".$table." (entity, item_id, variation_id, date_creation) VALUES (".((int) $conf->entity).", '".$this->db->escape($listing['item_id'])."', ".((int) $listing['variation_id']).", ".$now.")";
				if ($this->db->query($sql)) {
					$id = (int) $this->db->last_insert_id($table);
					$this->db->query("UPDATE ".$table." SET ".implode(', ', $sets)." WHERE rowid = ".$id);
					$seen[] = $id;
				}
			}
			$counts[$product > 0 ? 'linked' : 'unlinked']++;
		}

		// Listings no longer returned were closed, or replaced when Mercado Libre moved them to User
		// Products (the replacement came back with the same SKU and was linked above).
		$where = " WHERE entity = ".((int) $conf->entity)." AND active = 1".(empty($seen) ? '' : " AND rowid NOT IN (".implode(',', $seen).")");
		// Counted before: drivers report the rows an UPDATE changed differently.
		$resql = $this->db->query("SELECT COUNT(rowid) as nb FROM ".$table.$where);
		$row = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;
		$counts['gone'] = is_array($row) ? self::int($row['nb']) : 0;
		$this->db->query("UPDATE ".$table." SET active = 0".$where);

		return $counts;
	}

	/**
	 * The product a SKU designates.
	 *
	 * @param	string	$sku	SELLER_SKU of the listing
	 * @return	array{0: int, 1: string}	Product id (0 if none) and the reason when none
	 */
	private function match($sku)
	{
		if ($sku === '') {
			return array(0, self::NO_SKU);
		}
		$sql = "SELECT p.rowid FROM ".$this->db->prefix()."product as p WHERE p.ref = '".$this->db->escape($sku)."' AND p.entity IN (".getEntity('product').")";
		$ids = array();
		$resql = $this->db->query($sql);
		while ($resql && $resql !== true && is_array($row = $this->db->fetch_array($resql))) {
			$ids[] = self::int($row['rowid']);
		}
		if (empty($ids)) {
			return array(0, self::NO_PRODUCT);
		}
		if (count($ids) > 1) {
			return array(0, self::SEVERAL);
		}
		$reason = $this->reasonFor($ids);

		return ($reason === '' ? array($ids[0], '') : array(0, $reason));
	}

	/**
	 * Why a product cannot be linked to a listing.
	 *
	 * @param	array<int>	$productIds	One product id
	 * @return	string					'' when it can
	 */
	private function reasonFor(array $productIds)
	{
		// A product with variants has no stock of its own: its variants are what the listings sell.
		$sql = "SELECT pac.rowid FROM ".$this->db->prefix()."product_attribute_combination as pac WHERE pac.fk_product_parent IN (".implode(',', array_map('intval', $productIds)).")";
		$resql = $this->db->query($sql);

		return ($resql && $resql !== true && is_array($this->db->fetch_array($resql))) ? self::VARIANT_PARENT : '';
	}

	/**
	 * SELLER_SKU of an item or a variation: the attribute, else the old seller_custom_field.
	 *
	 * @param	array<mixed>	$node	Item or variation
	 * @return	string
	 */
	private static function sku(array $node)
	{
		foreach ((isset($node['attributes']) && is_array($node['attributes'])) ? $node['attributes'] : array() as $attribute) {
			if (is_array($attribute) && ($attribute['id'] ?? '') === 'SELLER_SKU' && is_string($attribute['value_name'] ?? null)) {
				return trim($attribute['value_name']);
			}
		}

		return trim(self::str($node['seller_custom_field'] ?? ''));
	}

	/**
	 * A number as an int.
	 *
	 * @param	mixed	$value	Value
	 * @return	int			0 when not numeric
	 */
	private static function int($value)
	{
		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * A scalar as a string.
	 *
	 * @param	mixed	$value	Value
	 * @return	string
	 */
	private static function str($value)
	{
		return is_scalar($value) ? (string) $value : '';
	}
}
