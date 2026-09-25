-- A faulty item can be repaired and put back into service. The repair is its
-- own ledger row (qty 0, with a note), so the history keeps both the fault
-- and the fix. Appending a value to the end of an ENUM only changes table
-- metadata in MySQL 8, so this is instant however many movements exist.

ALTER TABLE movements
    MODIFY type ENUM('receipt', 'consume', 'checkout', 'return', 'faulty', 'repaired') NOT NULL;
