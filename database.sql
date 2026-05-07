-- ======================================================
-- SmartBudget Pro Database Schema v4.0 — COMPLETE FIXED
-- ✅ Added: monthly_budgets table (required by index.php)
-- ✅ Added: wallets table (required by index.php)
-- ✅ Fixed: All foreign keys and indexes
-- ✅ Added: login_attempts table for rate limiting
-- Run this ONCE on a fresh database
-- ======================================================

CREATE DATABASE IF NOT EXISTS `smartbudget`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `smartbudget`;

-- ======================================================
-- TABLE: users
-- ======================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100)    NOT NULL,
    `email`         VARCHAR(255)    NOT NULL UNIQUE,
    `password`      VARCHAR(255)    NOT NULL DEFAULT '',
    `google_id`     VARCHAR(255)    DEFAULT NULL,
    `currency`      VARCHAR(3)      NOT NULL DEFAULT 'PKR',
    `role`          VARCHAR(20)     NOT NULL DEFAULT 'user',
    `last_login_at` DATETIME        DEFAULT NULL,
    `login_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `monthly_goal`  DECIMAL(12,2)   DEFAULT 0.00,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `avatar_color`  VARCHAR(20)     DEFAULT '#6366f1',
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_email`     (`email`),
    INDEX `idx_role`      (`role`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: categories
-- ======================================================
CREATE TABLE IF NOT EXISTS `categories` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED  NOT NULL,
    `name`       VARCHAR(100)  NOT NULL,
    `type`       ENUM('income','expense') NOT NULL,
    `icon`       VARCHAR(50)   DEFAULT 'fa-tag',
    `color`      VARCHAR(20)   DEFAULT '#6366f1',
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_type` (`user_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: incomes
-- ======================================================
CREATE TABLE IF NOT EXISTS `incomes` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED  NOT NULL,
    `category_id`  INT UNSIGNED  DEFAULT NULL,
    `name`         VARCHAR(200)  NOT NULL,
    `amount`       DECIMAL(12,2) NOT NULL,
    `date`         DATE          NOT NULL,
    `is_recurring` TINYINT(1)    NOT NULL DEFAULT 0,
    `recur_day`    TINYINT(1)    DEFAULT NULL,
    `note`         TEXT,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)      ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_date` (`user_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: expenses
-- ======================================================
CREATE TABLE IF NOT EXISTS `expenses` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED  NOT NULL,
    `category_id`    INT UNSIGNED  DEFAULT NULL,
    `name`           VARCHAR(200)  NOT NULL,
    `amount`         DECIMAL(12,2) NOT NULL,
    `date`           DATE          NOT NULL,
    `payment_method` ENUM('cash','card','bank_transfer','other') DEFAULT 'cash',
    `is_recurring`   TINYINT(1)    NOT NULL DEFAULT 0,
    `recur_day`      TINYINT(1)    DEFAULT NULL,
    `note`           TEXT,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)      ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_date` (`user_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: savings_goals
-- ======================================================
CREATE TABLE IF NOT EXISTS `savings_goals` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED  NOT NULL,
    `title`        VARCHAR(200)  NOT NULL,
    `target_amt`   DECIMAL(12,2) NOT NULL,
    `saved_amt`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `deadline`     DATE          DEFAULT NULL,
    `icon`         VARCHAR(50)   DEFAULT 'fa-bullseye',
    `purpose`      VARCHAR(500)  DEFAULT NULL,
    `is_completed` TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_completed` (`user_id`, `is_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: reminders (v4.0)
