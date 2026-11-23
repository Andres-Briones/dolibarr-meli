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
		$this->config_page_url = array('setup.php@meli');
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
		$this->cronjobs = array();
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

		return $this->_init(array(), $options);
	}
}
