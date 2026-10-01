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
        'starbase_percentage' => 5.0, // Percentage of ports that should be starbases (5% default)
    ],
    
    // Starbase Configuration
    'starbase' => [
        'fighter_price' => 50,      // Price per fighter
        'torpedo_price' => 100,     // Price per torpedo
        'max_hull_level' => 5,      // Ships with hull above this level are towed out of sector 1
        'emergency_warp_price' => 50000, // Price for Emergency Warp Drive device
        'mine_deflector_price' => 25000, // Price for Mine Deflector device
    ],

    // Security Configuration
    'security' => [
        'session_lifetime' => 3600, // 1 hour
        'password_min_length' => 8,
        'password_max_length' => 72, // bcrypt limit
        // bcrypt hash of the default password 'secret' - CHANGE THIS (php -r "echo password_hash('your_password', PASSWORD_DEFAULT);").
        // A literal (rather than calling password_hash() here) avoids ~200 ms of bcrypt work on every request.
        'admin_password' => getenv('ADMIN_PASSWORD_HASH') ?: '$2y$10$V/K7PJK7mD2D6eJpk08dqO0ZnWRuK89TIAI5VRyiz8H7Uylw.PORa',
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

    // Per planet-production cycle. These are configurable modern defaults.
    'planet_economy' => [
        'production_rate' => 0.01,
        'growth_rate' => 0.0005,
        'food_per_colonist' => 0.001,
        'starvation_rate' => 0.01,
        'tax_per_colonist' => 0.001,
        'interest_rate' => 0.0005,
        'population_limit' => 100000000,
        'unbased_credit_limit' => 10000000,
        'based_credit_limit' => 100000000000,
    ],

    // Alignment & Federation enforcement. All thresholds/percentages are starting values.
    'alignment' => [
        'enabled' => filter_var(getenv('ALIGNMENT_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'min' => -10000,
        'max' => 10000,
        // Lower bound of each tier (Pirate is everything at or below outlaw_min - 1).
        'tiers' => ['paragon' => 1000, 'lawful' => 100, 'neutral' => -99, 'outlaw' => -999],
        'starbase_paragon_discount_pct' => 5,
        'starbase_outlaw_surcharge_pct' => 15,
        'paragon_bounty_multiplier' => 1.25,
        'deltas' => [
            'attack_lawful' => -100,       // attack a ship whose owner is Neutral or better
            'destroy_lawful' => -250,      // in addition to the attack penalty
            'attack_outlaw' => 50,
            'destroy_outlaw' => 150,
            'capture_lawful_planet' => -150,
            'capture_outlaw_planet' => 50,
            'fedspace_hostile' => -500,    // also sets Wanted
            'destroy_pirate_npc' => 100,   // replaces destroy_outlaw for Xenobe NPCs
            'destroy_trader_npc' => -300,  // replaces destroy_lawful for Guild/Police/Free NPCs
        ],
        'trade_credits_per_point' => 10000,
        'trade_daily_cap' => 20,
        'drift_pct_per_day' => (int)(getenv('ALIGNMENT_DRIFT_PCT') ?: 1),
        'wanted_days' => (int)(getenv('ALIGNMENT_WANTED_DAYS') ?: 7),
        'fine_restore_to' => -99,
        'fine_credits_per_point' => 100,   // fine = points below 0 * this (min fine_minimum)
        'fine_minimum' => 10000,
        'bounty_credits_per_100_points' => 1000,
        'player_bounty_minimum' => 10000,
        'police_points_per_unit' => 500,
        'police_max_per_offender' => 5,
        'police_return_hours' => 48,
    ],

    // NPC framework and LLM agent interface
    'npc' => [
        'enabled' => filter_var(getenv('NPC_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'llm_enabled' => filter_var(getenv('NPC_LLM_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'wake_interval_min' => 10,
        'min_turns_to_wake' => 10,
        'max_steps' => 8,
        'event_debounce_min' => 5,
        'daily_budget_usd' => (float)(getenv('NPC_DAILY_BUDGET_USD') ?: 1.00),
        'wake_budget_usd' => (float)(getenv('NPC_WAKE_BUDGET_USD') ?: 0.25),
        // Used only when OpenRouter does not report a cost: deliberately conservative USD per million tokens.
        'price_per_million' => ['input' => 3.0, 'output' => 15.0],
        'global_daily_budget_usd' => (float)(getenv('NPC_GLOBAL_DAILY_BUDGET_USD') ?: 10.00),
        'default_model' => getenv('NPC_DEFAULT_MODEL') ?: '',   // OpenRouter model id; set by admin
        'default_fallback_models' => [],
        'show_in_rankings' => false,
        'log_retention_days' => 30,
        'worker_checkin_timeout_min' => 15,
        'llm_failure_threshold' => 3,
        'respawn_cooldown_hours' => 6,
        // Explicit NPC advantage (visible on the admin page): hulls and fighters restored in the faction's home zone.
        'repair_at_home' => true,
        // NPC wealth sink: credits above (faction loadout credits x this) are taxed away by the Federation each
        // population run, so successful traders do not compound without bound. 0 disables the cap.
        'credit_cap_multiplier' => 20,
        'raider_attack_cooldown_min' => 30,   // minimum gap between a Xenobe Raider's attacks
        'scripted_tick_max_npcs' => 25,
        'scripted_tick_max_ms' => 200,
        'scripted_tick_margin_ms' => 10,   // don't start an action with less than this left in the budget
        'graph_cache_seconds' => 600,
        'graph_cache_path' => getenv('NPC_GRAPH_CACHE') ?: sys_get_temp_dir() . '/bnt_sector_graph.json',
        'max_path_depth' => 20,
        'message_limit_per_hour' => 5,
        'message_max_chars' => 280,
        'notebook_max_chars' => 2000,
        // NPC API tokens are only accepted from these addresses.
        'worker_ips' => array_filter(array_map('trim', explode(',', getenv('NPC_WORKER_IPS') ?: '127.0.0.1,::1'))),
        'token_lifetime_days' => 30,
        'alert_email' => getenv('NPC_ALERT_EMAIL') ?: '',
        'openrouter_url' => 'https://openrouter.ai/api/v1/chat/completions',
        'api_base_url' => getenv('NPC_API_BASE_URL') ?: 'http://localhost:8000/api/v1',
        'prompt_dir' => __DIR__ . '/npc_prompts',
        // faction => template. count_per_1000 scales with universe size.
        'factions' => [
            'police' => ['label' => 'Federation Police', 'tag' => 'Fed', 'archetype' => 'police', 'alignment' => 5000, 'count_per_1000' => 4,
                'home_zone' => 2, 'turn_multiplier' => 1.0,
                'loadout' => ['hull' => 6, 'engines' => 6, 'beams' => 7, 'shields' => 7, 'armor' => 6, 'computer' => 5, 'ship_fighters' => 800, 'torps' => 100, 'credits' => 100000, 'ship_type' => 'warship']],
            'guild' => ['label' => 'Merchant Guild', 'tag' => 'Guild', 'archetype' => 'trader', 'alignment' => 500, 'count_per_1000' => 10,
                'home_zone' => 1, 'turn_multiplier' => 1.0,
                'loadout' => ['hull' => 4, 'engines' => 4, 'beams' => 2, 'shields' => 3, 'armor' => 3, 'computer' => 2, 'ship_fighters' => 100, 'torps' => 0, 'credits' => 50000, 'ship_type' => 'merchant', 'skill_trading' => 20]],
            'xenobe' => ['label' => 'Xenobe Raiders', 'tag' => 'Xenobe', 'archetype' => 'raider', 'alignment' => -3000, 'count_per_1000' => 6,
                'home_zone' => 4, 'turn_multiplier' => 1.0,
                'loadout' => ['hull' => 5, 'engines' => 5, 'beams' => 6, 'shields' => 5, 'armor' => 5, 'computer' => 4, 'ship_fighters' => 500, 'torps' => 60, 'credits' => 30000, 'ship_type' => 'warship']],
            'free' => ['label' => 'Free Captains', 'tag' => 'Free', 'archetype' => 'free_captain', 'alignment' => 0, 'count_per_1000' => 4,
                'home_zone' => 3, 'turn_multiplier' => 1.0,
                'loadout' => ['hull' => 4, 'engines' => 4, 'beams' => 4, 'shields' => 4, 'armor' => 4, 'computer' => 3, 'ship_fighters' => 200, 'torps' => 20, 'credits' => 50000, 'ship_type' => 'balanced', 'skill_trading' => 10]],
        ],
    ],

    // API behaviour
    'api' => [
        // Phase 2 switches: the extended game endpoints (trade/attack/defences/...) and rate limiting.
        'extended_enabled' => filter_var(getenv('API_EXTENDED_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'rate_limit_enabled' => filter_var(getenv('API_RATE_LIMIT_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        // Token bucket per API token: requests per minute.
        'rate_limit_player_per_min' => 60,
        'rate_limit_npc_per_min' => 30,
        'player_message_limit_per_hour' => 30,
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
