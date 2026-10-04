-- =====================================================================
-- EVORA: An Integrated Web Application for Event Management
-- Database Schema (MySQL / InnoDB, 3NF)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS evora_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE evora_db;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Core identity & RBAC
-- ---------------------------------------------------------------------

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name ENUM('admin','organizer','staff','participant') NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    profile_picture_path VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_activity_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Organizer verification
-- ---------------------------------------------------------------------

CREATE TABLE organizers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    organization_name VARCHAR(150) NOT NULL,
    contact_number VARCHAR(30) NOT NULL,
    organization_info TEXT,
    status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_organizers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_organizers_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE organizer_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    valid_id_path VARCHAR(255) NOT NULL,
    supporting_docs_path VARCHAR(255) DEFAULT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_overif_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Events
-- ---------------------------------------------------------------------

CREATE TABLE event_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    category_id INT NOT NULL,
    sport_id INT DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    banner_path VARCHAR(255) DEFAULT NULL,
    event_date DATE NOT NULL,
    event_time TIME NOT NULL,
    registration_deadline DATE DEFAULT NULL,
    venue VARCHAR(200) NOT NULL,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fee_mode ENUM('individual','team') NOT NULL DEFAULT 'individual',
    manual_payment_enabled TINYINT(1) NOT NULL DEFAULT 1,
    gcash_qr_path VARCHAR(255) DEFAULT NULL,
    gcash_number VARCHAR(30) DEFAULT NULL,
    gcash_account_name VARCHAR(150) DEFAULT NULL,
    max_participants INT NOT NULL DEFAULT 0,
    max_teams INT DEFAULT NULL,
    tournament_format ENUM('single_elim','double_elim','round_robin','league') DEFAULT NULL,
    game_duration_minutes INT DEFAULT NULL,
    periods_count INT DEFAULT NULL,
    requirements TEXT,
    rules TEXT,
    contact_info VARCHAR(255) DEFAULT NULL,
    status ENUM('draft','pending_approval','approved','open','closed','ongoing','completed','cancelled','removed','archived') NOT NULL DEFAULT 'draft',
    admin_remarks VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE event_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) NOT NULL,
    changed_by INT DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_esl_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_esl_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE event_staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    assignment_area VARCHAR(100) DEFAULT NULL,
    task_note VARCHAR(255) DEFAULT NULL,
    task_status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
    assigned_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_staff (event_id, user_id),
    CONSTRAINT fk_estaff_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_estaff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_estaff_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE staff_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_staff_id INT NOT NULL,
    permission_key ENUM('scan_qr','verify_participant','record_attendance','manage_claims','view_announcements','manage_walkin','manage_live_scores','manage_incidents','manage_bibs','manage_finish_results','manage_checkpoints','manage_medical','view_race_operations','manage_sessions','manage_speakers','manage_feedback') NOT NULL,
    UNIQUE KEY uq_staff_permission (event_staff_id, permission_key),
    CONSTRAINT fk_sperm_estaff FOREIGN KEY (event_staff_id) REFERENCES event_staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Sports Tournament sub-module
-- ---------------------------------------------------------------------

