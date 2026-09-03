-- Database setup for جاری شرکا
CREATE DATABASE IF NOT EXISTS jari_shoka CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;
USE jari_shoka;

-- Users table (کاربران)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default admin user (رمز: admin123)
INSERT IGNORE INTO users (username, password, fullname) VALUES
('admin', '$2y$10$wZ9ZbgbIT8NoklpFnn6nI./KiYp9RRFd6GJrGxnav3EEs.IN0P92.', 'مدیر سیستم');

-- Partners table (شرکا)
CREATE TABLE IF NOT EXISTS partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_num INT,
    date DATE,
    account_name VARCHAR(255),
    amount BIGINT DEFAULT 0,
    percentage DECIMAL(10,6) DEFAULT 0
) ENGINE=InnoDB;

-- Documents table (اسناد)
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    row_num INT,
    doc_date DATE,
    doc_number VARCHAR(50),
    seller VARCHAR(255),
    buyer VARCHAR(255),
    weight_kg INT DEFAULT 0,
    loss_kg INT DEFAULT 0,
    purchase_rials BIGINT DEFAULT 0,
    sale_rials BIGINT DEFAULT 0,
    profit_rials BIGINT DEFAULT 0,
    deduction_rials INT DEFAULT 0,
    bonus INT DEFAULT 0,
    payment_date DATE,
    status VARCHAR(50),
    month_name VARCHAR(20),
    INDEX idx_documents_doc_date (doc_date),
    INDEX idx_documents_status (status)
) ENGINE=InnoDB;

-- Partner transactions table (تراکنش‌های شرکا)
CREATE TABLE IF NOT EXISTS partner_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT,
    partner_name VARCHAR(255),
    transaction_date DATE,
    description VARCHAR(500),
    amount BIGINT DEFAULT 0,
    type VARCHAR(50) DEFAULT 'واریز',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
    INDEX idx_partner_transactions_date (transaction_date),
    INDEX idx_partner_transactions_type (type)
) ENGINE=InnoDB;

-- Settlements table (تسویه سود)
CREATE TABLE IF NOT EXISTS settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jalali_year INT,
    jalali_month INT,
    month_label VARCHAR(20),
    total_profit BIGINT DEFAULT 0,
    settled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_settlement (jalali_year, jalali_month)
) ENGINE=InnoDB;

-- Settlement details table (جزئیات تسویه)
CREATE TABLE IF NOT EXISTS settlement_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    settlement_id INT,
    partner_id INT,
    partner_name VARCHAR(255),
    share_amount BIGINT DEFAULT 0,
    percentage DECIMAL(10,6) DEFAULT 0,
    FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Partner daily balances table (مانده روزانه شرکا)
-- این جدول مانده هر شریک در هر روز را ذخیره می‌کند
-- برای محاسبه دقیق تقسیم سود بر اساس میانگین وزنی روزانه
CREATE TABLE IF NOT EXISTS partner_daily_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_id INT NOT NULL,
    partner_name VARCHAR(255) NOT NULL,
    balance_date DATE NOT NULL,
    opening_balance BIGINT DEFAULT 0 COMMENT 'مانده ابتدای روز',
    closing_balance BIGINT DEFAULT 0 COMMENT 'مانده انتهای روز',
    daily_change BIGINT DEFAULT 0 COMMENT 'تغییرات روز (واریز - برداشت)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_partner_date (partner_id, balance_date),
    INDEX idx_daily_balance_date (balance_date),
    INDEX idx_daily_balance_partner (partner_id),
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Partner daily balance history for settlements (تاریخچه مانده روزانه برای تسویه)
-- این جدول برای نگهداری تاریخچه محاسبات تسویه استفاده می‌شود
CREATE TABLE IF NOT EXISTS settlement_daily_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    settlement_id INT NOT NULL,
    partner_id INT NOT NULL,
    partner_name VARCHAR(255) NOT NULL,
    balance_date DATE NOT NULL,
    opening_balance BIGINT DEFAULT 0,
    closing_balance BIGINT DEFAULT 0,
    daily_change BIGINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_settlement_partner_date (settlement_id, partner_id, balance_date),
    FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE
) ENGINE=InnoDB;