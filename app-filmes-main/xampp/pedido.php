<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['title'] ?? ''));
    $type = (string) ($_POST['request_type'] ?? '');
    $yearInput = trim((string) ($_POST['release_year'] ?? ''));
    $year = $yearInput === '' ? null : filter_var($yearInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1888, 'max_range' => (int) date('Y') + 10]]);
    $requesterName = $user['display_name'];
    $genre = trim((string) ($_POST['genre'] ?? ''));
    $details = trim((string) ($_POST['details'] ?? ''));
    if ($name === '' || mb_strlen($name) > 180 || !in_array($type, ['movie', 'series'], true)) {
        $error = 'Informe um título válido e escolha filme ou série.';
    } elseif ($yearInput !== '' && $year === false) {
        $error = 'Informe um ano de lançamento válido.';
    } elseif (mb_strlen($requesterName) > 120 || mb_strlen($genre) > 80 || mb_strlen($details) > 2000) {
        $error = 'Alguns dados excedem o limite permitido. Revise o formulário.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO content_requests (user_id, requester_name, requester_email, request_type, title, release_year, genre, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $user['id'] ?? null,
            $requesterName ?: null,
            $user['email'],
            $type,
            $name,
            $year ?: null,
            $genre ?: null,
            $details ?: null,
        ]);
        audit($pdo, isset($user['id']) ? (int) $user['id'] : null, 'request.created', $name);
        flash('Pedido enviado. Obrigado por ajudar a escolher as próximas histórias!', 'success');
        redirect('pedido.php');
    }
}

$myRequests = [];
if ($user) {
    $stmt = $pdo->prepare('SELECT title, request_type, status, admin_response, created_at FROM content_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$user['id']]);
    $myRequests = $stmt->fetchAll();
}
$statusLabels = ['pending' => 'Pendente', 'reviewing' => 'Em análise', 'approved' => 'Aprovado', 'available' => 'Disponível', 'rejected' => 'Recusado'];
render_header('Pedir conteúdo', 'requests');
?>
<section class="page-heading">
  <span class="eyebrow">A comunidade escolhe</span>
  <h1>Que história quer ver?</h1>
  <p>Envie uma sugestão de filme ou série. A equipa irá analisar o pedido.</p>
</section>
<div class="request-layout">
  <section class="panel-card">
    <h2>Novo pedido</h2>
    <p class="muted">Partilhe o título que gostaria de encontrar na BALDÉ FLIX.</p>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Título<input name="title" maxlength="180" required placeholder="Ex.: O nome do filme ou série"></label>
      <div class="form-grid">
        <label>Tipo
          <select name="request_type" required><option value="movie">Filme</option><option value="series">Série</option></select>
        </label>
        <label>Ano (opcional)<input type="number" name="release_year" min="1888" max="<?= (int) date('Y') + 10 ?>" placeholder="2025"></label>
      </div>
      <div class="form-grid">
        <label>Género (opcional)<input name="genre" maxlength="80" placeholder="Aventura, drama..."></label>
        <label>Solicitante<input value="<?= e($user['display_name']) ?>" disabled></label>
      </div>
      <label>Informação adicional (opcional)<textarea name="details" rows="4" maxlength="2000" placeholder="Algum detalhe que ajude a identificar o conteúdo?"></textarea></label>
      <button class="button button-primary" type="submit">Enviar pedido <span>→</span></button>
    </form>
  </section>
  <aside class="request-side">
    <div class="panel-card side-card"><span class="side-icon">✦</span><h2>Como funciona</h2><ol class="steps"><li><b>Envie</b><span>Conte-nos o que gostaria de ver.</span></li><li><b>A equipa analisa</b><span>Verificamos disponibilidade e direitos de distribuição.</span></li><li><b>Acompanhe</b><span>Entre na sua conta para consultar o estado dos seus pedidos.</span></li></ol></div>
  </aside>
</div>
<?php if ($user): ?>
  <section class="content-section request-history">
    <div class="section-heading"><div><span class="eyebrow">A sua atividade</span><h2>Os meus pedidos</h2></div></div>
    <?php if ($myRequests): ?>
      <div class="request-list">
        <?php foreach ($myRequests as $request): ?>
          <article class="request-item">
            <div><span class="request-type"><?= $request['request_type'] === 'series' ? 'SÉRIE' : 'FILME' ?></span><h3><?= e($request['title']) ?></h3><time><?= e(date('d/m/Y', strtotime($request['created_at']))) ?></time></div>
            <div class="request-state"><span class="status status-<?= e($request['status']) ?>"><?= e($statusLabels[$request['status']] ?? 'Pendente') ?></span><?php if ($request['admin_response']): ?><p><?= e($request['admin_response']) ?></p><?php endif; ?></div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?><div class="empty-state compact">Ainda não enviou pedidos.</div><?php endif; ?>
  </section>
<?php endif; ?>
<?php render_footer(); ?>
