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

-- A Mercado Libre sale (a pack, or a single order when it has no pack) and the Dolibarr order made
-- from it. oversold = 1: when it was imported, the stock was already taken by another sale.
CREATE TABLE llx_meli_order(
	rowid              integer AUTO_INCREMENT PRIMARY KEY,
	entity             integer DEFAULT 1 NOT NULL,
	pack_id            varchar(32) NOT NULL,
	order_ids          varchar(255) NOT NULL,
	fk_commande        integer,
	fk_facture         integer,
	ml_status          varchar(32),
	buyer_id           bigint,
	shipment_id        varchar(32),
	shipment_status    varchar(32),
	shipment_substatus varchar(64),
	logistic_type      varchar(32),
	label_file         varchar(255),
	total_paid         double(24,8),
	sale_fee           double(24,8),
	shipping_cost      double(24,8),
	oversold           tinyint DEFAULT 0 NOT NULL,
	needs_review       tinyint DEFAULT 0 NOT NULL,
	note               varchar(255),
	date_creation      datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
