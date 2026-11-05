<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}
verify_csrf();
$user = require_login();
$titleId = filter_var($_POST['title_id'] ?? null, FILTER_VALIDATE_INT);
$episodeId = filter_var($_POST['episode_id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$progress = filter_var($_POST['progress_seconds'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
if (!$titleId || $episodeId === false || $progress === false) {
    http_response_code(400);
    exit('Dados de reprodução inválidos.');
}
$stmt = $pdo->prepare('SELECT id FROM titles WHERE id = ? AND published = 1');
$stmt->execute([$titleId]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('Conteúdo não encontrado.');
}
$paidTitle = $pdo->prepare('SELECT is_paid FROM titles WHERE id = ?');
$paidTitle->execute([$titleId]);
if ((bool) $paidTitle->fetchColumn() && !has_paid_title_access($pdo, (int) $user['id'], (int) $titleId)) {
    http_response_code(403);
    exit('Este conteúdo requer acesso pago ativo.');
}
if ($episodeId > 0) {
    $stmt = $pdo->prepare('SELECT e.id FROM episodes e JOIN seasons s ON s.id = e.season_id WHERE e.id = ? AND s.title_id = ?');
    $stmt->execute([$episodeId, $titleId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        exit('Episódio não encontrado.');
    }
}
$stmt = $pdo->prepare('INSERT INTO watch_history (user_id, title_id, episode_id, progress_seconds) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE progress_seconds = VALUES(progress_seconds), updated_at = CURRENT_TIMESTAMP');
$stmt->execute([$user['id'], $titleId, $episodeId, $progress]);
http_response_code(204);
