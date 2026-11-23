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

-- Products whose quantity must be sent to Mercado Libre: one row per product, so a burst of stock
-- movements becomes one push.
CREATE TABLE llx_meli_outbox(
	rowid          integer AUTO_INCREMENT PRIMARY KEY,
	entity         integer DEFAULT 1 NOT NULL,
	fk_product     integer NOT NULL,
	reason         varchar(32),
	date_due       datetime NOT NULL,
	attempts       integer DEFAULT 0 NOT NULL,
	last_error     varchar(255)
) ENGINE=innodb;
