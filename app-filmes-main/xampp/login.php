<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$ownerMode = (($_GET['dono'] ?? $_POST['dono'] ?? '') === '1');
$signedInUser = current_user();
if ($signedInUser) {
    redirect($ownerMode && $signedInUser['role'] === 'super_admin' ? 'admin.php' : 'index.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (mb_strlen($email) > 190) {
        $email = '';
    }
    $password = (string) ($_POST['password'] ?? '');
    $loginKey = hash('sha256', $email . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $attempts = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE login_key = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $attempts->execute([$loginKey]);
    $locked = (int) $attempts->fetchColumn() >= 8;
    $stmt = $pdo->prepare('SELECT id, email, username, display_name, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $passwordMatches = $user
        && $user['is_active']
        && (!$ownerMode || $user['role'] === 'super_admin')
        && strlen($password) <= 4096
        && password_verify($password, $user['password_hash']);
    if ($locked || !$passwordMatches) {
        $error = 'E-mail ou palavra-passe inválidos.';
        if (!$locked) {
            $record = $pdo->prepare('INSERT INTO login_attempts (login_key) VALUES (?)');
            $record->execute([$loginKey]);
        }
        usleep(250000);
    } else {
        $pdo->prepare('DELETE FROM login_attempts WHERE login_key = ?')->execute([$loginKey]);
        session_regenerate_id(true);
        unset($user['password_hash'], $user['is_active']);
        $_SESSION['user'] = $user;
        audit($pdo, (int) $user['id'], 'auth.login');
        redirect($ownerMode ? 'admin.php' : 'index.php');
    }
}
render_header($ownerMode ? 'Acesso do proprietário' : 'Iniciar sessão');
?>
<section class="auth-wrap">
  <div class="auth-card">
    <span class="eyebrow"><?= $ownerMode ? 'Área reservada' : 'Bem-vindo à plataforma' ?></span>
    <h1><?= $ownerMode ? 'Acesso do dono' : 'Entrar na BALDÉ FLIX' ?></h1>
    <p class="muted"><?= $ownerMode ? 'Apenas a conta SUPER ADMIN principal pode aceder ao painel do proprietário.' : 'Inicie sessão para gerir a sua lista e acompanhar pedidos.' ?></p>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <?php if ($ownerMode): ?><input type="hidden" name="dono" value="1"><?php endif; ?>
      <label>E-mail<input type="email" name="email" maxlength="190" required autocomplete="email"></label>
      <label>Palavra-passe<input type="password" name="password" required autocomplete="current-password"></label>
      <button class="button button-primary" type="submit"><?= $ownerMode ? 'Entrar como dono' : 'Entrar' ?></button>
    </form>
    <?php if ($ownerMode): ?>
      <p class="form-note"><a href="login.php">Entrar como utilizador</a></p>
    <?php else: ?>
      <p class="form-note">Ainda não tem conta? <a href="registar.php">Registar-se</a></p>
      <p class="form-note"><a href="login.php?dono=1">Login do dono</a></p>
    <?php endif; ?>
    <p class="form-note"><a href="recuperar.php">Esqueci-me da palavra-passe</a></p>
  </div>
</section>
<?php render_footer(); ?>
