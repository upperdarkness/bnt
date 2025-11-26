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
use BNT\Models\Combat;
use BNT\Models\Bounty;
use BNT\Controllers\AuthController;
use BNT\Controllers\GameController;
use BNT\Controllers\PortController;
use BNT\Controllers\CombatController;
use BNT\Controllers\PlanetController;

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
$combatModel = new Combat($db);
$bountyModel = new Bounty($db);

// Initialize controllers
$authController = new AuthController($shipModel, $session, $config);
$gameController = new GameController($shipModel, $universeModel, $planetModel, $combatModel, $session, $config);
$portController = new PortController($shipModel, $universeModel, $session, $config);
$combatController = new CombatController($shipModel, $universeModel, $planetModel, $combatModel, $session, $config);
$planetController = new PlanetController($shipModel, $planetModel, $session, $config);

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

$router->get('/combat', fn() => $combatController->show());
$router->post('/combat/attack/ship/:id', fn($id) => $combatController->attackShip((int)$id));
$router->post('/combat/attack/planet/:id', fn($id) => $combatController->attackPlanet((int)$id));
$router->post('/combat/deploy', fn() => $combatController->deployDefense());

$router->get('/defenses', fn() => $combatController->viewDefenses());
$router->post('/defenses/retrieve', fn() => $combatController->retrieveDefense());

$router->get('/planets', fn() => $planetController->listPlanets());
$router->get('/planet/manage/:id', fn($id) => $planetController->manage((int)$id));
$router->post('/planet/colonize/:id', fn($id) => $planetController->colonize((int)$id));
$router->post('/planet/transfer/:id', fn($id) => $planetController->transfer((int)$id));
$router->post('/planet/production/:id', fn($id) => $planetController->updateProduction((int)$id));
$router->post('/planet/base/:id', fn($id) => $planetController->buildBase((int)$id));

// Dispatch request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

try {
    $router->dispatch($method, $uri);
} catch (Exception $e) {
    http_response_code(500);
    echo '<h1>Error</h1><p>An error occurred: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
