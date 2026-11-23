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
 * \file    class/meliorders.class.php
 * \ingroup meli
 * \brief   Mercado Libre sales imported as Dolibarr orders, then invoiced and paid.
 */

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/melistock.class.php';

/**
 * Sales
 *
 * One Dolibarr order per pack (a cart purchase on Mercado Libre is a pack of one order per item).
 * The order is validated at import, which takes the units out of what the other channels can sell,
 * in a transaction that first locks the product rows: the online shop's checkout takes the same
 * locks, so the two never both count on the last unit. Mercado Libre's buyer has already paid, so
 * an order whose units another channel took first is imported anyway and flagged as oversold.
 */
class MeliOrders
{
	const IMPORTED = 'imported';
	const UPDATED = 'updated';
	const WAITING = 'waiting';
	const IGNORED = 'ignored';
	const FAILED = 'failed';

	/**
	 * Conditions frente al IVA by the label Mercado Libre gives, to the ids ARCA uses (module l10nar)
	 */
	const TAXPAYER_TYPES = array(
		'iva responsable inscripto' => 1, 'responsable inscripto' => 1, 'iva sujeto exento' => 4, 'exento' => 4,
		'consumidor final' => 5, 'monotributo' => 6, 'responsable monotributo' => 6, 'monotributista social' => 13,
	);

	/**
	 * @var string	Detail of the last failure
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
	 * @param DoliDB		$db		Database handler
	 * @param MeliApi|null	$api	API client
	 */
	public function __construct(DoliDB $db, $api = null)
	{
		$this->db = $db;
		$this->api = ($api instanceof MeliApi ? $api : new MeliApi($db));
	}

	/**
	 * Import, or bring up to date, the sale an order of Mercado Libre belongs to.
	 *
	 * @param	string	$orderId	Id of an order at Mercado Libre
	 * @return	string				One of the constants
	 */
	public function sync($orderId)
	{
		$this->error = '';
		$first = $this->api->get('/orders/'.rawurlencode($orderId));
		if (!$first['ok']) {
			return $this->fail($first['message']);
		}
		$packId = self::str($first['data']['pack_id'] ?? '');
		$orders = array($first['data']);
		if ($packId !== '') {
			$pack = $this->api->get('/packs/'.rawurlencode($packId));
			if (!$pack['ok']) {
				return $this->fail($pack['message']);
			}
			$orders = array();
			foreach (self::arr($pack['data']['orders'] ?? array()) as $entry) {
				$id = self::str(is_array($entry) ? ($entry['id'] ?? '') : $entry);
				$read = ($id === self::str($first['data']['id'] ?? '')) ? $first : $this->api->get('/orders/'.rawurlencode($id));
				if (!$read['ok']) {
					return $this->fail($read['message']);
				}
				$orders[] = $read['data'];
			}
		} else {
			// Without a pack, the order's own id identifies the sale, as Mercado Libre's own URLs do.
			$packId = self::str($first['data']['id'] ?? '');
		}

		$statuses = array();
		foreach ($orders as $order) {
			$statuses[] = self::str($order['status'] ?? '');
		}
		$existing = $this->fetchRow($packId);
		$cancelled = (in_array('cancelled', $statuses, true) || in_array('pending_cancel', $statuses, true));
		if ($existing !== null) {
			return $this->update($existing, $orders, $cancelled);
		}
		if ($cancelled) {
			return self::IGNORED;
		}
		// Only paid sales: an unpaid one may never be paid, and it would hold stock.
		if (count(array_unique($statuses)) !== 1 || $statuses[0] !== 'paid') {
			return self::WAITING;
		}

		return $this->import($packId, $orders);
	}

