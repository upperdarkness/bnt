<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiAuth;
use BNT\Core\ApiResponse;
use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\ShipType;

class ApiAuthController
{
    public function __construct(
        private Ship $shipModel,
        private ApiAuth $apiAuth,
        private Session $session,
        private array $config
    ) {}
    
    /**
     * POST /api/v1/auth/login
     * Login and get API token
     */
    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        
        if (!$email || !$password) {
            ApiResponse::validationError([
                'email' => 'Email is required',
                'password' => 'Password is required'
            ]);
        }
        
        $ship = $this->shipModel->authenticate($email, $password);
        
        if (!$ship) {
            ApiResponse::error('Invalid email or password', 'INVALID_CREDENTIALS', 401);
        }
        
        if ($ship['ship_destroyed']) {
            if (!$ship['dev_escapepod']) {
                ApiResponse::error('Ship destroyed. Please create a new account.', 'SHIP_DESTROYED', 403);
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
            // Respawn shield: a short breather after being rebuilt (once per cooldown)
            if (!empty($this->config['protection']['enabled'])) {
                $this->shipModel->grantRespawnShield(
                    (int)$ship['ship_id'],
                    (int)$this->config['protection']['respawn_shield_hours'],
                    (int)$this->config['protection']['respawn_shield_cooldown_days']
                );
            }
            
            // Reload ship after respawn
            $ship = $this->shipModel->find((int)$ship['ship_id']);
        }
        
        // Generate API token
        $tokenData = $this->apiAuth->generateToken((int)$ship['ship_id'], 'iPhone App');
        
        // Remove sensitive data
        unset($ship['password_hash']);
        
        ApiResponse::success([
            'token' => $tokenData['token'],
            'expires_at' => $tokenData['expires_at'],
            'ship' => $ship
        ]);
    }
    
    /**
     * POST /api/v1/auth/register
     * Register new account and get API token
     */
    public function register(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $characterName = $data['character_name'] ?? null;
        $shipType = $data['ship_type'] ?? ShipType::BALANCED;
        
        // Validation
        $errors = [];
        
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email is required';
        }
        
        if (!$password || strlen($password) < $this->config['security']['password_min_length']) {
            $errors['password'] = 'Password must be at least ' . $this->config['security']['password_min_length'] . ' characters';
        }
        
        if (!$characterName || strlen($characterName) < 3) {
            $errors['character_name'] = 'Character name must be at least 3 characters';
        }
        
        if (!ShipType::isValid($shipType)) {
            $shipType = ShipType::BALANCED;
        }
        
        if (!empty($errors)) {
            ApiResponse::validationError($errors);
        }
        
        // Check duplicates
        if ($this->shipModel->findByEmail($email)) {
            ApiResponse::error('Email already registered', 'EMAIL_EXISTS', 409);
        }
        
        if ($this->shipModel->findByName($characterName)) {
            ApiResponse::error('Character name already taken', 'NAME_EXISTS', 409);
        }
        
        try {
            $shipId = $this->shipModel->register(
                $email,
                $password,
                $characterName,
                $this->config['game'],
                $shipType
            );
            $this->shipModel->recordSignup($shipId, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_X_DEVICE_ID'] ?? ($_POST['device_id'] ?? null));
            
            $ship = $this->shipModel->find($shipId);
            $tokenData = $this->apiAuth->generateToken($shipId, 'iPhone App');
            
            unset($ship['password_hash']);
            
            ApiResponse::success([
                'token' => $tokenData['token'],
                'expires_at' => $tokenData['expires_at'],
                'ship' => $ship
            ], 'Registration successful', 201);
        } catch (\Exception $e) {
            ApiResponse::error('Registration failed', 'REGISTRATION_ERROR', 500);
        }
    }
    
    /**
     * POST /api/v1/auth/logout
     * Revoke current API token
     */
    public function logout(): void
    {
        $token = $this->apiAuth->getTokenFromRequest();
        
        if ($token) {
            $this->apiAuth->revokeToken($token);
        }
        
        ApiResponse::success(null, 'Logged out successfully');
    }
    
    /**
     * GET /api/v1/auth/me
     * Get current authenticated user
     */
    public function me(): void
    {
        $token = $this->apiAuth->getTokenFromRequest();
        
        if (!$token) {
            ApiResponse::unauthorized();
        }
        
        $ship = $this->apiAuth->validateToken($token);
        
        if (!$ship) {
            ApiResponse::unauthorized();
        }
        
        unset($ship['password_hash']);
        
        ApiResponse::success(['ship' => $ship]);
    }
}

