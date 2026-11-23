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

-- One row per Mercado Libre listing (item, or variation of a legacy item) and the product it sells.
-- active = 0: the listing is no longer the seller's (closed, or replaced when Mercado Libre moved it to
-- the User Products model); kept as history. manual = 1: linked by a person, not by SKU.
-- reason: why a listing is not linked (no_sku, no_product, several_products, variant_parent).
CREATE TABLE llx_meli_link(
	rowid            integer AUTO_INCREMENT PRIMARY KEY,
	entity           integer DEFAULT 1 NOT NULL,
	item_id          varchar(32) NOT NULL,
	variation_id     bigint DEFAULT 0 NOT NULL,
	user_product_id  varchar(32),
	family_name      varchar(255),
	title            varchar(255),
	seller_sku       varchar(128),
	listing_status   varchar(32),
	ml_quantity      integer,
	fk_product       integer,
	manual           tinyint DEFAULT 0 NOT NULL,
	ignored          tinyint DEFAULT 0 NOT NULL,
	active           tinyint DEFAULT 1 NOT NULL,
	reason           varchar(32),
	buffer           integer,
	last_pushed_qty  integer,
	last_x_version   varchar(64),
	last_push_at     datetime,
	last_error       varchar(255),
	date_creation    datetime NOT NULL,
	date_seen        datetime,
	tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
