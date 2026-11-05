<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('A migração só pode ser executada localmente.');
}

$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$alterations = [
    'username' => 'ALTER TABLE users ADD COLUMN username VARCHAR(40) NULL UNIQUE AFTER email',
    'bio' => 'ALTER TABLE users ADD COLUMN bio VARCHAR(500) NULL AFTER display_name',
    'avatar_path' => 'ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER bio',
    'cover_path' => 'ALTER TABLE users ADD COLUMN cover_path VARCHAR(255) NULL AFTER avatar_path',
    'email_verified_at' => 'ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER is_active',
];
foreach ($alterations as $column => $sql) {
    if (!in_array($column, $columns, true)) {
        $pdo->exec($sql);
    }
}
$pdo->exec("ALTER TABLE content_requests MODIFY status ENUM('pending', 'reviewing', 'approved', 'available', 'rejected') NOT NULL DEFAULT 'pending'");
$titleColumns = $pdo->query('SHOW COLUMNS FROM titles')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_paid', $titleColumns, true)) {
    $pdo->exec('ALTER TABLE titles ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER release_year');
}
$qualityColumns = ['video_360p_url', 'video_480p_url', 'video_720p_url', 'video_1080p_url'];
foreach (['titles', 'episodes'] as $table) {
    $columns = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($qualityColumns as $column) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' VARCHAR(2048) NULL');
        }
    }
}
$historyColumns = $pdo->query('SHOW COLUMNS FROM watch_history')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('episode_id', $historyColumns, true)) {
    $pdo->exec('ALTER TABLE watch_history ADD COLUMN episode_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER title_id');
    $pdo->exec('ALTER TABLE watch_history DROP PRIMARY KEY, ADD PRIMARY KEY (user_id, title_id, episode_id)');
}

$tables = [
    'account_tokens' => "CREATE TABLE IF NOT EXISTS account_tokens (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      token_hash CHAR(64) NOT NULL UNIQUE,
      purpose ENUM('password_reset') NOT NULL,
      target_email VARCHAR(190) NULL,
      expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      used_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_account_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_account_token_lookup (user_id, purpose, used_at, expires_at)
    ) ENGINE=InnoDB",
    'notifications' => "CREATE TABLE IF NOT EXISTS notifications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(180) NOT NULL,
      message TEXT NOT NULL,
      read_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      INDEX idx_notifications_user (user_id, read_at, created_at)
    ) ENGINE=InnoDB",
    'social_links' => "CREATE TABLE IF NOT EXISTS social_links (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      platform ENUM('facebook', 'instagram', 'discord', 'whatsapp') NOT NULL UNIQUE,
      url VARCHAR(2048) NULL,
      is_enabled TINYINT(1) NOT NULL DEFAULT 0,
      placement ENUM('header', 'footer', 'both') NOT NULL DEFAULT 'footer',
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
    'user_title_access' => "CREATE TABLE IF NOT EXISTS user_title_access (
      user_id BIGINT UNSIGNED NOT NULL,
      title_id BIGINT UNSIGNED NOT NULL,
      granted_by BIGINT UNSIGNED NULL,
      granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, title_id),
      CONSTRAINT fk_title_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_title_access_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
      CONSTRAINT fk_title_access_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB",
    'premium_subscriptions' => "CREATE TABLE IF NOT EXISTS premium_subscriptions (
      user_id BIGINT UNSIGNED PRIMARY KEY,
      started_at DATETIME NOT NULL,
      expires_at DATETIME NOT NULL,
      granted_by BIGINT UNSIGNED NULL,
      CONSTRAINT fk_premium_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_premium_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
      INDEX idx_premium_expiration (expires_at)
    ) ENGINE=InnoDB",
    'comments' => "CREATE TABLE IF NOT EXISTS comments (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT UNSIGNED NULL,
      title_id BIGINT UNSIGNED NOT NULL,
      body VARCHAR(2000) NOT NULL,
      is_approved TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_comments_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
      INDEX idx_comments_title (title_id, is_approved, created_at)
    ) ENGINE=InnoDB",
    'ratings' => "CREATE TABLE IF NOT EXISTS ratings (
      user_id BIGINT UNSIGNED NOT NULL,
      title_id BIGINT UNSIGNED NOT NULL,
      rating TINYINT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, title_id),
      CONSTRAINT fk_ratings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_ratings_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
      CONSTRAINT chk_rating_range CHECK (rating BETWEEN 1 AND 5)
    ) ENGINE=InnoDB",
    'site_settings' => "CREATE TABLE IF NOT EXISTS site_settings (
      setting_key VARCHAR(100) PRIMARY KEY,
      setting_value TEXT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
];
foreach ($tables as $sql) {
    $pdo->exec($sql);
}
$pdo->exec('ALTER TABLE account_tokens MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
$tokenColumns = $pdo->query('SHOW COLUMNS FROM account_tokens')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('target_email', $tokenColumns, true)) {
    $pdo->exec("ALTER TABLE account_tokens ADD COLUMN target_email VARCHAR(190) NULL AFTER purpose");
}
$pdo->exec("DELETE FROM account_tokens WHERE purpose <> 'password_reset'");
$pdo->exec("ALTER TABLE account_tokens MODIFY purpose ENUM('password_reset') NOT NULL");
$pdo->exec("INSERT IGNORE INTO social_links (platform, url, is_enabled, placement) VALUES
    ('facebook', NULL, 0, 'footer'), ('instagram', NULL, 0, 'footer'),
    ('discord', NULL, 0, 'footer'), ('whatsapp', NULL, 0, 'footer')");
$pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('payment_whatsapp_url', NULL)");

$pdo->exec("UPDATE users SET username = CONCAT('user', id) WHERE username IS NULL OR username = ''");
$pdo->exec("UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE role IN ('super_admin', 'admin', 'moderator') AND email_verified_at IS NULL");

$message = 'Migração BALDÉ FLIX concluída.';
if (PHP_SAPI === 'cli') {
    echo $message . PHP_EOL;
} else {
    render_header('Migração concluída');
    echo '<section class="page-heading"><h1>' . e($message) . '</h1><p>As novas funcionalidades estão preparadas. Por segurança, remova o ficheiro migrate.php da pasta pública.</p><a class="button button-primary" href="index.php">Abrir site</a></section>';
    render_footer();
}
