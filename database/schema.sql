-- BlackNova Traders - PostgreSQL Schema
-- Modern version with proper constraints and indexes

-- Ships (Players)
CREATE TABLE IF NOT EXISTS ships (
    ship_id SERIAL PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    character_name VARCHAR(50) NOT NULL UNIQUE,
    ship_name VARCHAR(50),

    -- Ship stats
    hull INTEGER DEFAULT 0,
    engines INTEGER DEFAULT 0,
    power INTEGER DEFAULT 0,
    computer INTEGER DEFAULT 0,
    sensors INTEGER DEFAULT 0,
    beams INTEGER DEFAULT 0,
    torp_launchers INTEGER DEFAULT 0,
    shields INTEGER DEFAULT 0,
    armor INTEGER DEFAULT 0,
    cloak INTEGER DEFAULT 0,

    -- Cargo
    ship_ore BIGINT DEFAULT 0,
    ship_organics BIGINT DEFAULT 0,
    ship_goods BIGINT DEFAULT 0,
    ship_energy BIGINT DEFAULT 0,
    ship_colonists BIGINT DEFAULT 0,
    ship_fighters INTEGER DEFAULT 0,
    torps INTEGER DEFAULT 0,
    armor_pts INTEGER DEFAULT 100,

    -- Resources
    credits BIGINT DEFAULT 1000,
    turns INTEGER DEFAULT 1200,
    score INTEGER DEFAULT 0,

    -- Devices
    dev_warpedit INTEGER DEFAULT 0,
    dev_genesis INTEGER DEFAULT 0,
    dev_beacon INTEGER DEFAULT 0,
    dev_emerwarp INTEGER DEFAULT 0,
    dev_minedeflector INTEGER DEFAULT 0,
    dev_escapepod BOOLEAN DEFAULT FALSE,
    dev_fuelscoop BOOLEAN DEFAULT FALSE,
    dev_lssd BOOLEAN DEFAULT FALSE,

    -- Location and status
    sector INTEGER DEFAULT 1,
    planet_id INTEGER DEFAULT 0,
    on_planet BOOLEAN DEFAULT FALSE,
    ship_destroyed BOOLEAN DEFAULT FALSE,
    ship_damage REAL DEFAULT 0,

    -- Team and zone
    team INTEGER DEFAULT 0,
    cleared_defences VARCHAR(200) DEFAULT '',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    turns_used INTEGER DEFAULT 0,

    CONSTRAINT chk_turns CHECK (turns >= 0 AND turns <= 2500)
);

CREATE INDEX idx_ships_email ON ships(email);
CREATE INDEX idx_ships_sector ON ships(sector);
CREATE INDEX idx_ships_team ON ships(team);

-- Universe (Sectors)
CREATE TABLE IF NOT EXISTS universe (
    sector_id SERIAL PRIMARY KEY,
    sector_name VARCHAR(100),
    zone_id INTEGER DEFAULT 1,
    port_type VARCHAR(20) DEFAULT 'none',
    port_organics BIGINT DEFAULT 0,
    port_ore BIGINT DEFAULT 0,
    port_goods BIGINT DEFAULT 0,
    port_energy BIGINT DEFAULT 0,
    beacon TEXT DEFAULT '',

    CONSTRAINT chk_port_type CHECK (port_type IN ('none', 'ore', 'organics', 'goods', 'energy', 'special'))
);

CREATE INDEX idx_universe_zone ON universe(zone_id);
CREATE INDEX idx_universe_port ON universe(port_type);

-- Links (Sector connections)
CREATE TABLE IF NOT EXISTS links (
    link_id SERIAL PRIMARY KEY,
    link_start INTEGER NOT NULL,
    link_dest INTEGER NOT NULL,

    CONSTRAINT fk_link_start FOREIGN KEY (link_start) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT fk_link_dest FOREIGN KEY (link_dest) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT chk_no_self_link CHECK (link_start != link_dest)
);

