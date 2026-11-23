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

-- Notifications as Mercado Libre sent them. The webhook only writes here and answers at once; a
-- scheduled job reads the resource again with the account's token and does the work.
-- status: new, done, failed, ignored. body is purged after 30 days (it may hold ids of buyers).
CREATE TABLE llx_meli_inbox(
	rowid           integer AUTO_INCREMENT PRIMARY KEY,
	entity          integer DEFAULT 1 NOT NULL,
	topic           varchar(64) NOT NULL,
	resource        varchar(255) NOT NULL,
	ml_user_id      bigint,
	attempts_ml     integer,
	date_sent       varchar(40),
	date_creation   datetime NOT NULL,
	status          varchar(16) DEFAULT 'new' NOT NULL,
	tries           integer DEFAULT 0 NOT NULL,
	date_next       datetime,
	date_done       datetime,
	error           varchar(255),
	body            text
) ENGINE=innodb;
