<?php

// Load environment-specific configuration
$env_file = __DIR__ . '/../.env.local';
if (file_exists($env_file)) {
    $env_vars = parse_ini_file($env_file, false, INI_SCANNER_TYPED);
    foreach ($env_vars as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Application Configuration
define('APP_URL', $_ENV['APP_URL'] ?? 'http://chat.mdriaz.local');
define('FORCE_HTTPS', $_ENV['FORCE_HTTPS'] ?? FALSE);

// Database Configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_TYPE', $_ENV['DB_TYPE'] ?? 'mysql'); // mysql or pgsql
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'chat');
define('DB_PORT', $_ENV['DB_PORT'] ?? 3306);

// Redis Configuration
define('REDIS_HOST', $_ENV['REDIS_HOST'] ?? '127.0.0.1');
define('REDIS_PORT', $_ENV['REDIS_PORT'] ?? 6379);
define('REDIS_PASSWORD', $_ENV['REDIS_PASSWORD'] ?? '');
define('REDIS_DATABASE', $_ENV['REDIS_DATABASE'] ?? 0);

// WebSocket Configuration
define('WEBSOCKET_HOST', $_ENV['WEBSOCKET_HOST'] ?? 'localhost');
define('WEBSOCKET_PORT', $_ENV['WEBSOCKET_PORT'] ?? 8080);

// Application Settings
define('SITE_TITLE', $_ENV['SITE_TITLE'] ?? 'Team Chat');
define('APP_MODE', $_ENV['APP_MODE'] ?? 'Debug'); //Values (Debug OR Live)
define('TIMEZONE', $_ENV['TIMEZONE'] ?? 'Asia/Dhaka');
define('ALLOWED_ORIGINS', $_ENV['ALLOWED_ORIGINS'] ?? ['http://chat.mdriaz.local', 'http://localhost', 'http://127.0.0.1']);

define('ALLOW_FORGET_PASSWORD', $_ENV['ALLOW_FORGET_PASSWORD'] ?? TRUE);
define('ALLOW_REGISTRATION', $_ENV['ALLOW_REGISTRATION'] ?? TRUE);
define('TOKEN_EXPIRATION', $_ENV['TOKEN_EXPIRATION'] ?? '-60 minutes');

define('PAGINATION_LIMIT', $_ENV['PAGINATION_LIMIT'] ?? 20);

setlocale(LC_MONETARY, 'en_IN');

// Uploads directory
define('UPLOADS_DIR', __DIR__ . '/../uploads');
if (!is_dir(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0755, true);
}

// Logs directory
define('LOGS_DIR', __DIR__ . '/../logs');
if (!is_dir(LOGS_DIR)) {
    mkdir(LOGS_DIR, 0755, true);
}
