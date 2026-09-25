-- Current state, derived from the ledger. Nothing here is stored; both views
-- are recomputed from `movements` on every read.

-- A gear item's status is the type of its most recent movement.
-- The correlated MAX(id) is one index seek on idx_movements_gear per item.
CREATE VIEW gear_item_status AS
SELECT gi.id AS gear_item_id,
       gi.gear_type_id,
       gi.serial,
       CASE m.type WHEN 'checkout' THEN 'out' WHEN 'faulty' THEN 'faulty' ELSE 'available' END AS status,
       IF(m.type = 'checkout', m.job_id, NULL) AS job_id,
       m.created_at AS since
FROM gear_items gi
JOIN movements m ON m.id = (SELECT MAX(id) FROM movements WHERE gear_item_id = gi.id);

-- A lot's on-hand quantity is the sum of its signed movements.
CREATE VIEW lot_balances AS
SELECT l.id AS lot_id,
       l.consumable_id,
       l.lot_code,
       l.received_on,
       CAST(COALESCE(SUM(m.qty), 0) AS SIGNED) AS on_hand
FROM lots l
LEFT JOIN movements m ON m.lot_id = l.id
GROUP BY l.id;
