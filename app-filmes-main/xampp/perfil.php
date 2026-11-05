<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$error = '';
$success = '';
$settingsActions = ['update_profile', 'change_email', 'delete_account'];
$activeTab = (($_GET['aba'] ?? '') === 'configuracoes' || in_array($_POST['action'] ?? '', $settingsActions, true))
    ? 'configuracoes'
    : 'perfil';
$uploadImage = static function (string $field): ?string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Cada imagem deve ter no máximo 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageInfo = getimagesize($file['tmp_name']);
    if (!isset($extensions[$mime]) || $imageInfo === false || $imageInfo['mime'] !== $mime) {
        throw new RuntimeException('Use imagens JPG, PNG ou WEBP.');
    }
    $directory = __DIR__ . '/uploads/profiles';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar a pasta de perfil.');
    }
    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $fileName)) {
        throw new RuntimeException('Não foi possível guardar a imagem.');
    }
    return 'uploads/profiles/' . $fileName;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'update_profile') {
            $name = trim((string) ($_POST['display_name'] ?? ''));
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $bio = trim((string) ($_POST['bio'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120 || !preg_match('/^[a-z0-9_]{3,32}$/', $username) || mb_strlen($bio) > 500) {
                throw new RuntimeException('Confira o nome, o username (3–32 letras, números ou _) e a biografia (máximo 500 caracteres).');
            }
            $avatar = $uploadImage('avatar');
            $cover = $uploadImage('cover');
            $sql = 'UPDATE users SET display_name = ?, username = ?, bio = ?';
            $values = [$name, $username, $bio ?: null];
            if ($avatar !== null) {
                $sql .= ', avatar_path = ?';
                $values[] = $avatar;
            }
            if ($cover !== null) {
                $sql .= ', cover_path = ?';
                $values[] = $cover;
            }
            $sql .= ' WHERE id = ?';
            $values[] = $user['id'];
            $pdo->prepare($sql)->execute($values);
            audit($pdo, (int) $user['id'], 'profile.updated');
            $success = 'Perfil atualizado.';
        } elseif ($action === 'change_email') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['current_password'] ?? '');
            if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um novo e-mail válido.');
            }
            if ($email === $user['email']) {
                throw new RuntimeException('O novo e-mail é igual ao atual.');
            }
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            if (!password_verify($password, (string) $stmt->fetchColumn())) {
                throw new RuntimeException('A palavra-passe atual está incorreta.');
            }
            $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $exists->execute([$email]);
            if ($exists->fetch()) {
                throw new RuntimeException('Esse e-mail já está associado a outra conta.');
            }
            $pdo->prepare('UPDATE users SET email = ?, email_verified_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$email, $user['id']]);
            $_SESSION['user']['email'] = $email;
            audit($pdo, (int) $user['id'], 'auth.email_changed');
            $success = 'Endereço de e-mail atualizado.';
        } elseif ($action === 'delete_account') {
            if ($user['role'] === 'super_admin') {
                throw new RuntimeException('A conta principal do proprietário não pode ser eliminada.');
            }
            $password = (string) ($_POST['current_password'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            if (!password_verify($password, (string) $stmt->fetchColumn())) {
                throw new RuntimeException('A palavra-passe atual está incorreta.');
            }
            $pdo->prepare('DELETE FROM users WHERE id = ? AND role <> ?')->execute([$user['id'], 'super_admin']);
            audit($pdo, null, 'account.self_deleted');
            $_SESSION = [];
            session_destroy();
            redirect('index.php');
        } elseif ($action === 'mark_notifications_read') {
            $pdo->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL')->execute([$user['id']]);
            $success = 'Notificações marcadas como lidas.';
        } elseif ($action === 'delete_notification') {
            $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$notificationId || $notificationId < 1) {
                throw new RuntimeException('Notificação inválida.');
            }
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
            $stmt->execute([$notificationId, $user['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('A notificação já não existe ou não pertence à sua conta.');
            }
            $success = 'Notificação eliminada.';
        } elseif ($action === 'delete_all_notifications') {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$user['id']]);
            $success = 'As suas notificações foram eliminadas.';
        } else {
            http_response_code(403);
            exit('Ação não autorizada.');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('BALDÉ FLIX profile action failed: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? 'Esse username ou endereço de e-mail já está em uso.'
            : 'Não foi possível guardar as alterações.';
    }
    $user = require_login();
}

$stmt = $pdo->prepare('SELECT id, email, username, display_name, bio, avatar_path, cover_path, created_at FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
$stmt->execute([$user['id']]);
$favoriteCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT t.id, t.name, t.kind, t.poster_path FROM favorites f JOIN titles t ON t.id = f.title_id WHERE f.user_id = ? AND t.published = 1 ORDER BY f.created_at DESC LIMIT 12');
$stmt->execute([$user['id']]);
$favorites = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM watch_history WHERE user_id = ?');
$stmt->execute([$user['id']]);
$historyCount = (int) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT title, request_type, status, admin_response, created_at FROM content_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT n.id, n.title, n.message, n.read_at, n.created_at FROM notifications n WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 20');
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT t.id, t.name, t.kind, t.poster_path, MAX(h.progress_seconds) AS progress_seconds, MAX(h.updated_at) AS last_watched FROM watch_history h JOIN titles t ON t.id = h.title_id WHERE h.user_id = ? GROUP BY t.id ORDER BY last_watched DESC LIMIT 12');
$stmt->execute([$user['id']]);
$history = $stmt->fetchAll();
$statuses = ['pending' => 'Pendente', 'reviewing' => 'Em análise', 'approved' => 'Aprovado', 'available' => 'Disponível', 'rejected' => 'Recusado'];
render_header('O meu perfil');
?>
<section class="profile-hero" style="<?= $profile['cover_path'] ? 'background-image:linear-gradient(90deg,rgba(9,9,11,.95),rgba(9,9,11,.4)),url(' . e($profile['cover_path']) . ');' : '' ?>">
  <div class="profile-avatar"><?php if ($profile['avatar_path']): ?><img src="<?= e($profile['avatar_path']) ?>" alt="Foto de perfil"><?php else: ?><?= e(mb_substr($profile['display_name'], 0, 1)) ?><?php endif; ?></div>
  <div><span class="eyebrow">Perfil BALDÉ FLIX</span><h1><?= e($profile['display_name']) ?></h1><p>@<?= e($profile['username'] ?? '') ?> · Desde <?= e(date('m/Y', strtotime($profile['created_at']))) ?></p></div>
  <div class="profile-stats"><span><b><?= $favoriteCount ?></b> favoritos</span><span><b><?= $historyCount ?></b> vistos</span></div>
</section>
<section class="content-section profile-content">
  <?php if ($error): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="notice notice-success"><?= e($success) ?></div><?php endif; ?>
  <nav class="profile-tabs" aria-label="Secções do perfil">
    <a class="<?= $activeTab === 'perfil' ? 'active' : '' ?>" href="perfil.php?aba=perfil" <?= $activeTab === 'perfil' ? 'aria-current="page"' : '' ?>>O meu perfil</a>
    <a class="<?= $activeTab === 'configuracoes' ? 'active' : '' ?>" href="perfil.php?aba=configuracoes" <?= $activeTab === 'configuracoes' ? 'aria-current="page"' : '' ?>>Configurações</a>
  </nav>
  <?php if ($activeTab === 'configuracoes'): ?>
  <div class="profile-grid">
    <section class="panel-card"><span class="eyebrow">Identidade</span><h2>Editar perfil</h2>
      <form method="post" enctype="multipart/form-data" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_profile">
        <label>Nome<input name="display_name" maxlength="120" required value="<?= e($profile['display_name']) ?>"></label>
        <label>Username<input name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9_]{3,32}" required value="<?= e($profile['username'] ?? '') ?>"></label>
        <label>Biografia<textarea name="bio" maxlength="500" rows="3"><?= e($profile['bio'] ?? '') ?></textarea></label>
        <label>Foto de perfil (JPG, PNG ou WEBP · máx. 5 MB)<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></label>
        <label>Imagem de capa (JPG, PNG ou WEBP · máx. 5 MB)<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label>
        <button class="button button-primary">Guardar perfil</button>
      </form>
    </section>
    <section class="panel-card"><span class="eyebrow">Conta</span><h2>E-mail e palavra-passe</h2>
      <p class="muted">E-mail atual: <?= e($profile['email']) ?></p>
      <form method="post" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_email">
        <label>Novo e-mail<input type="email" name="email" maxlength="190" required></label>
        <label>Palavra-passe atual<input type="password" name="current_password" required autocomplete="current-password"></label>
        <button class="button button-outline">Guardar novo e-mail</button>
      </form>
      <a class="profile-link" href="alterar_senha.php">Alterar palavra-passe →</a>
    </section>
  </div>
  <?php if ($user['role'] !== 'super_admin'): ?><section class="panel-card danger-zone"><span class="eyebrow">Zona de risco</span><h2>Eliminar a minha conta</h2><p class="muted">A eliminação é permanente e remove favoritos, histórico e notificações.</p><form method="post" class="form-grid" onsubmit="return confirm('Eliminar permanentemente a sua conta? Esta ação não pode ser desfeita.')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_account"><label>Confirme a sua palavra-passe<input type="password" name="current_password" required autocomplete="current-password"></label><button class="button button-danger">Eliminar conta</button></form></section><?php endif; ?>
  <?php else: ?>
  <section class="profile-section">
    <div class="section-heading"><div><span class="eyebrow">A sua seleção</span><h2>Filmes e séries favoritos</h2></div><a class="profile-link" href="index.php?lista=1#catalogo">Ver minha lista →</a></div>
    <?php if ($favorites): ?><div class="history-grid"><?php foreach ($favorites as $item): ?><a class="history-card" href="titulo.php?id=<?= (int) $item['id'] ?>"><?php if ($item['poster_path']): ?><img src="<?= e($item['poster_path']) ?>" alt=""><?php endif; ?><span><?= e($item['name']) ?> · <?= $item['kind'] === 'series' ? 'Série' : 'Filme' ?></span></a><?php endforeach; ?></div><?php else: ?><div class="empty-state compact">Ainda não guardou favoritos.</div><?php endif; ?>
  </section>

  <section class="profile-section">
    <div class="section-heading"><div><span class="eyebrow">Reprodução</span><h2>Continuar a assistir</h2></div></div>
    <?php if ($history): ?><div class="history-grid"><?php foreach ($history as $item): ?><a class="history-card" href="titulo.php?id=<?= (int) $item['id'] ?>"><?php if ($item['poster_path']): ?><img src="<?= e($item['poster_path']) ?>" alt=""><?php endif; ?><span><?= e($item['name']) ?></span><small>Continuar · <?= (int) floor($item['progress_seconds'] / 60) ?> min</small></a><?php endforeach; ?></div><?php else: ?><div class="empty-state compact">O histórico de reprodução está vazio.</div><?php endif; ?>
  </section>

  <section class="profile-section">
    <div class="section-heading"><div><span class="eyebrow">A sua atividade</span><h2>Os meus pedidos</h2></div><a class="profile-link" href="pedido.php">Novo pedido →</a></div>
    <?php if ($requests): ?><div class="request-list"><?php foreach ($requests as $request): ?><article class="request-item"><div><span class="request-type"><?= $request['request_type'] === 'series' ? 'SÉRIE' : 'FILME' ?></span><h3><?= e($request['title']) ?></h3><time><?= e(date('d/m/Y', strtotime($request['created_at']))) ?></time></div><div class="request-state"><span class="status status-<?= e($request['status']) ?>"><?= e($statuses[$request['status']] ?? $request['status']) ?></span><?php if ($request['admin_response']): ?><p><?= e($request['admin_response']) ?></p><?php endif; ?></div></article><?php endforeach; ?></div><?php else: ?><div class="empty-state compact">Ainda não enviou pedidos.</div><?php endif; ?>
  </section>

  <section class="profile-section">
    <div class="section-heading"><div><span class="eyebrow">Atualizações</span><h2>Notificações</h2></div><?php if ($notifications): ?><div class="inline-form notification-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mark_notifications_read"><button class="button button-outline button-small">Marcar como lidas</button></form><form method="post" onsubmit="return confirm('Eliminar todas as suas notificações? Esta ação não pode ser desfeita.')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_all_notifications"><button class="button button-danger button-small">Eliminar todas</button></form></div><?php endif; ?></div>
    <?php if ($notifications): ?><div class="notification-list"><?php foreach ($notifications as $notification): ?><article class="notification-item <?= $notification['read_at'] ? '' : 'unread' ?>"><div class="notification-content"><b><?= e($notification['title']) ?></b><p><?= e($notification['message']) ?></p><time><?= e(date('d/m/Y H:i', strtotime($notification['created_at']))) ?></time></div><form method="post" onsubmit="return confirm('Eliminar esta notificação?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_notification"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="button button-outline button-small" aria-label="Eliminar notificação">Eliminar</button></form></article><?php endforeach; ?></div><?php else: ?><div class="empty-state compact">Não tem notificações.</div><?php endif; ?>
  </section>
  <?php endif; ?>
</section>
<?php render_footer(); ?>
