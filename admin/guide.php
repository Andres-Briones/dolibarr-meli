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
 * \file    admin/guide.php
 * \ingroup meli
 * \brief   First steps: what the owners do at Mercado Libre and in Dolibarr, in order, with the state of each.
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

$langs->loadLangs(array('admin', 'meli@meli'));

if (!$user->admin) {
	accessforbidden();
}
if (!isModEnabled('meli')) {
	accessforbidden('Module not enabled');
}


/*
 * View
 */

$checks = meli_admin_checks($db);
// Each step: title, explanation, whether it is done (null: nothing Dolibarr can check), where it is done.
$steps = array(
	array('MeliGuideStepSku', 'MeliGuideStepSkuText', null, ''),
	array('MeliGuideStepApp', 'MeliGuideStepAppText', $checks['application'], 'setup.php'),
	array('MeliGuideStepConnect', 'MeliGuideStepConnectText', $checks['connected'], 'setup.php'),
	array('MeliGuideStepSettings', 'MeliGuideStepSettingsText', $checks['settings'], 'setup.php'),
	array('MeliGuideStepLinks', 'MeliGuideStepLinksText', $checks['links'] && $checks['unlinked'], 'listings.php'),
	array('MeliGuideStepBuffer', 'MeliGuideStepBufferText', null, 'listings.php'),
	array('MeliGuideStepPush', 'MeliGuideStepPushText', $checks['push'], 'setup.php'),
	array('MeliGuideStepDaily', 'MeliGuideStepDailyText', null, 'sales.php'),
);

meli_admin_header('guide');
print '<p>'.dol_escape_htmltag($langs->trans('MeliGuideIntro')).'</p>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="width25">#</td><td>'.$langs->trans('MeliGuideStep').'</td><td class="center width100">'.$langs->trans('Status').'</td></tr>'."\n";
foreach ($steps as $index => $step) {
	list($titlekey, $textkey, $done, $page) = $step;
	print '<tr class="oddeven"><td class="tdtop"><strong>'.($index + 1).'</strong></td><td>';
	print '<strong>'.dol_escape_htmltag($langs->trans($titlekey)).'</strong><br>';
	// The texts are ours, with line breaks and Mercado Libre's menu names: shown as plain text.
	print nl2br(dol_escape_htmltag(str_replace('\n', "\n", $langs->transnoentitiesnoconv($textkey)), 0, 1));
	if ($page !== '') {
		print '<br><a href="'.dol_buildpath('/meli/admin/'.$page, 1).'">'.dol_escape_htmltag($langs->trans('MeliGuideGoThere')).'</a>';
	}
	print '</td><td class="center tdtop">';
	if ($done === null) {
		print '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('MeliGuideByYou')).'</span>';
	} else {
		print $done ? img_picto($langs->trans('MeliGuideDone'), 'tick') : img_picto($langs->trans('MeliGuideToDo'), 'warning');
	}
	print '</td></tr>'."\n";
}
print '</table>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
