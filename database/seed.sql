-- =====================================================================
-- EVORA seed data
-- =====================================================================
USE evora_db;

INSERT INTO roles (name) VALUES ('admin'), ('organizer'), ('staff'), ('participant');

INSERT INTO event_categories (name, slug) VALUES
    ('Fun Run', 'fun-run'),
    ('Sports Tournament', 'sports-tournament'),
    ('Seminar / Workshop', 'seminar-workshop'),
    ('Competition', 'competition'),
    ('Community Event', 'community-event'),
    ('Festival', 'festival'),
    ('Other', 'other');

INSERT INTO sports (name, sport_type, team_size, player_format, scoring_method, default_tournament_format) VALUES
    ('Basketball', 'team', 5, '5 vs 5', 'points', 'single_elim'),
    ('Volleyball', 'team', 6, '6 vs 6', 'sets', 'single_elim'),
    ('Badminton', 'individual', 2, 'Singles/Doubles', 'sets', 'single_elim'),
    ('Pickleball', 'individual', 2, 'Singles/Doubles', 'sets', 'single_elim'),
    ('Other', 'team', NULL, NULL, 'points', 'single_elim'),
    ('Football / Soccer', 'team', 11, '11 vs 11', 'points', 'single_elim'),
    ('Futsal', 'team', 5, '5 vs 5', 'points', 'single_elim'),
    ('Baseball', 'team', 9, '9 vs 9', 'points', 'round_robin'),
    ('Softball', 'team', 9, '9 vs 9', 'points', 'round_robin'),
    ('Rugby', 'team', 15, '15 vs 15', 'points', 'single_elim'),
    ('Handball', 'team', 7, '7 vs 7', 'points', 'single_elim'),
    ('Sepak Takraw', 'team', 3, '3 vs 3', 'sets', 'single_elim'),
    ('Cricket', 'team', 11, '11 vs 11', 'points', 'round_robin'),
    ('Ultimate Frisbee', 'team', 7, '7 vs 7', 'points', 'single_elim'),
    ('Tennis', 'individual', 2, 'Singles/Doubles', 'sets', 'single_elim'),
    ('Table Tennis', 'individual', 2, 'Singles/Doubles', 'sets', 'single_elim'),
    ('Squash', 'individual', 2, 'Singles/Doubles', 'sets', 'single_elim'),
    ('Padel', 'individual', 2, 'Doubles', 'sets', 'single_elim'),
    ('Boxing', 'individual', 1, 'Individual', 'rounds', 'single_elim'),
    ('Taekwondo', 'individual', 1, 'Individual', 'rounds', 'single_elim'),
    ('Karate', 'individual', 1, 'Individual', 'rounds', 'single_elim'),
    ('Judo', 'individual', 1, 'Individual', 'rounds', 'single_elim'),
    ('Wrestling', 'individual', 1, 'Individual', 'rounds', 'single_elim'),
    ('Brazilian Jiu-Jitsu', 'individual', 1, 'Individual', 'rounds', 'single_elim'),
    ('MMA', 'individual', 1, 'Individual', 'rounds', 'single_elim'),
    ('Chess', 'individual', 1, 'Individual', 'win_draw_loss', 'round_robin'),
    ('Swimming', 'individual', 1, 'Individual', 'time_rank', 'league'),
    ('Running', 'individual', 1, 'Individual', 'time_rank', 'league'),
    ('Cycling', 'individual', 1, 'Individual', 'time_rank', 'league'),
    ('Archery', 'individual', 1, 'Individual', 'points', 'league'),
    ('Bowling', 'individual', 1, 'Individual', 'points', 'league'),
    ('Golf', 'individual', 1, 'Individual', 'points', 'league'),
    ('Darts', 'individual', 1, 'Individual', 'points', 'single_elim'),
    ('Esports', 'team', 5, '5 vs 5', 'points', 'single_elim');

-- Default administrator account
-- Email: admin@evora.local  Password: Admin@12345
INSERT INTO users (role_id, full_name, email, password_hash, phone, status)
VALUES (
    (SELECT id FROM roles WHERE name = 'admin'),
    'EVORA System Administrator',
    'admin@evora.local',
    '$2y$10$JCfNx7BXPIikvO5JVBx6Jeh9IA5KeXDIVooQzY1yXW87r3A67sDu2',
    '09171234567',
    'active'
);
