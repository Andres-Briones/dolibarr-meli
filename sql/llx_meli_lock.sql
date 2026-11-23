-- Copyright (C) 2026 Briones Andres
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- Leases: a lock is a row whose locked_until is in the future, taken with one conditional UPDATE.
-- No database transaction stays open during a call to Mercado Libre; a dead holder's lease runs out.
CREATE TABLE llx_meli_lock(
	rowid         integer AUTO_INCREMENT PRIMARY KEY,
	lock_key      varchar(128) NOT NULL,
	owner         varchar(64),
	locked_until  datetime
) ENGINE=innodb;