	/**
	 * Create the Dolibarr order of a paid sale, then its invoice.
	 *
	 * @param	string				$packId	Id of the sale
	 * @param	array<int, array<mixed>>	$orders	Orders of the sale as Mercado Libre returns them
	 * @return	string				One of the constants
	 */
	private function import($packId, array $orders)
	{
		/** @var User $user */
		global $user;

		$actor = $this->actor();
		if ($actor === null) {
			return $this->fail('No technical user configured (MELI_USER_ID) or it cannot create orders');
		}
		$warehouses = MeliStock::warehouses();
		$warehouse = empty($warehouses) ? 0 : $warehouses[0];

		// Everything remote first: nothing is called inside the transaction.
		$buyer = $this->buyer($orders[0]);
		$shipment = $this->shipment($orders[0]);
		if ($buyer === null) {
			return self::FAILED;
		}

		$lines = array();
		$fee = 0.0;
		$paid = 0.0;
		foreach ($orders as $order) {
			$paid += self::num($order['paid_amount'] ?? ($order['total_amount'] ?? 0));
			foreach (self::arr($order['order_items'] ?? array()) as $item) {
				if (!is_array($item)) {
					continue;
				}
				$listing = self::arr($item['item'] ?? array());
				$lines[] = array(
					'item_id' => self::str($listing['id'] ?? ''), 'variation_id' => (int) self::num($listing['variation_id'] ?? 0),
					'sku' => trim(self::str($listing['seller_sku'] ?? '')), 'title' => self::str($listing['title'] ?? ''),
					'qty' => self::num($item['quantity'] ?? 0), 'price' => self::num($item['unit_price'] ?? 0),
				);
				$fee += self::num($item['sale_fee'] ?? 0) * self::num($item['quantity'] ?? 1);
			}
		}
		$products = $this->products($lines);

		$saved = $user;
		$user = $actor;
		$this->db->begin();
		$orderId = 0;
		$oversold = false;
		$review = false;
		try {
			$ids = array_filter(array_values($products));
			if (!empty($ids)) {
				// The same locks as the shop's checkout: whoever holds them first owns the last unit.
				$this->db->query("SELECT p.rowid FROM ".$this->db->prefix()."product as p WHERE p.rowid IN (".implode(',', array_map('intval', $ids)).") ORDER BY p.rowid FOR UPDATE");
				$sellable = (new MeliStock($this->db))->sellable(array_map('intval', $ids));
				foreach ($lines as $index => $line) {
					$product = $products[$index];
					if ($product > 0 && array_key_exists($product, $sellable) && $sellable[$product] !== null && $sellable[$product] < $line['qty']) {
						$oversold = true;
					}
				}
			}

			$thirdparty = $this->thirdParty($buyer, $actor);
			if ($thirdparty <= 0) {
				throw new RuntimeException('Third party: '.$this->error);
			}
			$order = new Commande($this->db);
			$order->socid = $thirdparty;
			$order->date = dol_now();
			$order->ref_ext = 'meli:'.$packId;
			$order->ref_client = $packId;
			$order->module_source = 'meli';
			$order->demand_reason_id = (int) dol_getIdFromCode($this->db, 'SRC_INTE', 'c_input_reason', 'code', 'rowid');
			if ($warehouse > 0) {
				$order->warehouse_id = $warehouse;
			}
			$order->note_private = 'Mercado Libre: venta '.$packId;
			$orderId = $order->create($actor);
			if ($orderId <= 0) {
				throw new RuntimeException('Order: '.$order->error);
			}
			foreach ($lines as $index => $line) {
				$product = $products[$index];
				$tva = ($product > 0 ? $this->vatOf($product) : 0.0);
				$ht = $line['price'] / (1 + $tva / 100);
				$label = ($product > 0 ? '' : 'Mercado Libre '.$line['item_id'].' '.$line['title'].' (SKU '.$line['sku'].')');
				$result = $order->addline($label, $ht, $line['qty'], $tva, 0, 0, $product, 0, 0, 0, 'TTC', $line['price']);
				if ($result === Commande::STOCK_NOT_ENOUGH_FOR_ORDER) {
					// Core refuses lines without stock when told to: the sale is kept, in draft, for a person.
					$oversold = true;
					$review = true;
					$result = $order->addline($label.' [sin stock]', $ht, $line['qty'], $tva, 0, 0, 0, 0, 0, 0, 'TTC', $line['price']);
				}
				if ($result <= 0) {
					throw new RuntimeException('Line '.$line['item_id'].': '.$order->error);
				}
				$review = ($review || $product <= 0);
			}
			$shippingCost = $shipment['receiver_cost'];
			if ($shippingCost > 0) {
				$service = getDolGlobalInt('MELI_SHIPPING_PRODUCT_ID');
				$tva = ($service > 0 ? $this->vatOf($service) : 0.0);
				if ($order->addline('Envío Mercado Libre', $shippingCost / (1 + $tva / 100), 1, $tva, 0, 0, $service, 0, 0, 0, 'TTC', $shippingCost) <= 0) {
					throw new RuntimeException('Shipping line: '.$order->error);
				}
			}
			if ($shipment['address'] !== null) {
				$contact = $this->deliveryContact($shipment['address'], $thirdparty, $actor);
				if ($contact > 0) {
					$order->add_contact($contact, 'SHIPPING', 'external');
				}
			}
			if (!$review) {
				if ($order->fetch($orderId) <= 0 || $order->valid($actor, $warehouse) <= 0) {
					throw new RuntimeException('Validation: '.$order->error);
				}
			}
			$this->saveRow($packId, $orders, $orderId, $buyer['id'], $shipment, $paid, $fee, $oversold, $review);
			$this->db->commit();
		} catch (RuntimeException $e) {
			$this->db->rollback();
			$user = $saved;
			return $this->fail($e->getMessage());
		}
		$user = $saved;

		if ($oversold) {
			dol_syslog(__METHOD__.' sale '.$packId.' imported as oversold', LOG_WARNING);
		}
		(new MeliStock($this->db))->enqueue(array_values(array_filter($products)), 'meli_sale');
		if (!$review && getDolGlobalInt('MELI_INVOICE_ORDERS', 1)) {
			$this->invoice($packId);
		}

		return self::IMPORTED;
	}

