<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuração ausente. Copie config.example.php para config.php e configure a ligação ao MySQL.');
}

$config = require $configFile;
$config = array_replace_recursive([
    'app_name' => 'BALDÉ FLIX',
    'base_url' => 'http://localhost/balde-flix',
    'smtp' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name' => 'BALDÉ FLIX',
    ],
], $config);
try {
    $pdo = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $error) {
    error_log('BALDÉ FLIX database connection failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Não foi possível ligar à base de dados. Confirme se o MySQL está iniciado e se config.php está correto.');
}
require_once __DIR__ . '/mailer.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Sessão expirada ou formulário inválido. Volte atrás e tente novamente.');
    }
}

function current_user(): ?array
{
    if (empty($GLOBALS['authenticated_user_refreshed'])) {
        global $pdo;
        $GLOBALS['authenticated_user_refreshed'] = true;
        if (!empty($_SESSION['user']['id'])) {
            $stmt = $pdo->prepare('SELECT id, email, display_name, role, is_active FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$_SESSION['user']['id']]);
            $user = $stmt->fetch();
            if (!$user || !$user['is_active']) {
                unset($_SESSION['user']);
            } else {
                unset($user['is_active']);
                $_SESSION['user'] = $user;
            }
        }
    }
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!in_array($user['role'], ['super_admin', 'admin', 'moderator'], true)) {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }
    return $user;
}

function has_paid_title_access(PDO $pdo, int $userId, int $titleId): bool
{
    $stmt = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM user_title_access WHERE user_id = ? AND title_id = ?) OR EXISTS (SELECT 1 FROM premium_subscriptions WHERE user_id = ? AND started_at <= CURRENT_TIMESTAMP AND expires_at > CURRENT_TIMESTAMP)');
    $stmt->execute([$userId, $titleId, $userId]);
    return (bool) $stmt->fetchColumn();
}

function uploaded_video_types(): array
{
    return [
        '3g2' => 'video/3gpp2',
        '3gp' => 'video/3gpp',
        '264' => 'video/h264',
        'asf' => 'video/x-ms-asf',
        'avi' => 'video/x-msvideo',
        'divx' => 'video/divx',
        'f4v' => 'video/mp4',
        'flv' => 'video/x-flv',
        'h264' => 'video/h264',
        'h265' => 'video/h265',
        'hevc' => 'video/h265',
        'ivf' => 'video/x-ivf',
        'm1v' => 'video/mpeg',
        'm2ts' => 'video/mp2t',
        'm2v' => 'video/mpeg',
        'm4v' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'mp2' => 'video/mpeg',
        'mp4' => 'video/mp4',
        'mpe' => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
        'mpv' => 'video/mpeg',
        'mxf' => 'application/mxf',
        'mts' => 'video/mp2t',
        'ogv' => 'video/ogg',
        'rm' => 'video/vnd.rn-realvideo',
        'rmvb' => 'video/vnd.rn-realvideo',
        'ts' => 'video/mp2t',
        'vob' => 'video/mpeg',
        'webm' => 'video/webm',
        'wmv' => 'video/x-ms-wmv',
    ];
}

function is_mpeg_transport_stream_file(string $filePath): bool
{
    $handle = @fopen($filePath, 'rb');
    if ($handle === false) {
        return false;
    }

    try {
        foreach ([['packet_size' => 188, 'sync_offset' => 0], ['packet_size' => 192, 'sync_offset' => 4], ['packet_size' => 204, 'sync_offset' => 0]] as $format) {
            $isTransportStream = true;
            for ($packet = 0; $packet < 4; $packet++) {
                if (fseek($handle, $format['sync_offset'] + ($packet * $format['packet_size'])) !== 0 || fread($handle, 1) !== "\x47") {
                    $isTransportStream = false;
                    break;
                }
            }
            if ($isTransportStream) {
                return true;
            }
        }
    } finally {
        fclose($handle);
    }

    return false;
}

function is_uploaded_video_path(?string $path): bool
{
    if ($path === null || !preg_match('#^uploads/videos/[a-f0-9]{32}\.([a-z0-9]+)$#', $path, $matches)) {
        return false;
    }
    return $matches[1] === 'vid' || isset(uploaded_video_types()[$matches[1]]);
}

