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
 * \file    class/meliinbox.class.php
 * \ingroup meli
 * \brief   Notifications from Mercado Libre: written at once by the webhook, worked on later by a job.
 */

require_once __DIR__.'/meliorders.class.php';
require_once __DIR__.'/melishipping.class.php';

/**
 * Notifications
 *
 * Mercado Libre wants an answer within 500 ms and switches off topics whose endpoint is slow or
 * failing, and the body of a notification carries no business data anyway: the webhook only stores
 * it. The job reads the resource again with the account's token, which is also why the body is
 * never trusted.
 */
class MeliInbox
{
	const TOPICS = array('orders_v2', 'shipments', 'items');

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
	 * Store a notification as received.
	 *
	 * @param	string	$json	Body of the request
	 * @return	int			HTTP status to answer: 200 when stored or deliberately ignored, 400 for a malformed body, 500 when it could not be stored
	 */
	public function receive($json)
	{
		/** @var Conf $conf */
		global $conf;

		$data = json_decode($json, true);
		if (!is_array($data) || !is_string($data['topic'] ?? null) || !is_string($data['resource'] ?? null) || !preg_match('#^/[A-Za-z0-9_\-/.?=&,]{1,250}$#', $data['resource'])) {
			return 400;
		}
		$topic = $data['topic'];
		$appId = is_scalar($data['application_id'] ?? null) ? (string) $data['application_id'] : '';
		$userId = is_numeric($data['user_id'] ?? null) ? (int) $data['user_id'] : 0;
		// Only what concerns this application and its account; the rest is answered 200 so that it stops.
		$account = $this->accountUserId();
		if ($appId !== MeliConfig::appId() || ($account > 0 && $userId !== $account) || !in_array($topic, self::TOPICS, true)) {
			return 200;
		}

		$sql = "INSERT INTO ".$this->db->prefix()."meli_inbox (entity, topic, resource, ml_user_id, attempts_ml, date_sent, date_creation, status, date_next, body) VALUES (";
		$sql .= ((int) $conf->entity).", '".$this->db->escape($topic)."', '".$this->db->escape($data['resource'])."', ".$userId;
		$sql .= ", ".(is_numeric($data['attempts'] ?? null) ? (int) $data['attempts'] : 'NULL').", '".$this->db->escape(is_scalar($data['sent'] ?? null) ? dol_trunc((string) $data['sent'], 38, 'right', 'UTF-8', 1) : '')."'";
		$sql .= ", '".$this->db->idate(dol_now())."', 'new', '".$this->db->idate(dol_now())."', '".$this->db->escape(dol_trunc($json, 4000, 'right', 'UTF-8', 1))."')";

		return $this->db->query($sql) ? 200 : 500;
	}

	/**
	 * Work on the notifications due, the same resource only once per run.
	 *
	 * @param	int		$limit	Most notifications in one run
	 * @return	array<string, int>	Outcome => count
	 */
	public function process($limit = 50)
	{
		/** @var Conf $conf */
		global $conf;

		$prefix = $this->db->prefix();
		$counts = array();
		$sql = "SELECT i.rowid, i.topic, i.resource, i.tries FROM ".$prefix."meli_inbox as i WHERE i.entity = ".((int) $conf->entity)." AND i.status = 'new'";
		$sql .= " AND i.date_next <= '".$this->db->idate(dol_now())."' ORDER BY i.rowid".$this->db->plimit(max(1, $limit));
		$rows = array();
		$resql = $this->db->query($sql);
		while ($resql && $resql !== true && is_array($row = $this->db->fetch_array($resql))) {
			$rows[] = array('id' => is_numeric($row['rowid']) ? (int) $row['rowid'] : 0, 'topic' => is_string($row['topic']) ? $row['topic'] : '', 'resource' => is_string($row['resource']) ? $row['resource'] : '', 'tries' => is_numeric($row['tries']) ? (int) $row['tries'] : 0);
		}

		$api = new MeliApi($this->db, 20);
		$done = array();
		foreach ($rows as $row) {
			$key = $row['topic'].' '.$row['resource'];
			// Notifications repeat; the resource is read again in its current state, once.
			if (isset($done[$key])) {
				$this->finish($row['id'], 'done', '');
				continue;
			}
			$outcome = $this->handle($api, $row['topic'], $row['resource']);
			$done[$key] = true;
			$counts[$outcome[0]] = ($counts[$outcome[0]] ?? 0) + 1;
			if ($outcome[0] === MeliOrders::FAILED && $row['tries'] < 8) {
				$minutes = array(1, 5, 15, 60, 60, 120, 240, 480)[$row['tries']];
				$this->db->query("UPDATE ".$prefix."meli_inbox SET tries = tries + 1, date_next = '".$this->db->idate(dol_now() + $minutes * 60)."', error = '".$this->db->escape(dol_trunc($outcome[1], 250, 'right', 'UTF-8', 1))."' WHERE rowid = ".$row['id']);
			} else {
				$this->finish($row['id'], $outcome[0] === MeliOrders::FAILED ? 'failed' : 'done', $outcome[1]);
			}
		}

		return $counts;
	}

