<?php

declare(strict_types=1);

return [
    // Database Configuration
    'database' => [
        'driver' => 'pgsql',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int)(getenv('DB_PORT') ?: 5432),
        'database' => getenv('DB_NAME') ?: 'blacknova',
        'username' => getenv('DB_USER') ?: 'bnt',
        'password' => getenv('DB_PASS') ?: 'bnt',
        'charset' => 'utf8',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    // Game Configuration
    'game' => [
        'name' => 'BlackNova Traders',
        'version' => '2.0.0',
        'max_turns' => 2500,
        'start_turns' => 1200,
        'start_credits' => 1000,
        'start_energy' => 100,
        'start_fighters' => 10,
        'start_armor' => 10,
        'universe_size' => 200,
        'sector_max' => 5000,
        'max_links' => 10,
        'min_bases_to_own' => 3,
        'base_credits' => 10000000,
        'base_ore' => 10000,
        'base_organics' => 10000,
        'base_goods' => 10000,
    ],

    // Security Configuration
    'security' => [
        'session_lifetime' => 3600, // 1 hour
        'password_min_length' => 8,
        'password_max_length' => 72, // bcrypt limit
        'admin_password' => password_hash('secret', PASSWORD_DEFAULT), // Change this!
    ],

    // Scheduler Configuration
    'scheduler' => [
        'ticks' => 6, // minutes between scheduler runs
        'turns' => 2, // New turns rate
        'ports' => 2, // Port production
        'planets' => 2, // Planet production
        'igb' => 2, // IGB interest
        'ranking' => 30, // Rankings generation
        'news' => 15, // News generation
        'degrade' => 6, // Fighter degradation
    ],

    // Trading Configuration
    'trading' => [
        'ore' => [
            'price' => 11,           // Base price
            'delta' => 5,            // Price factor (max price variation)
            'rate' => 75000,         // Reference rate (legacy, kept for compatibility)
            'limit' => 100000000,    // Max capacity
            'regeneration_rate' => 0.05,  // 5% of empty space regenerated per tick
            'consumption_rate' => 0.02,   // 2% consumed per tick
        ],
        'organics' => [
            'price' => 5,
            'delta' => 2,
            'rate' => 5000,
            'limit' => 100000000,
            'regeneration_rate' => 0.05,
            'consumption_rate' => 0.02,
        ],
        'goods' => [
            'price' => 15,
            'delta' => 7,
            'rate' => 75000,
            'limit' => 100000000,
            'regeneration_rate' => 0.05,
            'consumption_rate' => 0.02,
        ],
        'energy' => [
            'price' => 3,
            'delta' => 1,
            'rate' => 75000,
            'limit' => 1000000000,
            'regeneration_rate' => 0.05,
            'consumption_rate' => 0.02,
        ],
    ],
];
