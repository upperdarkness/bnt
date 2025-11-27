<?php

declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;
use BNT\Core\Router;
use BNT\Core\Session;
use BNT\Core\AdminAuth;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Models\Combat;
use BNT\Models\Bounty;
use BNT\Models\Team;
use BNT\Models\Message;
use BNT\Controllers\AuthController;
use BNT\Controllers\GameController;
use BNT\Controllers\PortController;
use BNT\Controllers\CombatController;
use BNT\Controllers\PlanetController;
use BNT\Controllers\TeamController;
use BNT\Controllers\MessageController;
use BNT\Controllers\AdminController;

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Initialize core components
$db = new Database($config);
$session = new Session();
$router = new Router();
$adminAuth = new AdminAuth($session, $config);

// Initialize models
$shipModel = new Ship($db);
$universeModel = new Universe($db);
$planetModel = new Planet($db);
$combatModel = new Combat($db);
$bountyModel = new Bounty($db);
$teamModel = new Team($db);
$messageModel = new Message($db);

// Initialize controllers
$authController = new AuthController($shipModel, $session, $config);
$gameController = new GameController($shipModel, $universeModel, $planetModel, $combatModel, $session, $config);
$portController = new PortController($shipModel, $universeModel, $session, $config);
$combatController = new CombatController($shipModel, $universeModel, $planetModel, $combatModel, $session, $config);
$planetController = new PlanetController($shipModel, $planetModel, $session, $config);
$teamController = new TeamController($shipModel, $teamModel, $session, $config);
$messageController = new MessageController($shipModel, $messageModel, $session, $config);
$adminController = new AdminController($shipModel, $universeModel, $planetModel, $teamModel, $session, $adminAuth, $config);

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

$router->get('/teams', fn() => $teamController->index());
$router->get('/teams/create', fn() => $teamController->create());
$router->post('/teams/store', fn() => $teamController->store());
$router->get('/teams/:id', fn($id) => $teamController->show((int)$id));
$router->post('/teams/leave', fn() => $teamController->leave());
$router->post('/teams/members/:id/kick', fn($id) => $teamController->kick((int)$id));
$router->post('/teams/:id/invite', fn($id) => $teamController->invite());
$router->post('/teams/invitations/:id/accept', fn($id) => $teamController->acceptInvitation((int)$id));
$router->post('/teams/invitations/:id/decline', fn($id) => $teamController->declineInvitation((int)$id));
$router->post('/teams/:id/messages', fn($id) => $teamController->postMessage((int)$id));
$router->post('/teams/:id/update', fn($id) => $teamController->update((int)$id));
$router->post('/teams/:id/disband', fn($id) => $teamController->disband((int)$id));

$router->get('/messages', fn() => $messageController->inbox());
$router->get('/messages/sent', fn() => $messageController->sent());
$router->get('/messages/compose', fn() => $messageController->compose());
$router->post('/messages/send', fn() => $messageController->send());
$router->get('/messages/view/:id', fn($id) => $messageController->view((int)$id));
$router->post('/messages/:id/delete', fn($id) => $messageController->delete((int)$id));
$router->post('/messages/mark-all-read', fn() => $messageController->markAllRead());

$router->get('/admin/login', fn() => $adminController->showLogin());
$router->post('/admin/login', fn() => $adminController->login());
$router->get('/admin/logout', fn() => $adminController->logout());
$router->get('/admin', fn() => $adminController->dashboard());
$router->get('/admin/players', fn() => $adminController->players());
$router->get('/admin/players/:id/edit', fn($id) => $adminController->editPlayer((int)$id));
$router->post('/admin/players/:id/update', fn($id) => $adminController->updatePlayer((int)$id));
$router->post('/admin/players/:id/delete', fn($id) => $adminController->deletePlayer((int)$id));
$router->get('/admin/teams', fn() => $adminController->teams());
$router->post('/admin/teams/:id/delete', fn($id) => $adminController->deleteTeam((int)$id));
$router->get('/admin/universe', fn() => $adminController->universe());
$router->post('/admin/universe/regenerate', fn() => $adminController->regenerateUniverse());
$router->get('/admin/settings', fn() => $adminController->settings());
$router->get('/admin/logs', fn() => $adminController->logs());
$router->get('/admin/statistics', fn() => $adminController->statistics());

// Dispatch request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

try {
    $router->dispatch($method, $uri);
} catch (Exception $e) {
    http_response_code(500);
    echo '<h1>Error</h1><p>An error occurred: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
