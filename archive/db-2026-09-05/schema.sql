-- Система контроля задач для отдела механизации
-- Схема базы данных (MySQL 5.7+/8.0, MariaDB 10.x)
-- Кодировка: utf8mb4

SET NAMES utf8mb4;

-- =========================================================
-- Пользователи
-- =========================================================
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','mechanic') NOT NULL DEFAULT 'mechanic',
    phone VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Техника (парк)
-- =========================================================
CREATE TABLE IF NOT EXISTS equipment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_number VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(150) DEFAULT NULL,
    reg_number VARCHAR(50) DEFAULT NULL,
    pts VARCHAR(50) DEFAULT NULL,
    sts VARCHAR(50) DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    status ENUM('working','repair','idle') NOT NULL DEFAULT 'working',
    engine_hours INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Задачи (наряды)
-- =========================================================
CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    status ENUM('new','accepted','in_progress','done','verified','rejected') NOT NULL DEFAULT 'new',
    assignee_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    due_date DATE DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_tasks_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(id),
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES users(id),
    CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_tasks_status (status),
    INDEX idx_tasks_assignee (assignee_id),
    INDEX idx_tasks_equipment (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- История изменений по задаче (аудит + комментарии)
-- =========================================================
CREATE TABLE IF NOT EXISTS task_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    old_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_history_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Фото, прикреплённые к задаче
-- =========================================================
CREATE TABLE IF NOT EXISTS task_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_photos_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_photos_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_photos_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Учётная запись главного механика по умолчанию
-- Логин: admin  Пароль: admin123  (ОБЯЗАТЕЛЬНО смените после первого входа!)
-- Хэш ниже соответствует паролю admin123
-- =========================================================
INSERT INTO users (full_name, username, password_hash, role, is_active)
VALUES ('Главный механик', 'admin', '$2y$12$M8kwicITxAxP6f/JOcYkRu0mQz1Rg91.DPqeNoiJhJ8FrG3D98ia6', 'admin', 1)
ON DUPLICATE KEY UPDATE username = username;
