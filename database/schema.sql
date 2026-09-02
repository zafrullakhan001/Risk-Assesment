-- Future MySQL persistence layer (not required for v1)

CREATE DATABASE IF NOT EXISTS risk_assessment
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE risk_assessment;

CREATE TABLE IF NOT EXISTS assessments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solution_name VARCHAR(255) NOT NULL DEFAULT '',
    vendor VARCHAR(255) NOT NULL DEFAULT '',
    scope TEXT,
    architecture_model TEXT,
    reviewer VARCHAR(255) NOT NULL DEFAULT '',
    assessment_date DATE NULL,
    file_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uploaded_at (uploaded_at),
    INDEX idx_solution_name (solution_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT UNSIGNED NOT NULL,
    section VARCHAR(255) NOT NULL DEFAULT '',
    check_name TEXT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT '',
    risk_level VARCHAR(50) NOT NULL DEFAULT '',
    notes TEXT,
    mitigation TEXT,
    owner VARCHAR(255) NOT NULL DEFAULT '',
    remediation_timeline VARCHAR(255) NOT NULL DEFAULT '',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_assessment_items_assessment
        FOREIGN KEY (assessment_id) REFERENCES assessments (id)
        ON DELETE CASCADE,
    INDEX idx_assessment_id (assessment_id),
    INDEX idx_section (section),
    INDEX idx_status (status),
    INDEX idx_risk_level (risk_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
