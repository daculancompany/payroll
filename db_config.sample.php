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

    // Mapbox token for the map on Kiosk Devices → Site attendance areas.
    // Optional: leave '' and admins type the coordinates instead.
    // Use a PUBLIC token (starts with "pk.") from account.mapbox.com, and in
    // the Mapbox dashboard give it URL restrictions for this site's domain —
    // a public token is sent to the admin's browser, so the URL restriction is
    // what stops anyone else spending your quota with it.
    // NEVER a secret "sk." token: the app refuses to send one to a browser.
    // The MAPBOX_TOKEN environment variable wins if set.
    'mapbox_token' => '',
];