CREATE TABLE sports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    sport_type ENUM('team','individual') NOT NULL DEFAULT 'team',
    team_size INT DEFAULT NULL,
    player_format VARCHAR(50) DEFAULT NULL,
    scoring_method ENUM('points','sets','rounds','win_draw_loss','time_rank') NOT NULL DEFAULT 'points',
    default_tournament_format ENUM('single_elim','double_elim','round_robin','league') NOT NULL DEFAULT 'single_elim',
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sport_id INT NOT NULL,
    team_name VARCHAR(150) NOT NULL,
    coach_name VARCHAR(150) DEFAULT NULL,
    captain_participant_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_teams_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_teams_sport FOREIGN KEY (sport_id) REFERENCES sports(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    participant_id INT NOT NULL,
    UNIQUE KEY uq_team_member (team_id, participant_id),
    CONSTRAINT fk_tmember_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sport_id INT NOT NULL,
    team_a_id INT DEFAULT NULL,
    team_b_id INT DEFAULT NULL,
    participant_a_id INT DEFAULT NULL,
    participant_b_id INT DEFAULT NULL,
    scheduled_at DATETIME DEFAULT NULL,
    venue VARCHAR(200) DEFAULT NULL,
    referee_staff_id INT DEFAULT NULL,
    round_no INT NOT NULL DEFAULT 1,
    next_match_id INT DEFAULT NULL,
    loser_next_match_id INT DEFAULT NULL,
    status ENUM('scheduled','ongoing','paused','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    CONSTRAINT fk_matches_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_sport FOREIGN KEY (sport_id) REFERENCES sports(id) ON DELETE RESTRICT,
    CONSTRAINT fk_matches_team_a FOREIGN KEY (team_a_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_team_b FOREIGN KEY (team_b_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_referee FOREIGN KEY (referee_staff_id) REFERENCES event_staff(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_next FOREIGN KEY (next_match_id) REFERENCES matches(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_loser_next FOREIGN KEY (loser_next_match_id) REFERENCES matches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE venues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) DEFAULT NULL,
    venue_type VARCHAR(50) NOT NULL,
    location VARCHAR(150) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    capacity INT DEFAULT NULL,
    status ENUM('available','maintenance','closed') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_venues_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE match_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL UNIQUE,
    team_a_score INT DEFAULT NULL,
    team_b_score INT DEFAULT NULL,
    winner_team_id INT DEFAULT NULL,
    winner_participant_id INT DEFAULT NULL,
    score_detail JSON DEFAULT NULL,
    recorded_by INT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mscore_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    CONSTRAINT fk_mscore_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mscore_winner_team FOREIGN KEY (winner_team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE tournament_brackets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    round_no INT NOT NULL,
    match_id INT NOT NULL,
    position INT NOT NULL,
    CONSTRAINT fk_bracket_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_bracket_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE player_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    participant_id INT NOT NULL,
    team_id INT DEFAULT NULL,
    stat_key VARCHAR(50) NOT NULL,
    stat_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    recorded_by INT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_player_stat (match_id, participant_id, stat_key),
    CONSTRAINT fk_pstat_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    CONSTRAINT fk_pstat_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_pstat_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Seminar / Workshop sub-module
-- ---------------------------------------------------------------------

CREATE TABLE event_speakers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    speaker_name VARCHAR(150) NOT NULL,
    speaker_bio TEXT,
    photo_path VARCHAR(255) DEFAULT NULL,
    organization VARCHAR(150) DEFAULT NULL,
    position VARCHAR(100) DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    social_links VARCHAR(500) DEFAULT NULL,
    topic VARCHAR(200) DEFAULT NULL,
    CONSTRAINT fk_speaker_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    session_date DATE DEFAULT NULL,
    session_title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    venue VARCHAR(200) DEFAULT NULL,
    session_type ENUM('keynote','lecture','workshop','breakout','panel','hands_on') NOT NULL DEFAULT 'lecture',
    status ENUM('scheduled','ongoing','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    speaker_id INT DEFAULT NULL,
    CONSTRAINT fk_schedule_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_speaker FOREIGN KEY (speaker_id) REFERENCES event_speakers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE session_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    registration_id INT NOT NULL,
    scanned_by INT NOT NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_attendance (session_id, registration_id),
    CONSTRAINT fk_sessatt_session FOREIGN KEY (session_id) REFERENCES event_schedule(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessatt_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessatt_scanner FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Fun Run sub-module
-- ---------------------------------------------------------------------

CREATE TABLE event_checkpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sequence_no INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    checkpoint_type ENUM('start','finish','checkpoint','water_station','medical_station','emergency_point','turnaround_point') NOT NULL DEFAULT 'checkpoint',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    distance_km DECIMAL(5,2) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_checkpoint_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE checkpoint_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    checkpoint_id INT NOT NULL,
    registration_id INT NOT NULL,
    scanned_by INT NOT NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_checkpoint_scan (checkpoint_id, registration_id),
    CONSTRAINT fk_cpscan_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES event_checkpoints(id) ON DELETE CASCADE,
    CONSTRAINT fk_cpscan_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cpscan_scanner FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE race_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_participants INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_category (event_id, name),
    CONSTRAINT fk_racecat_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_route_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_routeimg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL UNIQUE,
    certificate_path VARCHAR(255) DEFAULT NULL,
    certificate_number VARCHAR(50) DEFAULT NULL,
    certificate_type VARCHAR(100) NOT NULL DEFAULT 'Certificate of Participation',
    verification_token VARCHAR(64) DEFAULT NULL,
    issued_at DATETIME DEFAULT NULL,
    generated_by INT DEFAULT NULL,
    status ENUM('issued','revoked') NOT NULL DEFAULT 'issued',
    UNIQUE KEY uq_cert_number (certificate_number),
    UNIQUE KEY uq_cert_token (verification_token),
    CONSTRAINT fk_cert_generated_by FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE event_certificate_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL UNIQUE,
    certificate_title VARCHAR(150) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    venue_override VARCHAR(200) DEFAULT NULL,
    organization_name_override VARCHAR(150) DEFAULT NULL,
    organizer_name_override VARCHAR(150) DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    signatory1_name VARCHAR(150) DEFAULT NULL,
    signatory1_title VARCHAR(150) DEFAULT NULL,
    signatory1_signature_path VARCHAR(255) DEFAULT NULL,
    signatory2_name VARCHAR(150) DEFAULT NULL,
    signatory2_title VARCHAR(150) DEFAULT NULL,
    signatory2_signature_path VARCHAR(255) DEFAULT NULL,
    auto_issue_enabled TINYINT(1) NOT NULL DEFAULT 0,
    attendance_required TINYINT(1) NOT NULL DEFAULT 0,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_certsettings_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_certsettings_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Participants & registration
-- ---------------------------------------------------------------------

CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    birthdate DATE DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    emergency_contact VARCHAR(100) DEFAULT NULL,
    CONSTRAINT fk_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    participant_id INT NOT NULL,
    team_id INT DEFAULT NULL,
    distance_category VARCHAR(50) DEFAULT NULL,
    race_category_id INT DEFAULT NULL,
    bib_number VARCHAR(20) DEFAULT NULL,
    organization VARCHAR(150) DEFAULT NULL,
    profession VARCHAR(100) DEFAULT NULL,
    dietary_preference VARCHAR(100) DEFAULT NULL,
    special_requirements VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requirements_upload_path VARCHAR(255) DEFAULT NULL,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_registration (event_id, participant_id),
    UNIQUE KEY uq_event_bib (event_id, bib_number),
    CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_reg_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_reg_racecat FOREIGN KEY (race_category_id) REFERENCES race_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE race_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL UNIQUE,
    finish_time DATETIME NOT NULL,
    recorded_by INT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_raceresult_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_raceresult_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE events
    ADD CONSTRAINT fk_events_sport FOREIGN KEY (sport_id) REFERENCES sports(id) ON DELETE SET NULL;

ALTER TABLE certificates
    ADD CONSTRAINT fk_cert_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE;

ALTER TABLE teams
    ADD CONSTRAINT fk_teams_captain FOREIGN KEY (captain_participant_id) REFERENCES participants(id) ON DELETE SET NULL;

ALTER TABLE team_members
    ADD CONSTRAINT fk_tmember_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE;

ALTER TABLE matches
    ADD CONSTRAINT fk_matches_participant_a FOREIGN KEY (participant_a_id) REFERENCES participants(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_matches_participant_b FOREIGN KEY (participant_b_id) REFERENCES participants(id) ON DELETE SET NULL;

ALTER TABLE match_scores
    ADD CONSTRAINT fk_mscore_winner_participant FOREIGN KEY (winner_participant_id) REFERENCES participants(id) ON DELETE SET NULL;

ALTER TABLE player_statistics
    ADD CONSTRAINT fk_pstat_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE;

-- ---------------------------------------------------------------------
-- Payments
-- ---------------------------------------------------------------------

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(30) DEFAULT NULL,
    registration_id INT NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('gcash','bank_transfer','cash') DEFAULT NULL,
    payment_channel ENUM('manual','paymongo','cash_walkin') NOT NULL DEFAULT 'manual',
    checkout_session_id VARCHAR(100) DEFAULT NULL,
    gateway_payment_id VARCHAR(100) DEFAULT NULL,
    gateway_payment_method VARCHAR(30) DEFAULT NULL,
    gateway_fee DECIMAL(10,2) DEFAULT NULL,
    net_amount DECIMAL(10,2) DEFAULT NULL,
    confirmed_via ENUM('return','webhook','reconcile','checkout','autocheck') DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    webhook_event_id VARCHAR(100) DEFAULT NULL,
    proof_path VARCHAR(255) DEFAULT NULL,
    proof_hash CHAR(64) DEFAULT NULL,
    status ENUM('pending','approved','rejected','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(500) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pay_reference (reference_no),
    UNIQUE KEY uq_pay_checkout_session (checkout_session_id),
    UNIQUE KEY uq_pay_webhook_event (webhook_event_id),
    INDEX idx_pay_proof_hash (proof_hash),
    CONSTRAINT fk_pay_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    performed_by INT DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payhist_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_payhist_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payment_checkout_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    checkout_session_id VARCHAR(100) NOT NULL,
    status ENUM('open','paid','expired','cancelled') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pcs_session (checkout_session_id),
    INDEX idx_pcs_payment (payment_id),
    CONSTRAINT fk_pcs_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE paymongo_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    checkout_session_id VARCHAR(100) DEFAULT NULL,
    payment_id INT DEFAULT NULL,
    result VARCHAR(40) NOT NULL DEFAULT 'received',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pwe_event (event_id),
    INDEX idx_pwe_payment (payment_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- QR / attendance / item claiming
-- ---------------------------------------------------------------------

CREATE TABLE qr_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL UNIQUE,
    code_token VARCHAR(255) NOT NULL UNIQUE,
    qr_image_path VARCHAR(255) NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL,
    event_id INT NOT NULL,
    staff_id INT NOT NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('present') NOT NULL DEFAULT 'present',
    UNIQUE KEY uq_attendance (registration_id, event_id),
    CONSTRAINT fk_att_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    item_type ENUM('kit','shirt','bib','medal','other') NOT NULL DEFAULT 'other',
    variant_label VARCHAR(50) DEFAULT NULL,
    total_quantity INT NOT NULL DEFAULT 0,
    claimed_quantity INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_item_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE item_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_item_id INT NOT NULL,
    registration_id INT NOT NULL,
    staff_id INT NOT NULL,
    claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_claim (event_item_id, registration_id),
    CONSTRAINT fk_claim_item FOREIGN KEY (event_item_id) REFERENCES event_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_claim_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_claim_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Notifications, reports, audit
-- ---------------------------------------------------------------------

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message VARCHAR(500) NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sent_by INT NOT NULL,
    message VARCHAR(500) NOT NULL,
    phase ENUM('before','during','after') DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_eventannc_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eventannc_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_eventannc_event (event_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE event_tool_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    tool_key VARCHAR(50) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tooloverride_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tooloverride (event_id, tool_key)
) ENGINE=InnoDB;

CREATE TABLE event_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    match_id INT DEFAULT NULL,
    venue_id INT DEFAULT NULL,
    incident_type ENUM('player_misconduct','team_complaint','referee_issue','schedule_delay','venue_problem','safety_issue','equipment_issue','other') NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    description TEXT NOT NULL,
    status ENUM('open','under_review','resolved','dismissed') NOT NULL DEFAULT 'open',
    attachment_path VARCHAR(255) DEFAULT NULL,
    reported_by INT NOT NULL,
    resolved_by INT DEFAULT NULL,
    resolution_notes VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_incident_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_incident_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE SET NULL,
    CONSTRAINT fk_incident_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
    CONSTRAINT fk_incident_reporter FOREIGN KEY (reported_by) REFERENCES users(id),
    CONSTRAINT fk_incident_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_incident_event (event_id, status)
) ENGINE=InnoDB;

CREATE TABLE race_medical_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    registration_id INT DEFAULT NULL,
    checkpoint_id INT DEFAULT NULL,
    incident_type ENUM('injury','dehydration','fatigue','cardiac','fall','other') NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    description TEXT NOT NULL,
    action_taken VARCHAR(500) DEFAULT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    reported_by INT NOT NULL,
    resolved_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_medinc_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_medinc_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE SET NULL,
    CONSTRAINT fk_medinc_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES event_checkpoints(id) ON DELETE SET NULL,
    CONSTRAINT fk_medinc_reporter FOREIGN KEY (reported_by) REFERENCES users(id),
    CONSTRAINT fk_medinc_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_medinc_event (event_id, status)
) ENGINE=InnoDB;

CREATE TABLE event_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    registration_id INT NOT NULL,
    overall_rating TINYINT NOT NULL CHECK (overall_rating BETWEEN 1 AND 5),
    speaker_rating TINYINT DEFAULT NULL CHECK (speaker_rating BETWEEN 1 AND 5),
    session_rating TINYINT DEFAULT NULL CHECK (session_rating BETWEEN 1 AND 5),
    venue_rating TINYINT DEFAULT NULL CHECK (venue_rating BETWEEN 1 AND 5),
    suggestions VARCHAR(1000) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_feedback_registration (event_id, registration_id),
    CONSTRAINT fk_feedback_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    generated_by INT NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    scope ENUM('event','organizer','platform') NOT NULL,
    related_id INT DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_report_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE system_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    functionality TINYINT NOT NULL CHECK (functionality BETWEEN 1 AND 5),
    usability TINYINT NOT NULL CHECK (usability BETWEEN 1 AND 5),
    reliability TINYINT NOT NULL CHECK (reliability BETWEEN 1 AND 5),
    performance_efficiency TINYINT NOT NULL CHECK (performance_efficiency BETWEEN 1 AND 5),
    security TINYINT NOT NULL CHECK (security BETWEEN 1 AND 5),
    compatibility TINYINT NOT NULL CHECK (compatibility BETWEEN 1 AND 5),
    maintainability TINYINT NOT NULL CHECK (maintainability BETWEEN 1 AND 5),
    portability TINYINT NOT NULL CHECK (portability BETWEEN 1 AND 5),
    comment VARCHAR(1000) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_eval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    id INT PRIMARY KEY DEFAULT 1,
    system_name VARCHAR(150) NOT NULL DEFAULT 'EVORA',
    system_description TEXT DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(30) DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    default_announcement_note TEXT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO system_settings (id, system_name) VALUES (1, 'EVORA');

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- v25: organizer ledger, settlements, payout accounts, payouts
-- (same as database/migration_v25.sql)
-- =====================================================================
CREATE TABLE settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    settlement_no VARCHAR(30) NOT NULL,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    sequence_no INT NOT NULL DEFAULT 1,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    entry_count INT NOT NULL DEFAULT 0,
    gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fee_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deduction_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    settlement_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
    reason VARCHAR(500) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    cancelled_by INT DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancel_reason VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY uq_settlement_no (settlement_no),
    UNIQUE KEY uq_settlement_event_seq (event_id, sequence_no),
    INDEX idx_settlement_org_status (organizer_id, status),
    CONSTRAINT fk_settlement_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_settlement_no_commission CHECK (deduction_total = 0.00),
    CONSTRAINT chk_settlement_formula CHECK (net_total = gross_total - fee_total AND settlement_amount = net_total - deduction_total)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Organizer ledger: one immutable row per confirmed PayMongo payment,
-- plus reversal rows for corrections. State (pending / on hold / eligible /
-- in settlement / settled / paid / reversed) is derived from these facts by
-- LedgerService, so it can never drift out of sync.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_ledger_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    registration_id INT DEFAULT NULL,
    payment_id INT DEFAULT NULL,
    entry_type ENUM('payment','reversal') NOT NULL,
    source_key VARCHAR(100) NOT NULL,
    paymongo_payment_id VARCHAR(100) DEFAULT NULL,
    checkout_session_id VARCHAR(100) DEFAULT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    gross_amount DECIMAL(10,2) NOT NULL,
    gateway_fee DECIMAL(10,2) DEFAULT NULL,
    net_amount DECIMAL(10,2) DEFAULT NULL,
    deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    organizer_earnings DECIMAL(10,2) DEFAULT NULL,
    event_date DATE NOT NULL,
    hold_until DATE NOT NULL,
    hold_reason ENUM('fee_unknown','amount_mismatch','manual_hold') DEFAULT NULL,
    reverses_entry_id INT DEFAULT NULL,
    settlement_id INT DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ledger_source (source_key),
    UNIQUE KEY uq_ledger_reverses (reverses_entry_id),
    INDEX idx_ledger_org_settlement (organizer_id, settlement_id),
    INDEX idx_ledger_event_settlement (event_id, settlement_id),
    INDEX idx_ledger_payment (payment_id),
    CONSTRAINT fk_ledger_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_reverses FOREIGN KEY (reverses_entry_id) REFERENCES organizer_ledger_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_ledger_no_commission CHECK (deduction_amount = 0.00),
    CONSTRAINT chk_ledger_formula CHECK (
        (gateway_fee IS NULL AND net_amount IS NULL AND organizer_earnings IS NULL AND hold_reason = 'fee_unknown')
        OR (gateway_fee IS NOT NULL AND net_amount = gross_amount - gateway_fee AND organizer_earnings = net_amount - deduction_amount)
    ),
    CONSTRAINT chk_ledger_reversal_link CHECK ((entry_type = 'reversal') = (reverses_entry_id IS NOT NULL))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Organizer payout destination. Rows are never edited: a change is a new
-- 'pending' row and the old one becomes 'inactive', so every payout keeps
-- pointing at the exact account it was sent to. No secrets are stored.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_payout_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    method ENUM('gcash','bank') NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(40) NOT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','verified','rejected','inactive') NOT NULL DEFAULT 'pending',
    submitted_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_remarks VARCHAR(500) DEFAULT NULL,
    INDEX idx_payout_account_org_status (organizer_id, status),
    CONSTRAINT fk_payout_account_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_account_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_account_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payout_account_bank CHECK (method <> 'bank' OR (bank_name IS NOT NULL AND bank_name <> ''))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Payouts: recorded manually by an admin after sending the money outside
-- EVORA. Amount must equal the approved settlement amount (trigger).
-- At most one pending/processing/paid payout per settlement (unique key on
-- a generated column); a failed/cancelled payout stays as history.
-- ---------------------------------------------------------------------
CREATE TABLE payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_no VARCHAR(30) NOT NULL,
    settlement_id INT NOT NULL,
    organizer_id INT NOT NULL,
    payout_account_id INT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('gcash','bank') NOT NULL,
    account_name_snapshot VARCHAR(150) NOT NULL,
    account_number_masked VARCHAR(40) NOT NULL,
    bank_name_snapshot VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','processing','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
    reference_no VARCHAR(100) DEFAULT NULL,
    proof_path VARCHAR(255) DEFAULT NULL,
    status_reason VARCHAR(500) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_by INT DEFAULT NULL,
    processing_at DATETIME DEFAULT NULL,
    finished_by INT DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    active_settlement_id INT AS (IF(status IN ('pending','processing','paid'), settlement_id, NULL)) PERSISTENT,
    paid_reference_key VARCHAR(120) AS (IF(status = 'paid', CONCAT(method, ':', reference_no), NULL)) PERSISTENT,
    UNIQUE KEY uq_payout_no (payout_no),
    UNIQUE KEY uq_payout_active_settlement (active_settlement_id),
    UNIQUE KEY uq_payout_paid_reference (paid_reference_key),
    INDEX idx_payout_org_status (organizer_id, status),
    INDEX idx_payout_settlement (settlement_id),
    CONSTRAINT fk_payout_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_account FOREIGN KEY (payout_account_id) REFERENCES organizer_payout_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_processing_by FOREIGN KEY (processing_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_finished_by FOREIGN KEY (finished_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payout_amount CHECK (amount > 0),
    CONSTRAINT chk_payout_paid_reference CHECK (status <> 'paid' OR (reference_no IS NOT NULL AND reference_no <> ''))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Append-only history of every financial status change.
-- ---------------------------------------------------------------------
CREATE TABLE finance_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('ledger_entry','settlement','payout','payout_account') NOT NULL,
    entity_id INT NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) NOT NULL,
    actor_user_id INT DEFAULT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fsh_entity (entity_type, entity_id),
    CONSTRAINT fk_fsh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE system_settings
    ADD COLUMN settlement_hold_days INT NOT NULL DEFAULT 3,
    ADD CONSTRAINT chk_settlement_hold_days CHECK (settlement_hold_days BETWEEN 0 AND 30);

-- ---------------------------------------------------------------------
-- Immutability triggers
-- ---------------------------------------------------------------------
DELIMITER //

CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON organizer_ledger_entries FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries cannot be deleted; record a reversal entry instead.';
END//

CREATE TRIGGER trg_ledger_guard_update BEFORE UPDATE ON organizer_ledger_entries FOR EACH ROW
BEGIN
    DECLARE v_settlement_status VARCHAR(20) DEFAULT NULL;

    IF NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NOT (NEW.registration_id <=> OLD.registration_id) OR NOT (NEW.payment_id <=> OLD.payment_id)
       OR NEW.entry_type <> OLD.entry_type OR NEW.source_key <> OLD.source_key
       OR NOT (NEW.paymongo_payment_id <=> OLD.paymongo_payment_id) OR NOT (NEW.checkout_session_id <=> OLD.checkout_session_id)
       OR NEW.currency <> OLD.currency OR NEW.gross_amount <> OLD.gross_amount
       OR NEW.deduction_amount <> OLD.deduction_amount OR NEW.event_date <> OLD.event_date
       OR NEW.hold_until <> OLD.hold_until OR NOT (NEW.reverses_entry_id <=> OLD.reverses_entry_id)
       OR NOT (NEW.remarks <=> OLD.remarks) OR NOT (NEW.created_by <=> OLD.created_by) OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entry identity and gross amount are immutable.';
    END IF;

    -- The only amount write ever allowed: a one-time fee fill on an
    -- unsettled entry recorded without a known fee.
    IF NOT (NEW.gateway_fee <=> OLD.gateway_fee) OR NOT (NEW.net_amount <=> OLD.net_amount)
       OR NOT (NEW.organizer_earnings <=> OLD.organizer_earnings) THEN
        IF OLD.gateway_fee IS NOT NULL OR OLD.settlement_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recorded ledger amounts are immutable.';
        END IF;
    END IF;

    -- Entries of an approved settlement are frozen.
    IF OLD.settlement_id IS NOT NULL THEN
        SELECT status INTO v_settlement_status FROM settlements WHERE id = OLD.settlement_id;
        IF v_settlement_status = 'approved'
           AND (NOT (NEW.settlement_id <=> OLD.settlement_id) OR NOT (NEW.hold_reason <=> OLD.hold_reason)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Entries of an approved settlement cannot change.';
        END IF;
    END IF;
END//

CREATE TRIGGER trg_settlement_no_delete BEFORE DELETE ON settlements FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlements cannot be deleted; cancel a draft instead.';
END//

CREATE TRIGGER trg_settlement_guard_update BEFORE UPDATE ON settlements FOR EACH ROW
BEGIN
    IF OLD.status IN ('approved', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved or cancelled settlements are immutable.';
    END IF;
    IF NEW.settlement_no <> OLD.settlement_no OR NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NEW.sequence_no <> OLD.sequence_no OR NEW.currency <> OLD.currency OR NEW.entry_count <> OLD.entry_count
       OR NEW.gross_total <> OLD.gross_total OR NEW.fee_total <> OLD.fee_total OR NEW.net_total <> OLD.net_total
       OR NEW.deduction_total <> OLD.deduction_total OR NEW.settlement_amount <> OLD.settlement_amount
       OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlement totals and identity are immutable.';
    END IF;
END//

CREATE TRIGGER trg_payout_account_no_delete BEFORE DELETE ON organizer_payout_accounts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout accounts cannot be deleted; they become inactive.';
END//

CREATE TRIGGER trg_payout_account_guard_update BEFORE UPDATE ON organizer_payout_accounts FOR EACH ROW
BEGIN
    IF NEW.organizer_id <> OLD.organizer_id OR NEW.method <> OLD.method OR NEW.account_name <> OLD.account_name
       OR NEW.account_number <> OLD.account_number OR NOT (NEW.bank_name <=> OLD.bank_name)
       OR NEW.submitted_by <> OLD.submitted_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout account details are immutable; submit a new account.';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('verified', 'rejected', 'inactive'))
        OR (OLD.status = 'verified' AND NEW.status = 'inactive')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout account status change.';
    END IF;
END//

CREATE TRIGGER trg_payout_validate_insert BEFORE INSERT ON payouts FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_amount DECIMAL(12,2) DEFAULT NULL;
    DECLARE v_organizer INT DEFAULT NULL;
    DECLARE v_account_org INT DEFAULT NULL;
    DECLARE v_account_status VARCHAR(20) DEFAULT NULL;

    SELECT status, settlement_amount, organizer_id INTO v_status, v_amount, v_organizer
    FROM settlements WHERE id = NEW.settlement_id;
    SELECT organizer_id, status INTO v_account_org, v_account_status
    FROM organizer_payout_accounts WHERE id = NEW.payout_account_id;

    IF v_status IS NULL OR v_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts require an approved settlement.';
    END IF;
    IF NEW.amount <> v_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout amount must equal the settlement amount.';
    END IF;
    IF NEW.organizer_id <> v_organizer OR v_account_org IS NULL OR v_account_org <> v_organizer THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout organizer/account mismatch.';
    END IF;
    IF v_account_status <> 'verified' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout account must be verified.';
    END IF;
    IF NEW.status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts must start as pending.';
    END IF;
END//

CREATE TRIGGER trg_payout_no_delete BEFORE DELETE ON payouts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts cannot be deleted; cancel or fail them instead.';
END//

CREATE TRIGGER trg_payout_guard_update BEFORE UPDATE ON payouts FOR EACH ROW
BEGIN
    IF OLD.status IN ('paid', 'failed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finished payouts are immutable.';
    END IF;
    IF NEW.payout_no <> OLD.payout_no OR NEW.settlement_id <> OLD.settlement_id OR NEW.organizer_id <> OLD.organizer_id
       OR NEW.payout_account_id <> OLD.payout_account_id OR NEW.currency <> OLD.currency OR NEW.amount <> OLD.amount
       OR NEW.method <> OLD.method OR NEW.account_name_snapshot <> OLD.account_name_snapshot
       OR NEW.account_number_masked <> OLD.account_number_masked OR NOT (NEW.bank_name_snapshot <=> OLD.bank_name_snapshot)
       OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout amount and destination are immutable.';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('processing', 'failed', 'cancelled'))
        OR (OLD.status = 'processing' AND NEW.status IN ('paid', 'failed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout status change.';
    END IF;
END//

CREATE TRIGGER trg_fsh_no_update BEFORE UPDATE ON finance_status_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance history is append-only.';
END//

CREATE TRIGGER trg_fsh_no_delete BEFORE DELETE ON finance_status_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance history is append-only.';
END//

DELIMITER ;

-- =====================================================================
-- v27 payment flow revision: registration-closing based payouts
-- (same as PART B of database/payment_flow_revision_migration.sql).
-- v25 settlements/payouts above are retired: kept for history, new
-- settlements are blocked by trg_settlement_retired.
-- =====================================================================

-- When registration closed (automatically after the deadline, or by the
-- organizer). Payout requests are only created for events that have it.
ALTER TABLE events
    ADD COLUMN registration_closed_at DATETIME DEFAULT NULL AFTER registration_deadline;

-- ---------------------------------------------------------------------
-- Per-event organizer earnings. One row per event; totals are always
-- recalculated from organizer_ledger_entries by PayoutRequestService (never
-- typed). payout_paused_reason is set when an admin rejects a payout request:
-- the scheduler then stops re-creating requests until an admin resumes it.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_event_earnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    participant_count INT NOT NULL DEFAULT 0,
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gateway_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    held_count INT NOT NULL DEFAULT 0,
    requested_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('collecting','closed','payout_requested','paid','on_hold') NOT NULL DEFAULT 'collecting',
    payout_paused_reason VARCHAR(500) DEFAULT NULL,
    last_calculated_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_earnings_event (event_id),
    INDEX idx_earnings_org_status (organizer_id, status),
    CONSTRAINT fk_earnings_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_earnings_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT chk_earnings_formula CHECK (net_amount = gross_amount - gateway_fee)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Payout requests. Created by the scheduler (created_by NULL = system) from
-- the event's eligible ledger entries; amounts are copied from those entries.
--   pending -> approved -> (processing ->) paid
--   pending / approved / processing -> rejected (entries are released)
-- At most one open (pending/approved/processing) request per event, and a
-- transfer reference can be used for only one paid request.
-- The payout account is snapshotted when the admin approves.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_payout_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(30) NOT NULL,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    earnings_id INT NOT NULL,
    sequence_no INT NOT NULL DEFAULT 1,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    entry_count INT NOT NULL,
    participant_count INT NOT NULL,
    gross_amount DECIMAL(12,2) NOT NULL,
    gateway_fee DECIMAL(12,2) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending','approved','processing','paid','rejected') NOT NULL DEFAULT 'pending',
    payout_account_id INT DEFAULT NULL,
    method ENUM('gcash','bank') DEFAULT NULL,
    account_name_snapshot VARCHAR(150) DEFAULT NULL,
    account_number_masked VARCHAR(40) DEFAULT NULL,
    bank_name_snapshot VARCHAR(100) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    processing_by INT DEFAULT NULL,
    processing_at DATETIME DEFAULT NULL,
    paid_by INT DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    proof_path VARCHAR(255) DEFAULT NULL,
    rejected_by INT DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    reject_reason VARCHAR(500) DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_event_id INT AS (IF(status IN ('pending','approved','processing'), event_id, NULL)) PERSISTENT,
    paid_reference_key VARCHAR(120) AS (IF(status = 'paid', CONCAT(method, ':', reference_number), NULL)) PERSISTENT,
    UNIQUE KEY uq_payout_request_no (request_no),
    UNIQUE KEY uq_payout_request_event_seq (event_id, sequence_no),
    UNIQUE KEY uq_payout_request_open_event (open_event_id),
    UNIQUE KEY uq_payout_request_paid_reference (paid_reference_key),
    INDEX idx_payout_request_org_status (organizer_id, status),
    INDEX idx_payout_request_status (status),
    CONSTRAINT fk_opr_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_earnings FOREIGN KEY (earnings_id) REFERENCES organizer_event_earnings(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_account FOREIGN KEY (payout_account_id) REFERENCES organizer_payout_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_processing_by FOREIGN KEY (processing_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_rejected_by FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_opr_amount CHECK (amount > 0 AND amount = gross_amount - gateway_fee),
    CONSTRAINT chk_opr_approved CHECK (status NOT IN ('approved','processing','paid')
        OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND payout_account_id IS NOT NULL AND method IS NOT NULL)),
    CONSTRAINT chk_opr_paid CHECK (status <> 'paid'
        OR (paid_by IS NOT NULL AND paid_at IS NOT NULL AND reference_number IS NOT NULL AND reference_number <> '')),
    CONSTRAINT chk_opr_rejected CHECK (status <> 'rejected'
        OR (rejected_by IS NOT NULL AND rejected_at IS NOT NULL AND reject_reason IS NOT NULL))
) ENGINE=InnoDB;

-- Ledger entries point at the payout request that pays them out.
ALTER TABLE organizer_ledger_entries
    ADD COLUMN payout_request_id INT DEFAULT NULL AFTER settlement_id,
    ADD INDEX idx_ledger_payout_request (payout_request_id),
    ADD CONSTRAINT fk_ledger_payout_request FOREIGN KEY (payout_request_id) REFERENCES organizer_payout_requests(id) ON DELETE RESTRICT;

ALTER TABLE finance_status_history
    MODIFY entity_type ENUM('ledger_entry','settlement','payout','payout_account','event_earnings','payout_request') NOT NULL;

-- =====================================================================
-- Triggers
-- =====================================================================
DELIMITER //

-- v25 settlements/payouts are retired: payout requests replace them, so a
-- ledger entry can never be paid out twice.
CREATE TRIGGER trg_settlement_retired BEFORE INSERT ON settlements FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlements are retired; payouts are created as organizer payout requests.';
END//

-- Payout request linkage on ledger entries (runs after the v25 guard).
CREATE TRIGGER trg_ledger_payout_request_guard BEFORE UPDATE ON organizer_ledger_entries FOR EACH ROW FOLLOWS trg_ledger_guard_update
BEGIN
    DECLARE v_new_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_new_event INT DEFAULT NULL;
    DECLARE v_old_status VARCHAR(20) DEFAULT NULL;

    IF OLD.settlement_id IS NULL AND NEW.settlement_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlements are retired; ledger entries are paid through payout requests.';
    END IF;

    IF OLD.payout_request_id IS NOT NULL THEN
        SELECT status INTO v_old_status FROM organizer_payout_requests WHERE id = OLD.payout_request_id;
    END IF;

    IF NOT (NEW.payout_request_id <=> OLD.payout_request_id) THEN
        IF OLD.payout_request_id IS NULL THEN
            -- Linking: only unsettled entries, only into a pending request of the same event.
            SELECT status, event_id INTO v_new_status, v_new_event FROM organizer_payout_requests WHERE id = NEW.payout_request_id;
            IF OLD.settlement_id IS NOT NULL OR v_new_status IS NULL OR v_new_status <> 'pending' OR v_new_event <> OLD.event_id THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ledger entry can only join a pending payout request of its own event.';
            END IF;
        ELSEIF NEW.payout_request_id IS NULL THEN
            -- Releasing: only when that request was rejected.
            IF v_old_status <> 'rejected' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are released only by rejecting their payout request.';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ledger entry cannot move between payout requests.';
        END IF;
    END IF;

    -- Entries inside a live payout request are frozen.
    IF OLD.payout_request_id IS NOT NULL AND v_old_status <> 'rejected'
       AND (NOT (NEW.hold_reason <=> OLD.hold_reason) OR NOT (NEW.gateway_fee <=> OLD.gateway_fee)
            OR NOT (NEW.net_amount <=> OLD.net_amount) OR NOT (NEW.organizer_earnings <=> OLD.organizer_earnings)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Entries of a payout request cannot change.';
    END IF;
END//

CREATE TRIGGER trg_earnings_no_delete BEFORE DELETE ON organizer_event_earnings FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event earnings records cannot be deleted.';
END//

CREATE TRIGGER trg_earnings_guard_update BEFORE UPDATE ON organizer_event_earnings FOR EACH ROW
BEGIN
    IF NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event earnings identity is immutable.';
    END IF;
END//

CREATE TRIGGER trg_payout_request_validate_insert BEFORE INSERT ON organizer_payout_requests FOR EACH ROW
BEGIN
    DECLARE v_event_org INT DEFAULT NULL;
    DECLARE v_earn_event INT DEFAULT NULL;

    SELECT organizer_id INTO v_event_org FROM events WHERE id = NEW.event_id;
    SELECT event_id INTO v_earn_event FROM organizer_event_earnings WHERE id = NEW.earnings_id;

    IF NEW.status <> 'pending' OR NEW.payout_account_id IS NOT NULL OR NEW.approved_by IS NOT NULL
       OR NEW.paid_by IS NOT NULL OR NEW.reference_number IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout requests must start as pending, unapproved and unpaid.';
    END IF;
    IF v_event_org IS NULL OR v_event_org <> NEW.organizer_id OR v_earn_event IS NULL OR v_earn_event <> NEW.event_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout request organizer/event/earnings mismatch.';
    END IF;
END//

CREATE TRIGGER trg_payout_request_no_delete BEFORE DELETE ON organizer_payout_requests FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout requests cannot be deleted; reject them instead.';
END//

CREATE TRIGGER trg_payout_request_guard_update BEFORE UPDATE ON organizer_payout_requests FOR EACH ROW
BEGIN
    DECLARE v_account_org INT DEFAULT NULL;
    DECLARE v_account_status VARCHAR(20) DEFAULT NULL;

    IF OLD.status IN ('paid', 'rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid or rejected payout requests are final.';
    END IF;

    IF NEW.request_no <> OLD.request_no OR NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NEW.earnings_id <> OLD.earnings_id OR NEW.sequence_no <> OLD.sequence_no OR NEW.currency <> OLD.currency
       OR NEW.entry_count <> OLD.entry_count OR NEW.participant_count <> OLD.participant_count
       OR NEW.gross_amount <> OLD.gross_amount OR NEW.gateway_fee <> OLD.gateway_fee OR NEW.amount <> OLD.amount
       OR NOT (NEW.created_by <=> OLD.created_by) OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout request amounts and identity are immutable.';
    END IF;

    -- Destination is fixed once set (at approval).
    IF OLD.payout_account_id IS NOT NULL
       AND (NOT (NEW.payout_account_id <=> OLD.payout_account_id) OR NOT (NEW.method <=> OLD.method)
            OR NOT (NEW.account_name_snapshot <=> OLD.account_name_snapshot)
            OR NOT (NEW.account_number_masked <=> OLD.account_number_masked)
            OR NOT (NEW.bank_name_snapshot <=> OLD.bank_name_snapshot)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payout destination cannot change after approval.';
    END IF;
    IF OLD.payout_account_id IS NULL AND NEW.payout_account_id IS NOT NULL THEN
        SELECT organizer_id, status INTO v_account_org, v_account_status FROM organizer_payout_accounts WHERE id = NEW.payout_account_id;
        IF v_account_org IS NULL OR v_account_org <> NEW.organizer_id OR v_account_status <> 'verified' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts go only to the organizer''s own verified payout account.';
        END IF;
    END IF;

    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
        OR (OLD.status = 'approved' AND NEW.status IN ('processing', 'paid', 'rejected'))
        OR (OLD.status = 'processing' AND NEW.status IN ('paid', 'rejected'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout request status change.';
    END IF;
END//

DELIMITER ;

-- =====================================================================
-- v28 secure manual payout processing (same as database/payout_proof_migration.sql)
-- =====================================================================
-- ---------------------------------------------------------------------
-- Maya as a payout method
-- ---------------------------------------------------------------------
ALTER TABLE organizer_payout_accounts
    MODIFY method ENUM('gcash','maya','bank') NOT NULL;

ALTER TABLE organizer_payout_requests
    MODIFY method ENUM('gcash','maya','bank') DEFAULT NULL;

-- ---------------------------------------------------------------------
-- Paid requires reference number AND proof of transfer
-- ---------------------------------------------------------------------
ALTER TABLE organizer_payout_requests
    DROP CONSTRAINT chk_opr_paid,
    ADD CONSTRAINT chk_opr_paid CHECK (status <> 'paid'
        OR (paid_by IS NOT NULL AND paid_at IS NOT NULL
            AND reference_number IS NOT NULL AND reference_number <> ''
            AND proof_path IS NOT NULL AND proof_path <> ''));

-- ---------------------------------------------------------------------
-- Who viewed an organizer's full payout details, and when
-- ---------------------------------------------------------------------
CREATE TABLE payout_detail_access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_request_id INT NOT NULL,
    payout_account_id INT NOT NULL,
    admin_user_id INT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pdal_request (payout_request_id),
    CONSTRAINT fk_pdal_request FOREIGN KEY (payout_request_id) REFERENCES organizer_payout_requests(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pdal_account FOREIGN KEY (payout_account_id) REFERENCES organizer_payout_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pdal_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

DELIMITER //

CREATE TRIGGER trg_pdal_no_update BEFORE UPDATE ON payout_detail_access_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout detail access logs are append-only.';
END//

CREATE TRIGGER trg_pdal_no_delete BEFORE DELETE ON payout_detail_access_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout detail access logs are append-only.';
END//

-- Same rules as v27, except: approved -> processing -> paid (no direct
-- approved -> paid; the admin marks "processing" when starting the transfer).
DROP TRIGGER IF EXISTS trg_payout_request_guard_update//

CREATE TRIGGER trg_payout_request_guard_update BEFORE UPDATE ON organizer_payout_requests FOR EACH ROW
BEGIN
    DECLARE v_account_org INT DEFAULT NULL;
    DECLARE v_account_status VARCHAR(20) DEFAULT NULL;

    IF OLD.status IN ('paid', 'rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid or rejected payout requests are final.';
    END IF;

    IF NEW.request_no <> OLD.request_no OR NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NEW.earnings_id <> OLD.earnings_id OR NEW.sequence_no <> OLD.sequence_no OR NEW.currency <> OLD.currency
       OR NEW.entry_count <> OLD.entry_count OR NEW.participant_count <> OLD.participant_count
       OR NEW.gross_amount <> OLD.gross_amount OR NEW.gateway_fee <> OLD.gateway_fee OR NEW.amount <> OLD.amount
       OR NOT (NEW.created_by <=> OLD.created_by) OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout request amounts and identity are immutable.';
    END IF;

    IF OLD.payout_account_id IS NOT NULL
       AND (NOT (NEW.payout_account_id <=> OLD.payout_account_id) OR NOT (NEW.method <=> OLD.method)
            OR NOT (NEW.account_name_snapshot <=> OLD.account_name_snapshot)
            OR NOT (NEW.account_number_masked <=> OLD.account_number_masked)
            OR NOT (NEW.bank_name_snapshot <=> OLD.bank_name_snapshot)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payout destination cannot change after approval.';
    END IF;
    IF OLD.payout_account_id IS NULL AND NEW.payout_account_id IS NOT NULL THEN
        SELECT organizer_id, status INTO v_account_org, v_account_status FROM organizer_payout_accounts WHERE id = NEW.payout_account_id;
        IF v_account_org IS NULL OR v_account_org <> NEW.organizer_id OR v_account_status <> 'verified' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts go only to the organizer''s own verified payout account.';
        END IF;
    END IF;

    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
        OR (OLD.status = 'approved' AND NEW.status IN ('processing', 'rejected'))
        OR (OLD.status = 'processing' AND NEW.status IN ('paid', 'rejected'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout request status change (paid requires processing first).';
    END IF;
END//

DELIMITER ;

-- =====================================================================
-- v29 multiple verified payout accounts (same as database/multi_payout_accounts_migration.sql)
-- =====================================================================
ALTER TABLE organizer_payout_accounts
    ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN primary_organizer_id INT AS (IF(is_primary = 1, organizer_id, NULL)) PERSISTENT,
    ADD COLUMN active_account_key VARCHAR(220) AS (IF(status IN ('pending', 'verified'),
        CONCAT(organizer_id, ':', method, ':', account_number, ':', LOWER(COALESCE(bank_name, ''))), NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_payout_account_primary (primary_organizer_id),
    ADD UNIQUE KEY uq_payout_account_active (active_account_key),
    ADD CONSTRAINT chk_payout_account_primary_verified CHECK (is_primary = 0 OR status = 'verified');

-- Each organizer's latest verified account becomes their primary account.
UPDATE organizer_payout_accounts a
JOIN (SELECT organizer_id, MAX(id) AS id FROM organizer_payout_accounts WHERE status = 'verified' GROUP BY organizer_id) v ON v.id = a.id
SET a.is_primary = 1;

ALTER TABLE payout_detail_access_logs
    MODIFY payout_request_id INT DEFAULT NULL;

-- =====================================================================
-- v30.1 refund management (same as database/refund_management_migration.sql)
-- =====================================================================
ALTER TABLE registrations
    MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE organizer_ledger_entries
    MODIFY hold_reason ENUM('fee_unknown','amount_mismatch','manual_hold','refund_pending') DEFAULT NULL;

ALTER TABLE finance_status_history
    MODIFY entity_type ENUM('ledger_entry','settlement','payout','payout_account','event_earnings','payout_request','payment_refund') NOT NULL;

ALTER TABLE qr_codes
    ADD COLUMN invalidated_at DATETIME DEFAULT NULL AFTER generated_at,
    ADD COLUMN invalidated_reason VARCHAR(255) DEFAULT NULL AFTER invalidated_at;

CREATE TABLE payment_refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    registration_id INT NOT NULL,
    refund_type ENUM('registration','duplicate_charge') NOT NULL DEFAULT 'registration',
    duplicate_history_id INT DEFAULT NULL,          -- payment_history row that flagged the second charge
    amount DECIMAL(10,2) NOT NULL,
    reason_code ENUM('cannot_attend','duplicate_payment','event_cancelled','other') NOT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    status ENUM('pending','approved','completed','rejected') NOT NULL DEFAULT 'pending',
    requested_by INT NOT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejected_by INT DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    review_remarks VARCHAR(500) DEFAULT NULL,
    refund_reference VARCHAR(100) DEFAULT NULL,
    proof_path VARCHAR(255) DEFAULT NULL,
    refunded_by INT DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    ledger_reversal_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_payment_key INT AS (IF(refund_type = 'registration' AND status <> 'rejected', payment_id, NULL)) PERSISTENT,
    active_duplicate_key INT AS (IF(refund_type = 'duplicate_charge' AND status <> 'rejected', duplicate_history_id, NULL)) PERSISTENT,
    completed_reference_key VARCHAR(100) AS (IF(status = 'completed', LOWER(refund_reference), NULL)) PERSISTENT,
    UNIQUE KEY uq_refund_active_payment (active_payment_key),
    UNIQUE KEY uq_refund_active_duplicate (active_duplicate_key),
    UNIQUE KEY uq_refund_reference (completed_reference_key),
    KEY idx_refund_status (status),
    KEY idx_refund_registration (registration_id),
    CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_refund_registration FOREIGN KEY (registration_id) REFERENCES registrations(id),
    CONSTRAINT fk_refund_duplicate FOREIGN KEY (duplicate_history_id) REFERENCES payment_history(id),
    CONSTRAINT fk_refund_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_refund_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_refund_rejected_by FOREIGN KEY (rejected_by) REFERENCES users(id),
    CONSTRAINT fk_refund_refunded_by FOREIGN KEY (refunded_by) REFERENCES users(id),
    CONSTRAINT fk_refund_ledger_reversal FOREIGN KEY (ledger_reversal_id) REFERENCES organizer_ledger_entries(id),
    CONSTRAINT chk_refund_amount CHECK (amount > 0),
    CONSTRAINT chk_refund_duplicate_link CHECK ((refund_type = 'duplicate_charge') = (duplicate_history_id IS NOT NULL)),
    CONSTRAINT chk_refund_approved CHECK (status NOT IN ('approved','completed') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
    CONSTRAINT chk_refund_rejected CHECK (status <> 'rejected' OR (rejected_by IS NOT NULL AND rejected_at IS NOT NULL AND review_remarks IS NOT NULL)),
    CONSTRAINT chk_refund_completed CHECK (status <> 'completed' OR (refund_reference IS NOT NULL AND refund_reference <> ''
        AND proof_path IS NOT NULL AND proof_path <> '' AND refunded_by IS NOT NULL AND completed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_refund_validate_insert BEFORE INSERT ON payment_refunds FOR EACH ROW
BEGIN
    DECLARE v_reg INT DEFAULT NULL;
    DECLARE v_amount DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_channel VARCHAR(20) DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    IF NEW.status NOT IN ('pending', 'approved') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A refund must start as pending or approved.';
    END IF;
    IF NEW.refund_reference IS NOT NULL OR NEW.proof_path IS NOT NULL OR NEW.refunded_by IS NOT NULL
       OR NEW.completed_at IS NOT NULL OR NEW.rejected_by IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new refund cannot already be completed or rejected.';
    END IF;
    SELECT registration_id, amount, payment_channel, status INTO v_reg, v_amount, v_channel, v_status
      FROM payments WHERE id = NEW.payment_id;
    IF v_reg IS NULL OR v_reg <> NEW.registration_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund registration does not match the payment.';
    END IF;
    IF v_channel <> 'paymongo' OR v_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only paid PayMongo payments collected by EVORA can be refunded.';
    END IF;
    IF NEW.amount <> v_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund amount must equal the recorded payment amount.';
    END IF;
    IF NEW.duplicate_history_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM payment_history WHERE id = NEW.duplicate_history_id AND payment_id = NEW.payment_id
          AND action = 'paymongo_duplicate_charge') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate-charge refund must point to a flagged duplicate charge of this payment.';
    END IF;
END$$

CREATE TRIGGER trg_refund_guard_update BEFORE UPDATE ON payment_refunds FOR EACH ROW
BEGIN
    IF OLD.status IN ('completed', 'rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed or rejected refunds cannot change.';
    END IF;
    IF NEW.payment_id <> OLD.payment_id OR NEW.registration_id <> OLD.registration_id
       OR NEW.refund_type <> OLD.refund_type OR NOT (NEW.duplicate_history_id <=> OLD.duplicate_history_id)
       OR NEW.amount <> OLD.amount OR NEW.reason_code <> OLD.reason_code OR NOT (NEW.reason <=> OLD.reason)
       OR NEW.requested_by <> OLD.requested_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund identity and amount are immutable.';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
           (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
        OR (OLD.status = 'approved' AND NEW.status IN ('completed', 'rejected'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid refund status change.';
    END IF;
    IF OLD.approved_by IS NOT NULL AND (NOT (NEW.approved_by <=> OLD.approved_by) OR NOT (NEW.approved_at <=> OLD.approved_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund approval cannot be changed.';
    END IF;
    IF OLD.ledger_reversal_id IS NOT NULL AND NOT (NEW.ledger_reversal_id <=> OLD.ledger_reversal_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund ledger reversal cannot be changed.';
    END IF;
    IF NEW.reason_code <> 'event_cancelled'
       AND (NEW.approved_by = NEW.requested_by OR NEW.refunded_by = NEW.requested_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The requester cannot approve or complete their own refund.';
    END IF;
END$$

CREATE TRIGGER trg_refund_no_delete BEFORE DELETE ON payment_refunds FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund records cannot be deleted.';
END$$

CREATE TRIGGER trg_payment_refund_guard BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF OLD.status = 'refunded' AND NEW.status <> 'refunded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A refunded payment cannot change status.';
    END IF;
    IF NEW.status = 'refunded' AND OLD.status <> 'refunded' AND NOT EXISTS (
        SELECT 1 FROM payment_refunds WHERE payment_id = OLD.id AND refund_type = 'registration' AND status = 'completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A payment can be marked refunded only by completing its refund.';
    END IF;
END$$

CREATE TRIGGER trg_registration_cancel_final BEFORE UPDATE ON registrations FOR EACH ROW
BEGIN
    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A cancelled registration cannot be reinstated.';
    END IF;
END$$

CREATE TRIGGER trg_qr_invalidation_final BEFORE UPDATE ON qr_codes FOR EACH ROW
BEGIN
    IF OLD.invalidated_at IS NOT NULL AND (NOT (NEW.invalidated_at <=> OLD.invalidated_at) OR NEW.code_token <> OLD.code_token) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An invalidated QR code cannot be reactivated.';
    END IF;
END$$
DELIMITER ;

-- =====================================================================
-- v31 notification center (same as database/notification_center_migration.sql)
-- Extends the existing notifications table; `type` stays the event key.
-- =====================================================================
ALTER TABLE notifications
    ADD COLUMN category ENUM('payment','refund','payout','event','system') NOT NULL DEFAULT 'system' AFTER type,
    ADD COLUMN notice_type ENUM('informational','action_required','status') NOT NULL DEFAULT 'informational' AFTER category,
    ADD COLUMN reference_type VARCHAR(40) DEFAULT NULL AFTER notice_type,
    ADD COLUMN reference_id INT DEFAULT NULL AFTER reference_type,
    ADD COLUMN read_at DATETIME DEFAULT NULL AFTER is_read,
    ADD KEY idx_notif_user_unread (user_id, is_read, category),
    ADD KEY idx_notif_reference (reference_type, reference_id);

-- =====================================================================
-- v32 evaluation & feedback — reuses event_feedback and system_evaluations.
-- Full statements and triggers: database/evaluation_migration.sql
-- =====================================================================
ALTER TABLE event_feedback
    ADD COLUMN updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE system_evaluations
    MODIFY functionality TINYINT DEFAULT NULL CHECK (functionality BETWEEN 1 AND 5),
    MODIFY usability TINYINT DEFAULT NULL CHECK (usability BETWEEN 1 AND 5),
    MODIFY reliability TINYINT DEFAULT NULL CHECK (reliability BETWEEN 1 AND 5),
    MODIFY performance_efficiency TINYINT DEFAULT NULL CHECK (performance_efficiency BETWEEN 1 AND 5),
    MODIFY security TINYINT DEFAULT NULL CHECK (security BETWEEN 1 AND 5),
    MODIFY compatibility TINYINT DEFAULT NULL CHECK (compatibility BETWEEN 1 AND 5),
    MODIFY maintainability TINYINT DEFAULT NULL CHECK (maintainability BETWEEN 1 AND 5),
    MODIFY portability TINYINT DEFAULT NULL CHECK (portability BETWEEN 1 AND 5),
    ADD COLUMN overall_rating TINYINT DEFAULT NULL AFTER user_id,
    ADD COLUMN source ENUM('full_survey','quick_prompt') NOT NULL DEFAULT 'full_survey' AFTER overall_rating,
    ADD COLUMN updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD CONSTRAINT chk_eval_overall CHECK (overall_rating IS NULL OR overall_rating BETWEEN 1 AND 5),
    ADD CONSTRAINT chk_eval_iso_complete CHECK (
        (functionality IS NULL AND usability IS NULL AND reliability IS NULL AND performance_efficiency IS NULL
         AND security IS NULL AND compatibility IS NULL AND maintainability IS NULL AND portability IS NULL)
     OR (functionality IS NOT NULL AND usability IS NOT NULL AND reliability IS NOT NULL AND performance_efficiency IS NOT NULL
         AND security IS NOT NULL AND compatibility IS NOT NULL AND maintainability IS NOT NULL AND portability IS NOT NULL)),
    ADD CONSTRAINT chk_eval_has_rating CHECK (overall_rating IS NOT NULL OR functionality IS NOT NULL);
-- Triggers: trg_event_feedback_validate_insert, trg_event_feedback_no_update, trg_event_feedback_no_delete,
--           trg_system_eval_one_per_user, trg_system_eval_no_edit, trg_system_eval_no_delete

-- =====================================================================
-- v32.1 system satisfaction rating (⭐ Rate EVORA) — extends system_evaluations.
-- Full statements and the replaced triggers: database/system_rating_migration.sql
-- =====================================================================
ALTER TABLE system_evaluations
    ADD COLUMN role ENUM('participant','organizer','staff') DEFAULT NULL AFTER user_id,
    ADD COLUMN registration_rating TINYINT DEFAULT NULL AFTER source,
    ADD COLUMN payment_rating TINYINT DEFAULT NULL AFTER registration_rating,
    ADD COLUMN event_management_rating TINYINT DEFAULT NULL AFTER payment_rating,
    ADD COLUMN checkin_rating TINYINT DEFAULT NULL AFTER event_management_rating,
    MODIFY source ENUM('full_survey','quick_prompt','star_button') NOT NULL DEFAULT 'full_survey',
    ADD KEY idx_eval_role (role),
    ADD CONSTRAINT chk_eval_categories CHECK (
        (registration_rating IS NULL OR registration_rating BETWEEN 1 AND 5)
        AND (payment_rating IS NULL OR payment_rating BETWEEN 1 AND 5)
        AND (event_management_rating IS NULL OR event_management_rating BETWEEN 1 AND 5)
        AND (checkin_rating IS NULL OR checkin_rating BETWEEN 1 AND 5));

-- =====================================================================
-- v33 user account center (same as database/account_center_migration.sql)
-- =====================================================================
ALTER TABLE activity_logs
    ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL AFTER ip_address,
    ADD KEY idx_log_user_created (user_id, created_at);

CREATE TABLE user_settings (
    user_id INT NOT NULL PRIMARY KEY,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila',
    notify_payment TINYINT(1) NOT NULL DEFAULT 1,
    notify_refund TINYINT(1) NOT NULL DEFAULT 1,
    notify_payout TINYINT(1) NOT NULL DEFAULT 1,
    notify_event TINYINT(1) NOT NULL DEFAULT 1,
    notify_system TINYINT(1) NOT NULL DEFAULT 1,
    notify_evaluation TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
