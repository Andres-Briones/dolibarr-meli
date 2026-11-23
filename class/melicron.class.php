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
 * \file    class/melicron.class.php
 * \ingroup meli
 * \brief   Scheduled jobs of the Meli module.
 */

require_once __DIR__.'/meliinbox.class.php';

/**
 * Scheduled jobs
 *
 * Each job takes a lease so that a run that lasts longer than its interval is not doubled.
 */
class MeliCron
{
	/**
	 * @var string Message shown in the job's result
	 */
	public $output = '';

	/**
	 * @var string Error of the job
	 */
	public $error = '';

	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Every minute: notifications, then the quantities to send.
	 *
	 * @return	int		0 if OK, <0 if KO
	 */
	public function runQueue()
	{
		return $this->leased('queue', 300, 'queue');
	}

	/**
	 * Every hour: notifications Mercado Libre could not deliver, and sales updated since the last run.
	 *
	 * @return	int		0 if OK, <0 if KO
	 */
	public function runPolling()
	{
		return $this->leased('polling', 1800, 'polling');
	}

	/**
	 * Every night: links read again, every linked product's quantity sent, old data purged; once a
	 * week a call that keeps the authorisation alive.
	 *
	 * @return	int		0 if OK, <0 if KO
	 */
	public function runNightly()
	{
		return $this->leased('nightly', 3600, 'nightly');
	}

	/**
	 * Run a job under a lease.
	 *
	 * @param	string	$name		Lease name
	 * @param	int		$seconds	Lease length
	 * @param	string	$job		queue, polling or nightly
	 * @return	int					0 if OK, <0 if KO
	 */
	private function leased($name, $seconds, $job)
	{
		/** @var Conf $conf */
		global $conf;

		$account = (new MeliAccount($this->db))->fetch();
		if ($account === null || $account['status'] !== MeliAccount::CONNECTED) {
			$this->output = 'not connected';
			return 0;
		}
		$lock = new MeliLock($this->db);
		$key = 'job:'.((int) $conf->entity).':'.$name;
		if (!$lock->acquire($key, $seconds)) {
			$this->output = 'already running';
			return 0;
		}
		try {
			if ($job === 'queue') {
				$this->queue();
			} elseif ($job === 'polling') {
				$this->polling($account);
			} else {
				$this->nightly($account);
			}
		} finally {
			$lock->release($key);
		}

		return ($this->error === '' ? 0 : -1);
	}

	/**
	 * Notifications, then stock.
	 *
	 * @return	void
	 */
	private function queue()
	{
		$parts = array();
		foreach ((new MeliInbox($this->db))->process() as $outcome => $count) {
			$parts[] = $count.' '.$outcome;
		}
		$pushed = (new MeliStock($this->db))->push();
		foreach ($pushed as $outcome => $count) {
			if ($count > 0) {
				$parts[] = $count.' '.$outcome;
			}
		}
		$this->output = empty($parts) ? 'nothing due' : implode(', ', $parts);
	}

	/**
	 * Missed notifications and recently updated sales, put in the inbox as if notified.
	 *
	 * @param	array<string, string>	$account	Account row
	 * @return	void
	 */
	private function polling(array $account)
	{
		/** @var Conf $conf */
		global $conf;

		$api = new MeliApi($this->db, 30);
		$inbox = new MeliInbox($this->db);
		$found = 0;
		$missed = $api->get('/missed_feeds?app_id='.rawurlencode(MeliConfig::appId()).'&limit=100');
		foreach ((isset($missed['data']['messages']) && is_array($missed['data']['messages'])) ? $missed['data']['messages'] : array() as $message) {
			if (is_array($message) && $inbox->receive((string) json_encode($message + array('application_id' => MeliConfig::appId()))) === 200) {
				$found++;
			}
		}

		// The last hour again on every run: a sale updated during the previous run is not lost.
		$since = max(dol_now() - 86400, getDolGlobalInt('MELI_POLL_LAST') - 3600);
		$from = gmdate('Y-m-d\TH:00:00.000\Z', $since);
		$offset = 0;
		do {
			$page = $api->get('/orders/search?seller='.((int) $account['ml_user_id']).'&order.date_last_updated.from='.rawurlencode($from).'&sort=date_asc&offset='.$offset.'&limit=50');
			$results = (isset($page['data']['results']) && is_array($page['data']['results'])) ? $page['data']['results'] : array();
			foreach ($results as $order) {
				if (is_array($order) && is_numeric($order['id'] ?? null)) {
					$body = array('topic' => 'orders_v2', 'resource' => '/orders/'.((int) $order['id']), 'user_id' => (int) $account['ml_user_id'], 'application_id' => MeliConfig::appId());
					if ($inbox->receive((string) json_encode($body)) === 200) {
						$found++;
					}
				}
			}
			$offset += 50;
		} while ($page['ok'] && count($results) === 50 && $offset < 1000);
		if (!$page['ok']) {
			$this->error = $page['message'];
		} else {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			dolibarr_set_const($this->db, 'MELI_POLL_LAST', (string) dol_now(), 'chaine', 0, '', $conf->entity);
		}
		$open = (new MeliShipping($this->db, $api))->refreshOpen();
		$this->output = $found.' queued, '.$open.' shipments read';
	}

	/**
	 * Links, full stock reconciliation, purge, keep-alive.
	 *
	 * @param	array<string, string>	$account	Account row
	 * @return	void
	 */
	private function nightly(array $account)
	{
		$api = new MeliApi($this->db, 60);
		$links = new MeliLink($this->db, $api);
		$counts = $links->sync();
		if ($counts === null) {
			$this->error = $links->error;
		}
		$products = array();
		foreach ($links->linksOf() as $link) {
			$products[] = $link['fk_product'];
		}
		(new MeliStock($this->db))->enqueue($products, 'nightly');
		(new MeliInbox($this->db))->purge();
		// Four months without a call revokes the authorisation; normal syncing calls every day anyway.
		$api->get('/users/me');
		$this->output = ($counts === null ? 'links failed' : $counts['linked'].' linked, '.$counts['unlinked'].' not linked').', '.count($products).' products to send';
	}
}
