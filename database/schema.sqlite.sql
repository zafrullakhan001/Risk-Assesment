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
    workbook_json TEXT NOT NULL DEFAULT '{}',
    custom_executive_verdict TEXT NOT NULL DEFAULT '',
    custom_executive_summary TEXT NOT NULL DEFAULT '',
    owner_user_id INTEGER,
    owner_username TEXT NOT NULL DEFAULT '',
    owner_display_name TEXT NOT NULL DEFAULT '',
    owner_auth_source TEXT NOT NULL DEFAULT '',
    uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_assessments_solution_name ON assessments (solution_name);
CREATE INDEX IF NOT EXISTS idx_assessments_uploaded_at ON assessments (uploaded_at);

CREATE TABLE IF NOT EXISTS assessment_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    item_type TEXT NOT NULL DEFAULT 'architecture',
    section TEXT NOT NULL DEFAULT '',
    check_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT '',
    risk_level TEXT NOT NULL DEFAULT '',
    notes TEXT,
    mitigation TEXT,
    owner TEXT NOT NULL DEFAULT '',
    remediation_timeline TEXT NOT NULL DEFAULT '',
    review_question TEXT NOT NULL DEFAULT '',
    source_reference TEXT NOT NULL DEFAULT '',
    origin TEXT NOT NULL DEFAULT 'excel',
    sort_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_assessment_items_assessment_id ON assessment_items (assessment_id);
CREATE INDEX IF NOT EXISTS idx_assessment_items_section ON assessment_items (section);

CREATE TABLE IF NOT EXISTS item_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    item_key TEXT NOT NULL,
    action TEXT NOT NULL DEFAULT 'open',
    comment TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_by_user_id INTEGER,
    updated_by_username TEXT NOT NULL DEFAULT '',
    updated_by_display_name TEXT NOT NULL DEFAULT '',
    updated_by_auth_source TEXT NOT NULL DEFAULT '',
    UNIQUE (assessment_id, item_key),
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_item_responses_assessment_id ON item_responses (assessment_id);

CREATE TABLE IF NOT EXISTS final_evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL UNIQUE,
    evaluator_name TEXT NOT NULL DEFAULT '',
    evaluator_email TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    ready_to_golive INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_by_user_id INTEGER,
    updated_by_username TEXT NOT NULL DEFAULT '',
    updated_by_display_name TEXT NOT NULL DEFAULT '',
    updated_by_auth_source TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_final_evaluations_assessment_id ON final_evaluations (assessment_id);

CREATE TABLE IF NOT EXISTS assessment_change_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    entity_type TEXT NOT NULL,
    entity_key TEXT NOT NULL DEFAULT '',
    actor_id INTEGER,
    actor_username TEXT NOT NULL DEFAULT '',
    actor_display_name TEXT NOT NULL DEFAULT '',
    actor_auth_source TEXT NOT NULL DEFAULT '',
    summary TEXT NOT NULL DEFAULT '',
    details TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_assessment_change_log_assessment_id ON assessment_change_log (assessment_id);
CREATE INDEX IF NOT EXISTS idx_assessment_change_log_entity ON assessment_change_log (assessment_id, entity_type, entity_key);

CREATE TABLE IF NOT EXISTS project_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    label TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_project_links_assessment_id ON project_links (assessment_id);

CREATE TABLE IF NOT EXISTS project_mermaid_diagrams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_project_mermaid_diagrams_assessment_id ON project_mermaid_diagrams (assessment_id);

CREATE TABLE IF NOT EXISTS project_pictures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    mime_type TEXT NOT NULL DEFAULT 'image/png',
    image_base64 TEXT NOT NULL DEFAULT '',
    original_filename TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_project_pictures_assessment_id ON project_pictures (assessment_id);

CREATE TABLE IF NOT EXISTS finding_statuses (
    assessment_id INTEGER NOT NULL,
    finding_id TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'Open',
    comment TEXT NOT NULL DEFAULT '',
    servicenow_links TEXT NOT NULL DEFAULT '[]',
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (assessment_id, finding_id),
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_finding_statuses_assessment_id ON finding_statuses (assessment_id);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    email TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    is_approved INTEGER NOT NULL DEFAULT 0,
    is_disabled INTEGER NOT NULL DEFAULT 0,
    auth_source TEXT NOT NULL DEFAULT 'local',
    display_name TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    last_login TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    created_by_user_id INTEGER,
    created_by_username TEXT NOT NULL DEFAULT '',
    UNIQUE (username),
    UNIQUE (email)
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);
CREATE INDEX IF NOT EXISTS idx_users_auth_source ON users (auth_source);

CREATE TABLE IF NOT EXISTS user_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event TEXT NOT NULL,
    actor_id INTEGER,
    actor_username TEXT,
    target_user_id INTEGER,
    target_username TEXT,
    details TEXT NOT NULL DEFAULT '',
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_user_audit_log_created_at ON user_audit_log (created_at);
CREATE INDEX IF NOT EXISTS idx_user_audit_log_event ON user_audit_log (event);

CREATE TABLE IF NOT EXISTS assessment_share_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL DEFAULT '',
    created_by_user_id INTEGER,
    created_by_username TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT,
    revoked_at TEXT,
    last_accessed_at TEXT,
    FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_assessment_share_links_assessment_id ON assessment_share_links (assessment_id);
CREATE INDEX IF NOT EXISTS idx_assessment_share_links_token_hash ON assessment_share_links (token_hash);

CREATE TABLE IF NOT EXISTS template_workbooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL DEFAULT '',
    workbook_path TEXT NOT NULL,
    workbook_filename TEXT NOT NULL DEFAULT '',
    workbook_size INTEGER NOT NULL DEFAULT 0,
    prompt_path TEXT NOT NULL DEFAULT '',
    prompt_filename TEXT NOT NULL DEFAULT '',
    prompt_size INTEGER NOT NULL DEFAULT 0,
    mermaid_title TEXT NOT NULL DEFAULT '',
    mermaid_source TEXT NOT NULL DEFAULT '',
    uploaded_by_user_id INTEGER,
    uploaded_by_username TEXT NOT NULL DEFAULT '',
    uploaded_by_display_name TEXT NOT NULL DEFAULT '',
    uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_template_workbooks_uploaded_at ON template_workbooks (uploaded_at);

CREATE TABLE IF NOT EXISTS template_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    mime_type TEXT NOT NULL DEFAULT 'image/png',
    image_base64 TEXT NOT NULL DEFAULT '',
    original_filename TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (template_id) REFERENCES template_workbooks (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_template_images_template_id ON template_images (template_id);