	/**
	 * Invoice an imported sale and record what the buyer paid. Separate from the import: a failure
	 * here leaves the order, and the next run tries again.
	 *
	 * @param	string	$packId	Id of the sale
	 * @return	bool
	 */
	public function invoice($packId)
	{
		/** @var User $user */
		global $user;

		$row = $this->fetchRow($packId);
		if ($row === null || (int) $row['fk_commande'] <= 0 || (int) $row['fk_facture'] > 0 || (int) $row['needs_review'] === 1) {
			return ($row !== null && (int) $row['fk_facture'] > 0);
		}
		$actor = $this->actor();
		$bank = getDolGlobalInt('MELI_BANK_ACCOUNT');
		if ($actor === null || $bank <= 0) {
			$this->error = 'No technical user or no bank account for Mercado Libre payments';
			$this->note($packId, $this->error);
			return false;
		}

		$saved = $user;
		$user = $actor;
		$this->db->begin();
		try {
			$order = new Commande($this->db);
			if ($order->fetch((int) $row['fk_commande']) <= 0) {
				throw new RuntimeException('Order not found');
			}
			$invoice = new Facture($this->db);
			// createFromOrder() returns >0 on success, not the id: the id is $invoice->id.
			if ($invoice->createFromOrder($order, $actor) <= 0 || (int) $invoice->id <= 0) {
				throw new RuntimeException('Invoice: '.$invoice->error);
			}
			if ($invoice->validate($actor) <= 0) {
				throw new RuntimeException('Invoice validation: '.$invoice->error);
			}
			$invoice->fetch((int) $invoice->id);
			$payment = new Paiement($this->db);
			$payment->datepaye = dol_now();
			$payment->amounts = array((int) $invoice->id => (float) $invoice->total_ttc);
			$payment->paiementid = self::paymentModeId($this->db);
			$payment->num_payment = $packId;
			$payment->ext_payment_id = $packId;
			$payment->ext_payment_site = 'MercadoLibre';
			$payment->note_private = 'Mercado Libre: venta '.$packId;
			if ($payment->create($actor, 1) <= 0) {
				throw new RuntimeException('Payment: '.$payment->error);
			}
			if ($payment->addPaymentToBank($actor, 'payment', '(CustomerInvoicePayment)', $bank, '', '') <= 0) {
				throw new RuntimeException('Bank line: '.$payment->error);
			}
			$order->classifyBilled($actor);
			$this->db->query("UPDATE ".$this->db->prefix()."meli_order SET fk_facture = ".((int) $invoice->id).", note = NULL WHERE rowid = ".((int) $row['rowid']));
			$this->db->commit();
		} catch (RuntimeException $e) {
			$this->db->rollback();
			$user = $saved;
			$this->error = $e->getMessage();
			$this->note($packId, $this->error);
			return false;
		}
		$user = $saved;

		return true;
	}

