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
 * \file    test/phpunit/bootstrap.inc.php
 * \ingroup meli
 * \brief   Loads Dolibarr for the tests and gives them fixtures they own.
 */

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */
global $conf, $user, $db;

$doliSrc = getenv('DOLI_SRC');
if (empty($doliSrc) || !is_file($doliSrc.'/htdocs/master.inc.php')) {
	fwrite(STDERR, "DOLI_SRC must point to the root of a configured Dolibarr source tree (containing htdocs/master.inc.php).\n");
	exit(1);
}

require_once $doliSrc.'/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once dirname(__DIR__, 2).'/class/meliinbox.class.php';
require_once dirname(__DIR__, 2).'/class/melicron.class.php';
require_once dirname(__DIR__, 2).'/core/modules/modMeli.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Settings the tests change; each test case puts them back as found.
 */
const MELI_TEST_CONSTANTS = array(
	'MELI_API_URL', 'MELI_AUTH_URL', 'MELI_APP_ID', 'MELI_APP_SECRET', 'MELI_REDIRECT_URL', 'MELI_WAREHOUSE_IDS', 'MELI_BUFFER', 'MELI_MAX_QTY',
	'MELI_PUSH_STOCK', 'MELI_INVOICE_ORDERS', 'MELI_USER_ID', 'MELI_BANK_ACCOUNT', 'MELI_PAYMENT_MODE', 'MELI_SHIPPING_PRODUCT_ID', 'MELI_WEBHOOK_IPS',
	'MELI_POLL_LAST', 'STOCK_CALCULATE_ON_VALIDATE_ORDER', 'STOCK_CALCULATE_ON_SHIPMENT', 'STOCK_CALCULATE_ON_SHIPMENT_CLOSE', 'STOCK_SUPPORTS_SERVICES',
	'STOCK_MUST_BE_ENOUGH_FOR_ORDER', 'TIENDA_WAREHOUSE_IDS', 'TIENDA_OUT_OF_STOCK',
);

/**
 * Value of a setting in memory, null when unset.
 *
 * @param	string	$name	Setting
 * @return	string|null
 */
function meli_test_get_global($name)
{
	/** @var Conf $conf */
	global $conf;

	return isset($conf->global->$name) ? getDolGlobalString($name) : null;
}

/**
 * Set a setting in memory only, or unset it with null.
 *
 * @param	string		$name	Setting
 * @param	string|null	$value	Value
 * @return	void
 */
function meli_test_set_global($name, $value)
{
	/** @var Conf $conf */
	global $conf;

	if ($value === null) {
		unset($conf->global->$name);
	} else {
		$conf->global->$name = $value;
	}
}

/**
 * A scalar as a string.
 *
 * @param	mixed	$value	Value
 * @return	string
 */
function meli_test_str($value)
{
	return is_scalar($value) ? (string) $value : '';
}

/**
 * Rows of a SELECT.
 *
 * @param	string	$sql	SELECT
 * @return	list<array<string, string>>
 */
function meli_test_rows($sql)
{
	/** @var DoliDB $db */
	global $db;

	$rows = array();
	$resql = $db->query($sql);
	while ($resql && $resql !== true && is_array($raw = $db->fetch_array($resql))) {
		$row = array();
		foreach ($raw as $column => $value) {
			if (is_string($column)) {
				$row[$column] = meli_test_str($value);
			}
		}
		$rows[] = $row;
	}

	return $rows;
}

/**
 * Records created by a test, and their removal.
 *
 * Every record carries a marker unique to the run (a ref or name prefix, or a fake item id), and is
 * deleted only when its id was recorded here AND the record read back still carries the marker: the
 * tests run on shared instances, where a bare id could designate somebody else's record.
 */
class MeliFixtures
{
	/**
	 * @var string	Marker of this run
	 */
	public $tag;

