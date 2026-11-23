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

-- The Mercado Libre account connected to this entity. Tokens are stored encrypted.
-- status: disconnected, connected, reconnect_needed.
CREATE TABLE llx_meli_account(
	rowid             integer AUTO_INCREMENT PRIMARY KEY,
	entity            integer DEFAULT 1 NOT NULL,
	status            varchar(20) DEFAULT 'disconnected' NOT NULL,
	ml_user_id        bigint,
	nickname          varchar(128),
	site_id           varchar(8) DEFAULT 'MLA' NOT NULL,
	access_token      text,
	refresh_token     text,
	expires_at        datetime,
	scopes            varchar(255),
	tags              text,
	oauth_state       varchar(64),
	oauth_verifier    varchar(255),
	oauth_user        integer,
	oauth_expires_at  datetime,
	last_call_at      datetime,
	last_error        varchar(255),
	date_creation     datetime NOT NULL,
	tms               timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
