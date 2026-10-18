CREATE DATABASE IF NOT EXISTS `AICSdb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `AICSdb`;

CREATE TABLE IF NOT EXISTS `aics_roles` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_code` VARCHAR(30) NOT NULL,
  `role_name` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_roles_role_code` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_account_statuses` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_code` VARCHAR(30) NOT NULL,
  `status_name` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_account_statuses_status_code` (`status_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `account_status_id` TINYINT UNSIGNED NOT NULL,
  `email` VARCHAR(180) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `session_invalidate_at` TIMESTAMP NULL DEFAULT NULL,
  `auth_revision` INT UNSIGNED NOT NULL DEFAULT 0,
  `force_password_change` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_accounts_email` (`email`),
  KEY `idx_aics_accounts_role_id` (`role_id`),
  KEY `idx_aics_accounts_status_id` (`account_status_id`),
  CONSTRAINT `fk_aics_accounts_role_id` FOREIGN KEY (`role_id`) REFERENCES `aics_roles`(`id`),
  CONSTRAINT `fk_aics_accounts_status_id` FOREIGN KEY (`account_status_id`) REFERENCES `aics_account_statuses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `aics_accounts`
  MODIFY COLUMN `email` VARCHAR(180) DEFAULT NULL;
ALTER TABLE `aics_accounts`
  ADD COLUMN IF NOT EXISTS `session_invalidate_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
ALTER TABLE `aics_accounts`
  ADD COLUMN IF NOT EXISTS `auth_revision` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `session_invalidate_at`;
ALTER TABLE `aics_accounts`
  ADD COLUMN IF NOT EXISTS `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `session_invalidate_at`;

CREATE TABLE IF NOT EXISTS `aics_applicant_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `first_name` VARCHAR(120) NOT NULL,
  `middle_name` VARCHAR(120) DEFAULT NULL,
  `last_name` VARCHAR(120) NOT NULL,
  `extension_name` VARCHAR(20) DEFAULT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `civil_status` VARCHAR(30) DEFAULT NULL,
  `civil_status_other` VARCHAR(120) DEFAULT NULL,
  `highest_educational_attainment` VARCHAR(150) DEFAULT NULL,
  `contact_number` VARCHAR(15) DEFAULT NULL,
  `occupation` VARCHAR(150) DEFAULT NULL,
  `occupation_other` VARCHAR(150) DEFAULT NULL,
  `estimated_monthly_income` VARCHAR(40) DEFAULT NULL,
  `province` VARCHAR(100) DEFAULT NULL,
  `municipality` VARCHAR(120) DEFAULT NULL,
  `barangay` VARCHAR(120) DEFAULT NULL,
  `address_line` VARCHAR(255) DEFAULT NULL,
  `complete_address` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_applicant_profiles_account_id` (`account_id`),
  UNIQUE KEY `uq_aics_applicant_profiles_contact_number` (`contact_number`),
  KEY `idx_aics_applicant_profiles_last_name` (`last_name`),
  CONSTRAINT `fk_aics_applicant_profiles_account_id` FOREIGN KEY (`account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_password_resets` (
  `account_id` INT UNSIGNED NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `sent_at` INT UNSIGNED NOT NULL,
  `expires_at` INT UNSIGNED NOT NULL,
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `window_start` INT UNSIGNED NOT NULL,
  `send_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `request_ip` VARCHAR(45) NOT NULL,
  PRIMARY KEY (`account_id`),
  KEY `idx_aics_password_resets_ip_sent` (`request_ip`, `sent_at`),
  CONSTRAINT `fk_aics_password_resets_account` FOREIGN KEY (`account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_password_reset_sends` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `request_ip` VARCHAR(45) NOT NULL,
  `sent_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aics_password_reset_sends_ip_time` (`request_ip`, `sent_at`),
  CONSTRAINT `fk_aics_password_reset_sends_account` FOREIGN KEY (`account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `aics_applicant_profiles`
  ADD COLUMN IF NOT EXISTS `civil_status_other` VARCHAR(120) DEFAULT NULL AFTER `civil_status`;
ALTER TABLE `aics_applicant_profiles`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_aics_applicant_profiles_contact_number` (`contact_number`);

CREATE TABLE IF NOT EXISTS `aics_staff_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `first_name` VARCHAR(120) NOT NULL,
  `middle_name` VARCHAR(120) DEFAULT NULL,
  `last_name` VARCHAR(120) NOT NULL,
  `position_title` VARCHAR(120) DEFAULT NULL,
  `contact_number` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_staff_profiles_account_id` (`account_id`),
  CONSTRAINT `fk_aics_staff_profiles_account_id` FOREIGN KEY (`account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(32) NOT NULL,
  `date_applied` DATE NOT NULL,
  `applicant_role` ENUM('self', 'representative') DEFAULT NULL COMMENT 'Deprecated legacy intake role; no longer used',
  `beneficiary_first_name` VARCHAR(100) NOT NULL,
  `beneficiary_middle_name` VARCHAR(100) DEFAULT NULL,
  `beneficiary_last_name` VARCHAR(100) NOT NULL,
  `beneficiary_extension` VARCHAR(20) DEFAULT NULL,
  `beneficiary_birth_date` DATE DEFAULT NULL,
  `beneficiary_age` TINYINT UNSIGNED NOT NULL,
  `beneficiary_complete_name` VARCHAR(255) NOT NULL,
  `beneficiary_barangay` VARCHAR(120) DEFAULT NULL,
  `beneficiary_sitio_purok` VARCHAR(120) DEFAULT NULL,
  `beneficiary_municipality` VARCHAR(120) DEFAULT NULL,
  `beneficiary_province` VARCHAR(120) DEFAULT NULL,
  `contact_number` VARCHAR(15) NOT NULL,
  `applicant_first_name` VARCHAR(100) NOT NULL,
  `applicant_middle_name` VARCHAR(100) DEFAULT NULL,
  `applicant_last_name` VARCHAR(100) NOT NULL,
  `applicant_extension` VARCHAR(20) DEFAULT NULL,
  `applicant_birth_date` DATE DEFAULT NULL,
  `applicant_age` TINYINT UNSIGNED NOT NULL,
  `applicant_contact_number` VARCHAR(15) DEFAULT NULL,
  `applicant_email` VARCHAR(180) DEFAULT NULL,
  `applicant_occupation` VARCHAR(150) DEFAULT NULL,
  `applicant_estimated_monthly_income` VARCHAR(40) DEFAULT NULL,
  `barangay` VARCHAR(120) NOT NULL,
  `sitio_purok` VARCHAR(120) DEFAULT NULL,
  `municipality` VARCHAR(120) NOT NULL,
  `province` VARCHAR(120) NOT NULL DEFAULT 'Antique',
  `gender` ENUM('male', 'female') NOT NULL,
  `estimated_income` VARCHAR(40) DEFAULT NULL,
  `employment` VARCHAR(150) DEFAULT NULL,
  `civil_status` VARCHAR(30) DEFAULT NULL,
  `civil_status_other` VARCHAR(120) DEFAULT NULL,
  `highest_educational_attainment` VARCHAR(150) DEFAULT NULL,
  `problem_presented` TEXT DEFAULT NULL,
  `client_category_other` VARCHAR(150) DEFAULT NULL,
  `assistance_type_other` VARCHAR(150) DEFAULT NULL,
  `family_composition_json` JSON DEFAULT NULL,
  `client_categories_json` JSON DEFAULT NULL,
  `assistance_types_json` JSON NOT NULL,
  `urgency_response` VARCHAR(3) DEFAULT NULL,
  `urgency_indicator` VARCHAR(20) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `claim_status` ENUM('claimed', 'unclaimed') NOT NULL,
  `amount_claimed` DECIMAL(12,2) DEFAULT NULL,
  `cheque_date` DATE DEFAULT NULL,
  `request_status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_requests_reference_no` (`reference_no`),
  KEY `idx_aics_requests_date_applied` (`date_applied`),
  KEY `idx_aics_requests_municipality` (`municipality`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `beneficiary_barangay` VARCHAR(120) DEFAULT NULL AFTER `beneficiary_complete_name`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `beneficiary_sitio_purok` VARCHAR(120) DEFAULT NULL AFTER `beneficiary_barangay`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `beneficiary_municipality` VARCHAR(120) DEFAULT NULL AFTER `beneficiary_sitio_purok`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `beneficiary_province` VARCHAR(120) DEFAULT NULL AFTER `beneficiary_municipality`;

CREATE TABLE IF NOT EXISTS `aics_account_requests` (
  `account_id` INT UNSIGNED NOT NULL,
  `request_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `request_id`),
  KEY `idx_aics_account_requests_request_id` (`request_id`),
  CONSTRAINT `fk_aics_account_requests_account_id` FOREIGN KEY (`account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_account_requests_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_request_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `document_type` VARCHAR(60) DEFAULT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_request_documents_request_type` (`request_id`, `document_type`),
  KEY `idx_aics_request_documents_request_id` (`request_id`),
  KEY `idx_aics_request_documents_type` (`document_type`),
  CONSTRAINT `fk_aics_request_documents_request_id`
    FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_ai_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_document_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'processing',
  `processing_stage` VARCHAR(30) NOT NULL DEFAULT 'queued',
  `progress_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `stage_updated_at` DATETIME DEFAULT NULL,
  `worker_heartbeat_at` DATETIME DEFAULT NULL,
  `expected_document_type` VARCHAR(100) DEFAULT NULL,
  `detected_document_type` VARCHAR(100) DEFAULT NULL,
  `confidence_score` DECIMAL(5,2) DEFAULT NULL,
  `extracted_fields` LONGTEXT DEFAULT NULL,
  `findings` LONGTEXT DEFAULT NULL,
  `model_service` VARCHAR(100) DEFAULT NULL,
  `model_version` VARCHAR(100) DEFAULT NULL,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error_code` VARCHAR(100) DEFAULT NULL,
  `last_error_message` TEXT DEFAULT NULL,
  `processing_started_at` DATETIME DEFAULT NULL,
  `processed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_ai_reviews_document` (`request_document_id`),
  KEY `idx_document_ai_reviews_status` (`status`),
  KEY `idx_document_ai_reviews_stage` (`processing_stage`),
  CONSTRAINT `fk_document_ai_reviews_document` FOREIGN KEY (`request_document_id`) REFERENCES `aics_request_documents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_ai_request_worker_leases` (
  `request_id` int unsigned NOT NULL,
  `lease_token` char(32) NOT NULL,
  `acquired_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `idx_document_ai_request_worker_leases_acquired` (`acquired_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_ai_review_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ai_review_id` BIGINT UNSIGNED NOT NULL,
  `request_document_id` INT UNSIGNED NOT NULL,
  `previous_status` VARCHAR(30) NOT NULL,
  `previous_expected_document_type` VARCHAR(100) DEFAULT NULL,
  `previous_detected_document_type` VARCHAR(100) DEFAULT NULL,
  `previous_confidence_score` DECIMAL(5,2) DEFAULT NULL,
  `previous_extracted_fields` LONGTEXT DEFAULT NULL,
  `previous_findings` LONGTEXT DEFAULT NULL,
  `previous_model_service` VARCHAR(100) DEFAULT NULL,
  `previous_model_version` VARCHAR(100) DEFAULT NULL,
  `previous_attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `previous_processing_started_at` DATETIME DEFAULT NULL,
  `previous_processed_at` DATETIME DEFAULT NULL,
  `previous_error_code` VARCHAR(100) DEFAULT NULL,
  `previous_error_message` TEXT DEFAULT NULL,
  `archive_reason` VARCHAR(80) NOT NULL,
  `archived_by_account_id` INT UNSIGNED DEFAULT NULL,
  `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document_ai_review_history_review` (`ai_review_id`),
  KEY `idx_document_ai_review_history_document` (`request_document_id`),
  KEY `idx_document_ai_review_history_archived_at` (`archived_at`),
  KEY `idx_document_ai_review_history_staff` (`archived_by_account_id`),
  CONSTRAINT `fk_document_ai_review_history_review` FOREIGN KEY (`ai_review_id`) REFERENCES `document_ai_reviews`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_ai_review_history_document` FOREIGN KEY (`request_document_id`) REFERENCES `aics_request_documents`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_ai_review_history_staff` FOREIGN KEY (`archived_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_ai_review_feedback` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ai_review_id` BIGINT UNSIGNED NOT NULL,
  `reviewed_by_account_id` INT UNSIGNED NOT NULL,
  `ai_suggested_action` VARCHAR(50) NOT NULL,
  `staff_outcome` VARCHAR(50) NOT NULL,
  `agreement_state` VARCHAR(30) NOT NULL,
  `corrected_document_type` VARCHAR(100) DEFAULT NULL,
  `reason_code` VARCHAR(80) NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_feedback_review` (`ai_review_id`),
  KEY `idx_ai_feedback_staff` (`reviewed_by_account_id`),
  KEY `idx_ai_feedback_created` (`created_at`),
  CONSTRAINT `fk_ai_feedback_review` FOREIGN KEY (`ai_review_id`) REFERENCES `document_ai_reviews`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_feedback_staff` FOREIGN KEY (`reviewed_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_assistance_types` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assistance_code` VARCHAR(60) NOT NULL,
  `assistance_name` VARCHAR(120) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_assistance_types_code` (`assistance_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_document_types` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_code` VARCHAR(80) NOT NULL,
  `document_name` VARCHAR(180) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_document_types_code` (`document_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_assistance_required_documents` (
  `assistance_type_id` SMALLINT UNSIGNED NOT NULL,
  `document_type_id` SMALLINT UNSIGNED NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`assistance_type_id`, `document_type_id`),
  KEY `idx_aics_ard_document_type_id` (`document_type_id`),
  CONSTRAINT `fk_aics_ard_assistance_type_id` FOREIGN KEY (`assistance_type_id`) REFERENCES `aics_assistance_types`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_ard_document_type_id` FOREIGN KEY (`document_type_id`) REFERENCES `aics_document_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_request_assistance` (
  `request_id` INT UNSIGNED NOT NULL,
  `assistance_type_id` SMALLINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`, `assistance_type_id`),
  KEY `idx_aics_request_assistance_assistance_type_id` (`assistance_type_id`),
  CONSTRAINT `fk_aics_request_assistance_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_assistance_assistance_type_id` FOREIGN KEY (`assistance_type_id`) REFERENCES `aics_assistance_types`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_request_statuses` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_code` VARCHAR(60) NOT NULL,
  `status_name` VARCHAR(120) NOT NULL,
  `timeline_level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `is_terminal` TINYINT(1) NOT NULL DEFAULT 0,
  `is_cancellable` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_request_statuses_code` (`status_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_request_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `status_id` SMALLINT UNSIGNED NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `changed_by_account_id` INT UNSIGNED DEFAULT NULL,
  `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aics_request_status_history_request_id` (`request_id`),
  KEY `idx_aics_request_status_history_status_id` (`status_id`),
  KEY `idx_aics_request_status_history_changed_by` (`changed_by_account_id`),
  CONSTRAINT `fk_aics_request_status_history_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_status_history_status_id` FOREIGN KEY (`status_id`) REFERENCES `aics_request_statuses`(`id`),
  CONSTRAINT `fk_aics_request_status_history_changed_by` FOREIGN KEY (`changed_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_request_document_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_document_id` INT UNSIGNED NOT NULL,
  `reviewed_by_account_id` INT UNSIGNED DEFAULT NULL,
  `is_checked` TINYINT(1) NOT NULL DEFAULT 0,
  `review_status` ENUM('pending','checked','needs_reupload','invalid') NOT NULL DEFAULT 'pending',
  `remarks` VARCHAR(255) DEFAULT NULL,
  `reviewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notified_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_request_document_reviews_document_id` (`request_document_id`),
  KEY `idx_aics_request_document_reviews_reviewed_by` (`reviewed_by_account_id`),
  CONSTRAINT `fk_aics_request_document_reviews_document_id` FOREIGN KEY (`request_document_id`) REFERENCES `aics_request_documents`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_document_reviews_reviewed_by` FOREIGN KEY (`reviewed_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `beneficiary_extension` VARCHAR(20) DEFAULT NULL AFTER `beneficiary_last_name`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `beneficiary_birth_date` DATE DEFAULT NULL AFTER `beneficiary_extension`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `applicant_extension` VARCHAR(20) DEFAULT NULL AFTER `applicant_last_name`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `applicant_birth_date` DATE DEFAULT NULL AFTER `applicant_extension`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `estimated_income` VARCHAR(40) DEFAULT NULL AFTER `gender`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `employment` VARCHAR(150) DEFAULT NULL AFTER `estimated_income`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `civil_status` VARCHAR(30) DEFAULT NULL AFTER `employment`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `civil_status_other` VARCHAR(120) DEFAULT NULL AFTER `civil_status`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `highest_educational_attainment` VARCHAR(150) DEFAULT NULL AFTER `civil_status_other`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `problem_presented` TEXT DEFAULT NULL AFTER `highest_educational_attainment`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `client_category_other` VARCHAR(150) DEFAULT NULL AFTER `problem_presented`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `assistance_type_other` VARCHAR(150) DEFAULT NULL AFTER `client_category_other`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `family_composition_json` JSON DEFAULT NULL AFTER `assistance_type_other`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `urgency_response` VARCHAR(3) DEFAULT NULL AFTER `assistance_types_json`;
ALTER TABLE `aics_requests` ADD COLUMN IF NOT EXISTS `urgency_indicator` VARCHAR(20) DEFAULT NULL AFTER `urgency_response`;
ALTER TABLE `aics_request_document_reviews` ADD COLUMN IF NOT EXISTS `review_status` ENUM('pending','checked','needs_reupload','invalid') NOT NULL DEFAULT 'pending' AFTER `is_checked`;
ALTER TABLE `aics_request_document_reviews` ADD COLUMN IF NOT EXISTS `notified_at` TIMESTAMP NULL DEFAULT NULL AFTER `reviewed_at`;

-- Standardize legacy numeric income records after widening their storage.
ALTER TABLE `aics_applicant_profiles` MODIFY COLUMN `estimated_monthly_income` VARCHAR(40) DEFAULT NULL;
ALTER TABLE `aics_requests` MODIFY COLUMN `estimated_income` VARCHAR(40) DEFAULT NULL;
UPDATE `aics_applicant_profiles` SET `estimated_monthly_income` = CASE
  WHEN CAST(`estimated_monthly_income` AS DECIMAL(12,2)) = 0 THEN 'No Income'
  WHEN CAST(`estimated_monthly_income` AS DECIMAL(12,2)) < 10000 THEN 'Below ₱10,000'
  WHEN CAST(`estimated_monthly_income` AS DECIMAL(12,2)) < 20000 THEN '₱10,000 – ₱19,999'
  WHEN CAST(`estimated_monthly_income` AS DECIMAL(12,2)) < 30000 THEN '₱20,000 – ₱29,999'
  WHEN CAST(`estimated_monthly_income` AS DECIMAL(12,2)) < 40000 THEN '₱30,000 – ₱39,999'
  WHEN CAST(`estimated_monthly_income` AS DECIMAL(12,2)) < 50000 THEN '₱40,000 – ₱49,999'
  ELSE '₱50,000 and above' END
WHERE `estimated_monthly_income` REGEXP '^[0-9]+(\\.[0-9]+)?$';
UPDATE `aics_requests` SET `estimated_income` = CASE
  WHEN CAST(`estimated_income` AS DECIMAL(12,2)) = 0 THEN 'No Income'
  WHEN CAST(`estimated_income` AS DECIMAL(12,2)) < 10000 THEN 'Below ₱10,000'
  WHEN CAST(`estimated_income` AS DECIMAL(12,2)) < 20000 THEN '₱10,000 – ₱19,999'
  WHEN CAST(`estimated_income` AS DECIMAL(12,2)) < 30000 THEN '₱20,000 – ₱29,999'
  WHEN CAST(`estimated_income` AS DECIMAL(12,2)) < 40000 THEN '₱30,000 – ₱39,999'
  WHEN CAST(`estimated_income` AS DECIMAL(12,2)) < 50000 THEN '₱40,000 – ₱49,999'
  ELSE '₱50,000 and above' END
WHERE `estimated_income` REGEXP '^[0-9]+(\\.[0-9]+)?$';

CREATE TABLE IF NOT EXISTS `aics_request_validation_results` (
  `request_id` INT UNSIGNED NOT NULL,
  `duplicate_flag` TINYINT(1) NOT NULL DEFAULT 0,
  `repeated_claim_flag` TINYINT(1) NOT NULL DEFAULT 0,
  `inconsistency_flag` TINYINT(1) NOT NULL DEFAULT 0,
  `risk_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `validation_note` VARCHAR(500) DEFAULT NULL,
  `updated_by_account_id` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_aics_request_validation_results_updated_by` (`updated_by_account_id`),
  CONSTRAINT `fk_aics_request_validation_results_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_validation_results_updated_by` FOREIGN KEY (`updated_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_request_prioritization` (
  `request_id` INT UNSIGNED NOT NULL,
  `urgency_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `urgency_indicator` VARCHAR(20) DEFAULT NULL,
  `vulnerability_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `readiness_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `risk_penalty` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `priority_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `priority_rank` INT UNSIGNED DEFAULT NULL,
  `priority_level` VARCHAR(40) NOT NULL DEFAULT 'Normal Priority',
  `priority_basis` VARCHAR(700) DEFAULT NULL,
  `updated_by_account_id` INT UNSIGNED DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_aics_request_prioritization_priority_rank` (`priority_rank`),
  KEY `idx_aics_request_prioritization_updated_by` (`updated_by_account_id`),
  CONSTRAINT `fk_aics_request_prioritization_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_prioritization_updated_by` FOREIGN KEY (`updated_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `aics_request_prioritization` ADD COLUMN IF NOT EXISTS `urgency_indicator` VARCHAR(20) DEFAULT NULL AFTER `urgency_score`;
ALTER TABLE `aics_request_prioritization` ADD COLUMN IF NOT EXISTS `priority_basis` VARCHAR(700) DEFAULT NULL AFTER `priority_level`;

CREATE TABLE IF NOT EXISTS `aics_request_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `notification_stage` VARCHAR(80) NOT NULL,
  `message_text` VARCHAR(600) NOT NULL,
  `sent_by_account_id` INT UNSIGNED DEFAULT NULL,
  `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aics_request_notifications_request_id` (`request_id`),
  KEY `idx_aics_request_notifications_sent_by` (`sent_by_account_id`),
  CONSTRAINT `fk_aics_request_notifications_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_notifications_sent_by` FOREIGN KEY (`sent_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A notification can be a real applicant SMS or a simulated municipality
-- coordination notice. Existing rows remain valid as legacy SMS/audit rows.
ALTER TABLE `aics_request_notifications` ADD COLUMN IF NOT EXISTS `channel` VARCHAR(60) NOT NULL DEFAULT 'applicant_sms' AFTER `notification_stage`;
ALTER TABLE `aics_request_notifications` ADD COLUMN IF NOT EXISTS `delivery_mode` VARCHAR(20) DEFAULT NULL AFTER `channel`;
ALTER TABLE `aics_request_notifications` ADD COLUMN IF NOT EXISTS `delivery_status` VARCHAR(20) DEFAULT NULL AFTER `delivery_mode`;
ALTER TABLE `aics_request_notifications` ADD COLUMN IF NOT EXISTS `municipality` VARCHAR(120) DEFAULT NULL AFTER `delivery_status`;
ALTER TABLE `aics_request_notifications` ADD COLUMN IF NOT EXISTS `batch_key` VARCHAR(160) DEFAULT NULL AFTER `municipality`;
ALTER TABLE `aics_request_notifications` ADD COLUMN IF NOT EXISTS `notification_template` TEXT DEFAULT NULL AFTER `message_text`;
ALTER TABLE `aics_request_notifications` ADD COLUMN IF NOT EXISTS `dedupe_key` CHAR(64) DEFAULT NULL AFTER `notification_template`;
ALTER TABLE `aics_request_notifications` ADD UNIQUE INDEX IF NOT EXISTS `uq_aics_notification_dedupe_key` (`dedupe_key`);

CREATE TABLE IF NOT EXISTS `aics_request_prescreening` (
  `request_id` INT UNSIGNED NOT NULL,
  `decision_status` ENUM('for_screening', 'incomplete_requirements', 'invalid_requirements') NOT NULL,
  `required_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `missing_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `confirmed_by_account_id` INT UNSIGNED DEFAULT NULL,
  `confirmed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_aics_request_prescreening_decision` (`decision_status`),
  KEY `idx_aics_request_prescreening_confirmed_by` (`confirmed_by_account_id`),
  KEY `idx_aics_request_prescreening_confirmed_at` (`confirmed_at`),
  CONSTRAINT `fk_aics_request_prescreening_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_prescreening_confirmed_by` FOREIGN KEY (`confirmed_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_request_release_schedule` (
  `request_id` INT UNSIGNED NOT NULL,
  `scheduled_date` DATE DEFAULT NULL,
  `scheduled_time` TIME DEFAULT NULL,
  `venue` VARCHAR(180) DEFAULT NULL,
  `release_batch` VARCHAR(120) DEFAULT NULL,
  `amount_given` DECIMAL(10,2) DEFAULT NULL,
  `release_status` ENUM('unscheduled', 'scheduled', 'rescheduled', 'ready_for_release', 'missed', 'released') NOT NULL DEFAULT 'unscheduled',
  `notes` VARCHAR(500) DEFAULT NULL,
  `scheduled_by_account_id` INT UNSIGNED DEFAULT NULL,
  `updated_by_account_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_aics_request_release_schedule_status` (`release_status`),
  KEY `idx_aics_request_release_schedule_date` (`scheduled_date`),
  KEY `idx_aics_request_release_schedule_scheduled_by` (`scheduled_by_account_id`),
  KEY `idx_aics_request_release_schedule_updated_by` (`updated_by_account_id`),
  CONSTRAINT `fk_aics_request_release_schedule_request_id` FOREIGN KEY (`request_id`) REFERENCES `aics_requests`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aics_request_release_schedule_scheduled_by` FOREIGN KEY (`scheduled_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_aics_request_release_schedule_updated_by` FOREIGN KEY (`updated_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `aics_request_release_schedule`
  ADD COLUMN IF NOT EXISTS `amount_given` DECIMAL(10,2) DEFAULT NULL AFTER `release_batch`;

CREATE TABLE IF NOT EXISTS `aics_admin_login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(180) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `is_success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempt_reason` VARCHAR(80) DEFAULT NULL,
  `role_code` VARCHAR(30) DEFAULT NULL,
  `login_portal` VARCHAR(30) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aics_admin_login_attempts_email` (`email`),
  KEY `idx_aics_admin_login_attempts_ip` (`ip_address`),
  KEY `idx_aics_admin_login_attempts_success` (`is_success`),
  KEY `idx_aics_admin_login_attempts_created` (`created_at`),
  KEY `idx_aics_admin_login_attempts_role_code` (`role_code`),
  KEY `idx_aics_admin_login_attempts_login_portal` (`login_portal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_admin_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_account_id` INT UNSIGNED DEFAULT NULL,
  `target_account_id` INT UNSIGNED DEFAULT NULL,
  `event_type` VARCHAR(80) NOT NULL,
  `outcome` ENUM('success', 'failure', 'warning', 'info') NOT NULL DEFAULT 'info',
  `event_message` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `context_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aics_admin_audit_logs_actor` (`actor_account_id`),
  KEY `idx_aics_admin_audit_logs_target` (`target_account_id`),
  KEY `idx_aics_admin_audit_logs_event_type` (`event_type`),
  KEY `idx_aics_admin_audit_logs_created` (`created_at`),
  CONSTRAINT `fk_aics_admin_audit_logs_actor` FOREIGN KEY (`actor_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_aics_admin_audit_logs_target` FOREIGN KEY (`target_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_admin_backup_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `initiated_by_account_id` INT UNSIGNED DEFAULT NULL,
  `backup_type` ENUM('incremental', 'full', 'archive') NOT NULL DEFAULT 'incremental',
  `status` ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `file_size` BIGINT UNSIGNED DEFAULT NULL,
  `checksum_sha256` CHAR(64) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aics_admin_backup_runs_status` (`status`),
  KEY `idx_aics_admin_backup_runs_started` (`started_at`),
  KEY `idx_aics_admin_backup_runs_initiated_by` (`initiated_by_account_id`),
  CONSTRAINT `fk_aics_admin_backup_runs_initiated_by` FOREIGN KEY (`initiated_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_admin_maintenance_checks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `performed_by_account_id` INT UNSIGNED DEFAULT NULL,
  `check_type` VARCHAR(80) NOT NULL,
  `check_status` ENUM('pass', 'fail') NOT NULL,
  `details_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aics_admin_maintenance_checks_status` (`check_status`),
  KEY `idx_aics_admin_maintenance_checks_created` (`created_at`),
  KEY `idx_aics_admin_maintenance_checks_by` (`performed_by_account_id`),
  CONSTRAINT `fk_aics_admin_maintenance_checks_by` FOREIGN KEY (`performed_by_account_id`) REFERENCES `aics_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_admin_flow_steps` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `step_no` SMALLINT UNSIGNED NOT NULL,
  `action_text` VARCHAR(255) NOT NULL,
  `frequency_text` VARCHAR(120) NOT NULL,
  `success_criteria_text` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aics_admin_flow_steps_step_no` (`step_no`),
  KEY `idx_aics_admin_flow_steps_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aics_admin_exception_flows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exception_name` VARCHAR(120) NOT NULL,
  `trigger_text` VARCHAR(255) NOT NULL,
  `response_flow_text` VARCHAR(500) NOT NULL,
  `frequency_text` VARCHAR(120) NOT NULL,
  `success_criteria_text` VARCHAR(255) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aics_admin_exception_flows_order` (`sort_order`),
  KEY `idx_aics_admin_exception_flows_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `aics_roles` (`role_code`, `role_name`) VALUES
('applicat', 'Applicant'),
('pswdo', 'PSWDO Personnel');

INSERT IGNORE INTO `aics_account_statuses` (`status_code`, `status_name`) VALUES
('active', 'Active');

INSERT IGNORE INTO `aics_assistance_types` (`assistance_code`, `assistance_name`, `sort_order`) VALUES
('medical', 'Medical', 10),
('burial', 'Burial', 20),
('victim_of_disaster', 'Victim of Disaster', 30),
('special_cases', 'Special Cases', 40),
('high_inflation_affected', 'Persons Affected by High Inflation Rate', 50);

INSERT IGNORE INTO `aics_document_types` (`document_code`, `document_name`, `sort_order`) VALUES
('coe_opswdo', 'Certificate of Eligibility (Issued by OPSWDO)', 10),
('med_cert', 'Medical Certificate', 20),
('billing_statement', 'Prescription / Billing Statement', 30),
('valid_id', 'Valid ID', 40),
('coe_mswdo', 'Certificate of Person in Crisis Situation', 50),
('death_cert', 'Death Certificate', 60),
('funeral_contract', 'Funeral Contract', 70),
('mdrrmo_cert', 'Certification of Calamity Victim (MDRRMO)', 80),
('damage_photos', 'Picture of Damaged Property', 90),
('report_police_med', 'Police or Medical Report', 100),
('eclip_cert', 'Certificate from E-CLIP or JTF-ELCAC', 110),
('medical_certificate', 'Medical Certificate', 120),
('death_certificate', 'Death Certificate', 130),
('certificate_of_indigency', 'Certificate of Indigency', 140);

-- Certificate of Eligibility is OPSWDO-issued and must never be counted as an
-- applicant-uploaded requirement, including on databases upgraded in place.
DELETE ard
FROM `aics_assistance_required_documents` ard
INNER JOIN `aics_document_types` dt ON dt.id = ard.document_type_id
WHERE dt.document_code = 'coe_opswdo';

-- CPCS is an optional applicant upload for Medical/Burial, including after
-- upgrading databases that previously marked it as required.
DELETE ard
FROM `aics_assistance_required_documents` ard
INNER JOIN `aics_assistance_types` at ON at.id = ard.assistance_type_id
INNER JOIN `aics_document_types` dt ON dt.id = ard.document_type_id
WHERE dt.document_code = 'coe_mswdo'
  AND at.assistance_code IN ('medical', 'burial');

UPDATE `aics_request_prioritization` p
INNER JOIN `aics_requests` r ON r.id = p.request_id
SET p.urgency_score = 0,
    p.vulnerability_score = 0,
    p.readiness_score = 0,
    p.risk_penalty = 0,
    p.priority_score = CASE
        WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%pwd%' THEN 100
        WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%senior_citizen%' THEN 90
        ELSE 0
    END,
    p.priority_level = CASE
        WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%pwd%' THEN 'High Priority – PWD'
        WHEN LOWER(COALESCE(r.client_categories_json, '')) LIKE '%senior_citizen%' THEN 'High Priority – Senior Citizen'
        ELSE 'Normal Priority'
    END;

INSERT IGNORE INTO `aics_request_statuses` (`status_code`, `status_name`, `timeline_level`, `is_terminal`, `is_cancellable`) VALUES
('pending_review', 'Pending Review', 1, 0, 1),
('incomplete_requirements', 'Incomplete Requirements', 2, 0, 1),
('for_interview', 'For Interview / Further Screening', 2, 0, 1),
('under_review', 'Under Review', 3, 0, 1),
('approved', 'Approved', 4, 0, 1),
('not_approved', 'Not Approved', 4, 1, 0),
('released', 'Released / Distributed', 5, 1, 0),
('cancelled', 'Cancelled', 5, 1, 0);

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 30
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'med_cert'
WHERE at.assistance_code = 'medical';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 40
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'billing_statement'
WHERE at.assistance_code = 'medical';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 20
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'valid_id'
WHERE at.assistance_code = 'medical';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 30
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'death_cert'
WHERE at.assistance_code = 'burial';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 40
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'funeral_contract'
WHERE at.assistance_code = 'burial';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 20
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'valid_id'
WHERE at.assistance_code = 'burial';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 30
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'valid_id'
WHERE at.assistance_code = 'victim_of_disaster';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 50
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'damage_photos'
WHERE at.assistance_code = 'victim_of_disaster';

-- Existing databases may still have older disaster requirements.
DELETE ard
FROM `aics_assistance_required_documents` ard
INNER JOIN `aics_assistance_types` at ON at.id = ard.assistance_type_id
INNER JOIN `aics_document_types` dt ON dt.id = ard.document_type_id
WHERE at.assistance_code IN ('victim_of_disaster', 'disaster_victims')
  AND dt.document_code NOT IN ('valid_id', 'damaged_house_photo', 'damage_photos');

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 20
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'report_police_med'
WHERE at.assistance_code = 'special_cases';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 30
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'coe_mswdo'
WHERE at.assistance_code = 'special_cases';

INSERT IGNORE INTO `aics_assistance_required_documents` (`assistance_type_id`, `document_type_id`, `sort_order`)
SELECT at.id, dt.id, 20
FROM aics_assistance_types at
INNER JOIN aics_document_types dt ON dt.document_code = 'valid_id'
WHERE at.assistance_code = 'high_inflation_affected';

-- Historical municipality-only AICS map aggregates. These are not request rows.
CREATE TABLE IF NOT EXISTS `aics_historical_map_municipality_stats` (
  `municipality_psgc` VARCHAR(12) NOT NULL,
  `municipality_name` VARCHAR(120) NOT NULL,
  `client_total` INT UNSIGNED NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`municipality_psgc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `aics_historical_map_benefit_stats` (
  `benefit_label` VARCHAR(80) NOT NULL,
  `client_total` INT UNSIGNED NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`benefit_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `aics_historical_map_municipality_stats` (`municipality_psgc`, `municipality_name`, `client_total`) VALUES
('0600601000', 'Anini-Y', 91), ('0600602000', 'Barbaza', 235), ('0600603000', 'Belison', 111),
('0600604000', 'Bugasong', 195), ('0600605000', 'Caluya', 25), ('0600606000', 'Culasi', 687),
('0600607000', 'Tobias Fornier', 205), ('0600608000', 'Hamtic', 181), ('0600609000', 'Laua-An', 121),
('0600610000', 'Libertad', 81), ('0600611000', 'Pandan', 68), ('0600612000', 'Patnongon', 390),
('0600613000', 'San Jose de Buenavista', 546), ('0600614000', 'San Remigio', 336), ('0600615000', 'Sebaste', 85),
('0600616000', 'Sibalom', 775), ('0600617000', 'Tibiao', 392), ('0600618000', 'Valderrama', 73)
ON DUPLICATE KEY UPDATE `municipality_name` = VALUES(`municipality_name`), `client_total` = VALUES(`client_total`);

INSERT INTO `aics_historical_map_benefit_stats` (`benefit_label`, `client_total`) VALUES
('Medical Assistance', 2760), ('Calamity Assistance', 1125), ('Burial Assistance', 712)
ON DUPLICATE KEY UPDATE `client_total` = VALUES(`client_total`);