	/**
	 * Id of the payment mode "Mercado Libre", created when the module is enabled.
	 *
	 * @param	DoliDB	$db	Database handler
	 * @return	int			0 if it is missing
	 */
	public static function paymentModeId(DoliDB $db)
	{
		$configured = getDolGlobalInt('MELI_PAYMENT_MODE');
		if ($configured > 0) {
			return $configured;
		}

		return max(0, (int) dol_getIdFromCode($db, 'MELI', 'c_paiement', 'code', 'id', 1));
	}

	/**
	 * Bring an imported sale up to date: cancellation, shipment.
	 *
	 * @param	array<string, string>		$row		Row of the sale
	 * @param	array<int, array<mixed>>	$orders		Orders as Mercado Libre returns them now
	 * @param	bool						$cancelled	Whether Mercado Libre cancelled the sale
	 * @return	string								One of the constants
	 */
	private function update(array $row, array $orders, $cancelled)
	{
		$status = $cancelled ? 'cancelled' : self::str($orders[0]['status'] ?? '');
		$this->db->query("UPDATE ".$this->db->prefix()."meli_order SET ml_status = '".$this->db->escape($status)."' WHERE rowid = ".((int) $row['rowid']));
		if (!$cancelled || $row['ml_status'] === 'cancelled') {
			return self::UPDATED;
		}

		// Cancelled after import: stock goes back only through a cancellation of the order.
		$order = new Commande($this->db);
		if ((int) $row['fk_facture'] > 0 || $order->fetch((int) $row['fk_commande']) <= 0 || (int) $order->statut !== Commande::STATUS_VALIDATED) {
			// Invoiced, or already beyond validation: a credit note or a return is a person's decision.
			$this->db->query("UPDATE ".$this->db->prefix()."meli_order SET needs_review = 1, note = 'Cancelada en Mercado Libre: revisar (nota de crédito / devolución)' WHERE rowid = ".((int) $row['rowid']));
			return self::UPDATED;
		}
		$actor = $this->actor();
		$warehouses = MeliStock::warehouses();
		if ($actor !== null) {
			/** @var User $user */
			global $user;
			$saved = $user;
			$user = $actor;
			$arguments = version_compare(DOL_VERSION, '23.0', '<') ? array(empty($warehouses) ? -1 : $warehouses[0]) : array($actor, empty($warehouses) ? -1 : $warehouses[0]);
			$result = call_user_func_array(array($order, 'cancel'), $arguments);
			$user = $saved;
			if (is_numeric($result) && (int) $result > 0) {
				$products = array();
				foreach ($order->lines as $line) {
					$products[] = (int) $line->fk_product;
				}
				(new MeliStock($this->db))->enqueue($products, 'meli_cancel');
				return self::UPDATED;
			}
		}
		$this->db->query("UPDATE ".$this->db->prefix()."meli_order SET needs_review = 1, note = 'Cancelada en Mercado Libre: no se pudo cancelar el pedido' WHERE rowid = ".((int) $row['rowid']));

		return self::UPDATED;
	}

	/**
	 * Buyer of a sale, from its billing information.
	 *
	 * @param	array<mixed>	$order	First order of the sale
	 * @return	array{id: int, name: string, doc_type: string, doc_number: string, condition: int, address: string, zip: string, town: string}|null
	 */
	private function buyer(array $order)
	{
		$buyer = self::arr($order['buyer'] ?? array());
		$result = array('id' => (int) self::num($buyer['id'] ?? 0), 'name' => trim(self::str($buyer['nickname'] ?? '')), 'doc_type' => '', 'doc_number' => '', 'condition' => 0, 'address' => '', 'zip' => '', 'town' => '');
		$billingId = self::str(self::arr($buyer['billing_info'] ?? array())['id'] ?? '');
		if ($billingId === '') {
			return $result;
		}
		$billing = $this->api->get('/orders/billing-info/'.MeliConfig::SITE.'/'.rawurlencode($billingId));
		if (!$billing['ok']) {
			$this->fail($billing['message']);
			return null;
		}
		$data = self::arr($billing['data']['buyer'] ?? $billing['data']);
		$data = self::arr($data['billing_info'] ?? $data);
		$name = trim(self::str($data['name'] ?? '').' '.self::str($data['last_name'] ?? ''));
		if ($name !== '') {
			$result['name'] = $name;
		}
		$identification = self::arr($data['identification'] ?? array());
		$result['doc_type'] = strtoupper(self::str($identification['type'] ?? ''));
		$result['doc_number'] = (string) preg_replace('/\D+/', '', self::str($identification['number'] ?? ''));
		$taxpayer = strtolower(trim(self::str(self::arr(self::arr($data['taxes'] ?? array())['taxpayer_type'] ?? array())['description'] ?? '')));
		$result['condition'] = self::TAXPAYER_TYPES[$taxpayer] ?? 0;
		$address = self::arr($data['address'] ?? array());
		$result['address'] = trim(self::str($address['street_name'] ?? '').' '.self::str($address['street_number'] ?? ''));
		$result['zip'] = self::str($address['zip_code'] ?? '');
		$result['town'] = self::str($address['city_name'] ?? '');

		return $result;
	}

