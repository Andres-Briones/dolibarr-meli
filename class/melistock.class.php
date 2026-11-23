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
 * \file    class/melistock.class.php
 * \ingroup meli
 * \brief   Quantity of each product that may be offered on Mercado Libre, and its push.
 */

require_once __DIR__.'/melilink.class.php';

/**
 * Stock for Mercado Libre
 *
 * Dolibarr holds the only real stock; Mercado Libre gets a projection of it. The sellable quantity
 * follows the same rule as the online shop's (module tienda), with its own warehouses: physical stock
 * minus what validated orders still have to take, which depends on when the instance decrements
 * stock. The two classes are kept equal by a test that compares them when both modules are present.
 */
class MeliStock
{
	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Sellable quantity of products.
	 *
	 * @param	array<int>	$productIds	Product ids
	 * @return	array<int, float|null>	Product id => quantity; null when not limited by stock (service, not stock-managed, stock module off). Unknown ids are absent.
	 */
	public function sellable(array $productIds)
	{
		$ids = self::idList($productIds);
		if ($ids === '0') {
			return array();
		}
		$prefix = $this->db->prefix();

		$stock = array();
		$managed = array();
		foreach ($this->rows("SELECT p.rowid, p.fk_product_type, p.stockable_product FROM ".$prefix."product as p WHERE p.rowid IN (".$ids.")") as $row) {
			$id = (int) $row['rowid'];
			$isProduct = ((int) $row['fk_product_type'] === 0 || getDolGlobalInt('STOCK_SUPPORTS_SERVICES') > 0);
			if (!isModEnabled('stock') || !$isProduct || (int) $row['stockable_product'] <= 0) {
				$stock[$id] = null;
			} else {
				$stock[$id] = 0.0;
				$managed[] = $id;
			}
		}
		if (empty($managed)) {
			return $stock;
		}
		$ids = self::idList($managed);

		$sql = "SELECT ps.fk_product, SUM(ps.reel) as qty FROM ".$prefix."product_stock as ps";
		$sql .= " JOIN ".$prefix."entrepot as w ON w.rowid = ps.fk_entrepot AND w.statut = 1 AND w.entity IN (".getEntity('stock').")";
		$sql .= " WHERE ps.fk_product IN (".$ids.") AND ps.fk_entrepot IN (".self::idList(self::warehouses()).")";
		$sql .= " GROUP BY ps.fk_product";
		foreach ($this->rows($sql) as $row) {
			$stock[(int) $row['fk_product']] = (float) $row['qty'];
		}

		// With the decrement at order validation, validated orders are already out of the physical stock.
		if (getDolGlobalInt('STOCK_CALCULATE_ON_VALIDATE_ORDER')) {
			return $stock;
		}
		$sql = "SELECT cd.fk_product, SUM(cd.qty) as qty FROM ".$prefix."commandedet as cd";
		$sql .= " JOIN ".$prefix."commande as c ON c.rowid = cd.fk_commande";
		$sql .= " WHERE c.fk_statut IN (1, 2) AND c.entity IN (".getEntity('commande').") AND cd.fk_product IN (".$ids.")";
		$sql .= " GROUP BY cd.fk_product";
		foreach ($this->rows($sql) as $row) {
			$stock[(int) $row['fk_product']] -= (float) $row['qty'];
		}
		// What was shipped for those orders has left the physical stock already, if shipments decrement it.
		$shipped = '';
		if (getDolGlobalInt('STOCK_CALCULATE_ON_SHIPMENT')) {
			$shipped = '1, 2';
		} elseif (getDolGlobalInt('STOCK_CALCULATE_ON_SHIPMENT_CLOSE')) {
			$shipped = '2';
		}
		if ($shipped !== '') {
			$sql = "SELECT cd.fk_product, SUM(ed.qty) as qty FROM ".$prefix."expeditiondet as ed";
			$sql .= " JOIN ".$prefix."expedition as e ON e.rowid = ed.fk_expedition AND e.fk_statut IN (".$shipped.")";
			$sql .= " JOIN ".$prefix."commandedet as cd ON cd.rowid = ed.fk_elementdet";
			$sql .= " JOIN ".$prefix."commande as c ON c.rowid = cd.fk_commande AND c.fk_statut IN (1, 2) AND c.entity IN (".getEntity('commande').")";
			$sql .= " WHERE cd.fk_product IN (".$ids.")";
			$sql .= " GROUP BY cd.fk_product";
			foreach ($this->rows($sql) as $row) {
				$stock[(int) $row['fk_product']] += (float) $row['qty'];
			}
		}

		return $stock;
	}

