<?php

define('APP_URL', 'https://chat.mdriaz.com.bd');
define('FORCE_HTTPS', FALSE);

define('DB_HOST', 'localhost');
define('DB_TYPE', 'mysql'); // mysql or pgsql
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'chat');

define('SITE_TITLE', 'Team Chat');
define('APP_MODE', 'Debug'); //Values (Debug OR Live)
define('TIMEZONE', 'Asia/Dhaka');
define('ALLOWED_ORIGINS', ['*']);

define('ALLOW_FORGET_PASSWORD', TRUE);
define('ALLOW_REGISTRATION', TRUE);
define('TOKEN_EXPIRATION', '-60 minutes');

define('PAGINATION_LIMIT', 20);

setlocale(LC_MONETARY, 'en_IN');