	/**
	 * What the sale's shipment says: what the buyer paid for it and where it goes.
	 *
	 * @param	array<mixed>	$order	First order of the sale
	 * @return	array{id: string, status: string, substatus: string, logistic: string, receiver_cost: float, sender_cost: float, address: array<string, string>|null}
	 */
	private function shipment(array $order)
	{
		$result = array('id' => '', 'status' => '', 'substatus' => '', 'logistic' => '', 'receiver_cost' => 0.0, 'sender_cost' => 0.0, 'address' => null);
		$id = self::str(self::arr($order['shipping'] ?? array())['id'] ?? '');
		if ($id === '') {
			return $result;
		}
		$result['id'] = $id;
		$read = $this->api->get('/shipments/'.rawurlencode($id), array('x-format-new' => 'true'));
		if ($read['ok']) {
			$result['status'] = self::str($read['data']['status'] ?? '');
			$result['substatus'] = self::str($read['data']['substatus'] ?? '');
			$result['logistic'] = self::str(self::arr($read['data']['logistic'] ?? array())['type'] ?? ($read['data']['logistic_type'] ?? ''));
			$destination = self::arr(self::arr($read['data']['destination'] ?? array())['shipping_address'] ?? ($read['data']['receiver_address'] ?? array()));
			if (!empty($destination)) {
				$result['address'] = array(
					'name' => self::str(self::arr($read['data']['destination'] ?? array())['receiver_name'] ?? ($destination['receiver_name'] ?? '')),
					'street' => trim(self::str($destination['address_line'] ?? (self::str($destination['street_name'] ?? '').' '.self::str($destination['street_number'] ?? '')))),
					'zip' => self::str($destination['zip_code'] ?? ''),
					'town' => self::str(self::arr($destination['city'] ?? array())['name'] ?? ($destination['city_name'] ?? '')),
				);
			}
		}
		$costs = $this->api->get('/shipments/'.rawurlencode($id).'/costs', array('x-format-new' => 'true'));
		if ($costs['ok']) {
			$result['receiver_cost'] = self::num(self::arr($costs['data']['receiver'] ?? array())['cost'] ?? 0);
			foreach (self::arr($costs['data']['senders'] ?? array()) as $sender) {
				$result['sender_cost'] += self::num(is_array($sender) ? ($sender['cost'] ?? 0) : 0);
			}
		}

		return $result;
	}

	/**
	 * Find or create the buyer's third party: by document number, else by Mercado Libre buyer id.
	 *
	 * @param	array{id: int, name: string, doc_type: string, doc_number: string, condition: int, address: string, zip: string, town: string}	$buyer	Buyer
	 * @param	User	$actor	User the records are created with
	 * @return	int				Third party id, <=0 on error
	 */
	private function thirdParty(array $buyer, User $actor)
	{
		$prefix = $this->db->prefix();
		$where = array();
		if ($buyer['doc_number'] !== '') {
			$where[] = "s.siren = '".$this->db->escape($buyer['doc_number'])."'";
		}
		if ($buyer['id'] > 0) {
			$where[] = "s.rowid IN (SELECT e.fk_object FROM ".$prefix."societe_extrafields as e WHERE e.meli_buyer_id = '".((int) $buyer['id'])."')";
		}
		if (!empty($where)) {
			$resql = $this->db->query("SELECT s.rowid FROM ".$prefix."societe as s WHERE s.entity IN (".getEntity('societe').") AND (".implode(' OR ', $where).") ORDER BY s.rowid", 1);
			$found = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;
			if (is_array($found) && is_numeric($found['rowid'])) {
				return (int) $found['rowid'];
			}
		}

		$thirdparty = new Societe($this->db);
		$thirdparty->name = ($buyer['name'] !== '' ? $buyer['name'] : 'Comprador Mercado Libre '.$buyer['id']);
		$thirdparty->client = 1;
		$thirdparty->code_client = 'auto';
		$thirdparty->country_id = (int) dol_getIdFromCode($this->db, 'AR', 'c_country', 'code', 'rowid');
		$thirdparty->address = $buyer['address'];
		$thirdparty->zip = $buyer['zip'];
		$thirdparty->town = $buyer['town'];
		$thirdparty->idprof1 = $buyer['doc_number'];
		$thirdparty->array_options['options_meli_buyer_id'] = (string) $buyer['id'];
		if ($buyer['condition'] > 0) {
			$thirdparty->array_options['options_l10nar_iva_condition'] = (string) $buyer['condition'];
		}
		$id = $thirdparty->create($actor);
		if ($id <= 0) {
			$this->error = $thirdparty->error.' '.implode(' ', $thirdparty->errors);
		}

		return $id;
	}

