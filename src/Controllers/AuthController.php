<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\ShipType;

class AuthController
{
    public function __construct(
        private Ship $shipModel,
        private Session $session,
        private array $config
    ) {}

    public function showLogin(): string
    {
        if ($this->session->isLoggedIn()) {
            header('Location: /main');
            exit;
        }

        ob_start();
        include __DIR__ . '/../Views/login.php';
        return ob_get_clean();
    }

    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /');
            exit;
        }

        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $this->session->set('error', 'Email and password are required');
            header('Location: /');
            exit;
        }

        $ship = $this->shipModel->authenticate($email, $password);

        if (!$ship) {
            $this->session->set('error', 'Invalid email or password');
            header('Location: /');
            exit;
        }

        if ($ship['ship_destroyed']) {
            if (!$ship['dev_escapepod']) {
                $this->session->set('error', 'Your ship has been destroyed. Please create a new account.');
                header('Location: /');
                exit;
            }

            // Respawn with escape pod
            $this->shipModel->update((int)$ship['ship_id'], [
                'hull' => 0,
                'engines' => 0,
                'power' => 0,
                'computer' => 0,
                'sensors' => 0,
                'beams' => 0,
                'torp_launchers' => 0,
                'shields' => 0,
                'armor' => 0,
                'cloak' => 0,
                'torps' => 0,
                'armor_pts' => 100,
                'ship_ore' => 0,
                'ship_organics' => 0,
                'ship_goods' => 0,
                'ship_energy' => 1000,
                'ship_colonists' => 0,
                'ship_fighters' => 100,
                'ship_damage' => 0,
                'ship_destroyed' => false,
                'dev_escapepod' => false,
                'sector' => 1,
            ]);

            $this->session->set('message', 'Your escape pod has saved you! Starting over...');
        }

        $this->session->setUserId((int)$ship['ship_id']);
        header('Location: /main');
        exit;
    }

    public function register(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /');
            exit;
        }

        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        $characterName = filter_input(INPUT_POST, 'character_name', FILTER_SANITIZE_STRING);
        $shipType = $_POST['ship_type'] ?? ShipType::BALANCED;

        // Validation
        if (!ShipType::isValid($shipType)) {
            $shipType = ShipType::BALANCED;
        }

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->set('error', 'Valid email is required');
            header('Location: /');
            exit;
        }

        if (strlen($password) < $this->config['security']['password_min_length']) {
            $this->session->set('error', 'Password must be at least ' . $this->config['security']['password_min_length'] . ' characters');
            header('Location: /');
            exit;
        }

        if (!$characterName || strlen($characterName) < 3) {
            $this->session->set('error', 'Character name must be at least 3 characters');
            header('Location: /');
            exit;
        }

        // Check if email exists
        if ($this->shipModel->findByEmail($email)) {
            $this->session->set('error', 'Email already registered');
            header('Location: /');
            exit;
        }

        // Check if character name exists
        if ($this->shipModel->findByName($characterName)) {
            $this->session->set('error', 'Character name already taken');
            header('Location: /');
            exit;
        }

        try {
            $shipId = $this->shipModel->register(
                $email,
                $password,
                $characterName,
                $this->config['game'],
                $shipType
            );

            $shipTypeName = ShipType::getInfo($shipType)['name'];
            $this->session->setUserId($shipId);
            $this->session->set('message', "Welcome to BlackNova Traders! Your {$shipTypeName} is ready for launch.");
            header('Location: /main');
            exit;
        } catch (\Exception $e) {
            $this->session->set('error', 'Registration failed. Please try again.');
            header('Location: /');
            exit;
        }
    }

    public function logout(): void
    {
        $this->session->logout();
        header('Location: /');
        exit;
    }
}
