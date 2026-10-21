<?php

require dirname(__DIR__).'/vendor/autoload.php';

// The default test database is a SQLite file in var/ (see phpunit.xml.dist); SQLite creates the file but
// not its directory, which may not exist yet on a fresh checkout.
if (!is_dir($varDir = dirname(__DIR__).'/var')) {
    mkdir($varDir, 0777, true);
}