	/**
	 * Contact holding the delivery address of the sale.
	 *
	 * @param	array<string, string>	$address	Address from the shipment
	 * @param	int						$thirdparty	Third party id
	 * @param	User					$actor		User the records are created with
	 * @return	int								Contact id, <=0 on error
	 */
	private function deliveryContact(array $address, $thirdparty, User $actor)
	{
		$contact = new Contact($this->db);
		$contact->socid = $thirdparty;
		$contact->lastname = ($address['name'] !== '' ? $address['name'] : 'Entrega Mercado Libre');
		$contact->address = $address['street'];
		$contact->zip = $address['zip'];
		$contact->town = $address['town'];
		$contact->country_id = (int) dol_getIdFromCode($this->db, 'AR', 'c_country', 'code', 'rowid');
		$contact->statut = 1;

		return $contact->create($actor);
	}

	/**
	 * Products of the lines of a sale: through the listing link, else the SKU as a ref.
	 *
	 * @param	array<int, array{item_id: string, variation_id: int, sku: string, title: string, qty: float, price: float}>	$lines	Lines
	 * @return	array<int, int>	Line index => product id, 0 when unknown
	 */
	private function products(array $lines)
	{
		/** @var Conf $conf */
		global $conf;

		$products = array();
		foreach ($lines as $index => $line) {
			$sql = "SELECT l.fk_product FROM ".$this->db->prefix()."meli_link as l WHERE l.entity = ".((int) $conf->entity)." AND l.item_id = '".$this->db->escape($line['item_id'])."'";
			$sql .= " AND l.variation_id = ".((int) $line['variation_id'])." AND l.fk_product > 0 ORDER BY l.active DESC";
			$resql = $this->db->query($sql);
			$row = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;
			$product = (is_array($row) && is_numeric($row['fk_product'])) ? (int) $row['fk_product'] : 0;
			if ($product === 0 && $line['sku'] !== '') {
				$resql = $this->db->query("SELECT p.rowid FROM ".$this->db->prefix()."product as p WHERE p.ref = '".$this->db->escape($line['sku'])."' AND p.entity IN (".getEntity('product').")");
				$row = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;
				$product = (is_array($row) && is_numeric($row['rowid'])) ? (int) $row['rowid'] : 0;
			}
			$products[$index] = $product;
		}

		return $products;
	}

	/**
	 * Default VAT rate of a product.
	 *
	 * @param	int	$productId	Product id
	 * @return	float
	 */
	private function vatOf($productId)
	{
		$resql = $this->db->query("SELECT p.tva_tx FROM ".$this->db->prefix()."product as p WHERE p.rowid = ".((int) $productId));
		$row = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;

		return (is_array($row) && is_numeric($row['tva_tx'])) ? (float) $row['tva_tx'] : 0.0;
	}

