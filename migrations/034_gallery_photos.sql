-- Migration 034: Photo Gallery System
-- Creates gallery_photos table, permissions, and seeds initial verified photos.

CREATE TABLE IF NOT EXISTS gallery_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'General',
    photo_date DATE NULL,
    description TEXT NULL,
    file_path VARCHAR(500) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    show_on_home TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status_home (status, show_on_home),
    KEY idx_sort_date (sort_order, photo_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions for gallery
INSERT IGNORE INTO permissions (module, action, name, status) VALUES
('gallery', 'view', 'View photo gallery items', 'active'),
('gallery', 'manage', 'Upload, edit, and delete photo gallery items', 'active');

-- Grant gallery permissions to admin and executive roles
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name IN (
    'State Super Admin',
    'State President',
    'State General Secretary',
    'State Vice President',
    'State Treasurer',
    'State Joint Secretary',
    'State Organizing Secretary',
    'State IT Coordinator'
)
AND p.module = 'gallery';

-- Pre-populate existing verified association gallery photos
INSERT INTO gallery_photos (title, category, photo_date, description, file_path, sort_order, show_on_home, status) VALUES
('ರಾಜ್ಯ ಕಾರ್ಯಕಾರಿಣಿ ಸಭೆ, ಬೆಂಗಳೂರು', 'State Executive', '2026-08-15', 'State Executive Committee meeting held at the association headquarters in Bengaluru to discuss key PDO welfare initiatives.', '/assets/images/gallery/meeting-state-executive.jpg', 1, 1, 'active'),
('ತಾಂತ್ರಿಕ ತರಬೇತಿ ಕಾರ್ಯಕ್ರಮ', 'Training', '2026-07-22', 'Technical skill development and Panchatantra 2.0 digital software training session conducted for Panchayat Development Officers.', '/assets/images/gallery/technical-training.jpg', 2, 1, 'active'),
('ಜಿಲ್ಲಾ PDOಗಳ ಸಮನ್ವಯ ಸಭೆ', 'Coordination', '2026-07-05', 'District-level PDO coordination conference reviewing rural developmental projects and administrative coordination.', '/assets/images/gallery/district-pdo-coordination.jpg', 3, 1, 'active'),
('ಸನ್ಮಾನ ಕಾರ್ಯಕ್ರಮ', 'Felicitation', '2026-06-18', 'Felicitation ceremony recognizing outstanding service, dedication, and exemplary administrative excellence by association officers.', '/assets/images/gallery/felicitation-ceremony.jpg', 4, 1, 'active'),
('ರಾಜ್ಯ ಮಟ್ಟದ ಮಹಾಸಭೆ', 'General Body', '2026-05-28', 'Annual state level general body convention with representative delegates from all 31 districts of Karnataka.', '/assets/images/gallery/general-meeting.jpg', 5, 1, 'active'),
('ಸಂಘದ ಕಾರ್ಯಕಾರಿಣಿ ಸಮಾಲೋಚನೆ', 'Executive Consultation', '2026-05-17', 'Executive deliberations on service rule amendments, cadre review, and welfare representations.', '/assets/images/gallery/association-deliberation.jpg', 6, 1, 'active');