	/**
	 * @var array<string, list<int>>	Ids created, by kind
	 */
	private $ids = array('product' => array(), 'warehouse' => array(), 'thirdparty' => array(), 'order' => array(), 'invoice' => array());

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->tag = 'MELITEST'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
	}

	/**
	 * A product of this run.
	 *
	 * @param	array<string, mixed>	$fields	Fields to set: type (0 product, 1 service), stockable (bool), price, tva_tx
	 * @return	Product
	 */
	public function product(array $fields = array())
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		if ((is_numeric($fields['type'] ?? null) ? (int) $fields['type'] : 0) === 1 && !isModEnabled('service')) {
			// Core would refuse to delete it afterwards.
			throw new LogicException('services need the service module');
		}
		$product = new Product($db);
		$product->ref = $this->tag.'-P'.(count($this->ids['product']) + 1);
		$product->label = 'Test meli '.$product->ref;
		$product->type = (is_numeric($fields['type'] ?? null) ? (int) $fields['type'] : 0);
		$product->status = 1;
		$product->status_buy = 1;
		$product->price = is_numeric($fields['price'] ?? null) ? (float) $fields['price'] : 1000.0;
		$product->price_base_type = 'TTC';
		$product->tva_tx = is_numeric($fields['tva_tx'] ?? null) ? (float) $fields['tva_tx'] : 21.0;
		$product->stockable_product = (($fields['stockable'] ?? true) ? 1 : 0);
		if ($product->create($user) <= 0) {
			throw new RuntimeException('product: '.$product->error.' '.implode(' ', $product->errors));
		}
		$this->ids['product'][] = (int) $product->id;

		return $product;
	}

	/**
	 * A warehouse of this run.
	 *
	 * @return	int		Id
	 */
	public function warehouse()
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$warehouse = new Entrepot($db);
		$warehouse->label = $this->tag.'-W'.(count($this->ids['warehouse']) + 1);
		$warehouse->statut = 1;
		if ($warehouse->create($user) <= 0) {
			throw new RuntimeException('warehouse: '.$warehouse->error);
		}
		$this->ids['warehouse'][] = (int) $warehouse->id;

		return (int) $warehouse->id;
	}

	/**
	 * Add stock of a product of this run to a warehouse of this run.
	 *
	 * @param	Product	$product	Product
	 * @param	int		$warehouse	Warehouse id
	 * @param	float	$qty		Quantity (negative removes)
	 * @return	void
	 */
	public function stock(Product $product, $warehouse, $qty)
	{
		/** @var User $user */
		global $user;

		$this->assertOwned('product', (int) $product->id);
		$this->assertOwned('warehouse', $warehouse);
		if ($product->correct_stock($user, $warehouse, abs($qty), $qty < 0 ? 1 : 0, 'meli test') <= 0) {
			throw new RuntimeException('stock: '.$product->error);
		}
	}

	/**
	 * A customer of this run.
	 *
	 * @return	int		Id
	 */
	public function thirdparty()
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->name = $this->tag.' customer';
		$thirdparty->client = 1;
		$thirdparty->code_client = 'auto';
		$id = $thirdparty->create($user);
		if ($id <= 0) {
			throw new RuntimeException('thirdparty: '.$thirdparty->error);
		}
		$this->ids['thirdparty'][] = (int) $thirdparty->id;

		return (int) $thirdparty->id;
	}

	/**
	 * An order of this run, validated without moving stock.
	 *
	 * @param	array<int, float|int>	$lines		Product id => quantity
	 * @param	bool				$validate	Validate it
	 * @return	Commande
	 */
	public function order(array $lines, $validate = true)
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$order = new Commande($db);
		$order->socid = $this->thirdparty();
		$order->date = dol_now();
		$order->ref_client = $this->tag;
		if ($order->create($user) <= 0) {
			throw new RuntimeException('order: '.$order->error);
		}
		$this->ids['order'][] = (int) $order->id;
		foreach ($lines as $product => $qty) {
			$this->assertOwned('product', $product);
			if ($order->addline('', 100, $qty, 0, 0, 0, $product) <= 0) {
				throw new RuntimeException('order line: '.$order->error);
			}
		}
		if ($validate) {
			$saved = meli_test_get_global('STOCK_CALCULATE_ON_VALIDATE_ORDER');
			meli_test_set_global('STOCK_CALCULATE_ON_VALIDATE_ORDER', '0');
			$order->fetch((int) $order->id);
			$result = $order->valid($user);
			meli_test_set_global('STOCK_CALCULATE_ON_VALIDATE_ORDER', $saved);
			if ($result <= 0) {
				throw new RuntimeException('order validation: '.$order->error);
			}
		}

		return $order;
	}

	/**
	 * Record a record the module created for this run (a third party, an order, an invoice).
	 *
	 * @param	string	$kind	thirdparty, order or invoice
	 * @param	int		$id		Id
	 * @return	void
	 */
	public function adopt($kind, $id)
	{
		if ($id > 0 && !in_array($id, $this->ids[$kind], true)) {
			$this->ids[$kind][] = $id;
		}
	}

	/**
	 * Ids created, by kind.
	 *
	 * @param	string	$kind	Kind
	 * @return	list<int>
	 */
	public function ids($kind)
	{
		return $this->ids[$kind];
	}

	/**
	 * Remove what this run created, newest first, each record re-checked by its marker.
	 *
	 * @return	void
	 * @throws	RuntimeException	When a record of this run could not be deleted
	 */
	public function cleanup()
	{
		/**
		 * @var DoliDB $db
		 * @var User $user
		 */
		global $db, $user;

		$failed = array();
		$prefix = $db->prefix();
		$tag = $db->escape($this->tag);
		$products = $this->ids['product'];
		$productList = empty($products) ? '0' : implode(',', $products);

		// The module's own rows about this run's products or orders.
		$db->query("DELETE FROM ".$prefix."meli_outbox WHERE fk_product IN (".$productList.")");
		$db->query("DELETE FROM ".$prefix."meli_link WHERE fk_product IN (".$productList.") OR item_id LIKE '".$tag."%' OR seller_sku LIKE '".$tag."%'");
		$orderList = empty($this->ids['order']) ? '0' : implode(',', $this->ids['order']);
		$db->query("DELETE FROM ".$prefix."meli_order WHERE fk_commande IN (".$orderList.")");

		foreach (array_reverse($this->ids['invoice']) as $id) {
			$invoice = new Facture($db);
			if ($invoice->fetch($id) > 0 && in_array((int) $invoice->socid, $this->ids['thirdparty'], true)) {
				// Payments first: a paid invoice cannot be deleted.
				foreach (meli_test_rows("SELECT pf.fk_paiement FROM ".$prefix."paiement_facture as pf WHERE pf.fk_facture = ".((int) $id)) as $row) {
					$payment = new Paiement($db);
					if ($payment->fetch((int) $row['fk_paiement']) > 0) {
						$payment->delete($user);
					}
				}
				if ($invoice->delete($user) <= 0) {
					$failed[] = 'invoice '.$id;
				}
			}
		}
		foreach (array_reverse($this->ids['order']) as $id) {
			$order = new Commande($db);
			if ($order->fetch($id) > 0 && in_array((int) $order->socid, $this->ids['thirdparty'], true)) {
				if ($order->delete($user) <= 0) {
					$failed[] = 'order '.$id;
				}
			}
		}
		foreach (array_reverse($this->ids['thirdparty']) as $id) {
			$thirdparty = new Societe($db);
			if ($thirdparty->fetch($id) > 0 && strpos((string) $thirdparty->name, $this->tag) === 0) {
				$db->query("DELETE FROM ".$prefix."socpeople WHERE fk_soc = ".((int) $id));
				if ($thirdparty->delete($id, $user) <= 0) {
					$failed[] = 'thirdparty '.$id;
				}
			}
		}
		foreach (array_reverse($products) as $id) {
			$product = new Product($db);
			if ($product->fetch($id) > 0 && strpos((string) $product->ref, $this->tag) === 0) {
				// Stock rows of a product of this run only.
				$db->query("DELETE FROM ".$prefix."stock_mouvement WHERE fk_product = ".((int) $id));
				$db->query("DELETE FROM ".$prefix."product_stock WHERE fk_product = ".((int) $id));
				if ($product->delete($user) <= 0) {
					$failed[] = 'product '.$id;
				}
			}
		}
		foreach (array_reverse($this->ids['warehouse']) as $id) {
			$warehouse = new Entrepot($db);
			if ($warehouse->fetch($id) > 0 && strpos((string) $warehouse->ref, $this->tag) === 0) {
				if ($warehouse->delete($user) <= 0) {
					$failed[] = 'warehouse '.$id;
				}
			}
		}
		$this->ids = array('product' => array(), 'warehouse' => array(), 'thirdparty' => array(), 'order' => array(), 'invoice' => array());
		if (!empty($failed)) {
			// Loud on purpose: a leftover must be seen and removed by hand, not pile up.
			throw new RuntimeException('test records left behind ('.$this->tag.'): '.implode(', ', $failed));
		}
	}

	/**
	 * Refuse to touch a record this run did not create.
	 *
	 * @param	string	$kind	Kind
	 * @param	int		$id		Id
	 * @return	void
	 */
	private function assertOwned($kind, $id)
	{
		if (!in_array($id, $this->ids[$kind], true)) {
			throw new LogicException($kind.' '.$id.' does not belong to this test run');
		}
	}
}

/**
 * Base of the module's tests: settings put back as found, fixtures removed.
 */
abstract class MeliTestCase extends PHPUnit\Framework\TestCase
{
	/**
	 * @var MeliFixtures
	 */
	protected $fixtures;

	/**
	 * @var array<string, string|null>
	 */
	private $savedGlobals = array();

	/**
	 * Enable the module once and keep the settings.
	 *
	 * @return	void
	 */
	protected function setUp(): void
	{
		parent::setUp();
		if (!isModEnabled('meli')) {
			$result = activateModule('modMeli');
			if (!empty($result['errors'])) {
				$this->fail('module activation: '.implode(' ', $result['errors']));
			}
		}
		foreach (MELI_TEST_CONSTANTS as $name) {
			$this->savedGlobals[$name] = meli_test_get_global($name);
		}
		$this->fixtures = new MeliFixtures();
	}

	/**
	 * Remove the fixtures and restore the settings.
	 *
	 * @return	void
	 */
	protected function tearDown(): void
	{
		$this->fixtures->cleanup();
		foreach ($this->savedGlobals as $name => $value) {
			meli_test_set_global($name, $value);
		}
		parent::tearDown();
	}
}
