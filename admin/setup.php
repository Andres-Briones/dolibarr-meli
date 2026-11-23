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
 * \file    admin/setup.php
 * \ingroup meli
 * \brief   Application, account connection and settings of the Meli module.
 */

require __DIR__.'/../lib/meliloader.inc.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!function_exists('dolEncrypt')) {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
}
require_once __DIR__.'/../lib/meli_admin.lib.php';
require_once __DIR__.'/../class/melilink.class.php';
require_once __DIR__.'/../class/meliorders.class.php';

$langs->loadLangs(array('admin', 'banks', 'meli@meli'));

if (!$user->admin) {
	accessforbidden();
}
if (!isModEnabled('meli')) {
	accessforbidden('Module not enabled');
}

$action = meli_admin_param('action', 'aZ09');
$account = new MeliAccount($db);
$entity = (int) $conf->entity;


$self = dol_buildpath('/meli/admin/setup.php', 1);

/*
 * Actions
 */

if ($action === 'save') {
	foreach (array('MELI_APP_ID', 'MELI_WEBHOOK_IPS') as $name) {
		dolibarr_set_const($db, $name, trim(meli_admin_param($name, 'alphanohtml', 2)), 'chaine', 0, '', $entity);
	}
	// The secret is never shown again: an empty field keeps the stored one.
	$secret = trim(meli_admin_param('MELI_APP_SECRET', 'none', 2));
	if ($secret !== '') {
		dolibarr_set_const($db, 'MELI_APP_SECRET', dolEncrypt($secret), 'chaine', 0, '', $entity);
	}
	foreach (array('MELI_USER_ID', 'MELI_BANK_ACCOUNT', 'MELI_PAYMENT_MODE', 'MELI_SHIPPING_PRODUCT_ID', 'MELI_BUFFER', 'MELI_MAX_QTY') as $name) {
		dolibarr_set_const($db, $name, (string) max(0, GETPOSTINT($name, 2)), 'chaine', 0, '', $entity);
	}
	foreach (array('MELI_PUSH_STOCK', 'MELI_INVOICE_ORDERS') as $name) {
		dolibarr_set_const($db, $name, GETPOSTINT($name, 2) > 0 ? '1' : '0', 'chaine', 0, '', $entity);
	}
	$warehouses = GETPOST('MELI_WAREHOUSE_IDS', 'array:int', 2);
	$ids = array();
	foreach (is_array($warehouses) ? $warehouses : array() as $warehouse) {
		if (is_numeric($warehouse) && (int) $warehouse > 0) {
			$ids[] = (int) $warehouse;
		}
	}
	dolibarr_set_const($db, 'MELI_WAREHOUSE_IDS', implode(',', $ids), 'chaine', 0, '', $entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$self);
	exit;
}
if ($action === 'connect') {
	$url = $account->authorizationUrl($user);
	if ($url !== '') {
		header('Location: '.$url);
		exit;
	}
	setEventMessages($langs->trans('MeliAppMissing'), null, 'errors');
}
if ($action === 'disconnect') {
	$account->disconnect();
	setEventMessages($langs->trans('MeliDisconnected'), null, 'mesgs');
}
if ($action === 'readlistings') {
	$links = new MeliLink($db);
	$counts = $links->sync();
	if ($counts === null) {
		setEventMessages($langs->trans('MeliListingsFailed', $links->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('MeliListingsRead', $counts['listings'], $counts['linked'], $counts['unlinked']), null, 'mesgs');
	}
}


/*
 * View
 */

$form = new Form($db);
$row = $account->fetch();
meli_admin_header('setup');

// Connection
print load_fiche_titre($langs->trans('MeliConnection'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('Status').'</td><td id="meli_status">';
$status = ($row === null ? MeliAccount::DISCONNECTED : $row['status']);
if ($status === MeliAccount::CONNECTED) {
	print dolGetBadge($langs->trans('MeliStatusConnected'), '', 'status4').' '.dol_escape_htmltag((string) ($row['nickname'] ?? '')).' <span class="opacitymedium">('.dol_escape_htmltag((string) ($row['ml_user_id'] ?? '')).')</span>';
} elseif ($status === MeliAccount::RECONNECT) {
	print dolGetBadge($langs->trans('MeliStatusReconnect'), '', 'status8');
} else {
	print dolGetBadge($langs->trans('MeliStatusDisconnected'), '', 'status0');
}
if ($row !== null && (string) ($row['last_error'] ?? '') !== '') {
	print '<br><span class="error small">'.dol_escape_htmltag($row['last_error']).'</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MeliRedirectUrl').'</td><td><code id="meli_redirect">'.dol_escape_htmltag(MeliConfig::redirectUrl()).'</code></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MeliWebhookUrl').'</td><td><code id="meli_webhook">'.dol_escape_htmltag(MeliConfig::webhookUrl()).'</code><br><span class="opacitymedium small">'.$langs->trans('MeliWebhookTopics').'</span></td></tr>';
print '</table>';
print '<div class="tabsAction">';
if ($status === MeliAccount::CONNECTED) {
	print '<a class="butAction" id="meli_readlistings" href="'.$self.'?action=readlistings&token='.newToken().'">'.$langs->trans('MeliReadListings').'</a>';
	print '<a class="butActionDelete" id="meli_disconnect" href="'.$self.'?action=disconnect&token='.newToken().'">'.$langs->trans('MeliDisconnect').'</a>';
} else {
	print '<a class="butAction" id="meli_connect" href="'.$self.'?action=connect&token='.newToken().'">'.$langs->trans('MeliConnect').'</a>';
}
print '</div>';

// Settings
$warehouses = array();
$resql = $db->query("SELECT e.rowid, e.ref FROM ".$db->prefix()."entrepot as e WHERE e.entity IN (".getEntity('stock').") AND e.statut = 1 ORDER BY e.ref");
while (($w = meli_admin_row($db, $resql)) !== null) {
	$warehouses[$w['rowid']] = $w['ref'];
}
$services = array(0 => '');
$resql = $db->query("SELECT p.rowid, p.ref, p.label FROM ".$db->prefix()."product as p WHERE p.entity IN (".getEntity('product').") AND p.fk_product_type = 1 AND p.tosell = 1 ORDER BY p.ref");
while (($p = meli_admin_row($db, $resql)) !== null) {
	$services[(int) $p['rowid']] = $p['ref'].' - '.$p['label'];
}
$invoice = getDolGlobalInt('MELI_INVOICE_ORDERS', 1);

print load_fiche_titre($langs->trans('Settings'), '', '');
print '<form method="POST" action="'.$self.'" id="meli_settings">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
$line = function (string $label, string $field, string $help = '') use ($langs): void {
	print '<tr class="oddeven"><td>'.$langs->trans($label).'</td><td>'.$field;
	if ($help !== '') {
		print '<br><span class="opacitymedium small">'.dol_escape_htmltag($langs->transnoentitiesnoconv($help)).'</span>';
	}
	print '</td></tr>';
};
$line('MeliAppId', '<input type="text" name="MELI_APP_ID" class="minwidth200" value="'.dol_escape_htmltag(MeliConfig::appId()).'">', 'MeliAppIdHelp');
$line('MeliAppSecret', '<input type="password" name="MELI_APP_SECRET" class="minwidth200" autocomplete="new-password" value="" placeholder="'.dol_escape_htmltag(MeliConfig::appSecret() !== '' ? $langs->trans('MeliSecretStored') : '').'">', 'MeliAppSecretHelp');
// -1: core would otherwise preselect the administrator viewing the page.
$users = $form->select_dolusers(getDolGlobalInt('MELI_USER_ID') > 0 ? getDolGlobalInt('MELI_USER_ID') : -1, 'MELI_USER_ID', 1);
$line('MeliTechnicalUser', is_string($users) ? $users : '', 'MeliTechnicalUserHelp');
$line('MeliWarehouses', Form::multiselectarray('MELI_WAREHOUSE_IDS', $warehouses, explode(',', getDolGlobalString('MELI_WAREHOUSE_IDS')), 0, 0, 'minwidth300'), 'MeliWarehousesHelp');
$line('MeliBuffer', '<input type="number" min="0" name="MELI_BUFFER" class="width75" value="'.getDolGlobalInt('MELI_BUFFER').'">', 'MeliBufferHelp');
$line('MeliMaxQty', '<input type="number" min="0" name="MELI_MAX_QTY" class="width75" value="'.getDolGlobalInt('MELI_MAX_QTY').'">', 'MeliMaxQtyHelp');
$line('MeliPushStock', $form->selectyesno('MELI_PUSH_STOCK', (string) getDolGlobalInt('MELI_PUSH_STOCK'), 1), 'MeliPushStockHelp');
$line('MeliInvoiceOrders', $form->selectyesno('MELI_INVOICE_ORDERS', (string) $invoice, 1), 'MeliInvoiceOrdersHelp');
$line('MeliBankAccount', (string) $form->select_comptes(getDolGlobalInt('MELI_BANK_ACCOUNT'), 'MELI_BANK_ACCOUNT', 0, '', 1, '', 0, 'minwidth200', 1), 'MeliBankAccountHelp');
$line('MeliPaymentMode', (string) $form->select_types_paiements((string) MeliOrders::paymentModeId($db), 'MELI_PAYMENT_MODE', 'CRDT', 0, 1, 0, 0, 1, 'minwidth200', 1));
$line('MeliShippingProduct', Form::selectarray('MELI_SHIPPING_PRODUCT_ID', $services, getDolGlobalInt('MELI_SHIPPING_PRODUCT_ID'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300'), 'MeliShippingProductHelp');
$line('MeliWebhookIps', '<input type="text" name="MELI_WEBHOOK_IPS" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('MELI_WEBHOOK_IPS')).'">', 'MeliWebhookIpsHelp');
print '</table>';
if (!$invoice) {
	// The invoices are then issued with Mercado Libre's Facturador: issuing them again here would duplicate them at ARCA.
	print '<div class="warning" id="meli_no_invoice">'.dol_escape_htmltag($langs->trans('MeliInvoiceOffWarning')).'</div>';
}
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
