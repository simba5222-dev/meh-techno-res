-- Миграция для уже развёрнутой (боевой) базы данных.
-- Добавляет роль "менеджер" и таблицу ежедневных отчётов/табеля.
-- Выполнить один раз через phpMyAdmin (вкладка "SQL") на боевой базе данных.
-- Ничего из существующих данных не удаляется и не изменяется.

SET NAMES utf8mb4;

ALTER TABLE users
    MODIFY role ENUM('admin','mechanic','manager') NOT NULL DEFAULT 'mechanic';

CREATE TABLE IF NOT EXISTS daily_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    report_date DATE NOT NULL,
    is_present TINYINT(1) NOT NULL DEFAULT 1,
    summary TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_date (user_id, report_date),
    CONSTRAINT fk_daily_reports_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_daily_reports_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