	/**
	 * Save the row of an imported sale.
	 *
	 * @param	string	$packId		Id of the sale
	 * @param	array<int, array<mixed>>	$orders	Orders
	 * @param	int		$orderId	Dolibarr order id
	 * @param	int		$buyerId	Mercado Libre buyer id
	 * @param	array{id: string, status: string, substatus: string, logistic: string, receiver_cost: float, sender_cost: float, address: array<string, string>|null}	$shipment	Shipment
	 * @param	float	$paid		Amount paid by the buyer
	 * @param	float	$fee		Mercado Libre's fee
	 * @param	bool	$oversold	Units taken by another sale first
	 * @param	bool	$review		A person must look at it
	 * @return	void
	 * @throws	RuntimeException	If it cannot be saved
	 */
	private function saveRow($packId, array $orders, $orderId, $buyerId, array $shipment, $paid, $fee, $oversold, $review)
	{
		/** @var Conf $conf */
		global $conf;

		$ids = array();
		foreach ($orders as $order) {
			$ids[] = self::str($order['id'] ?? '');
		}
		$sql = "INSERT INTO ".$this->db->prefix()."meli_order (entity, pack_id, order_ids, fk_commande, ml_status, buyer_id, shipment_id, shipment_status, shipment_substatus, logistic_type, total_paid, sale_fee, shipping_cost, oversold, needs_review, note, date_creation) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->escape($packId)."', '".$this->db->escape(dol_trunc(implode(',', $ids), 250, 'right', 'UTF-8', 1))."', ".((int) $orderId).", 'paid', ".((int) $buyerId);
		$sql .= ", ".($shipment['id'] === '' ? 'NULL' : "'".$this->db->escape($shipment['id'])."'").", '".$this->db->escape($shipment['status'])."', '".$this->db->escape($shipment['substatus'])."', '".$this->db->escape($shipment['logistic'])."'";
		$sql .= ", ".((float) $paid).", ".((float) $fee).", ".((float) $shipment['sender_cost']).", ".($oversold ? 1 : 0).", ".($review ? 1 : 0);
		$sql .= ", ".($oversold ? "'Vendido sin stock: otra venta tomó las unidades antes'" : ($review ? "'Revisar: producto desconocido o sin stock'" : 'NULL')).", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			throw new RuntimeException('Sale row: '.$this->db->lasterror());
		}
	}

	/**
	 * Row of a sale.
	 *
	 * @param	string	$packId	Id of the sale
	 * @return	array<string, string>|null
	 */
	public function fetchRow($packId)
	{
		/** @var Conf $conf */
		global $conf;

		$sql = "SELECT o.rowid, o.pack_id, o.fk_commande, o.fk_facture, o.ml_status, o.shipment_id, o.needs_review, o.oversold FROM ".$this->db->prefix()."meli_order as o";
		$sql .= " WHERE o.entity = ".((int) $conf->entity)." AND o.pack_id = '".$this->db->escape($packId)."'";
		$resql = $this->db->query($sql);
		$raw = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;
		if (!is_array($raw)) {
			return null;
		}
		$row = array();
		foreach ($raw as $column => $value) {
			if (is_string($column)) {
				$row[$column] = is_scalar($value) ? (string) $value : '';
			}
		}

		return $row;
	}

	/**
	 * Keep a note on a sale's row.
	 *
	 * @param	string	$packId		Id of the sale
	 * @param	string	$message	Note
	 * @return	void
	 */
	private function note($packId, $message)
	{
		/** @var Conf $conf */
		global $conf;

		$sql = "UPDATE ".$this->db->prefix()."meli_order SET note = '".$this->db->escape(dol_trunc($message, 250, 'right', 'UTF-8', 1))."'";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND pack_id = '".$this->db->escape($packId)."'";
		$this->db->query($sql);
	}

	/**
	 * User in whose name sales are recorded.
	 *
	 * @return	User|null
	 */
	private function actor()
	{
		$actor = new User($this->db);
		if (getDolGlobalInt('MELI_USER_ID') <= 0 || $actor->fetch(getDolGlobalInt('MELI_USER_ID')) <= 0 || (int) $actor->statut !== 1) {
			return null;
		}
		$actor->loadRights();

		return $actor->hasRight('commande', 'creer') ? $actor : null;
	}

	/**
	 * Record a failure.
	 *
	 * @param	string	$message	Detail
	 * @return	string				self::FAILED
	 */
	private function fail($message)
	{
		$this->error = $message;
		dol_syslog(get_class($this).' '.$message, LOG_WARNING);

		return self::FAILED;
	}

	/**
	 * An array, or an empty one.
	 *
	 * @param	mixed	$value	Value
	 * @return	array<mixed>
	 */
	private static function arr($value)
	{
		return is_array($value) ? $value : array();
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

	/**
	 * A number.
	 *
	 * @param	mixed	$value	Value
	 * @return	float
	 */
	private static function num($value)
	{
		return is_numeric($value) ? (float) $value : 0.0;
	}
}
