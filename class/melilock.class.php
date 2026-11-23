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
 * \file    class/melilock.class.php
 * \ingroup meli
 * \brief   Locks that expire on their own.
 */


/**
 * Lease
 *
 * A lock is a row whose date is in the future. It is taken with one conditional UPDATE, so no
 * database transaction stays open while ARCA is being called, and the lease of a worker that
 * died runs out by itself.
 */
class MeliLock
{
	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * @var string Identifies this holder among the processes
	 */
	private $owner;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->owner = bin2hex(random_bytes(12));
	}

	/**
	 * Try to take a lock.
	 *
	 * @param	string	$key		Name of what is locked
	 * @param	int		$seconds	How long the lease lasts if it is not released
	 * @return	bool				False if someone else holds it
	 */
	public function acquire($key, $seconds = 120)
	{
		$table = $this->db->prefix().'meli_lock';
		$now = dol_now();
		$name = "'".$this->db->escape($key)."'";

		// The row is created once; a second creator hits the unique key, which is fine.
		$resql = $this->db->query("SELECT rowid FROM ".$table." WHERE lock_key = ".$name);
		if ($resql && $resql !== true && !is_array($this->db->fetch_array($resql))) {
			$this->db->query("INSERT INTO ".$table." (lock_key, owner, locked_until) VALUES (".$name.", NULL, NULL)", 1);
		}

		$sql = "UPDATE ".$table." SET owner = '".$this->db->escape($this->owner)."', locked_until = '".$this->db->idate($now + max(1, $seconds))."'";
		$sql .= " WHERE lock_key = ".$name." AND (locked_until IS NULL OR locked_until < '".$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			return false;
		}

		// Read back rather than trusting the affected-rows count, which drivers report differently.
		$resql = $this->db->query("SELECT owner FROM ".$table." WHERE lock_key = ".$name);
		if (!$resql || $resql === true) {
			return false;
		}
		$row = $this->db->fetch_array($resql);

		return (is_array($row) && $row['owner'] === $this->owner);
	}

	/**
	 * Release a lock held by this object.
	 *
	 * @param	string	$key	Name of what is locked
	 * @return	void
	 */
	public function release($key)
	{
		$sql = "UPDATE ".$this->db->prefix()."meli_lock SET owner = NULL, locked_until = NULL";
		$sql .= " WHERE lock_key = '".$this->db->escape($key)."' AND owner = '".$this->db->escape($this->owner)."'";
		$this->db->query($sql);
	}
}
