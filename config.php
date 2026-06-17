<?php
// config.php — Database connection settings
// Place this file OUTSIDE your web root in production!

define('DB_HOST', 'localhost');
define('DB_NAME', 'review_nautilus');
define('DB_USER', 'ivistaz');        // create a dedicated DB user
define('DB_PASS', 'e0D^L56D2xpp#09$$');   // replace with strong password
define('DB_CHARSET', 'utf8mb4');

define('FORM_CODE', 'JSW');           // Unique code for this form set

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
