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
 * \file    admin/sales.php
 * \ingroup meli
 * \brief   Sales imported from Mercado Libre, those a person must look at first, and their labels.
 */

require __DIR__.'/../lib/meliloader.inc.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/meli_admin.lib.php';
require_once __DIR__.'/../class/melishipping.class.php';

$langs->loadLangs(array('admin', 'orders', 'bills', 'meli@meli'));

if (!$user->admin) {
	accessforbidden();
}
if (!isModEnabled('meli')) {
	accessforbidden('Module not enabled');
}

$action = meli_admin_param('action', 'aZ09');
$id = GETPOSTINT('id');
$table = $db->prefix().'meli_order';
$entity = (int) $conf->entity;


$self = dol_buildpath('/meli/admin/sales.php', 1);

/*
 * Actions
 */

if ($action === 'reviewed' && $id > 0) {
	$db->query("UPDATE ".$table." SET needs_review = 0, note = NULL WHERE rowid = ".$id." AND entity = ".$entity);
}
if ($action === 'invoice' && $id > 0) {
	$resql = $db->query("SELECT o.pack_id FROM ".$table." as o WHERE o.rowid = ".$id." AND o.entity = ".$entity);
	$row = meli_admin_row($db, $resql);
	$orders = new MeliOrders($db);
	if (is_array($row) && !$orders->invoice($row['pack_id'])) {
		setEventMessages($orders->error, null, 'errors');
	}
}
if ($action === 'label' && $id > 0) {
	$shipping = new MeliShipping($db);
	$path = $shipping->label(array($id));
	if ($path === '') {
		setEventMessages($langs->trans('MeliLabelFailed', $shipping->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('MeliLabelSaved', basename($path)), null, 'mesgs');
	}
}


/*
 * View
 */

meli_admin_header('sales');
print '<p>'.dol_escape_htmltag($langs->trans('MeliSalesIntro')).'</p>';

$sql = "SELECT o.rowid, o.pack_id, o.fk_commande, o.fk_facture, o.ml_status, o.shipment_status, o.shipment_substatus, o.label_file, o.total_paid, o.oversold, o.needs_review, o.note, o.date_creation, c.ref as order_ref, f.ref as invoice_ref";
$sql .= " FROM ".$table." as o LEFT JOIN ".$db->prefix()."commande as c ON c.rowid = o.fk_commande LEFT JOIN ".$db->prefix()."facture as f ON f.rowid = o.fk_facture";
$sql .= " WHERE o.entity = ".$entity." ORDER BY o.needs_review DESC, o.oversold DESC, o.rowid DESC".$db->plimit(200);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent" id="meli_sales">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('MeliSale').'</td><td>'.$langs->trans('Order').'</td><td>'.$langs->trans('Invoice').'</td><td class="right">'.$langs->trans('AmountTTC').'</td><td>'.$langs->trans('MeliShipment').'</td><td>'.$langs->trans('Note').'</td><td></td></tr>';
$resql = $db->query($sql);
while (($r = meli_admin_row($db, $resql)) !== null) {
	$rowid = (int) $r['rowid'];
	print '<tr class="oddeven" data-pack="'.dol_escape_htmltag($r['pack_id']).'">';
	print '<td>'.dol_print_date($db->jdate($r['date_creation']), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag($r['pack_id']).'<br><span class="opacitymedium small">'.dol_escape_htmltag($r['ml_status']).'</span></td>';
	print '<td>'.((int) $r['fk_commande'] > 0 ? '<a href="'.DOL_URL_ROOT.'/commande/card.php?id='.((int) $r['fk_commande']).'">'.dol_escape_htmltag($r['order_ref']).'</a>' : '').'</td>';
	print '<td>'.((int) $r['fk_facture'] > 0 ? '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $r['fk_facture']).'">'.dol_escape_htmltag($r['invoice_ref']).'</a>' : '').'</td>';
	print '<td class="right">'.price((float) $r['total_paid']).'</td>';
	print '<td>'.dol_escape_htmltag(trim($r['shipment_status'].' '.$r['shipment_substatus'])).'</td>';
	print '<td>';
	if ((int) $r['oversold'] === 1) {
		print dolGetBadge($langs->trans('MeliOversold'), '', 'status8').' ';
	}
	if ((int) $r['needs_review'] === 1) {
		print dolGetBadge($langs->trans('MeliNeedsReview'), '', 'warning').' ';
	}
	print dol_escape_htmltag($r['note']).'</td>';
	print '<td class="right nowraponall">';
	if ((int) $r['needs_review'] === 1) {
		print '<a href="'.$self.'?action=reviewed&id='.$rowid.'&token='.newToken().'">'.$langs->trans('MeliMarkReviewed').'</a><br>';
	}
	if ((int) $r['fk_facture'] <= 0 && (int) $r['fk_commande'] > 0 && (int) $r['needs_review'] === 0 && getDolGlobalInt('MELI_INVOICE_ORDERS', 1)) {
		print '<a href="'.$self.'?action=invoice&id='.$rowid.'&token='.newToken().'">'.$langs->trans('MeliInvoiceNow').'</a><br>';
	}
	if (in_array($r['shipment_status'], array('ready_to_ship'), true) && (int) $r['fk_commande'] > 0) {
		print '<a href="'.$self.'?action=label&id='.((int) $r['fk_commande']).'&token='.newToken().'">'.$langs->trans('MeliGetLabel').'</a>';
	}
	print '</td></tr>';
}
print '</table></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
