-- Каждое сообщение из чата MAX — отдельная проверяемая запись, а не
-- склейка в daily_reports.summary. daily_reports остаётся для присутствия
-- (is_present) и самостоятельных отчётов, внесённых прямо на сайте.

CREATE TABLE IF NOT EXISTS max_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  report_date DATE NOT NULL,
  text TEXT NOT NULL,
  max_chat_id VARCHAR(64) NOT NULL,
  max_message_id VARCHAR(64) NOT NULL,
  review_status ENUM('pending','approved','rejected','partial') NOT NULL DEFAULT 'pending',
  review_comment TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_max_message (max_message_id),
  KEY idx_user_date (user_id, report_date),
  CONSTRAINT fk_max_reports_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_max_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Фото/видео, приложенные к сообщению-отчёту (сотрудник прикрепляет их
-- прямо к сообщению с «Отчёт:» — отдельно присланные без триггера фото
-- бот не подхватывает, как и обычный текст без триггера).
CREATE TABLE IF NOT EXISTS max_report_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  max_report_id INT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_type ENUM('image','video') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_max_report_files_report FOREIGN KEY (max_report_id) REFERENCES max_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
