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
 * \file    class/melishipping.class.php
 * \ingroup meli
 * \brief   Status of the shipments of Mercado Libre sales, and their labels.
 */

require_once __DIR__.'/meliorders.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Shipments
 *
 * Mercado Libre manages the shipment; Dolibarr keeps its status on the sale and the label to print,
 * as Mercado Libre gives it (labels must not be altered).
 */
class MeliShipping
{
	/**
	 * Shipments in these states may still change
	 */
	const OPEN = array('pending', 'handling', 'ready_to_ship', 'shipped');

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
	 * Read a shipment's status into its sale.
	 *
	 * @param	string	$shipmentId	Shipment id
	 * @return	string				MeliOrders::UPDATED, IGNORED (no imported sale has it yet) or FAILED
	 */
	public function refresh($shipmentId)
	{
		/** @var Conf $conf */
		global $conf;

		$read = $this->api->get('/shipments/'.rawurlencode($shipmentId), array('x-format-new' => 'true'));
		if (!$read['ok']) {
			$this->error = $read['message'];
			return MeliOrders::FAILED;
		}
		$status = is_string($read['data']['status'] ?? null) ? $read['data']['status'] : '';
		$substatus = is_string($read['data']['substatus'] ?? null) ? $read['data']['substatus'] : '';
		$sql = "UPDATE ".$this->db->prefix()."meli_order SET shipment_status = '".$this->db->escape($status)."', shipment_substatus = '".$this->db->escape($substatus)."'";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND shipment_id = '".$this->db->escape($shipmentId)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return MeliOrders::FAILED;
		}

		return MeliOrders::UPDATED;
	}

	/**
	 * Read again the status of every open shipment: the net under lost notifications.
	 *
	 * @return	int		Shipments read
	 */
	public function refreshOpen()
	{
		/** @var Conf $conf */
		global $conf;

		$sql = "SELECT o.shipment_id FROM ".$this->db->prefix()."meli_order as o WHERE o.entity = ".((int) $conf->entity)." AND o.shipment_id IS NOT NULL";
		$sql .= " AND (o.shipment_status IS NULL OR o.shipment_status IN ('".implode("','", self::OPEN)."', ''))".$this->db->plimit(100);
		$ids = array();
		$resql = $this->db->query($sql);
		while ($resql && $resql !== true && is_array($row = $this->db->fetch_array($resql))) {
			if (is_scalar($row['shipment_id'])) {
				$ids[] = (string) $row['shipment_id'];
			}
		}
		foreach ($ids as $id) {
			$this->refresh($id);
		}

		return count($ids);
	}

	/**
	 * Download the label of sales and attach it to their order, as Mercado Libre gives it.
	 *
	 * @param	array<int>	$orderIds	Dolibarr order ids
	 * @return	string					Full path of the PDF (one file for all of them), '' on failure
	 */
	public function label(array $orderIds)
	{
		/** @var Conf $conf */
		global $conf;

		$sql = "SELECT o.fk_commande, o.shipment_id FROM ".$this->db->prefix()."meli_order as o WHERE o.entity = ".((int) $conf->entity);
		$sql .= " AND o.fk_commande IN (".implode(',', array_map('intval', $orderIds ?: array(0))).") AND o.shipment_id IS NOT NULL";
		$shipments = array();
		$resql = $this->db->query($sql);
		while ($resql && $resql !== true && is_array($row = $this->db->fetch_array($resql))) {
			if (is_numeric($row['fk_commande']) && is_scalar($row['shipment_id'])) {
				$shipments[(int) $row['fk_commande']] = (string) $row['shipment_id'];
			}
		}
		if (empty($shipments)) {
			$this->error = 'No shipment';
			return '';
		}
		// Mercado Libre takes at most 50 shipments per label request.
		$shipments = array_slice($shipments, 0, 50, true);
		$file = $this->api->download('/shipment_labels?shipment_ids='.implode(',', array_map('rawurlencode', $shipments)).'&response_type=pdf', array('x-format-new' => 'true'));
		if (!$file['ok'] || strncmp($file['body'], '%PDF', 4) !== 0) {
			$this->error = ($file['ok'] ? 'Not a PDF' : $file['message']);
			return '';
		}

		$order = new Commande($this->db);
		$first = (int) array_key_first($shipments);
		if ($order->fetch($first) <= 0) {
			$this->error = 'Order not found';
			return '';
		}
		$dirs = $conf->commande->multidir_output;
		$entity = (int) ($order->entity ?? $conf->entity);
		$base = (is_array($dirs) && isset($dirs[$entity]) && is_string($dirs[$entity])) ? $dirs[$entity] : DOL_DATA_ROOT.'/commande';
		$dir = $base.'/'.dol_sanitizeFileName((string) $order->ref);
		dol_mkdir($dir);
		$path = $dir.'/etiqueta-mercadolibre-'.dol_sanitizeFileName(implode('-', $shipments)).'.pdf';
		if (file_put_contents($path, $file['body']) === false) {
			$this->error = 'Cannot write '.$path;
			return '';
		}
		dolChmod($path);
		$this->db->query("UPDATE ".$this->db->prefix()."meli_order SET label_file = '".$this->db->escape(basename($path))."' WHERE entity = ".((int) $conf->entity)." AND fk_commande IN (".implode(',', array_keys($shipments)).")");

		return $path;
	}
}
