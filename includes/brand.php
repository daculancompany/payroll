<?php
/**
 * Product brand — one place. Shown in the v2 sidebar, the sign-in screen,
 * browser tabs and (via manifest-admin.webmanifest) the installed app.
 * Override per installation with APP_BRAND / APP_TAGLINE in the server
 * environment (e.g. SetEnv APP_BRAND "Acme HR") for white-label setups.
 */
if (!defined('APP_BRAND'))   define('APP_BRAND',   getenv('APP_BRAND')   ?: 'Kawani HR');
if (!defined('APP_TAGLINE')) define('APP_TAGLINE', getenv('APP_TAGLINE') ?: 'From time-in to payday.');
