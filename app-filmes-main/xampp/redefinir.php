<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$validToken = false;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = $pdo->prepare("SELECT id FROM account_tokens WHERE token_hash = ? AND purpose = 'password_reset' AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $validToken = (bool) $stmt->fetchColumn();
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if (!$validToken) {
        $error = 'O link expirou ou já foi utilizado. Peça outro link de recuperação.';
    } elseif (strlen($password) < 8 || strlen($password) > 4096) {
        $error = 'A palavra-passe deve ter entre 8 e 4096 caracteres.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'As palavras-passe não coincidem.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT id, user_id FROM account_tokens WHERE token_hash = ? AND purpose = 'password_reset' AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE");
            $stmt->execute([hash('sha256', $token)]);
            $record = $stmt->fetch();
            if (!$record) {
                $pdo->rollBack();
                $error = 'O link expirou ou já foi utilizado. Peça outro link de recuperação.';
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND is_active = 1')->execute([password_hash($password, PASSWORD_DEFAULT), $record['user_id']]);
                $pdo->prepare('UPDATE account_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$record['user_id'], 'password_reset']);
                audit($pdo, (int) $record['user_id'], 'auth.password_reset');
                $pdo->commit();
                flash('Palavra-passe alterada. Inicie sessão com a nova palavra-passe.', 'success');
                redirect('login.php');
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('BALDÉ FLIX password reset failed: ' . $exception->getMessage());
            $error = 'Não foi possível alterar a palavra-passe. Tente novamente.';
        }
    }
}
render_header('Definir nova palavra-passe');
?>
<section class="auth-wrap"><div class="auth-card">
  <span class="eyebrow">Recuperação segura</span><h1>Nova palavra-passe</h1>
  <?php if ($error): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($validToken): ?>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
      <label>Nova palavra-passe (mínimo 8 caracteres)<input type="password" name="password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
      <label>Confirmar palavra-passe<input type="password" name="confirm_password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
      <button class="button button-primary" type="submit">Guardar palavra-passe</button>
    </form>
  <?php else: ?><p class="muted">Este link não é válido. <a href="recuperar.php">Peça um novo.</a></p><?php endif; ?>
</div></section>
<?php render_footer(); ?>
