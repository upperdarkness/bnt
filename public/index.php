<?php

declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;
use BNT\Core\Router;
use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Controllers\AuthController;
use BNT\Controllers\GameController;
use BNT\Controllers\PortController;

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Initialize core components
$db = new Database($config);
$session = new Session();
$router = new Router();

// Initialize models
$shipModel = new Ship($db);
$universeModel = new Universe($db);
$planetModel = new Planet($db);

// Initialize controllers
$authController = new AuthController($shipModel, $session, $config);
$gameController = new GameController($shipModel, $universeModel, $planetModel, $session, $config);
$portController = new PortController($shipModel, $universeModel, $session, $config);

// Define routes
$router->get('/', fn() => $authController->showLogin());
$router->post('/login', fn() => $authController->login());
$router->post('/register', fn() => $authController->register());
$router->get('/logout', fn() => $authController->logout());

$router->get('/main', fn() => $gameController->main());
$router->post('/move/:sector', fn($sector) => $gameController->move((int)$sector));
$router->get('/scan', fn() => $gameController->scan());
$router->get('/status', fn() => $gameController->status());
$router->get('/planet/:id', fn($id) => $gameController->planet((int)$id));
$router->post('/land/:id', fn($id) => $gameController->landOnPlanet((int)$id));

$router->get('/port', fn() => $portController->show());
$router->post('/port/trade', fn() => $portController->trade());

// Dispatch request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

try {
    $router->dispatch($method, $uri);
} catch (Exception $e) {
    http_response_code(500);
    echo '<h1>Error</h1><p>An error occurred: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