	/**
	 * Quantity to publish for a sellable quantity: never below zero, minus the buffer, within the cap.
	 *
	 * @param	float|null	$sellable	Sellable quantity, null when not limited by stock
	 * @param	int|null	$buffer		Units held back for this listing, null for the default
	 * @return	int
	 */
	public static function published($sellable, $buffer = null)
	{
		$cap = getDolGlobalInt('MELI_MAX_QTY');
		$held = ($buffer !== null ? $buffer : getDolGlobalInt('MELI_BUFFER'));
		// A product not limited by stock is offered up to the cap, or a quantity Mercado Libre accepts.
		$quantity = ($sellable === null ? ($cap > 0 ? $cap : 99) : max(0, (int) floor($sellable) - max(0, $held)));

		return ($cap > 0 ? min($quantity, $cap) : $quantity);
	}

	/**
	 * Put products in the outbox: their quantity will be sent at the next run.
	 *
	 * @param	array<int>	$productIds	Product ids
	 * @param	string		$reason		Why, for the screens
	 * @return	void
	 */
	public function enqueue(array $productIds, $reason)
	{
		/** @var Conf $conf */
		global $conf;

		$table = $this->db->prefix().'meli_outbox';
		$now = "'".$this->db->idate(dol_now())."'";
		// Only products sold on Mercado Libre: the others would fill the outbox for nothing.
		$linked = array();
		foreach ((new MeliLink($this->db))->linksOf(array_map('intval', $productIds)) as $link) {
			$linked[$link['fk_product']] = $link['fk_product'];
		}
		foreach ($linked as $id) {
			if ($id <= 0) {
				continue;
			}
			// One row per product: an existing row is made due now instead of adding another.
			$sql = "UPDATE ".$table." SET date_due = ".$now.", reason = '".$this->db->escape($reason)."' WHERE entity = ".((int) $conf->entity)." AND fk_product = ".$id;
			$resql = $this->db->query($sql, 1);
			if ($resql && $resql !== true && $this->db->affected_rows($resql) > 0) {
				continue;
			}
			$this->db->query("INSERT INTO ".$table." (entity, fk_product, reason, date_due) VALUES (".((int) $conf->entity).", ".$id.", '".$this->db->escape($reason)."', ".$now.")", 1);
		}
	}

	/**
	 * Send the quantities of the products due in the outbox.
	 *
	 * @param	MeliApi|null	$api	API client
	 * @param	int				$limit	Most products in one run
	 * @return	array{pushed: int, unchanged: int, failed: int, skipped: int}
	 */
	public function push($api = null, $limit = 50)
	{
		/** @var Conf $conf */
		global $conf;

		$counts = array('pushed' => 0, 'unchanged' => 0, 'failed' => 0, 'skipped' => 0);
		$table = $this->db->prefix().'meli_outbox';
		$due = array();
		$sql = "SELECT o.fk_product, o.attempts FROM ".$table." as o WHERE o.entity = ".((int) $conf->entity)." AND o.date_due <= '".$this->db->idate(dol_now())."' ORDER BY o.date_due, o.rowid".$this->db->plimit(max(1, $limit));
		foreach ($this->rows($sql) as $row) {
			$due[(int) $row['fk_product']] = (int) $row['attempts'];
		}
		if (empty($due)) {
			return $counts;
		}
		// Read-only until the owners switch the push on: nothing is sent, nothing is lost.
		if (!getDolGlobalInt('MELI_PUSH_STOCK')) {
			$counts['skipped'] = count($due);
			return $counts;
		}

		$api = ($api instanceof MeliApi ? $api : new MeliApi($this->db));
		$links = new MeliLink($this->db, $api);
		$sellable = $this->sellable(array_keys($due));
		$byProduct = array();
		foreach ($links->linksOf(array_keys($due)) as $link) {
			$byProduct[$link['fk_product']][] = $link;
		}

		foreach ($due as $product => $attempts) {
			$failed = false;
			foreach ($byProduct[$product] ?? array() as $link) {
				$quantity = self::published(array_key_exists($product, $sellable) ? $sellable[$product] : 0.0, $link['buffer']);
				$result = $this->pushLink($api, $link, $quantity);
				if ($result === 'failed') {
					$failed = true;
				}
				$counts[$result]++;
			}
			if ($failed) {
				// Back off: 1, 5, 15, 60 minutes.
				$minutes = array(1, 5, 15, 60)[min($attempts, 3)];
				$this->db->query("UPDATE ".$table." SET attempts = attempts + 1, date_due = '".$this->db->idate(dol_now() + $minutes * 60)."' WHERE entity = ".((int) $conf->entity)." AND fk_product = ".((int) $product));
			} else {
				$this->db->query("DELETE FROM ".$table." WHERE entity = ".((int) $conf->entity)." AND fk_product = ".((int) $product));
			}
		}

		return $counts;
	}

