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
 * \file    lib/meli_admin.lib.php
 * \ingroup meli
 * \brief   Functions of the setup pages of the module: tabs, banner, checks.
 */

require_once __DIR__.'/../class/meliaccount.class.php';

/**
 * Prepare the tabs of the admin pages of the module.
 *
 * @return	list<array{0: string, 1: string, 2: string}>	Tabs, as expected by dol_get_fiche_head()
 */
function meli_admin_prepare_head()
{
	/**
	 * @var Conf $conf
	 * @var Translate $langs
	 */
	global $conf, $langs;

	$h = 0;
	$head = array();
	foreach (array('guide' => 'MeliGuideTab', 'setup' => 'Settings', 'listings' => 'MeliListingsTab', 'sales' => 'MeliSalesTab') as $page => $label) {
		$head[$h] = array(dol_buildpath('/meli/admin/'.$page.'.php', 1), $langs->trans($label), $page);
		$h++;
	}
	complete_head_from_modules($conf, $langs, null, $head, $h, 'meli@meli');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'meli@meli', 'remove');

	// The "remove" pass can leave gaps in the keys
	return array_values($head);
}

/**
 * Warning shown on every tab when the module talks to a test double, and the push status.
 *
 * @return	string	HTML
 */
function meli_admin_banner()
{
	/** @var Translate $langs */
	global $langs;

	$out = '';
	if (MeliConfig::isFake()) {
		$out .= '<div class="error" id="meli_fake_banner">'.img_warning('', '', 'pictowarning pictofixedwidth').'<strong>'.$langs->trans('MeliFakeBanner').'</strong>';
		$out .= '<br><span class="small">API: '.dol_escape_htmltag(MeliConfig::apiUrl()).' &middot; Auth: '.dol_escape_htmltag(MeliConfig::authUrl()).'</span></div>';
	}
	if (getDolGlobalInt('MELI_PUSH_STOCK')) {
		$badge = dolGetBadge($langs->trans('MeliPushOn'), '', 'status4');
	} else {
		$badge = dolGetBadge($langs->trans('MeliPushOff'), '', 'warning');
	}
	$out .= '<div class="marginbottomonly" id="meli_push_state">'.$langs->trans('MeliPushState').' '.$badge.'</div>';

	return $out;
}

/**
 * State of each configuration step.
 *
 * @param	DoliDB	$db	Database handler
 * @return	array<string, bool>
 */
function meli_admin_checks(DoliDB $db)
{
	/** @var Conf $conf */
	global $conf;

	$account = (new MeliAccount($db))->fetch();
	$count = function (string $sql) use ($db): int {
		$resql = $db->query($sql);
		$row = ($resql && $resql !== true) ? $db->fetch_array($resql) : null;
		return (is_array($row) && is_numeric($row['nb'])) ? (int) $row['nb'] : 0;
	};
	$entity = ((int) $conf->entity);

	return array(
		'application' => (MeliConfig::appId() !== '' && MeliConfig::appSecret() !== ''),
		'connected' => ($account !== null && $account['status'] === MeliAccount::CONNECTED),
		'settings' => (getDolGlobalInt('MELI_USER_ID') > 0 && getDolGlobalString('MELI_WAREHOUSE_IDS') !== '' && (!getDolGlobalInt('MELI_INVOICE_ORDERS', 1) || getDolGlobalInt('MELI_BANK_ACCOUNT') > 0)),
		'links' => ($count("SELECT COUNT(l.rowid) as nb FROM ".$db->prefix()."meli_link as l WHERE l.entity = ".$entity." AND l.active = 1 AND l.fk_product > 0") > 0),
		'unlinked' => ($count("SELECT COUNT(l.rowid) as nb FROM ".$db->prefix()."meli_link as l WHERE l.entity = ".$entity." AND l.active = 1 AND l.ignored = 0 AND l.fk_product IS NULL") === 0),
		'push' => (getDolGlobalInt('MELI_PUSH_STOCK') > 0),
	);
}

/**
 * Text value of a request parameter, whatever GETPOST() returned.
 *
 * @param	string	$name	Parameter
 * @param	'alpha'|'alphanohtml'|'aZ09'|'none'	$check	Check type of GETPOST(); 'none' only for what is never printed nor stored as such
 * @param	int		$method	0 = GET then POST, 2 = POST only
 * @return	string			'' if missing or not a text
 */
function meli_admin_param($name, $check = 'alphanohtml', $method = 0)
{
	$value = GETPOST($name, $check, $method);

	return is_string($value) ? $value : '';
}

/**
 * Page header shared by the tabs.
 *
 * @param	string	$tab	Active tab
 * @return	void
 */
function meli_admin_header($tab)
{
	/** @var Translate $langs */
	global $langs;

	$title = 'MeliSetup';
	llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-meli page-admin');
	$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
	print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');
	print dol_get_fiche_head(meli_admin_prepare_head(), $tab, $langs->trans($title), -1, 'fa-store-alt');
	print meli_admin_banner();
}

/**
 * Next row of a result, columns as strings (NULL as '').
 *
 * @param	DoliDB	$db		Database handler
 * @param	bool|mysqli_result|resource|SQLite3Result	$resql	Result of DoliDB::query()
 * @return	array<string, string>|null	null at the end
 */
function meli_admin_row(DoliDB $db, $resql)
{
	if (!$resql || $resql === true) {
		return null;
	}
	$raw = $db->fetch_array($resql);
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