function audit(PDO $pdo, ?int $userId, string $action, ?string $details = null): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function render_header(string $title, string $active = ''): void
{
    $brand = e($GLOBALS['config']['app_name'] ?? 'BALDÉ FLIX');
    $user = current_user();
    global $pdo;
    $socialMarks = ['facebook' => 'f', 'instagram' => 'IG', 'discord' => 'DS', 'whatsapp' => 'WA'];
    $socials = $pdo->query("SELECT platform, url, placement FROM social_links WHERE is_enabled = 1 AND url IS NOT NULL AND url <> '' AND placement IN ('header', 'both')")->fetchAll();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?><!doctype html>
    <html lang="pt-PT">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="theme-color" content="#09090b">
      <title><?= e($title) ?> · <?= $brand ?></title>
      <link rel="stylesheet" href="assets/site.css?v=<?= (string) filemtime(__DIR__ . '/../assets/site.css') ?>">
    </head>
    <body>
      <header class="topbar">
        <a class="brand" href="index.php"><span class="brand-mark">B</span><span>BALDÉ <b>FLIX</b></span></a>
        <nav class="nav-links" aria-label="Navegação principal">
          <a class="<?= $active === 'home' ? 'active' : '' ?>" href="index.php">Início</a>
          <a href="index.php#catalogo">Filmes e séries</a>
          <?php if ($user): ?><a class="<?= $active === 'my-list' ? 'active' : '' ?>" href="index.php?lista=1#catalogo">Minha lista</a><?php endif; ?>
          <a class="<?= $active === 'requests' ? 'active' : '' ?>" href="pedido.php">Pedir conteúdo</a>
          <?php if ($user): ?>
            <a href="perfil.php"><?= e($user['display_name']) ?></a>
            <?php if (in_array($user['role'], ['super_admin', 'admin', 'moderator'], true)): ?>
              <a href="admin.php">Painel</a>
            <?php endif; ?>
            <form method="post" action="logout.php" class="inline-form">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <button class="nav-action" type="submit">Sair</button>
            </form>
          <?php else: ?>
            <a class="nav-login" href="login.php">Entrar</a>
            <a class="nav-login nav-login-owner" href="login.php?dono=1">Login do dono</a>
          <?php endif; ?>
        </nav>
      </header>
      <main class="page-shell">
        <?php if ($flash): ?>
          <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($socials): ?><div class="social-bar" aria-label="Redes sociais BALDÉ FLIX"><?php foreach ($socials as $social): ?><a class="social-link social-link-<?= e($social['platform']) ?>" href="<?= e($social['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e(ucfirst($social['platform'])) ?>" title="<?= e(ucfirst($social['platform'])) ?>"><span class="social-mark"><?= e($socialMarks[$social['platform']] ?? strtoupper(substr($social['platform'], 0, 2))) ?></span><span><?= e(ucfirst($social['platform'])) ?></span></a><?php endforeach; ?></div><?php endif; ?>
    <?php
}

function render_footer(): void
{
    global $pdo;
    $socialMarks = ['facebook' => 'f', 'instagram' => 'IG', 'discord' => 'DS', 'whatsapp' => 'WA'];
    $socials = $pdo->query("SELECT platform, url FROM social_links WHERE is_enabled = 1 AND url IS NOT NULL AND url <> '' AND placement IN ('footer', 'both')")->fetchAll();
    ?></main><footer class="footer"><span>© <?= date('Y') ?> BALDÉ FLIX</span><span>Conteúdo próprio ou autorizado. A disponibilidade pode variar.</span><?php if ($socials): ?><span class="footer-socials" aria-label="Redes sociais BALDÉ FLIX"><?php foreach ($socials as $social): ?><a class="social-link social-link-<?= e($social['platform']) ?>" href="<?= e($social['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e(ucfirst($social['platform'])) ?>" title="<?= e(ucfirst($social['platform'])) ?>"><span class="social-mark"><?= e($socialMarks[$social['platform']] ?? strtoupper(substr($social['platform'], 0, 2))) ?></span><span><?= e(ucfirst($social['platform'])) ?></span></a><?php endforeach; ?></span><?php endif; ?></footer>
    <script>
      document.querySelectorAll('input[type="password"]').forEach((input) => {
        const wrapper = document.createElement('span');
        wrapper.className = 'password-input-wrap';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const toggle = document.createElement('button');
        toggle.className = 'password-visibility-toggle';
        toggle.type = 'button';
        toggle.setAttribute('aria-label', 'Mostrar palavra-passe');
        toggle.setAttribute('aria-pressed', 'false');
        toggle.innerHTML = '<svg class="password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg><svg class="password-eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.8M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10.5 10.5 0 0 0 4.1-.8"></path></svg>';
        wrapper.appendChild(toggle);

        toggle.addEventListener('click', () => {
          const isVisible = input.type === 'text';
          input.type = isVisible ? 'password' : 'text';
          toggle.setAttribute('aria-pressed', String(!isVisible));
          toggle.setAttribute('aria-label', isVisible ? 'Mostrar palavra-passe' : 'Ocultar palavra-passe');
          toggle.title = isVisible ? 'Mostrar palavra-passe' : 'Ocultar palavra-passe';
        });
      });
    </script>
    </body></html><?php
}