	/**
	 * Send one listing's quantity.
	 *
	 * @param	MeliApi		$api		API client
	 * @param	array{id: int, fk_product: int, item_id: string, variation_id: int, user_product_id: string, buffer: int|null, last_pushed_qty: int|null, last_x_version: string, last_push_at: string}	$link	Link
	 * @param	int			$quantity	Quantity to publish
	 * @return	'pushed'|'unchanged'|'failed'
	 */
	private function pushLink(MeliApi $api, array $link, $quantity)
	{
		$recent = ($link['last_push_at'] !== '' && (int) $this->db->jdate($link['last_push_at']) > dol_now() - 86400);
		// The same value again only once a day, to repair what may have changed on Mercado Libre's side.
		if ($link['last_pushed_qty'] === $quantity && $recent) {
			return 'unchanged';
		}

		$version = '';
		if ($link['user_product_id'] !== '' && $link['variation_id'] === 0) {
			// Optimistic locking: a quantity computed before a sale on Mercado Libre cannot overwrite it.
			$result = null;
			for ($try = 0; $try < 2; $try++) {
				$read = $api->get('/user-products/'.rawurlencode($link['user_product_id']).'/stock');
				$version = (string) ($read['headers']['x-version'] ?? '');
				if (!$read['ok'] || $version === '') {
					$result = $read;
					break;
				}
				$result = $api->put('/user-products/'.rawurlencode($link['user_product_id']).'/stock/type/selling_address', array('quantity' => $quantity), array('x-version' => $version));
				if ($result['status'] !== 409) {
					break;
				}
			}
		} elseif ($link['variation_id'] > 0) {
			$result = $api->put('/items/'.rawurlencode($link['item_id']), array('variations' => array(array('id' => $link['variation_id'], 'available_quantity' => $quantity))));
		} else {
			$result = $api->put('/items/'.rawurlencode($link['item_id']), array('available_quantity' => $quantity));
		}

		$table = $this->db->prefix().'meli_link';
		if ($result === null || !$result['ok']) {
			$message = ($result === null ? 'no answer' : $result['message']);
			$this->db->query("UPDATE ".$table." SET last_error = '".$this->db->escape(dol_trunc($message, 250, 'right', 'UTF-8', 1))."' WHERE rowid = ".((int) $link['id']));
			return 'failed';
		}
		$warning = empty($result['warnings']) ? 'NULL' : "'".$this->db->escape(dol_trunc((string) json_encode($result['warnings']), 250, 'right', 'UTF-8', 1))."'";
		$sql = "UPDATE ".$table." SET last_pushed_qty = ".((int) $quantity).", last_push_at = '".$this->db->idate(dol_now())."', last_error = ".$warning;
		$sql .= ($version !== '' ? ", last_x_version = '".$this->db->escape($version)."'" : '')." WHERE rowid = ".((int) $link['id']);
		$this->db->query($sql);

		return 'pushed';
	}

	/**
	 * Warehouses whose stock is offered on Mercado Libre.
	 *
	 * @return	array<int>
	 */
	public static function warehouses()
	{
		$ids = array();
		foreach (explode(',', getDolGlobalString('MELI_WAREHOUSE_IDS')) as $part) {
			if ((int) $part > 0) {
				$ids[] = (int) $part;
			}
		}

		return $ids;
	}

	/**
	 * Content of an SQL IN ().
	 *
	 * @param	array<int|string>	$ids	Ids
	 * @return	string						'0' when empty
	 */
	private static function idList(array $ids)
	{
		$clean = array();
		foreach ($ids as $id) {
			if ((int) $id > 0) {
				$clean[(int) $id] = (int) $id;
			}
		}

		return empty($clean) ? '0' : implode(',', $clean);
	}

	/**
	 * Rows of a SELECT, columns as strings.
	 *
	 * @param	string	$sql	SELECT
	 * @return	array<int, array<string, string>>
	 */
	private function rows($sql)
	{
		$rows = array();
		$resql = $this->db->query($sql);
		while ($resql && $resql !== true && is_array($raw = $this->db->fetch_array($resql))) {
			$row = array();
			foreach ($raw as $column => $value) {
				if (is_string($column)) {
					$row[$column] = is_scalar($value) ? (string) $value : '';
				}
			}
			$rows[] = $row;
		}

		return $rows;
	}
}
