<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $password = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string) $stmt->fetchColumn();
    if (!password_verify($current, $hash)) {
        $error = 'A palavra-passe atual está incorreta.';
    } elseif (strlen($password) < 8 || strlen($password) > 4096) {
        $error = 'A nova palavra-passe deve ter entre 8 e 4096 caracteres.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'A confirmação da nova palavra-passe não coincide.';
    } elseif (password_verify($password, $hash)) {
        $error = 'Escolha uma palavra-passe diferente da atual.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        audit($pdo, (int) $user['id'], 'auth.password_changed');
        flash('Palavra-passe alterada com sucesso.', 'success');
        redirect('perfil.php?aba=configuracoes');
    }
}
render_header('Alterar palavra-passe');
?>
<section class="auth-wrap"><div class="auth-card">
  <span class="eyebrow">Segurança da conta</span><h1>Alterar palavra-passe</h1>
  <?php if ($error): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form-stack">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label>Palavra-passe atual<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>Nova palavra-passe (mínimo 8 caracteres)<input type="password" name="new_password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
    <label>Confirmar nova palavra-passe<input type="password" name="confirm_password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
    <button class="button button-primary">Guardar nova palavra-passe</button>
  </form>
</div></section>
<?php render_footer(); ?>
