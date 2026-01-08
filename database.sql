-- DDL for CulturalActivity (inferred from code usage)
-- Core tables (portal DB)

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    login_id VARCHAR(64) NOT NULL,
    position VARCHAR(100) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_login_id (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_name VARCHAR(200) NOT NULL,
    program_description TEXT NOT NULL,
    activity_date DATE NOT NULL,
    activity_time TIME DEFAULT NULL,
    location VARCHAR(200) NOT NULL,
    requires_gown_size TINYINT(1) NOT NULL DEFAULT 0,
    gown_capacity_s INT DEFAULT NULL,
    gown_capacity_m INT DEFAULT NULL,
    gown_capacity_l INT DEFAULT NULL,
    capacity INT DEFAULT NULL,
    current_enrollment INT NOT NULL DEFAULT 0,
    has_fee TINYINT(1) NOT NULL DEFAULT 0,
    fee_amount DECIMAL(10,2) DEFAULT NULL,
    registration_start_date DATETIME NOT NULL,
    registration_end_date DATETIME NOT NULL,
    cancellation_deadline DATETIME DEFAULT NULL,
    main_image_path VARCHAR(500) NOT NULL,
    qr_code VARCHAR(128) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cultural_activities_date (activity_date),
    KEY idx_cultural_activities_active (is_active, is_deleted),
    KEY idx_cultural_activities_creator (created_by),
    CONSTRAINT fk_cultural_activities_admin
        FOREIGN KEY (created_by) REFERENCES admins (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    activity_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_images_activity (activity_id),
    CONSTRAINT fk_activity_images_activity
        FOREIGN KEY (activity_id) REFERENCES cultural_activities (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_enrollments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    activity_id INT UNSIGNED NOT NULL,
    student_id VARCHAR(32) NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    gown_size VARCHAR(5) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'approved',
    enrollment_type VARCHAR(20) NOT NULL DEFAULT 'student',
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fee_paid TINYINT(1) NOT NULL DEFAULT 0,
    checked_in TINYINT(1) NOT NULL DEFAULT 0,
    check_in_time DATETIME DEFAULT NULL,
    gown_rented_at DATETIME DEFAULT NULL,
    gown_returned_at DATETIME DEFAULT NULL,
    cancelled_by VARCHAR(20) DEFAULT NULL,
    admin_reason TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrollments_activity_student (activity_id, student_id),
    KEY idx_enrollments_activity (activity_id),
    KEY idx_enrollments_student (student_id),
    KEY idx_enrollments_status (status),
    CONSTRAINT fk_enrollments_activity
        FOREIGN KEY (activity_id) REFERENCES cultural_activities (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_enrollment_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrollment_id INT UNSIGNED NOT NULL,
    student_id VARCHAR(32) NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    activity_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    action_details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_enrollment_history_enrollment (enrollment_id),
    KEY idx_enrollment_history_activity (activity_id),
    KEY idx_enrollment_history_student (student_id),
    CONSTRAINT fk_enrollment_history_enrollment
        FOREIGN KEY (enrollment_id) REFERENCES cultural_activity_enrollments (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_enrollment_history_activity
        FOREIGN KEY (activity_id) REFERENCES cultural_activities (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_bans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id VARCHAR(32) NOT NULL,
    ban_type VARCHAR(16) NOT NULL DEFAULT 'specific',
    activity_id INT UNSIGNED DEFAULT NULL,
    ban_reason TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    banned_by INT UNSIGNED DEFAULT NULL,
    banned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bans_student (student_id),
    KEY idx_bans_activity (activity_id),
    KEY idx_bans_active (is_active),
    CONSTRAINT fk_bans_activity
        FOREIGN KEY (activity_id) REFERENCES cultural_activities (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bans_admin
        FOREIGN KEY (banned_by) REFERENCES admins (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_faqs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_faqs_active (is_active),
    KEY idx_faqs_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_board_posts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    activity_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    author_admin_id INT UNSIGNED DEFAULT NULL,
    author_name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_board_posts_activity (activity_id),
    KEY idx_board_posts_pinned (is_pinned),
    CONSTRAINT fk_board_posts_activity
        FOREIGN KEY (activity_id) REFERENCES cultural_activities (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_board_posts_admin
        FOREIGN KEY (author_admin_id) REFERENCES admins (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_board_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_board_files_post (post_id),
    CONSTRAINT fk_board_files_post
        FOREIGN KEY (post_id) REFERENCES cultural_activity_board_posts (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_checkin_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token VARCHAR(128) NOT NULL,
    label VARCHAR(200) NOT NULL,
    expires_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checkin_tokens_token (token),
    KEY idx_checkin_tokens_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_admin_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id VARCHAR(64) NOT NULL,
    activity_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_logs_admin (admin_id),
    KEY idx_admin_logs_activity (activity_id),
    CONSTRAINT fk_admin_logs_activity
        FOREIGN KEY (activity_id) REFERENCES cultural_activities (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cultural_activity_student_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id VARCHAR(32) NOT NULL,
    activity_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_logs_student (student_id),
    KEY idx_student_logs_activity (activity_id),
    CONSTRAINT fk_student_logs_activity
        FOREIGN KEY (activity_id) REFERENCES cultural_activities (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- External tables (UwaySync DB) - minimal columns used by this app

CREATE TABLE IF NOT EXISTS uway_user_current (
    user_id VARCHAR(32) NOT NULL,
    passwd VARCHAR(255) NOT NULL,
    institution_role VARCHAR(50) DEFAULT NULL,
    firstname VARCHAR(50) DEFAULT NULL,
    lastname VARCHAR(50) DEFAULT NULL,
    company VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS uwayxlsx_current (
    application_no VARCHAR(32) NOT NULL,
    applicant_name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(200) DEFAULT NULL,
    origin_school VARCHAR(200) DEFAULT NULL,
    birthdate DATE DEFAULT NULL,
    nationality VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (application_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
