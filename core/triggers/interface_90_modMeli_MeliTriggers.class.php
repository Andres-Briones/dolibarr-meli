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
 * \file    core/triggers/interface_90_modMeli_MeliTriggers.class.php
 * \ingroup meli
 * \brief   Notes the products whose quantity on Mercado Libre must be sent again.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__.'/../../class/melistock.class.php';


/**
 * Triggers of the Meli module
 */
class InterfaceMeliTriggers extends DolibarrTriggers
{
	/**
	 * Events after which the sellable quantity of the products concerned may have changed
	 */
	const ORDER_EVENTS = array('ORDER_VALIDATE', 'ORDER_UNVALIDATE', 'ORDER_CANCEL', 'ORDER_CLOSE', 'ORDER_REOPEN', 'ORDER_DELETE', 'SHIPPING_VALIDATE', 'SHIPPING_CLOSED', 'SHIPPING_DELETE');

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'interface';
		$this->description = 'Notes the products whose quantity on Mercado Libre must be sent again.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'fa-store-alt';
	}

	/**
	 * Function called when a Dolibarr business event occurs.
	 *
	 * Nothing is sent from here: these events happen inside other transactions (a sale at the till, a
	 * checkout), which must neither wait for Mercado Libre nor fail because of it.
	 *
	 * @param	string			$action		Event action code
	 * @param	CommonObject	$object		Object the event is about
	 * @param	User			$user		User doing the action
	 * @param	Translate		$langs		Language object
	 * @param	Conf			$conf		Configuration object
	 * @return	int							Always 0
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('meli')) {
			return 0;
		}
		$products = array();
		if ($action === 'STOCK_MOVEMENT' && isset($object->product_id) && is_numeric($object->product_id)) {
			$products[] = (int) $object->product_id;
		} elseif ($action === 'PRODUCT_MODIFY' && $object instanceof Product) {
			$products[] = (int) $object->id;
		} elseif (in_array($action, self::ORDER_EVENTS, true)) {
			foreach (is_array($object->lines) ? $object->lines : array() as $line) {
				if (is_object($line) && isset($line->fk_product) && is_numeric($line->fk_product) && (int) $line->fk_product > 0) {
					$products[] = (int) $line->fk_product;
				}
			}
		}
		if (!empty($products)) {
			(new MeliStock($this->db))->enqueue($products, strtolower($action));
		}

		return 0;
	}
}
