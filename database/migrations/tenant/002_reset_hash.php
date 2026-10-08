<?php
// password reset codes/tokens are stored hashed (sha256 hex)
return function (mysqli $db) {
    if (Migrator::tableExists($db, 'password_resets')) {
        $db->query("ALTER TABLE password_resets MODIFY COLUMN code VARCHAR(64) NOT NULL");
        $db->query("UPDATE password_resets SET used = 1 WHERE used = 0"); // retire any plaintext codes still outstanding
    }
};