CREATE INDEX idx_links_start ON links(link_start);
CREATE INDEX idx_links_dest ON links(link_dest);
CREATE UNIQUE INDEX idx_links_unique ON links(link_start, link_dest);

-- Planets
CREATE TABLE IF NOT EXISTS planets (
    planet_id SERIAL PRIMARY KEY,
    planet_name VARCHAR(100) NOT NULL,
    sector_id INTEGER NOT NULL,
    owner INTEGER DEFAULT 0,
    corp INTEGER DEFAULT 0,

    -- Resources
    organics BIGINT DEFAULT 0,
    ore BIGINT DEFAULT 0,
    goods BIGINT DEFAULT 0,
    energy BIGINT DEFAULT 0,
    credits BIGINT DEFAULT 0,
    colonists BIGINT DEFAULT 0,

    -- Production percentages
    prod_ore REAL DEFAULT 20.0,
    prod_organics REAL DEFAULT 20.0,
    prod_goods REAL DEFAULT 20.0,
    prod_energy REAL DEFAULT 20.0,
    prod_fighters REAL DEFAULT 10.0,
    prod_torp REAL DEFAULT 10.0,

    -- Defense
    fighters INTEGER DEFAULT 0,
    torps INTEGER DEFAULT 0,
    base BOOLEAN DEFAULT FALSE,
    defeated BOOLEAN DEFAULT FALSE,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_planet_sector FOREIGN KEY (sector_id) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT fk_planet_owner FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET DEFAULT,
    CONSTRAINT chk_prod_total CHECK (prod_ore + prod_organics + prod_goods + prod_energy + prod_fighters + prod_torp = 100)
);

CREATE INDEX idx_planets_sector ON planets(sector_id);
CREATE INDEX idx_planets_owner ON planets(owner);
CREATE INDEX idx_planets_corp ON planets(corp);

-- Sector Defenses (Mines and Fighters)
CREATE TABLE IF NOT EXISTS sector_defence (
    defence_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    sector_id INTEGER NOT NULL,
    defence_type CHAR(1) NOT NULL,
    quantity INTEGER DEFAULT 0,

    CONSTRAINT fk_defence_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_defence_sector FOREIGN KEY (sector_id) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT chk_defence_type CHECK (defence_type IN ('F', 'M'))
);

CREATE INDEX idx_defence_ship ON sector_defence(ship_id);
CREATE INDEX idx_defence_sector ON sector_defence(sector_id);

-- Teams
CREATE TABLE IF NOT EXISTS teams (
    id SERIAL PRIMARY KEY,
    team_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    creator INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_team_creator FOREIGN KEY (creator) REFERENCES ships(ship_id) ON DELETE CASCADE
);

-- Zones
CREATE TABLE IF NOT EXISTS zones (
    zone_id SERIAL PRIMARY KEY,
    zone_name VARCHAR(50) NOT NULL,
    owner INTEGER DEFAULT 0,
    corp_zone BOOLEAN DEFAULT FALSE,

    CONSTRAINT fk_zone_owner FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET DEFAULT
);

-- Insert default zones
INSERT INTO zones (zone_id, zone_name, owner, corp_zone) VALUES
(1, 'Neutral Zone', 0, FALSE),
(2, 'Federation Space', 0, TRUE),
(3, 'Free Trade Zone', 0, FALSE),
(4, 'War Zone', 0, FALSE)
ON CONFLICT (zone_id) DO NOTHING;

