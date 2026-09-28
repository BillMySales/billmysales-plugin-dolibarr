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

-- One row per invoice with a delivery still pending (removed once it's no
-- longer pending: not a growing log).
CREATE TABLE llx_billmysales_delivery_queue(
	id_queue INTEGER AUTO_INCREMENT PRIMARY KEY,
	id_facture INTEGER NOT NULL,
	event VARCHAR(32) NOT NULL,
	delivery_id VARCHAR(36) NOT NULL,
	attempt INTEGER NOT NULL DEFAULT 0,
	next_attempt_at DATETIME NOT NULL,
	created_at DATETIME NOT NULL
) ENGINE=innodb;
