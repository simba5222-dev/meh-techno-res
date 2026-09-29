-- Миграция: приём ежедневных отчётов из группы MAX и их проверка на сайте.
-- Выполнить один раз через phpMyAdmin (MySQL 5.7 — нет ADD COLUMN IF NOT EXISTS,
-- поэтому сначала SHOW COLUMNS FROM daily_reports; и сверить со списком ниже,
-- чтобы не дублировать колонки, которые уже могли быть добавлены раньше).

ALTER TABLE users
  ADD COLUMN max_user_id BIGINT UNSIGNED NULL UNIQUE
  COMMENT 'id пользователя в мессенджере MAX' AFTER can_login;

ALTER TABLE daily_reports
  ADD COLUMN max_chat_id VARCHAR(64) NULL COMMENT 'чат MAX, откуда пришёл отчёт',
  ADD COLUMN max_message_id VARCHAR(64) NULL COMMENT 'id сообщения в MAX — для ответа',
  ADD COLUMN review_status ENUM('pending','approved','rejected','partial')
      NOT NULL DEFAULT 'pending',
  ADD COLUMN review_comment TEXT NULL,
  ADD COLUMN reviewed_by INT UNSIGNED NULL,
  ADD COLUMN reviewed_at DATETIME NULL,
  ADD CONSTRAINT fk_daily_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id);
