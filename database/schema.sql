CREATE TABLE IF NOT EXISTS releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    header VARCHAR(255) NOT NULL,
    status ENUM('draft', 'publish') NOT NULL DEFAULT 'draft',
    slug VARCHAR(191) NOT NULL,
    html_content LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_releases_slug (slug),
    KEY idx_releases_status_created_at (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS features (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    header VARCHAR(255) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    html_content LONGTEXT NULL,
    asset_path VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_features_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_features (
    release_id BIGINT UNSIGNED NOT NULL,
    feature_id BIGINT UNSIGNED NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (release_id, feature_id),
    KEY idx_release_features_order (release_id, display_order),
    CONSTRAINT fk_release_features_release
        FOREIGN KEY (release_id) REFERENCES releases (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_release_features_feature
        FOREIGN KEY (feature_id) REFERENCES features (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS help_docs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    status ENUM('draft', 'publish') NOT NULL DEFAULT 'draft',
    slug VARCHAR(191) NOT NULL,
    html_content LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_help_docs_slug (slug),
    KEY idx_help_docs_status_created_at (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_sessions_token_hash (token_hash),
    KEY idx_admin_sessions_expires_at (expires_at),
    CONSTRAINT fk_admin_sessions_admin_user
        FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO releases (header, status, slug, html_content)
VALUES (
    'v1.0.0 - Initial Public Starter',
    'publish',
    'v1-0-0-initial-public-starter',
    '<p>First public wiki release with navigation, sections, and release-note support.</p>'
)
ON DUPLICATE KEY UPDATE
    header = VALUES(header),
    status = VALUES(status),
    html_content = VALUES(html_content);

INSERT INTO features (header, slug, html_content, asset_path)
VALUES
(
    'Bootstrap + PHP Public Wiki',
    'bootstrap-php-public-wiki',
    '<p>Launched a responsive documentation shell using PHP and Bootstrap with a left-nav layout.</p>',
    NULL
),
(
    'Automatic Navigation Discovery',
    'automatic-navigation-discovery',
    '<p>Sidebar navigation now updates automatically from folder and file structure under <code>content/</code>.</p>',
    NULL
)
ON DUPLICATE KEY UPDATE
    header = VALUES(header),
    html_content = VALUES(html_content),
    asset_path = VALUES(asset_path);

INSERT INTO release_features (release_id, feature_id, display_order)
SELECT r.id, f.id, x.display_order
FROM (
    SELECT 'v1-0-0-initial-public-starter' AS release_slug, 'bootstrap-php-public-wiki' AS feature_slug, 1 AS display_order
    UNION ALL
    SELECT 'v1-0-0-initial-public-starter', 'automatic-navigation-discovery', 2
) AS x
INNER JOIN releases r ON r.slug = x.release_slug
INNER JOIN features f ON f.slug = x.feature_slug
ON DUPLICATE KEY UPDATE
    display_order = VALUES(display_order);

INSERT INTO help_docs (title, status, slug, html_content)
VALUES (
    'How To Use The Wiki',
    'publish',
    'how-to-use-the-wiki',
    '<p>Use the sidebar to browse docs and release pages. Admin users can create new docs and releases from <code>/admin</code>.</p>'
)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    status = VALUES(status),
    html_content = VALUES(html_content);
