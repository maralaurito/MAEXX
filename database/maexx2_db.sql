-- ============================================================
-- MAEXX2 Enterprises Inc.
-- Web-Based Inventory and Sales Monitoring System
-- MySQL Database Schema
-- ============================================================
-- Run this in phpMyAdmin (http://localhost/phpmyadmin)
-- or via MySQL CLI: mysql -u root < maexx2_db.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS maexx2_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE maexx2_db;

-- ============================================================
-- 1. USERS TABLE
-- Stores all registered system users (Administrator, Product Inventory)
-- ============================================================
CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100)    NOT NULL,
    username      VARCHAR(100)    NOT NULL UNIQUE,
    email         VARCHAR(150)    NOT NULL UNIQUE,
    password      VARCHAR(255)    NOT NULL,
    role          ENUM('Administrator', 'Product Inventory') NOT NULL DEFAULT 'Product Inventory',
    status        ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    avatar        VARCHAR(255)    DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 2. PRODUCTS TABLE
-- Stores all product information managed through the system
-- ============================================================
CREATE TABLE products (
    product_id    INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)    NOT NULL,
    category      VARCHAR(100)    NOT NULL DEFAULT 'General',
    unit          VARCHAR(50)     NOT NULL DEFAULT 'pcs',
    description   TEXT            DEFAULT NULL,
    price         DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'Selling price',
    cost          DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'Cost price',
    stock         INT             NOT NULL DEFAULT 0    COMMENT 'Current available quantity',
    threshold     INT             NOT NULL DEFAULT 0    COMMENT 'Minimum stock level for low-stock alert',
    archived      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 3. INVENTORY TRANSACTIONS TABLE
-- Records every stock-in and stock-out movement.
-- The remaining_qty column supports FIFO:
--   Stock In  → remaining_qty starts equal to quantity
--   Stock Out → remaining_qty is NULL (not a receivable batch)
-- When issuing stock (stock-out), the system queries the oldest
-- stock-in records with remaining_qty > 0 and deducts from them
-- in order (First-In, First-Out).
-- ============================================================
CREATE TABLE inventory_transactions (
    transaction_id  INT AUTO_INCREMENT PRIMARY KEY,
    product_id      INT             NOT NULL,
    type            ENUM('in','out') NOT NULL,
    quantity        INT             NOT NULL,
    remaining_qty   INT             DEFAULT NULL COMMENT 'FIFO: remaining units from this stock-in batch; NULL for stock-out',
    date            DATE            NOT NULL,
    supplier        VARCHAR(150)    DEFAULT NULL COMMENT 'Supplier name (stock-in only)',
    remarks         TEXT            DEFAULT NULL,
    user_id         INT             NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (user_id)    REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ============================================================
-- 4. SALES ORDERS TABLE
-- Records customer sales orders from placement to delivery
-- ============================================================
CREATE TABLE sales_orders (
    order_id        INT AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(50)     NOT NULL UNIQUE COMMENT 'e.g. SO-20260915-6833B',
    customer_name   VARCHAR(150)    NOT NULL,
    po_number       VARCHAR(100)    DEFAULT NULL COMMENT 'Customer Purchase Order number',
    product_id      INT             NOT NULL,
    product_name    VARCHAR(150)    NOT NULL COMMENT 'Denormalized for historical record',
    quantity        INT             NOT NULL,
    unit            VARCHAR(50)     NOT NULL DEFAULT 'pcs',
    unit_price      DECIMAL(10,2)   NOT NULL,
    total           DECIMAL(12,2)   NOT NULL,
    status          ENUM('Pending','Delivered') NOT NULL DEFAULT 'Pending',
    notes           TEXT            DEFAULT NULL,
    si_number       VARCHAR(100)    DEFAULT NULL COMMENT 'Sales Invoice number (filled on delivery)',
    delivery_date   DATE            DEFAULT NULL COMMENT 'Filled when marked as delivered',
    delivered_qty   INT             DEFAULT NULL COMMENT 'Actual delivered quantity',
    user_id         INT             NOT NULL COMMENT 'User who processed the order',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (user_id)    REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ============================================================
-- 5. ACTIVITY LOGS TABLE
-- Audit trail of all significant actions in the system
-- ============================================================
CREATE TABLE activity_logs (
    log_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT             DEFAULT NULL,
    user_email    VARCHAR(150)    NOT NULL,
    message       TEXT            NOT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 6. LOGIN ATTEMPTS TABLE
-- Tracks failed login attempts for throttling / lockout
-- ============================================================
CREATE TABLE login_attempts (
    attempt_id    INT AUTO_INCREMENT PRIMARY KEY,
    identifier    VARCHAR(150)    NOT NULL COMMENT 'Username or email used',
    ip_address    VARCHAR(45)     NOT NULL,
    attempt_count INT             NOT NULL DEFAULT 1,
    first_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until  DATETIME        DEFAULT NULL
) ENGINE=InnoDB;

-- ============================================================
-- 7. PASSWORD RESETS TABLE
-- OTP-based password reset requests
-- ============================================================
CREATE TABLE password_resets (
    reset_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT             NOT NULL,
    email         VARCHAR(150)    NOT NULL,
    otp_code      VARCHAR(10)     NOT NULL,
    status        ENUM('pending','verified','expired') NOT NULL DEFAULT 'pending',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME        NOT NULL,

    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;
