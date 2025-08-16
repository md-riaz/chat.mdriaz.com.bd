<?php

require_once __DIR__ . '/../framework/core/Framework.php';

use Ratchet\App;
use App\ChatServer;

// Initialize framework for autoloading
Framework::run();

$port = isset($argv[1]) ? (int)$argv[1] : 8080;
$host = isset($argv[2]) ? $argv[2] : 'localhost';

echo "Starting WebSocket Chat Server on {$host}:{$port}\n";

$app = new App($host, $port, '0.0.0.0');

// Route WebSocket connections to our chat server
$app->route('/chat', new ChatServer, ['*']);

// Start the server
$app->run();
