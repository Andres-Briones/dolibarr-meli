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
 * \file    admin/listings.php
 * \ingroup meli
 * \brief   Listings read from Mercado Libre and the product each one is linked to.
 */

require __DIR__.'/../lib/meliloader.inc.php';
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once __DIR__.'/../lib/meli_admin.lib.php';
require_once __DIR__.'/../class/melistock.class.php';

$langs->loadLangs(array('admin', 'products', 'meli@meli'));

if (!$user->admin) {
	accessforbidden();
}
if (!isModEnabled('meli')) {
	accessforbidden('Module not enabled');
}

$action = meli_admin_param('action', 'aZ09');
$id = GETPOSTINT('id');
$filter = meli_admin_param('filter', 'aZ09');
$links = new MeliLink($db);
$table = $db->prefix().'meli_link';
$entity = (int) $conf->entity;


$self = dol_buildpath('/meli/admin/listings.php', 1);

/*
 * Actions
 */

$changed = 0;
if ($action === 'link' && $id > 0) {
	$ref = trim(meli_admin_param('product_ref', 'alphanohtml', 2));
	$product = new Product($db);
	if ($ref === '') {
		$links->setProduct($id, 0);
	} elseif ($product->fetch(0, $ref) > 0 && $links->setProduct($id, (int) $product->id)) {
		$changed = (int) $product->id;
	} else {
		setEventMessages($langs->trans('MeliCannotLink', $ref), null, 'errors');
	}
	$buffer = meli_admin_param('buffer', 'alphanohtml', 2);
	$db->query("UPDATE ".$table." SET buffer = ".(is_numeric($buffer) ? max(0, (int) $buffer) : 'NULL')." WHERE rowid = ".$id." AND entity = ".$entity);
}
if (($action === 'ignore' || $action === 'unignore') && $id > 0) {
	$links->setIgnored($id, $action === 'ignore');
}
if (in_array($action, array('link', 'ignore', 'unignore'), true) && $id > 0) {
	// The quantity is sent again under the new link or buffer.
	$resql = $db->query("SELECT l.fk_product FROM ".$table." as l WHERE l.rowid = ".$id." AND l.entity = ".$entity);
	$row = meli_admin_row($db, $resql);
	if (is_array($row) && is_numeric($row['fk_product'])) {
		(new MeliStock($db))->enqueue(array((int) $row['fk_product']), 'manual');
	}
	header('Location: '.$self.($filter !== '' ? '?filter='.urlencode($filter) : ''));
	exit;
}


/*
 * View
 */

meli_admin_header('listings');
print '<p>'.dol_escape_htmltag($langs->trans('MeliListingsIntro')).'</p>';
print '<div class="marginbottomonly">';
foreach (array('' => 'MeliFilterAll', 'unlinked' => 'MeliFilterUnlinked', 'ignored' => 'MeliFilterIgnored', 'gone' => 'MeliFilterGone') as $key => $label) {
	print ($filter === $key ? '<strong>' : '<a href="'.$self.($key !== '' ? '?filter='.$key : '').'">').dol_escape_htmltag($langs->trans($label)).($filter === $key ? '</strong>' : '</a>').' &nbsp; ';
}
print '</div>';

$sql = "SELECT l.rowid, l.item_id, l.variation_id, l.title, l.seller_sku, l.listing_status, l.ml_quantity, l.fk_product, l.manual, l.ignored, l.active, l.reason, l.buffer, l.last_pushed_qty, l.last_error, p.ref";
$sql .= " FROM ".$table." as l LEFT JOIN ".$db->prefix()."product as p ON p.rowid = l.fk_product WHERE l.entity = ".$entity;
if ($filter === 'unlinked') {
	$sql .= " AND l.active = 1 AND l.ignored = 0 AND l.fk_product IS NULL";
} elseif ($filter === 'ignored') {
	$sql .= " AND l.ignored = 1";
} elseif ($filter === 'gone') {
	$sql .= " AND l.active = 0";
} else {
	$sql .= " AND l.active = 1";
}
$sql .= " ORDER BY l.fk_product IS NULL DESC, l.title".$db->plimit(500);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent" id="meli_listings">';
print '<tr class="liste_titre"><td>'.$langs->trans('MeliListing').'</td><td>SKU</td><td>'.$langs->trans('Product').'</td><td class="right">'.$langs->trans('MeliQtyOnMl').'</td><td class="right">'.$langs->trans('MeliQtySent').'</td><td>'.$langs->trans('MeliBuffer').'</td><td></td></tr>';
$resql = $db->query($sql);
while (($r = meli_admin_row($db, $resql)) !== null) {
	$rowid = (int) $r['rowid'];
	print '<tr class="oddeven" data-item="'.dol_escape_htmltag($r['item_id'].($r['variation_id'] > 0 ? '-'.$r['variation_id'] : '')).'">';
	print '<td>'.dol_escape_htmltag($r['title']).'<br><span class="opacitymedium small">'.dol_escape_htmltag($r['item_id']).((int) $r['variation_id'] > 0 ? ' / '.((int) $r['variation_id']) : '').' &middot; '.dol_escape_htmltag($r['listing_status']).'</span></td>';
	print '<td>'.dol_escape_htmltag($r['seller_sku']).'</td>';
	print '<td><form method="POST" action="'.$self.'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="link"><input type="hidden" name="id" value="'.$rowid.'"><input type="hidden" name="filter" value="'.dol_escape_htmltag($filter).'">';
	print '<input type="text" name="product_ref" class="width100" value="'.dol_escape_htmltag($r['ref']).'" placeholder="'.dol_escape_htmltag($langs->trans('Ref')).'">';
	if ((int) $r['manual'] === 1) {
		print ' '.img_picto($langs->trans('MeliLinkedByHand'), 'fa-hand-paper');
	}
	if ($r['reason'] !== '') {
		print '<br><span class="warning small">'.dol_escape_htmltag($langs->trans('MeliReason_'.$r['reason'])).'</span>';
	}
	print ' <input type="text" name="buffer" class="width50" value="'.dol_escape_htmltag($r['buffer']).'" placeholder="'.getDolGlobalInt('MELI_BUFFER').'">';
	print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'"></form></td>';
	print '<td class="right">'.dol_escape_htmltag($r['ml_quantity']).'</td>';
	print '<td class="right">'.dol_escape_htmltag($r['last_pushed_qty']).($r['last_error'] !== '' ? ' '.img_warning(dol_escape_htmltag($r['last_error'])) : '').'</td>';
	print '<td class="opacitymedium small">'.$langs->trans('MeliBufferBlank').'</td>';
	$ignore = ((int) $r['ignored'] === 1 ? 'unignore' : 'ignore');
	print '<td class="right"><a href="'.$self.'?action='.$ignore.'&id='.$rowid.'&filter='.urlencode($filter).'&token='.newToken().'">'.$langs->trans($ignore === 'ignore' ? 'MeliIgnore' : 'MeliUnignore').'</a></td>';
	print '</tr>';
}
print '</table></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
