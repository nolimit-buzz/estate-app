<?php
// Payments ledger immutability (triggers can't be multi-statement in the plain SQL runner).
return function (mysqli $db) {
    $db->query("DROP TRIGGER IF EXISTS trg_payments_no_update");
    $db->query("CREATE TRIGGER trg_payments_no_update BEFORE UPDATE ON payments FOR EACH ROW
        BEGIN
          IF OLD.status <> 'pending' OR NEW.estate_id <> OLD.estate_id OR NEW.amount <> OLD.amount OR NEW.reference <> OLD.reference OR NEW.entry_type <> OLD.entry_type THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments ledger is immutable';
          END IF;
        END");
    $db->query("DROP TRIGGER IF EXISTS trg_payments_no_delete");
    $db->query("CREATE TRIGGER trg_payments_no_delete BEFORE DELETE ON payments FOR EACH ROW
        BEGIN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments ledger is immutable';
        END");
};
