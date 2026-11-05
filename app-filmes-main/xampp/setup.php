<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$localAddresses = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $localAddresses, true)) {
    http_response_code(403);
    exit('A configuração inicial só pode ser executada a partir deste computador.');
}

$count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
if ($count > 0) {
    redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = 'aliujogos8@gmail.com';
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Preencha o nome e um e-mail válido.';
    } elseif (strlen($password) < 8 || strlen($password) > 4096) {
        $error = 'Use uma palavra-passe entre 8 e 4096 caracteres.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'As palavras-passe não coincidem.';
    } else {
        try {
            $lock = (int) $pdo->query("SELECT GET_LOCK('balde_flix_super_admin_setup', 5)")->fetchColumn();
            if ($lock !== 1) {
                throw new RuntimeException('A configuração já está em curso. Aguarde alguns segundos e tente novamente.');
            }
            $check = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
            if ($check > 0) {
                $pdo->query("SELECT RELEASE_LOCK('balde_flix_super_admin_setup')");
                redirect('login.php');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$email]);
            $existingUserId = $stmt->fetchColumn();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($existingUserId !== false) {
                $ownerId = (int) $existingUserId;
                $update = $pdo->prepare("UPDATE users SET display_name = ?, password_hash = ?, role = 'super_admin', is_active = 1, email_verified_at = CURRENT_TIMESTAMP WHERE id = ?");
                $update->execute([$name, $passwordHash, $ownerId]);
                audit($pdo, $ownerId, 'setup.super_admin_created', 'Conta local existente promovida a SUPER ADMIN');
            } else {
                $insert = $pdo->prepare("INSERT INTO users (email, username, display_name, password_hash, role, email_verified_at) VALUES (?, 'aliujogos8', ?, ?, 'super_admin', CURRENT_TIMESTAMP)");
                $insert->execute([$email, $name, $passwordHash]);
                $ownerId = (int) $pdo->lastInsertId();
                audit($pdo, $ownerId, 'setup.super_admin_created', 'Conta inicial criada');
            }
            $pdo->commit();
            $pdo->query("SELECT RELEASE_LOCK('balde_flix_super_admin_setup')");
            flash('Conta de SUPER ADMIN criada. Inicie sessão para continuar.', 'success');
            redirect('login.php');
        } catch (RuntimeException $exception) {
            if (isset($lock) && $lock === 1) {
                $pdo->query("SELECT RELEASE_LOCK('balde_flix_super_admin_setup')");
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isset($lock) && $lock === 1) {
                $pdo->query("SELECT RELEASE_LOCK('balde_flix_super_admin_setup')");
            }
            error_log('BALDÉ FLIX setup failed: ' . $exception->getMessage());
            $error = 'Não foi possível criar a conta. Verifique se o e-mail já está registado e tente novamente.';
        }
    }
}

render_header('Configuração inicial');
?>
<section class="auth-wrap">
  <div class="auth-card">
    <span class="eyebrow">Configuração local</span>
    <h1>Criar o SUPER ADMIN</h1>
    <p class="muted">Escolha uma palavra-passe forte e única. Se este e-mail já tiver uma conta, ela será convertida na conta SUPER ADMIN principal.</p>
    <?php if ($error): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Nome<input name="name" maxlength="120" required autocomplete="name"></label>
      <label>E-mail da conta principal<input type="email" value="aliujogos8@gmail.com" readonly></label>
      <label>Palavra-passe (mínimo 8 caracteres)<input type="password" name="password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
      <label>Confirmar palavra-passe<input type="password" name="confirm_password" minlength="8" maxlength="4096" required autocomplete="new-password"></label>
      <button class="button button-primary" type="submit">Criar conta principal</button>
    </form>
  </div>
</section>
<?php render_footer(); ?>