-- Messages/Mail
CREATE TABLE IF NOT EXISTS messages (
    message_id SERIAL PRIMARY KEY,
    from_id INTEGER NOT NULL,
    to_id INTEGER NOT NULL,
    subject VARCHAR(100),
    message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read BOOLEAN DEFAULT FALSE,

    CONSTRAINT fk_message_from FOREIGN KEY (from_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_to FOREIGN KEY (to_id) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_messages_to ON messages(to_id);
CREATE INDEX idx_messages_read ON messages(read);

-- Logs
CREATE TABLE IF NOT EXISTS logs (
    log_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    log_type INTEGER NOT NULL,
    log_data TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_log_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_logs_ship ON logs(ship_id);
CREATE INDEX idx_logs_time ON logs(logged_at);

-- News
CREATE TABLE IF NOT EXISTS news (
    news_id SERIAL PRIMARY KEY,
    headline VARCHAR(200) NOT NULL,
    newstext TEXT,
    user_id INTEGER DEFAULT 0,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    news_type VARCHAR(50) DEFAULT 'general',

    CONSTRAINT fk_news_user FOREIGN KEY (user_id) REFERENCES ships(ship_id) ON DELETE SET DEFAULT
);

CREATE INDEX idx_news_date ON news(date DESC);
CREATE INDEX idx_news_type ON news(news_type);

-- IGB (Intergalactic Bank)
CREATE TABLE IF NOT EXISTS ibank_accounts (
    account_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL UNIQUE,
    balance BIGINT DEFAULT 0,
    loan BIGINT DEFAULT 0,
    loantime TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ibank_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT chk_balance CHECK (balance >= 0)
);

CREATE INDEX idx_ibank_ship ON ibank_accounts(ship_id);

-- Trade Routes
CREATE TABLE IF NOT EXISTS traderoutes (
    traderoute_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    source_sector INTEGER NOT NULL,
    dest_sector INTEGER NOT NULL,
    source_type VARCHAR(20),
    dest_type VARCHAR(20),
    move_type VARCHAR(20),

    CONSTRAINT fk_traderoute_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_traderoute_source FOREIGN KEY (source_sector) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT fk_traderoute_dest FOREIGN KEY (dest_sector) REFERENCES universe(sector_id) ON DELETE CASCADE
);

CREATE INDEX idx_traderoutes_ship ON traderoutes(ship_id);

-- Bounty
CREATE TABLE IF NOT EXISTS bounty (
    bounty_id SERIAL PRIMARY KEY,
    bounty_on INTEGER NOT NULL,
    placed_by INTEGER NOT NULL,
    amount BIGINT NOT NULL,
    placed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_bounty_target FOREIGN KEY (bounty_on) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_bounty_placer FOREIGN KEY (placed_by) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT chk_bounty_amount CHECK (amount > 0)
);

CREATE INDEX idx_bounty_target ON bounty(bounty_on);

-- Scheduler
CREATE TABLE IF NOT EXISTS scheduler (
    scheduler_id SERIAL PRIMARY KEY,
    last_run TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    task_name VARCHAR(50) NOT NULL UNIQUE
);

-- Movement Log
CREATE TABLE IF NOT EXISTS movement_log (
    movement_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    sector_id INTEGER NOT NULL,
    time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_movement_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_movement_ship ON movement_log(ship_id);
CREATE INDEX idx_movement_time ON movement_log(time);

-- IP Bans
CREATE TABLE IF NOT EXISTS ip_bans (
    ban_id SERIAL PRIMARY KEY,
    ip_address INET NOT NULL UNIQUE,
    reason TEXT,
    banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    banned_by INTEGER,

    CONSTRAINT fk_ban_admin FOREIGN KEY (banned_by) REFERENCES ships(ship_id) ON DELETE SET NULL
);

CREATE INDEX idx_ipban_address ON ip_bans(ip_address);

-- IGB Transfers Log
CREATE TABLE IF NOT EXISTS igb_transfers (
    transfer_id SERIAL PRIMARY KEY,
    from_ship INTEGER NOT NULL,
    to_ship INTEGER NOT NULL,
    amount BIGINT NOT NULL,
    transfer_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_transfer_from FOREIGN KEY (from_ship) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_transfer_to FOREIGN KEY (to_ship) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_transfers_from ON igb_transfers(from_ship);
CREATE INDEX idx_transfers_to ON igb_transfers(to_ship);
CREATE INDEX idx_transfers_time ON igb_transfers(transfer_time);
