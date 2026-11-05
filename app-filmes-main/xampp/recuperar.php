<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190) {
        $stmt = $pdo->prepare('SELECT id, email, display_name FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            try {
                $token = issue_account_token($pdo, (int) $user['id'], 'password_reset', 60);
                $url = account_action_url('redefinir.php', $token);
                send_smtp_email(
                    $user,
                    'Recuperação da palavra-passe — BALDÉ FLIX',
                    '<p>Olá, ' . e($user['display_name']) . '.</p><p><a href="' . e($url) . '">Criar uma nova palavra-passe</a></p><p>Este link expira em 60 minutos. Se não pediu a recuperação, ignore esta mensagem.</p>',
                    "Olá, " . $user['display_name'] . ".\n\nCrie uma nova palavra-passe: " . $url . "\nEste link expira em 60 minutos."
                );
            } catch (RuntimeException $exception) {
                error_log('BALDÉ FLIX password reset email failed: ' . $exception->getMessage());
            }
        }
    }
    $message = 'Se existir uma conta ativa para esse endereço, enviaremos instruções de recuperação.';
}
render_header('Recuperar palavra-passe');
?>
<section class="auth-wrap"><div class="auth-card">
  <span class="eyebrow">Recuperação segura</span><h1>Esqueceu-se?</h1>
  <p class="muted">Enviaremos um link para o e-mail associado à sua conta.</p>
  <?php if ($message): ?><div class="notice notice-info"><?= e($message) ?></div><?php endif; ?>
  <form method="post" class="form-stack">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label>E-mail<input type="email" name="email" maxlength="190" required autocomplete="email"></label>
    <button class="button button-primary" type="submit">Enviar link</button>
  </form>
</div></section>
<?php render_footer(); ?>
