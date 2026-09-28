-- Copyright (C) 2026 BillMySales <https://www.billmysales.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU Affero General Public License as
-- published by the Free Software Foundation, either version 3 of the
-- License, or (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU Affero General Public License for more details.
--
-- You should have received a copy of the GNU Affero General Public
-- License along with this program.  If not, see
-- https://www.gnu.org/licenses/.

-- One row per invoice that has ever had a delivery attempt (replaced on
-- each new attempt, bounded by the shop's invoice count, not by attempts).
CREATE TABLE llx_billmysales_order_status(
	id_facture INTEGER NOT NULL PRIMARY KEY,
	status VARCHAR(16) NOT NULL,
	detail VARCHAR(255) NOT NULL DEFAULT '',
	event VARCHAR(32) NOT NULL DEFAULT '',
	delivery_id VARCHAR(36) NOT NULL DEFAULT '',
	attempts INTEGER NOT NULL DEFAULT 0,
	updated_at DATETIME NOT NULL
) ENGINE=innodb;
