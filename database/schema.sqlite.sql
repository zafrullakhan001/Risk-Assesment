-- SQLite schema for Architecture Risk Assessment

CREATE TABLE IF NOT EXISTS assessments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    solution_name TEXT NOT NULL DEFAULT '',
    vendor TEXT NOT NULL DEFAULT '',
    scope TEXT,
    architecture_model TEXT,
    reviewer TEXT NOT NULL DEFAULT '',
    assessment_date TEXT,
    file_path TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_assessments_solution_name ON assessments (solution_name);
CREATE INDEX IF NOT EXISTS idx_assessments_uploaded_at ON assessments (uploaded_at);

CREATE TABLE IF NOT EXISTS assessment_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    section TEXT NOT NULL DEFAULT '',
    check_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT '',
    risk_level TEXT NOT NULL DEFAULT '',
    notes TEXT,
    mitigation TEXT,
    owner TEXT NOT NULL DEFAULT '',
    remediation_timeline TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_assessment_items_assessment_id ON assessment_items (assessment_id);
CREATE INDEX IF NOT EXISTS idx_assessment_items_section ON assessment_items (section);
