-- Cave à Vin — schéma MySQL
-- À importer via phpMyAdmin (IONOS) dans la base créée pour macave.famille-dumas.fr

SET NAMES utf8mb4;

-- Une « cave » = un rangement physique (casier, armoire à vin...) avec sa propre grille
CREATE TABLE IF NOT EXISTS cellars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(150) NULL,
    grid_rows INT NOT NULL DEFAULT 3,
    grid_cols INT NOT NULL DEFAULT 6,
    cell_capacity INT NOT NULL DEFAULT 36,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS storage_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cellar_id INT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    capacity INT NULL,
    rack_row INT NULL,
    rack_col INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- La position est unique DANS une cave : deux caves peuvent chacune avoir une case A1
    UNIQUE KEY uniq_cellar_position (cellar_id, rack_row, rack_col),
    FOREIGN KEY (cellar_id) REFERENCES cellars(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    producer VARCHAR(200) NULL,
    region VARCHAR(150) NULL,
    appellation VARCHAR(150) NULL,
    country VARCHAR(100) NULL,
    color ENUM('red','white','rose','sparkling','sweet','fortified','other') NOT NULL DEFAULT 'other',
    vintage INT NULL,
    alcohol_percent DECIMAL(4,1) NULL,
    volume_ml INT NOT NULL DEFAULT 750,
    barcode VARCHAR(20) NULL,
    description TEXT NULL,
    food_pairing TEXT NULL,
    drink_from_year INT NULL,
    drink_until_year INT NULL,
    purchase_price DECIMAL(10,2) NULL,
    purchase_date DATE NULL,
    current_estimated_price DECIMAL(10,2) NULL,
    price_updated_at DATETIME NULL,
    label_photo_path VARCHAR(255) NULL,
    ai_enriched TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_gift TINYINT(1) NOT NULL DEFAULT 0,
    gift_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wines_color (color),
    INDEX idx_wines_region (region),
    INDEX idx_wines_vintage (vintage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grape_varieties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wine_grape_varieties (
    wine_id INT NOT NULL,
    grape_variety_id INT NOT NULL,
    percentage DECIMAL(5,2) NULL,
    PRIMARY KEY (wine_id, grape_variety_id),
    FOREIGN KEY (wine_id) REFERENCES wines(id) ON DELETE CASCADE,
    FOREIGN KEY (grape_variety_id) REFERENCES grape_varieties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wine_id INT NOT NULL,
    storage_location_id INT NULL,
    quantity INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_wine_location (wine_id, storage_location_id),
    FOREIGN KEY (wine_id) REFERENCES wines(id) ON DELETE CASCADE,
    FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consumption_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wine_id INT NOT NULL,
    storage_location_id INT NULL,
    quantity INT NOT NULL DEFAULT 1,
    consumed_date DATE NOT NULL,
    rating TINYINT NULL,
    tasting_notes TEXT NULL,
    occasion VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wine_id) REFERENCES wines(id) ON DELETE CASCADE,
    FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS price_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wine_id INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    -- purchase = prix d'achat ; observed = prix relevé à la main ;
    -- open_prices = relevé Open Food Facts ; *_estimate = anciens (IA, dépréciés)
    price_type ENUM('purchase','manual_estimate','ai_estimate','observed','open_prices') NOT NULL,
    note VARCHAR(255) NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wine_id) REFERENCES wines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_enrichment_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wine_id INT NULL,
    request_type ENUM('text','photo','price_estimate') NOT NULL,
    prompt TEXT NULL,
    raw_response TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (wine_id) REFERENCES wines(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    last_attempt DATETIME NOT NULL,
    locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal détaillé (une ligne par tentative de connexion, contrairement à
-- login_attempts qui n'est qu'un compteur écrasé par IP) — sert uniquement à
-- l'affichage du journal de sécurité, aucun rôle dans le verrouillage lui-même.
-- country_code vient de l'en-tête CF-IPCountry fourni gratuitement par
-- Cloudflare sur chaque requête (aucun appel à une API tierce).
CREATE TABLE IF NOT EXISTS login_attempt_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username_tried VARCHAR(100) NULL,
    result ENUM('success','fail_password','fail_username') NOT NULL,
    country_code CHAR(2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempt_log_created (created_at),
    INDEX idx_login_attempt_log_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historique des vins scannés/photographiés en mode magasin (page Scanner),
-- avec géolocalisation optionnelle pour retrouver le magasin plus tard.
CREATE TABLE IF NOT EXISTS scan_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scan_type ENUM('photo','barcode','qr','name') NOT NULL,
    wine_name VARCHAR(200) NOT NULL,
    producer VARCHAR(200) NULL,
    vintage INT NULL,
    region VARCHAR(150) NULL,
    color VARCHAR(20) NULL,
    price_low DECIMAL(10,2) NULL,
    price_high DECIMAL(10,2) NULL,
    photo_path VARCHAR(255) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_label VARCHAR(255) NULL,
    details_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scan_history_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