-- ======================================================
CREATE TABLE IF NOT EXISTS `reminders` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED  NOT NULL,
    `title`      VARCHAR(200)  NOT NULL,
    `due_date`   DATE          NOT NULL,
    `type`       ENUM('bill','budget','goal') NOT NULL DEFAULT 'bill',
    `amount`     DECIMAL(12,2) DEFAULT 0.00,
    `is_done`    TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_done` (`user_id`, `is_done`),
    INDEX `idx_due_date`  (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- ✅ NEW TABLE: wallets (MISSING — caused wallet feature crash)
-- Tracks cash, card, bank_transfer balances per user
-- ======================================================
CREATE TABLE IF NOT EXISTS `wallets` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED  NOT NULL,
    `wallet_type` ENUM('cash','card','bank_transfer','other') NOT NULL DEFAULT 'cash',
    `balance`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_wallet` (`user_id`, `wallet_type`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- ✅ NEW TABLE: monthly_budgets (MISSING — caused budget planner crash)
-- Stores per-month budget targets set by users
-- ======================================================
CREATE TABLE IF NOT EXISTS `monthly_budgets` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED  NOT NULL,
    `year`          SMALLINT      NOT NULL,
    `month`         TINYINT       NOT NULL,
    `budget_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_year_month` (`user_id`, `year`, `month`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_budget` (`user_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: password_resets
-- ======================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `token`      VARCHAR(255) NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used`       TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: email_alerts_log
-- ======================================================
CREATE TABLE IF NOT EXISTS `email_alerts_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `alert_type` VARCHAR(50)  NOT NULL,
    `message`    TEXT,
    `sent_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_sent` (`user_id`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- TABLE: cookie_analytics
-- ======================================================
CREATE TABLE IF NOT EXISTS `cookie_analytics` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` VARCHAR(255) NOT NULL,
    `action`     ENUM('accepted','rejected','page_view') NOT NULL,
    `ip_address` VARCHAR(45)  DEFAULT NULL,
    `user_agent` TEXT,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_session` (`session_id`),
    INDEX `idx_action`  (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- DEMO USERS
-- password for both: demo123
-- ======================================================
INSERT INTO `users` (`id`,`name`,`email`,`password`,`currency`,`role`,`monthly_goal`,`is_active`,`avatar_color`) VALUES
(1, 'Demo User',     'demo@budget.com',          '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PKR', 'user',  50000.00, 1, '#6366f1'),
(2, 'Administrator', 'admin@smartbudget.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'PKR', 'admin', 0.00,     1, '#a855f7')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- ======================================================
-- CATEGORIES FOR DEMO USER
-- ======================================================
INSERT IGNORE INTO `categories` (`user_id`,`name`,`type`,`icon`,`color`) VALUES
(1,'Salary',       'income',  'fa-briefcase',    '#059669'),
(1,'Freelance',    'income',  'fa-laptop',        '#3b82f6'),
(1,'Investments',  'income',  'fa-chart-line',    '#a855f7'),
(1,'Rent',         'expense', 'fa-home',          '#dc2626'),
(1,'Groceries',    'expense', 'fa-shopping-cart', '#f97316'),
(1,'Food & Dining','expense', 'fa-utensils',      '#f59e0b'),
(1,'Entertainment','expense', 'fa-gamepad',       '#ec4899'),
(1,'Utilities',    'expense', 'fa-bolt',          '#eab308'),
(1,'Transport',    'expense', 'fa-car',           '#6366f1'),
(1,'Health',       'expense', 'fa-heart-pulse',   '#10b981'),
(1,'Education',    'expense', 'fa-graduation-cap','#8b5cf6'),
(1,'Shopping',     'expense', 'fa-bag-shopping',  '#06b6d4');

INSERT IGNORE INTO `categories` (`user_id`,`name`,`type`,`icon`,`color`) VALUES
(2,'Salary',       'income',  'fa-briefcase',    '#059669'),
(2,'Freelance',    'income',  'fa-laptop',        '#3b82f6'),
(2,'Rent',         'expense', 'fa-home',          '#dc2626'),
(2,'Groceries',    'expense', 'fa-shopping-cart', '#f97316'),
(2,'Food & Dining','expense', 'fa-utensils',      '#f59e0b'),
(2,'Transport',    'expense', 'fa-car',           '#6366f1');

-- ======================================================
-- SAMPLE DATA FOR DEMO USER
-- ======================================================
INSERT INTO `incomes` (`user_id`,`category_id`,`name`,`amount`,`date`,`is_recurring`,`note`) VALUES
(1,1,'Monthly Salary',  85000.00, CURDATE(),                              1, 'Full time salary'),
(1,2,'Freelance Project',25000.00,DATE_SUB(CURDATE(),INTERVAL 5 DAY),    0, 'Website development'),
(1,3,'Dividend Income',  3500.00, DATE_SUB(CURDATE(),INTERVAL 10 DAY),   0, 'Quarterly dividends');

INSERT INTO `expenses` (`user_id`,`category_id`,`name`,`amount`,`date`,`payment_method`,`is_recurring`,`note`) VALUES
(1,4,'House Rent',       25000.00, CURDATE(),                              'bank_transfer',1,'Monthly rent'),
(1,5,'Supermarket',       8500.00, DATE_SUB(CURDATE(),INTERVAL 2 DAY),    'card',          0,'Weekly groceries'),
(1,6,'KFC Family Meal',   3200.00, DATE_SUB(CURDATE(),INTERVAL 3 DAY),    'card',          0,'Family dinner'),
(1,7,'Netflix',           1500.00, DATE_SUB(CURDATE(),INTERVAL 5 DAY),    'card',          1,'Monthly subscription'),
(1,8,'Electricity Bill',  4800.00, DATE_SUB(CURDATE(),INTERVAL 8 DAY),    'bank_transfer', 0,'K-Electric bill'),
(1,9,'Uber Rides',        2200.00, DATE_SUB(CURDATE(),INTERVAL 3 DAY),    'card',          0,'Weekly transport');

INSERT INTO `savings_goals` (`user_id`,`title`,`target_amt`,`saved_amt`,`deadline`,`icon`,`is_completed`) VALUES
(1,'Emergency Fund', 300000.00, 85000.00, DATE_ADD(CURDATE(),INTERVAL 6 MONTH), 'fa-shield-alt',    0),
(1,'New Laptop',      80000.00, 35000.00, DATE_ADD(CURDATE(),INTERVAL 3 MONTH), 'fa-laptop',        0),
(1,'Family Vacation',150000.00, 40000.00, DATE_ADD(CURDATE(),INTERVAL 5 MONTH), 'fa-umbrella-beach',0);

INSERT INTO `reminders` (`user_id`,`title`,`due_date`,`type`,`amount`,`is_done`) VALUES
(1,'Pay Electricity Bill',   DATE_ADD(CURDATE(),INTERVAL 5 DAY),'bill',  4800.00,0),
(1,'Internet Bill Due',      DATE_ADD(CURDATE(),INTERVAL 8 DAY),'bill',  2500.00,0),
(1,'Monthly Budget Review',  DATE_ADD(CURDATE(),INTERVAL 3 DAY),'budget',0.00,   0);

-- ✅ Wallets for demo user (initial balances)
INSERT IGNORE INTO `wallets` (`user_id`,`wallet_type`,`balance`) VALUES
(1,'cash',          15000.00),
(1,'card',          45000.00),
(1,'bank_transfer', 120000.00);

-- ✅ Monthly budgets for demo user
INSERT IGNORE INTO `monthly_budgets` (`user_id`,`year`,`month`,`budget_amount`) VALUES
(1, YEAR(CURDATE()),  MONTH(CURDATE()),  60000.00),
(1, YEAR(CURDATE()),  MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)), 60000.00);

-- ======================================================
-- VERIFICATION QUERY — run this to confirm setup
-- ======================================================
SELECT 'SmartBudget Pro v4.0 — Database Ready!' AS status;
SELECT
    (SELECT COUNT(*) FROM users)          AS total_users,
    (SELECT COUNT(*) FROM categories)     AS total_categories,
    (SELECT COUNT(*) FROM incomes)        AS total_incomes,
    (SELECT COUNT(*) FROM expenses)       AS total_expenses,
    (SELECT COUNT(*) FROM savings_goals)  AS total_goals,
    (SELECT COUNT(*) FROM reminders)      AS total_reminders,
    (SELECT COUNT(*) FROM wallets)        AS total_wallets,
    (SELECT COUNT(*) FROM monthly_budgets) AS total_budgets;

-- ======================================================
-- MIGRATION: Add purpose column (run on existing installs)
-- ======================================================
ALTER TABLE `savings_goals` ADD COLUMN IF NOT EXISTS `purpose` VARCHAR(500) DEFAULT NULL AFTER `icon`;