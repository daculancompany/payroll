<?php
// Template for db_config.php — copy this file to db_config.php on each server
// and fill in that server's DB settings. db_config.php is git-ignored and is
// never part of a deploy, so the live credentials survive every code upload.
// Environment variables DB_HOST / DB_USER / DB_PASS / DB_NAME still win if set.
return [
    'host' => 'localhost',
    'user' => 'your_db_user',
    'pass' => 'your_db_password',
    'name' => 'your_db_name',
];
