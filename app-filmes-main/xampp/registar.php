<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 120 || !preg_match('/^[a-z0-9_]{3,32}$/', $username) || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe nome, username válido (3–32 letras, números ou _) e e-mail válido.';
    } elseif (strlen($password) < 8 || strlen($password) > 4096) {
        $error = 'Use uma palavra-passe entre 8 e 4096 caracteres.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (email, username, display_name, password_hash, role, is_active, email_verified_at) VALUES (?, ?, ?, ?, 'viewer', 1, CURRENT_TIMESTAMP)");
            $stmt->execute([$email, $username, $name, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, $id, 'auth.register');
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => $id,
                'email' => $email,
                'username' => $username,
                'display_name' => $name,
                'role' => 'viewer',
            ];
            flash('Conta criada com sucesso. Bem-vindo à BALDÉ FLIX!', 'success');
            redirect('index.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'Este e-mail ou username já está registado.';
            } else {
                error_log('BALDÉ FLIX registration failed: ' . $exception->getMessage());
                $error = 'Não foi possível criar a conta. Tente novamente.';
            }
        }
    }
}
render_header('Criar conta');
?>
<section class="auth-wrap">
  <div class="auth-card">
    <span class="eyebrow">A sua experiência começa aqui</span>
    <h1>Criar conta</h1>
    <p class="muted">Crie a sua conta para começar a usar a plataforma.</p>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Nome<input name="name" maxlength="120" required autocomplete="name"></label>
      <label>Username<input name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9_]{3,32}" required autocomplete="username"></label>
      <label>E-mail<input type="email" name="email" maxlength="190" required autocomplete="email"></label>
      <label>Palavra-passe (mínimo 8 caracteres)<input type="password" name="password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
      <button class="button button-primary" type="submit">Criar conta</button>
    </form>
  </div>
</section>
<?php render_footer(); ?>