	/**
	 * Delete the bodies of old notifications: they may hold ids of buyers.
	 *
	 * @param	int	$days	Age in days
	 * @return	void
	 */
	public function purge($days = 30)
	{
		/** @var Conf $conf */
		global $conf;

		$limit = "'".$this->db->idate(dol_now() - $days * 86400)."'";
		$this->db->query("UPDATE ".$this->db->prefix()."meli_inbox SET body = NULL WHERE entity = ".((int) $conf->entity)." AND date_creation < ".$limit." AND body IS NOT NULL");
		$this->db->query("DELETE FROM ".$this->db->prefix()."meli_inbox WHERE entity = ".((int) $conf->entity)." AND date_creation < '".$this->db->idate(dol_now() - 90 * 86400)."' AND status <> 'new'");
		$this->db->query("DELETE FROM ".$this->db->prefix()."meli_call WHERE entity = ".((int) $conf->entity)." AND date_creation < ".$limit);
	}

	/**
	 * Act on one notification.
	 *
	 * @param	MeliApi	$api		API client
	 * @param	string	$topic		Topic
	 * @param	string	$resource	Resource, e.g. /orders/123
	 * @return	array{0: string, 1: string}	Outcome (a MeliOrders constant or 'linked') and detail
	 */
	private function handle(MeliApi $api, $topic, $resource)
	{
		if ($topic === 'orders_v2' && preg_match('#^/orders/(\d+)$#', $resource, $match)) {
			$orders = new MeliOrders($this->db, $api);
			$outcome = $orders->sync($match[1]);
			return array($outcome, $orders->error);
		}
		if ($topic === 'shipments' && preg_match('#^/shipments/(\d+)$#', $resource, $match)) {
			$shipping = new MeliShipping($this->db, $api);
			$outcome = $shipping->refresh($match[1]);
			return array($outcome, $shipping->error);
		}
		if ($topic === 'items') {
			// An item changed or was replaced (moved to User Products): the links are read again.
			$links = new MeliLink($this->db, $api);
			return ($links->sync() === null) ? array(MeliOrders::FAILED, $links->error) : array(MeliOrders::UPDATED, '');
		}

		return array(MeliOrders::IGNORED, '');
	}

	/**
	 * Close a notification.
	 *
	 * @param	int		$id		Row id
	 * @param	string	$status	done or failed
	 * @param	string	$error	Detail
	 * @return	void
	 */
	private function finish($id, $status, $error)
	{
		$sql = "UPDATE ".$this->db->prefix()."meli_inbox SET status = '".$this->db->escape($status)."', date_done = '".$this->db->idate(dol_now())."'";
		$sql .= ", error = ".($error === '' ? 'NULL' : "'".$this->db->escape(dol_trunc($error, 250, 'right', 'UTF-8', 1))."'")." WHERE rowid = ".((int) $id);
		$this->db->query($sql);
	}

	/**
	 * Mercado Libre id of the connected account.
	 *
	 * @return	int		0 when not connected yet
	 */
	private function accountUserId()
	{
		/** @var Conf $conf */
		global $conf;

		$resql = $this->db->query("SELECT a.ml_user_id FROM ".$this->db->prefix()."meli_account as a WHERE a.entity = ".((int) $conf->entity));
		$row = ($resql && $resql !== true) ? $this->db->fetch_array($resql) : null;

		return (is_array($row) && is_numeric($row['ml_user_id'])) ? (int) $row['ml_user_id'] : 0;
	}
}
