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
 *  \defgroup   meli     Module Meli
 *  \brief      Mercado Libre (Argentina) for Dolibarr: listings linked to products, stock, orders, shipping.
 *
 *  \file       core/modules/modMeli.class.php
 *  \ingroup    meli
 *  \brief      Description and activation file for module Meli
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module Meli
 */
class modMeli extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 510104;
		$this->rights_class = 'meli';
		$this->family = 'interface';
		$this->name = 'Meli';
		$this->description = 'MeliDescription';
		$this->descriptionlong = 'MeliDescription';

		$this->editor_name = 'Briones Andres';
		$this->editor_url = '';

		$this->version = '0.1.0';
		// Must match the name core derives from the class name in admin/modules.php
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-store-alt';

		$this->module_parts = array(
			'triggers' => 1,
		);
		$this->dirs = array('/meli');
		$this->config_page_url = array('guide.php@meli');
		$this->depends = array('modProduct', 'modCommande', 'modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('meli@meli');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(22, 0);

		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array(
			0 => array(
				'label' => 'MeliCronQueue',
				'jobtype' => 'method',
				'class' => '/meli/class/melicron.class.php',
				'objectname' => 'MeliCron',
				'method' => 'runQueue',
				'parameters' => '',
				'comment' => 'MeliCronQueueDesc',
				'frequency' => 1,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("meli")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'MeliCronPolling',
				'jobtype' => 'method',
				'class' => '/meli/class/melicron.class.php',
				'objectname' => 'MeliCron',
				'method' => 'runPolling',
				'parameters' => '',
				'comment' => 'MeliCronPollingDesc',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 1,
				'test' => 'isModEnabled("meli")',
				'priority' => 51,
			),
			2 => array(
				'label' => 'MeliCronNightly',
				'jobtype' => 'method',
				'class' => '/meli/class/melicron.class.php',
				'objectname' => 'MeliCron',
				'method' => 'runNightly',
				'parameters' => '',
				'comment' => 'MeliCronNightlyDesc',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("meli")',
				'priority' => 52,
			),
		);
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Function called when module is enabled: creates the tables, then the constants and scheduled jobs.
	 *
	 * @param	string	$options	Options when enabling module ('', 'noboxes')
	 * @return	int					1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/meli/sql/');
		if ($result <= 0) {
			return 0;
		}

		/** @var Conf $conf */
		global $conf;

		// Buyers are found again by their Mercado Libre id when they gave no document.
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		if ($extrafields->addExtraField('meli_buyer_id', 'MeliBuyerId', 'varchar', 900, '32', 'thirdparty', 0, 0, '', '', 0, '', '0', '', '', '', 'meli@meli', 'isModEnabled("meli")') <= 0) {
			$this->error = $extrafields->error;
			return 0;
		}

		// The payment mode of sales paid on Mercado Libre, for customers only. An existing one, even
		// renamed or disabled by an administrator, is left as it is; disabling the module keeps it.
		$sql = array();
		$resql = $this->db->query("SELECT id FROM ".$this->db->prefix()."c_paiement WHERE code = 'MELI' AND entity = ".((int) $conf->entity));
		if ($resql && $resql !== true && !is_array($this->db->fetch_array($resql))) {
			$sql[] = array(
				'sql' => "INSERT INTO ".$this->db->prefix()."c_paiement (entity, code, libelle, type, active, module) VALUES (".((int) $conf->entity).", 'MELI', 'Mercado Libre', 0, 1, 'meli')",
				'ignoreerror' => 1,
			);
		}

		return $this->_init($sql, $options);
	}
}
