<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin();
$isSuper = $admin['role'] === 'super_admin';
$error = '';
$success = '';
$roles = ['super_admin' => 'SUPER ADMIN', 'admin' => 'Administrador', 'moderator' => 'Moderador', 'viewer' => 'Visitante'];
$statuses = ['pending' => 'Pendente', 'reviewing' => 'Em análise', 'approved' => 'Aprovado', 'available' => 'Disponível', 'rejected' => 'Recusado'];
$videoMaxBytes = 10 * 1024 * 1024 * 1024;
$newVideoUploads = [];
$saveVideoUpload = static function (string $field) use ($videoMaxBytes, &$newVideoUploads): ?string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > $videoMaxBytes) {
        throw new RuntimeException('O vídeo excede o limite máximo de 10 GB.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('O vídeo não foi recebido corretamente. Confirme o espaço disponível e tente novamente.');
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $videoTypes = uploaded_video_types();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $isTransportStream = is_mpeg_transport_stream_file($file['tmp_name']);
    $knownExtension = isset($videoTypes[$extension]);
    $knownVideoMime = is_string($mime) && (
        str_starts_with($mime, 'video/')
        || in_array($mime, array_values($videoTypes), true)
        || in_array($mime, ['application/mxf', 'application/x-matroska', 'application/vnd.rn-realmedia'], true)
    );
    if (!$knownVideoMime && !$isTransportStream) {
        throw new RuntimeException('O ficheiro selecionado não parece ser um vídeo válido.');
    }
    $storageExtension = $isTransportStream ? 'ts' : ($knownExtension ? $extension : 'vid');
    $uploadDir = __DIR__ . '/uploads/videos';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Não foi possível preparar a pasta dos vídeos.');
    }
    $fileName = bin2hex(random_bytes(16)) . '.' . $storageExtension;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
        throw new RuntimeException('Não foi possível guardar o vídeo. Confirme as permissões e o espaço disponível.');
    }
    $relativePath = 'uploads/videos/' . $fileName;
    $newVideoUploads[] = $relativePath;
    return $relativePath;
};
$qualityColumns = ['360p' => 'video_360p_url', '480p' => 'video_480p_url', '720p' => 'video_720p_url', '1080p' => 'video_1080p_url'];
$saveQualityUploads = static function (string $prefix, array $existing = []) use ($qualityColumns, $saveVideoUpload): array {
    $videos = [];
    foreach ($qualityColumns as $quality => $column) {
        $uploadedPath = $saveVideoUpload($prefix . '_' . $quality . '_file');
        $videos[$column] = $uploadedPath ?? ($existing[$column] ?? null);
    }
    return $videos;
};
$deleteVideoUpload = static function (?string $videoPath): void {
    if (!is_uploaded_video_path($videoPath)) {
        return;
    }
    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $videoPath);
    if (is_file($absolutePath) && !unlink($absolutePath)) {
        error_log('BALDÉ FLIX could not remove uploaded video: ' . $videoPath);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($admin['role'] === 'moderator' && !in_array($action, ['update_request', 'change_password'], true)) {
        http_response_code(403);
        exit('A função de moderador só permite gerir pedidos.');
    }
    try {
        if ($action === 'clear_audit_logs' && $isSuper) {
            $deletedCount = $pdo->exec('DELETE FROM audit_logs');
            $success = 'Atividades recentes eliminadas (' . $deletedCount . ' registos).';
        } elseif ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$admin['id']]);
            $passwordHash = $stmt->fetchColumn();
            if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
                throw new RuntimeException('A palavra-passe atual está incorreta.');
            }
            if (strlen($newPassword) < 8 || strlen($newPassword) > 4096) {
                throw new RuntimeException('A nova palavra-passe deve ter entre 8 e 4096 caracteres.');
            }
            if (!hash_equals($newPassword, $confirmPassword)) {
                throw new RuntimeException('A confirmação da nova palavra-passe não coincide.');
            }
            if (password_verify($newPassword, $passwordHash)) {
                throw new RuntimeException('Escolha uma palavra-passe diferente da atual.');
            }
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $admin['id']]);
            session_regenerate_id(true);
            audit($pdo, (int) $admin['id'], 'auth.password_changed');
            $success = 'Palavra-passe alterada. A sessão foi protegida novamente.';
        } elseif ($action === 'create_title') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $kind = (string) ($_POST['kind'] ?? '');
            $synopsis = trim((string) ($_POST['synopsis'] ?? ''));
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $yearInput = trim((string) ($_POST['release_year'] ?? ''));
            $year = $yearInput === '' ? null : filter_var($yearInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1888, 'max_range' => (int) date('Y') + 10]]);
            $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
            $posterPath = null;
            if ($name === '' || mb_strlen($name) > 180 || !in_array($kind, ['movie', 'series'], true) || $synopsis === '' || mb_strlen($synopsis) > 5000) {
                throw new RuntimeException('Preencha o título, o tipo e a sinopse.');
            }
            if ($yearInput !== '' && $year === false) {
                throw new RuntimeException('O ano indicado não é válido.');
            }
            if (mb_strlen($videoUrl) > 2048 || ($videoUrl !== '' && (!filter_var($videoUrl, FILTER_VALIDATE_URL) || parse_url($videoUrl, PHP_URL_SCHEME) !== 'https'))) {
                throw new RuntimeException('Use um endereço HTTPS válido para a fonte autorizada do vídeo.');
            }
            if ($videoUrl !== '' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Escolha o upload do vídeo ou uma URL HTTPS, não ambos.');
            }
            $uploadedVideoPath = $saveVideoUpload('video_file');
            if ($uploadedVideoPath !== null) {
                $videoUrl = $uploadedVideoPath;
            }
            if (isset($_FILES['poster']) && $_FILES['poster']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['poster'];
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('A imagem não foi enviada ou excede o limite de 5 MB.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $imageInfo = getimagesize($file['tmp_name']);
                if (!isset($extensions[$mime]) || $imageInfo === false || $imageInfo['mime'] !== $mime) {
                    throw new RuntimeException('Formato de imagem inválido. Use JPG, PNG ou WEBP.');
                }
                $uploadDir = __DIR__ . '/uploads/posters';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('Não foi possível preparar a pasta de imagens.');
                }
                $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
                    throw new RuntimeException('Não foi possível guardar a imagem enviada.');
                }
                $posterPath = 'uploads/posters/' . $fileName;
            }
            $qualityVideos = $saveQualityUploads('video');
            $qualityColumnSql = implode(', ', array_values($qualityColumns));
            $stmt = $pdo->prepare('INSERT INTO titles (name, kind, synopsis, category_id, release_year, is_paid, poster_path, video_url, ' . $qualityColumnSql . ', featured, published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute(array_merge([
                $name, $kind, $synopsis, $categoryId, $year ?: null, isset($_POST['is_paid']) ? 1 : 0, $posterPath, $videoUrl ?: null,
            ], array_values($qualityVideos), [
                isset($_POST['featured']) ? 1 : 0, isset($_POST['published']) ? 1 : 0,
            ]));
            audit($pdo, (int) $admin['id'], 'title.created', $name);
            $success = 'Título guardado com sucesso.';
        } elseif ($action === 'update_title') {
            $titleId = filter_var($_POST['title_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            $kind = (string) ($_POST['kind'] ?? '');
            $synopsis = trim((string) ($_POST['synopsis'] ?? ''));
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $yearInput = trim((string) ($_POST['release_year'] ?? ''));
            $year = $yearInput === '' ? null : filter_var($yearInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1888, 'max_range' => (int) date('Y') + 10]]);
            $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
            if (!$titleId || $name === '' || mb_strlen($name) > 180 || !in_array($kind, ['movie', 'series'], true) || $synopsis === '' || mb_strlen($synopsis) > 5000) {
                throw new RuntimeException('Preencha o título, o tipo e a sinopse.');
            }
            if ($yearInput !== '' && $year === false) {
                throw new RuntimeException('O ano indicado não é válido.');
            }
            if (mb_strlen($videoUrl) > 2048 || ($videoUrl !== '' && (!filter_var($videoUrl, FILTER_VALIDATE_URL) || parse_url($videoUrl, PHP_URL_SCHEME) !== 'https'))) {
                throw new RuntimeException('Use um endereço HTTPS válido para a fonte autorizada do vídeo.');
            }
            if ($videoUrl !== '' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Escolha o upload do vídeo ou uma URL HTTPS, não ambos.');
            }
            $existingTitle = $pdo->prepare('SELECT id, video_url, ' . implode(', ', array_values($qualityColumns)) . ' FROM titles WHERE id = ?');
            $existingTitle->execute([$titleId]);
            $existingTitleData = $existingTitle->fetch();
            if (!$existingTitleData) {
                throw new RuntimeException('O título que pretende editar já não existe.');
            }
            $uploadedVideoPath = $saveVideoUpload('video_file');
            if ($uploadedVideoPath !== null) {
                $videoUrl = $uploadedVideoPath;
            } elseif ($videoUrl === '' && is_uploaded_video_path((string) $existingTitleData['video_url'])) {
                $videoUrl = $existingTitleData['video_url'];
            }
            $qualityVideos = $saveQualityUploads('video', $existingTitleData);
            $qualityAssignments = implode(' = ?, ', array_values($qualityColumns)) . ' = ?';
            $stmt = $pdo->prepare('UPDATE titles SET name = ?, kind = ?, synopsis = ?, category_id = ?, release_year = ?, is_paid = ?, video_url = ?, ' . $qualityAssignments . ', featured = ?, published = ? WHERE id = ?');
            $stmt->execute(array_merge([
                $name, $kind, $synopsis, $categoryId, $year ?: null, isset($_POST['is_paid']) ? 1 : 0, $videoUrl ?: null,
            ], array_values($qualityVideos), [
                isset($_POST['featured']) ? 1 : 0, isset($_POST['published']) ? 1 : 0, $titleId,
            ]));
            if ($videoUrl !== $existingTitleData['video_url']) {
                $deleteVideoUpload($existingTitleData['video_url']);
            }
            foreach ($qualityColumns as $column) {
                if ($qualityVideos[$column] !== $existingTitleData[$column]) {
                    $deleteVideoUpload($existingTitleData[$column]);
                }
            }
            audit($pdo, (int) $admin['id'], 'title.updated', $name);
            $success = 'Título atualizado.';
        } elseif ($action === 'update_request') {
            $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            $response = trim((string) ($_POST['admin_response'] ?? ''));
            if (!$requestId || !isset($statuses[$status]) || $status === 'pending') {
                throw new RuntimeException('Estado do pedido inválido.');
            }
            $existingRequest = $pdo->prepare('SELECT id FROM content_requests WHERE id = ?');
            $existingRequest->execute([$requestId]);
            if (!$existingRequest->fetch()) {
                throw new RuntimeException('O pedido já não existe.');
            }
            if (mb_strlen($response) > 2000) {
                throw new RuntimeException('A resposta não pode exceder 2000 caracteres.');
            }
            $stmt = $pdo->prepare('UPDATE content_requests SET status = ?, admin_response = ? WHERE id = ?');
            $stmt->execute([$status, $response ?: null, $requestId]);
            $notify = $pdo->prepare('SELECT user_id, title FROM content_requests WHERE id = ?');
            $notify->execute([$requestId]);
            $requestOwner = $notify->fetch();
            if ($requestOwner && $requestOwner['user_id']) {
                $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)')->execute([
                    $requestOwner['user_id'],
                    'Atualização do seu pedido',
                    'O estado do pedido "' . $requestOwner['title'] . '" foi atualizado para ' . $statuses[$status] . ($response !== '' ? '. Resposta: ' . $response : '.'),
                ]);
            }
            audit($pdo, (int) $admin['id'], 'request.updated', 'Pedido #' . $requestId . ' · ' . $status);
            $success = 'Pedido atualizado.';
        } elseif ($action === 'create_season') {
            $seriesId = filter_var($_POST['series_id'] ?? null, FILTER_VALIDATE_INT);
            $seasonNumber = filter_var($_POST['season_number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
            $seasonName = trim((string) ($_POST['season_name'] ?? ''));
            if (!$seriesId || !$seasonNumber || mb_strlen($seasonName) > 160) {
                throw new RuntimeException('Escolha uma série e indique uma temporada válida.');
            }
            $stmt = $pdo->prepare("SELECT id FROM titles WHERE id = ? AND kind = 'series'");
            $stmt->execute([$seriesId]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('A série selecionada não existe.');
            }
            $pdo->prepare('INSERT INTO seasons (title_id, season_number, name) VALUES (?, ?, ?)')->execute([$seriesId, $seasonNumber, $seasonName ?: null]);
            audit($pdo, (int) $admin['id'], 'series.season_created', 'Série #' . $seriesId . ' · temporada ' . $seasonNumber);
            $success = 'Temporada adicionada.';
        } elseif ($action === 'create_episode') {
            $seasonId = filter_var($_POST['season_id'] ?? null, FILTER_VALIDATE_INT);
            $episodeNumber = filter_var($_POST['episode_number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
            $episodeName = trim((string) ($_POST['episode_name'] ?? ''));
            $episodeSynopsis = trim((string) ($_POST['episode_synopsis'] ?? ''));
            $episodeVideo = trim((string) ($_POST['episode_video_url'] ?? ''));
            if (!$seasonId || !$episodeNumber || $episodeName === '' || mb_strlen($episodeName) > 180 || mb_strlen($episodeSynopsis) > 5000) {
                throw new RuntimeException('Preencha a temporada, o número e o nome do episódio.');
            }
            if (mb_strlen($episodeVideo) > 2048 || ($episodeVideo !== '' && (!filter_var($episodeVideo, FILTER_VALIDATE_URL) || parse_url($episodeVideo, PHP_URL_SCHEME) !== 'https'))) {
                throw new RuntimeException('Use um endereço HTTPS válido para o vídeo autorizado.');
            }
            $stmt = $pdo->prepare('SELECT s.id FROM seasons s JOIN titles t ON t.id = s.title_id WHERE s.id = ? AND t.kind = ?');
            $stmt->execute([$seasonId, 'series']);
            if (!$stmt->fetch()) {
                throw new RuntimeException('A temporada selecionada não existe.');
            }
            if ($episodeVideo !== '' && isset($_FILES['episode_video_file']) && $_FILES['episode_video_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Escolha o upload do vídeo ou uma URL HTTPS, não ambos.');
            }
            $uploadedEpisodePath = $saveVideoUpload('episode_video_file');
            if ($uploadedEpisodePath !== null) {
                $episodeVideo = $uploadedEpisodePath;
            }
            $qualityVideos = $saveQualityUploads('episode_video');
            $qualityColumnSql = implode(', ', array_values($qualityColumns));
            $pdo->prepare('INSERT INTO episodes (season_id, episode_number, name, synopsis, video_url, ' . $qualityColumnSql . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge([$seasonId, $episodeNumber, $episodeName, $episodeSynopsis ?: null, $episodeVideo ?: null], array_values($qualityVideos)));
            audit($pdo, (int) $admin['id'], 'series.episode_created', $episodeName);
            $success = 'Episódio adicionado.';
        } elseif ($action === 'update_episode_qualities') {
            $episodeId = filter_var($_POST['episode_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$episodeId) {
                throw new RuntimeException('Episódio inválido.');
            }
            $stmt = $pdo->prepare('SELECT e.id, ' . implode(', ', array_map(static fn(string $column): string => 'e.' . $column, array_values($qualityColumns))) . ' FROM episodes e WHERE e.id = ?');
            $stmt->execute([$episodeId]);
            $existingEpisode = $stmt->fetch();
            if (!$existingEpisode) {
                throw new RuntimeException('O episódio selecionado já não existe.');
            }
            $qualityVideos = $saveQualityUploads('episode_video', $existingEpisode);
            $qualityAssignments = implode(' = ?, ', array_values($qualityColumns)) . ' = ?';
            $pdo->prepare('UPDATE episodes SET ' . $qualityAssignments . ' WHERE id = ?')->execute(array_merge(array_values($qualityVideos), [$episodeId]));
            foreach ($qualityColumns as $column) {
                if ($qualityVideos[$column] !== $existingEpisode[$column]) {
                    $deleteVideoUpload($existingEpisode[$column]);
                }
            }
            audit($pdo, (int) $admin['id'], 'series.episode_qualities_updated', 'Episódio #' . $episodeId);
            $success = 'Qualidades do episódio atualizadas.';
        } elseif ($action === 'delete_episode') {
            $episodeId = filter_var($_POST['episode_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$episodeId) {
                throw new RuntimeException('Episódio inválido.');
            }
            $stmt = $pdo->prepare('SELECT video_url, ' . implode(', ', array_values($qualityColumns)) . ' FROM episodes WHERE id = ?');
            $stmt->execute([$episodeId]);
            $videoPaths = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->prepare('DELETE FROM watch_history WHERE episode_id = ?')->execute([$episodeId]);
            $pdo->prepare('DELETE FROM episodes WHERE id = ?')->execute([$episodeId]);
            foreach ($videoPaths as $videoPath) {
                $deleteVideoUpload(is_string($videoPath) ? $videoPath : null);
            }
            audit($pdo, (int) $admin['id'], 'series.episode_deleted', 'Episódio #' . $episodeId);
            $success = 'Episódio eliminado.';
        } elseif ($action === 'delete_season') {
            $seasonId = filter_var($_POST['season_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$seasonId) {
                throw new RuntimeException('Temporada inválida.');
            }
            $stmt = $pdo->prepare('SELECT video_url, ' . implode(', ', array_values($qualityColumns)) . ' FROM episodes WHERE season_id = ?');
            $stmt->execute([$seasonId]);
            $videoPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare('DELETE h FROM watch_history h JOIN episodes e ON e.id = h.episode_id WHERE e.season_id = ?')->execute([$seasonId]);
            $pdo->prepare('DELETE FROM seasons WHERE id = ?')->execute([$seasonId]);
            foreach ($videoPaths as $episodePaths) {
                foreach ($episodePaths as $videoPath) {
                    $deleteVideoUpload(is_string($videoPath) ? $videoPath : null);
                }
            }
            audit($pdo, (int) $admin['id'], 'series.season_deleted', 'Temporada #' . $seasonId);
            $success = 'Temporada eliminada.';
        } elseif ($action === 'moderate_comment' && $isSuper) {
            $commentId = filter_var($_POST['comment_id'] ?? null, FILTER_VALIDATE_INT);
            $decision = (string) ($_POST['decision'] ?? '');
            if (!$commentId || !in_array($decision, ['approve', 'delete'], true)) {
                throw new RuntimeException('Ação de moderação inválida.');
            }
            if ($decision === 'approve') {
                $pdo->prepare('UPDATE comments SET is_approved = 1 WHERE id = ?')->execute([$commentId]);
            } else {
                $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$commentId]);
            }
            audit($pdo, (int) $admin['id'], 'comment.moderated', 'Comentário #' . $commentId . ' · ' . $decision);
            $success = $decision === 'approve' ? 'Comentário aprovado.' : 'Comentário removido.';
        } elseif ($action === 'delete_title') {
            $titleId = filter_var($_POST['title_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$titleId) {
                throw new RuntimeException('Título inválido.');
            }
            $stmt = $pdo->prepare('SELECT video_url, ' . implode(', ', array_values($qualityColumns)) . ' FROM titles WHERE id = ?');
            $stmt->execute([$titleId]);
            $videoPaths = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $episodeVideos = $pdo->prepare('SELECT e.video_url, ' . implode(', ', array_map(static fn(string $column): string => 'e.' . $column, array_values($qualityColumns))) . ' FROM episodes e JOIN seasons s ON s.id = e.season_id WHERE s.title_id = ?');
            $episodeVideos->execute([$titleId]);
            $episodeVideoPaths = $episodeVideos->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare('DELETE FROM titles WHERE id = ?');
            $stmt->execute([$titleId]);
            foreach ($videoPaths as $videoPath) {
                $deleteVideoUpload(is_string($videoPath) ? $videoPath : null);
            }
            foreach ($episodeVideoPaths as $episodePaths) {
                foreach ($episodePaths as $episodeVideoPath) {
                    $deleteVideoUpload(is_string($episodeVideoPath) ? $episodeVideoPath : null);
                }
            }
            audit($pdo, (int) $admin['id'], 'title.deleted', 'Título #' . $titleId);
            $success = 'Título eliminado.';
        } elseif ($action === 'create_user' && $isSuper) {
            $name = trim((string) ($_POST['display_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');
            if ($name === '' || mb_strlen($name) > 120 || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'moderator'], true) || strlen($password) < 8 || strlen($password) > 4096) {
                throw new RuntimeException('Preencha os dados corretamente. A palavra-passe deve ter pelo menos 8 caracteres.');
            }
            $username = 'admin' . bin2hex(random_bytes(5));
            $stmt = $pdo->prepare('INSERT INTO users (display_name, username, email, password_hash, role, email_verified_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            audit($pdo, (int) $admin['id'], 'user.created', $email . ' · ' . $role);
            $success = 'Conta administrativa criada.';
        } elseif ($action === 'toggle_user' && $isSuper) {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId || $userId === (int) $admin['id']) {
                throw new RuntimeException('Não é possível alterar esta conta.');
            }
            $stmt = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role <> 'super_admin'");
            $stmt->execute([$userId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Esta conta não pode ser suspensa nem alterada.');
            }
            audit($pdo, (int) $admin['id'], 'user.status_changed', 'Conta #' . $userId);
            $success = 'Estado da conta atualizado.';
        } elseif ($action === 'delete_user' && $isSuper) {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId || $userId === (int) $admin['id']) {
                throw new RuntimeException('Não é possível eliminar esta conta.');
            }
            $stmt = $pdo->prepare("SELECT email, role FROM users WHERE id = ? AND role <> 'super_admin'");
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('A conta principal do proprietário não pode ser eliminada.');
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            audit($pdo, (int) $admin['id'], 'user.deleted', $target['email'] . ' · ' . $target['role']);
            $success = 'Conta eliminada.';
        } elseif ($action === 'grant_title_access' && $isSuper) {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $titleId = filter_var($_POST['title_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId || !$titleId) {
                throw new RuntimeException('Selecione um utilizador e um título válidos.');
            }
            $stmt = $pdo->prepare("SELECT t.name FROM titles t JOIN users u ON u.id = ? WHERE t.id = ? AND t.is_paid = 1 AND t.published = 1 AND u.role = 'viewer' AND u.is_active = 1");
            $stmt->execute([$userId, $titleId]);
            $titleName = $stmt->fetchColumn();
            if (!is_string($titleName)) {
                throw new RuntimeException('O acesso só pode ser atribuído a utilizadores ativos e títulos pagos publicados.');
            }
            $pdo->prepare('INSERT IGNORE INTO user_title_access (user_id, title_id, granted_by) VALUES (?, ?, ?)')->execute([$userId, $titleId, $admin['id']]);
            audit($pdo, (int) $admin['id'], 'payment.title_access_granted', 'Conta #' . $userId . ' · título #' . $titleId);
            $success = 'Acesso a "' . $titleName . '" desbloqueado para o utilizador.';
        } elseif ($action === 'revoke_title_access' && $isSuper) {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $titleId = filter_var($_POST['title_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId || !$titleId) {
                throw new RuntimeException('Acesso selecionado inválido.');
            }
            $stmt = $pdo->prepare('DELETE FROM user_title_access WHERE user_id = ? AND title_id = ?');
            $stmt->execute([$userId, $titleId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Este utilizador não tem esse acesso individual ativo.');
            }
            audit($pdo, (int) $admin['id'], 'payment.title_access_revoked', 'Conta #' . $userId . ' · título #' . $titleId);
            $success = 'Acesso individual removido.';
        } elseif ($action === 'activate_premium' && $isSuper) {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId) {
                throw new RuntimeException('Selecione um utilizador válido.');
            }
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'viewer' AND is_active = 1");
            $stmt->execute([$userId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('O Premium só pode ser ativado para contas de utilizador ativas.');
            }
            $pdo->prepare('INSERT INTO premium_subscriptions (user_id, started_at, expires_at, granted_by) VALUES (?, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 DAY), ?) ON DUPLICATE KEY UPDATE started_at = CURRENT_TIMESTAMP, expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 DAY), granted_by = VALUES(granted_by)')
                ->execute([$userId, $admin['id']]);
            audit($pdo, (int) $admin['id'], 'payment.premium_activated', 'Conta #' . $userId . ' · 30 dias');
            $success = 'Premium ativado por 30 dias a partir de agora.';
        } elseif ($action === 'revoke_premium' && $isSuper) {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$userId) {
                throw new RuntimeException('Utilizador selecionado inválido.');
            }
            $stmt = $pdo->prepare('UPDATE premium_subscriptions SET expires_at = CURRENT_TIMESTAMP WHERE user_id = ? AND expires_at > CURRENT_TIMESTAMP');
            $stmt->execute([$userId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Este utilizador não tem uma subscrição Premium ativa.');
            }
            audit($pdo, (int) $admin['id'], 'payment.premium_revoked', 'Conta #' . $userId);
            $success = 'Acesso Premium encerrado.';
        } elseif ($action === 'save_socials' && $isSuper) {
            $platforms = ['facebook', 'instagram', 'discord', 'whatsapp'];
            $update = $pdo->prepare('UPDATE social_links SET url = ?, is_enabled = ?, placement = ? WHERE platform = ?');
            foreach ($platforms as $platform) {
                $url = trim((string) ($_POST['social_url'][$platform] ?? ''));
                $enabled = isset($_POST['social_enabled'][$platform]) ? 1 : 0;
                $placement = (string) ($_POST['social_placement'][$platform] ?? 'footer');
                if (!in_array($placement, ['header', 'footer', 'both'], true)) {
                    throw new RuntimeException('Local de exibição de rede social inválido.');
                }
                if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || mb_strlen($url) > 2048)) {
                    throw new RuntimeException('Informe URLs válidas para as redes sociais.');
                }
                if ($enabled && $url === '') {
                    throw new RuntimeException('Informe o endereço antes de ativar cada rede social.');
                }
                $update->execute([$url ?: null, $enabled, $placement, $platform]);
            }
            audit($pdo, (int) $admin['id'], 'settings.socials_updated');
            $success = 'Redes sociais atualizadas.';
        } elseif ($action === 'save_payment_settings' && $isSuper) {
            $whatsappUrl = trim((string) ($_POST['payment_whatsapp_url'] ?? ''));
            if ($whatsappUrl !== '') {
                $parts = parse_url($whatsappUrl);
                $host = strtolower((string) ($parts['host'] ?? ''));
                $phone = '';
                if (($parts['scheme'] ?? '') === 'https' && $host === 'wa.me') {
                    $waPath = trim((string) ($parts['path'] ?? ''), '/');
                    if (preg_match('/^\+?[0-9]{8,15}$/', $waPath)) {
                        $phone = preg_replace('/\D+/', '', $waPath) ?? '';
                    }
                } elseif (($parts['scheme'] ?? '') === 'https' && $host === 'api.whatsapp.com' && ($parts['path'] ?? '') === '/send') {
                    parse_str((string) ($parts['query'] ?? ''), $query);
                    $phone = preg_replace('/\D+/', '', (string) ($query['phone'] ?? '')) ?? '';
                }
                if (strlen($phone) < 8 || strlen($phone) > 15 || isset($parts['user']) || isset($parts['pass'])) {
                    throw new RuntimeException('Informe um link HTTPS válido do WhatsApp, como https://wa.me/351900000000.');
                }
            }
            $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                ->execute(['payment_whatsapp_url', $whatsappUrl ?: null]);
            audit($pdo, (int) $admin['id'], 'settings.payment_whatsapp_updated');
            $success = 'Link de contacto para conteúdos pagos guardado.';
        } elseif ($action === 'send_notification' && $isSuper) {
            $notificationTitle = trim((string) ($_POST['notification_title'] ?? ''));
            $notificationMessage = trim((string) ($_POST['notification_message'] ?? ''));
            if ($notificationTitle === '' || mb_strlen($notificationTitle) > 180 || $notificationMessage === '' || mb_strlen($notificationMessage) > 2000) {
                throw new RuntimeException('Preencha o título e a mensagem (máximo 2000 caracteres).');
            }
            $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message) SELECT id, ?, ? FROM users WHERE role = ? AND is_active = 1');
            $stmt->execute([$notificationTitle, $notificationMessage, 'viewer']);
            audit($pdo, (int) $admin['id'], 'notification.broadcast', 'Destinatários: ' . $stmt->rowCount());
            $success = 'Notificação enviada a ' . $stmt->rowCount() . ' utilizadores.';
        } else {
            http_response_code(403);
            exit('Ação não autorizada.');
        }
    } catch (RuntimeException $exception) {
        foreach ($newVideoUploads as $videoPath) {
            $deleteVideoUpload($videoPath);
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        foreach ($newVideoUploads as $videoPath) {
            $deleteVideoUpload($videoPath);
        }
        error_log('BALDÉ FLIX admin action failed: ' . $exception->getMessage());
        $error = $action === 'create_user' && $exception->getCode() === '23000'
            ? 'Já existe uma conta com este e-mail.'
            : 'Não foi possível concluir a operação. Confirme se os dados enviados são válidos.';
    }
}

$titleCount = (int) $pdo->query('SELECT COUNT(*) FROM titles')->fetchColumn();
$movieCount = (int) $pdo->query("SELECT COUNT(*) FROM titles WHERE kind = 'movie'")->fetchColumn();
$seriesCount = (int) $pdo->query("SELECT COUNT(*) FROM titles WHERE kind = 'series'")->fetchColumn();
$publishedCount = (int) $pdo->query('SELECT COUNT(*) FROM titles WHERE published = 1')->fetchColumn();
$requestCount = (int) $pdo->query("SELECT COUNT(*) FROM content_requests WHERE status IN ('pending', 'reviewing', 'approved')")->fetchColumn();
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$titles = $pdo->query('SELECT t.id, t.name, t.kind, t.synopsis, t.category_id, t.release_year, t.is_paid, t.video_url, t.published, t.featured, c.name AS category_name FROM titles t LEFT JOIN categories c ON c.id = t.category_id ORDER BY t.created_at DESC LIMIT 100')->fetchAll();
$editId = $admin['role'] === 'moderator' ? null : filter_var($_GET['editar'] ?? null, FILTER_VALIDATE_INT);
$editTitle = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT id, name, kind, synopsis, category_id, release_year, is_paid, video_url, ' . implode(', ', array_values($qualityColumns)) . ', published, featured FROM titles WHERE id = ?');
    $stmt->execute([$editId]);
    $editTitle = $stmt->fetch() ?: null;
}
$requests = $pdo->query('SELECT id, requester_name, requester_email, request_type, title, release_year, genre, details, status, admin_response, created_at FROM content_requests ORDER BY FIELD(status, "pending", "reviewing", "approved", "available", "rejected"), created_at DESC LIMIT 100')->fetchAll();
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$seriesTitles = $pdo->query("SELECT id, name FROM titles WHERE kind = 'series' ORDER BY name")->fetchAll();
$seasons = $pdo->query('SELECT s.id, s.title_id, s.season_number, s.name, t.name AS series_name, COUNT(e.id) AS episode_count FROM seasons s JOIN titles t ON t.id = s.title_id LEFT JOIN episodes e ON e.season_id = s.id GROUP BY s.id ORDER BY t.name, s.season_number')->fetchAll();
$episodesList = $pdo->query('SELECT e.id, e.episode_number, e.name, s.season_number, t.name AS series_name FROM episodes e JOIN seasons s ON s.id = e.season_id JOIN titles t ON t.id = s.title_id ORDER BY t.name, s.season_number, e.episode_number LIMIT 200')->fetchAll();
$editEpisodeId = $admin['role'] === 'moderator' ? null : filter_var($_GET['editar_episodio'] ?? null, FILTER_VALIDATE_INT);
$editEpisode = null;
if ($editEpisodeId) {
    $stmt = $pdo->prepare('SELECT e.id, e.name, e.episode_number, t.name AS series_name, s.season_number, e.video_url, ' . implode(', ', array_map(static fn(string $column): string => 'e.' . $column, array_values($qualityColumns))) . ' FROM episodes e JOIN seasons s ON s.id = e.season_id JOIN titles t ON t.id = s.title_id WHERE e.id = ?');
    $stmt->execute([$editEpisodeId]);
    $editEpisode = $stmt->fetch() ?: null;
}
$users = $isSuper ? $pdo->query("SELECT u.id, u.display_name, u.username, u.email, u.bio, u.role, u.is_active, u.created_at, (SELECT COUNT(*) FROM favorites f WHERE f.user_id = u.id) AS favorite_count, (SELECT COUNT(*) FROM watch_history h WHERE h.user_id = u.id) AS history_count, (SELECT p.expires_at FROM premium_subscriptions p WHERE p.user_id = u.id AND p.started_at <= CURRENT_TIMESTAMP AND p.expires_at > CURRENT_TIMESTAMP LIMIT 1) AS premium_expires_at FROM users u WHERE u.role <> 'super_admin' ORDER BY u.created_at DESC")->fetchAll() : [];
$customerUsers = $isSuper ? $pdo->query("SELECT id, display_name, email FROM users WHERE role = 'viewer' AND is_active = 1 ORDER BY display_name")->fetchAll() : [];
$paidTitles = $isSuper ? $pdo->query("SELECT id, name, kind FROM titles WHERE is_paid = 1 AND published = 1 ORDER BY name")->fetchAll() : [];
$titleAccessGrants = $isSuper ? $pdo->query("SELECT a.user_id, a.title_id, a.granted_at, u.display_name, u.email, t.name AS title_name FROM user_title_access a JOIN users u ON u.id = a.user_id JOIN titles t ON t.id = a.title_id ORDER BY a.granted_at DESC")->fetchAll() : [];
$premiumRecords = $isSuper ? $pdo->query("SELECT p.user_id, p.started_at, p.expires_at, u.display_name, u.email FROM premium_subscriptions p JOIN users u ON u.id = p.user_id WHERE u.role = 'viewer' ORDER BY p.expires_at DESC")->fetchAll() : [];
$socialLinks = $isSuper ? $pdo->query('SELECT platform, url, is_enabled, placement FROM social_links ORDER BY FIELD(platform, "facebook", "instagram", "discord", "whatsapp")')->fetchAll() : [];
$paymentWhatsappUrl = $isSuper ? $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'payment_whatsapp_url'")->fetchColumn() : null;
$comments = $isSuper ? $pdo->query('SELECT c.id, c.body, c.is_approved, c.created_at, t.name AS title_name, u.display_name, u.email FROM comments c JOIN titles t ON t.id = c.title_id LEFT JOIN users u ON u.id = c.user_id ORDER BY c.is_approved ASC, c.created_at DESC LIMIT 100')->fetchAll() : [];
render_header('Painel de controlo');
?>
<section class="admin-heading">
  <div><span class="eyebrow">Área segura · <?= e(strtoupper(str_replace('_', ' ', $admin['role']))) ?></span><h1>Painel de controlo</h1><p>Olá, <?= e($admin['display_name']) ?>. Aqui está a atividade da plataforma.</p></div>
  <a class="button button-outline" href="index.php">Ver site ↗</a>
</section>
<?php if ($error): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="notice notice-success"><?= e($success) ?></div><?php endif; ?>
<section class="stats-grid">
  <article class="stat-card"><span>Filmes</span><strong><?= $movieCount ?></strong><small>No catálogo</small></article>
  <article class="stat-card"><span>Séries</span><strong><?= $seriesCount ?></strong><small>No catálogo</small></article>
  <article class="stat-card"><span>Publicados</span><strong><?= $publishedCount ?></strong><small>De <?= $titleCount ?> conteúdos</small></article>
  <article class="stat-card"><span>Pedidos ativos</span><strong><?= $requestCount ?></strong><small>Pendentes ou em análise</small></article>
  <article class="stat-card"><span>Utilizadores</span><strong><?= $userCount ?></strong><small>Contas registadas</small></article>
</section>
<nav class="admin-tabs"><?php if ($admin['role'] !== 'moderator'): ?><a href="#conteudos">Filmes e séries</a><a href="#series">Temporadas e episódios</a><?php endif; ?><a href="#pedidos">Pedidos <span><?= $requestCount ?></span></a><?php if ($isSuper): ?><a href="#utilizadores">Utilizadores e administradores</a><a href="#pagamentos">Pagamentos</a><a href="#redes">Redes sociais</a><a href="#notificacoes">Notificações</a><a href="#comentarios">Comentários</a><a href="#auditoria">Atividade</a><?php endif; ?><a href="#seguranca">Segurança</a></nav>

<?php if ($admin['role'] !== 'moderator'): ?>
<section class="admin-section" id="conteudos">
  <div class="section-heading"><div><span class="eyebrow">Catálogo</span><h2>Gerir filmes e séries</h2><p>Adicione conteúdos, edite detalhes ou remova títulos.</p></div></div>
  <?php if ($editTitle): ?>
    <form method="post" enctype="multipart/form-data" class="panel-card admin-form">
      <h3>Editar: <?= e($editTitle['name']) ?></h3>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_title"><input type="hidden" name="title_id" value="<?= (int) $editTitle['id'] ?>">
      <div class="form-grid">
        <label>Título<input name="name" maxlength="180" required value="<?= e($editTitle['name']) ?>"></label>
        <label>Tipo<select name="kind"><option value="movie" <?= $editTitle['kind'] === 'movie' ? 'selected' : '' ?>>Filme</option><option value="series" <?= $editTitle['kind'] === 'series' ? 'selected' : '' ?>>Série</option></select></label>
        <label>Categoria<select name="category_id"><option value="">Sem categoria</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $editTitle['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
        <label>Ano<input type="number" name="release_year" min="1888" max="<?= (int) date('Y') + 10 ?>" value="<?= e((string) ($editTitle['release_year'] ?? '')) ?>"></label>
      </div>
      <label>Sinopse<textarea name="synopsis" rows="3" maxlength="5000" required><?= e($editTitle['synopsis']) ?></textarea></label>
      <label class="check-label"><input type="checkbox" name="is_paid" <?= $editTitle['is_paid'] ? 'checked' : '' ?>> Conteúdo pago (o utilizador será encaminhado para o WhatsApp)</label>
      <label>Vídeo por URL HTTPS (opcional)<input type="url" name="video_url" maxlength="2048" value="<?= is_uploaded_video_path((string) ($editTitle['video_url'] ?? '')) ? '' : e($editTitle['video_url'] ?? '') ?>"></label>
      <label>Substituir ou enviar vídeo (formatos de vídeo reconhecidos · máx. 10 GB)<input type="file" name="video_file"></label>
      <fieldset class="video-quality-uploads"><legend>Versões de qualidade</legend><p class="form-note">Envie versões MP4 já convertidas. As versões atuais mantêm-se quando não escolher um ficheiro novo.</p><div class="form-grid">
        <?php foreach (['360p', '480p', '720p', '1080p'] as $quality): ?><label><?= $quality ?><?php if (!empty($editTitle['video_' . $quality . '_url'])): ?> · já carregada<?php endif; ?><input type="file" name="video_<?= $quality ?>_file"></label><?php endforeach; ?>
      </div></fieldset>
      <div class="form-grid form-checks"><label class="check-label"><input type="checkbox" name="published" <?= $editTitle['published'] ? 'checked' : '' ?>> Publicado</label><label class="check-label"><input type="checkbox" name="featured" <?= $editTitle['featured'] ? 'checked' : '' ?>> Destaque</label></div>
      <div class="inline-form"><button class="button button-primary" type="submit">Guardar alterações</button><a class="button button-outline" href="admin.php#conteudos">Cancelar</a></div>
      <p class="form-note">O cartaz e o vídeo carregado atuais são mantidos. Para vídeo local, escolha um ficheiro novo para substituir; para uma fonte externa, pode atualizar a URL HTTPS.</p>
    </form>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="panel-card admin-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_title">
    <div class="form-grid">
      <label>Título<input name="name" maxlength="180" required></label>
      <label>Tipo<select name="kind"><option value="movie">Filme</option><option value="series">Série</option></select></label>
      <label>Categoria<select name="category_id"><option value="">Sem categoria</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
      <label>Ano<input type="number" name="release_year" min="1888" max="<?= (int) date('Y') + 10 ?>"></label>
    </div>
    <label>Sinopse<textarea name="synopsis" rows="3" maxlength="5000" required></textarea></label>
    <label class="check-label"><input type="checkbox" name="is_paid"> Conteúdo pago (o utilizador será encaminhado para o WhatsApp)</label>
    <div class="form-grid">
      <label>Cartaz (JPG, PNG ou WEBP · máx. 5 MB)<input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></label>
      <label>Vídeo por URL HTTPS (opcional)<input type="url" name="video_url" maxlength="2048" placeholder="https://..."></label>
      <label>Ou carregar vídeo da galeria (formatos de vídeo reconhecidos · máx. 10 GB)<input type="file" name="video_file"></label>
    </div>
    <fieldset class="video-quality-uploads"><legend>Versões de qualidade (opcional)</legend><p class="form-note">Carregue versões MP4 já convertidas; quem assistir poderá escolher entre as opções enviadas.</p><div class="form-grid">
      <?php foreach (['360p', '480p', '720p', '1080p'] as $quality): ?><label><?= $quality ?><input type="file" name="video_<?= $quality ?>_file"></label><?php endforeach; ?>
    </div></fieldset>
    <div class="form-grid form-checks"><label class="check-label"><input type="checkbox" name="published" checked> Publicar no catálogo</label><label class="check-label"><input type="checkbox" name="featured"> Destacar na página inicial</label></div>
    <button class="button button-primary" type="submit">Guardar conteúdo</button>
    <p class="form-note">Use apenas conteúdos próprios ou autorizados para distribuição. O servidor precisa de espaço livre suficiente para guardar os vídeos enviados.</p>
  </form>
  <div class="title-filters">
    <button class="chip active" type="button" data-title-filter="all">Todos (<?= $titleCount ?>)</button>
    <button class="chip" type="button" data-title-filter="movie">Filmes (<?= $movieCount ?>)</button>
    <button class="chip" type="button" data-title-filter="series">Séries (<?= $seriesCount ?>)</button>
  </div>
  <div class="table-wrap">
    <table><thead><tr><th>Título</th><th>Tipo</th><th>Categoria</th><th>Estado</th><th>Ação</th></tr></thead><tbody>
    <?php foreach ($titles as $item): ?><tr class="title-row" data-title-kind="<?= e($item['kind']) ?>"><td><?= e($item['name']) ?></td><td><?= $item['kind'] === 'series' ? 'Série' : 'Filme' ?></td><td><?= e($item['category_name'] ?? '—') ?></td><td><?= $item['is_paid'] ? 'Pago' : 'Gratuito' ?> · <?= $item['published'] ? 'Publicado' : 'Rascunho' ?><?= $item['featured'] ? ' · Destaque' : '' ?></td><td><a href="admin.php?editar=<?= (int) $item['id'] ?>#conteudos">Editar</a> · <form method="post" onsubmit="return confirm('Eliminar este título?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_title"><input type="hidden" name="title_id" value="<?= (int) $item['id'] ?>"><button class="text-danger" type="submit">Eliminar</button></form></td></tr><?php endforeach; ?>
    <?php if (!$titles): ?><tr><td colspan="5" class="table-empty">Ainda não há conteúdos.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</section>
<?php endif; ?>

<?php if ($admin['role'] !== 'moderator'): ?>
<section class="admin-section" id="series">
  <div class="section-heading"><div><span class="eyebrow">Episódios</span><h2>Temporadas e episódios</h2><p>Organize temporadas e adicione episódios às séries do catálogo.</p></div></div>
  <?php if (!$seriesTitles): ?><div class="empty-state compact">Adicione primeiro um título do tipo Série no catálogo.</div><?php else: ?>
  <div class="profile-grid">
    <form method="post" class="panel-card admin-form"><h3>Adicionar temporada</h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_season">
      <label>Série<select name="series_id" required><?php foreach ($seriesTitles as $series): ?><option value="<?= (int) $series['id'] ?>"><?= e($series['name']) ?></option><?php endforeach; ?></select></label>
      <div class="form-grid"><label>Número da temporada<input type="number" name="season_number" min="1" max="99" required></label><label>Nome opcional<input name="season_name" maxlength="160"></label></div>
      <button class="button button-primary">Criar temporada</button>
    </form>
    <?php if ($editEpisode): ?><form method="post" enctype="multipart/form-data" class="panel-card admin-form"><h3>Adicionar qualidades: <?= e($editEpisode['series_name'] . ' · T' . $editEpisode['season_number'] . ' · E' . $editEpisode['episode_number'] . ' · ' . $editEpisode['name']) ?></h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_episode_qualities"><input type="hidden" name="episode_id" value="<?= (int) $editEpisode['id'] ?>">
      <fieldset class="video-quality-uploads"><legend>Versões de qualidade</legend><p class="form-note">As versões existentes mantêm-se quando não escolher um ficheiro novo.</p><div class="form-grid">
        <?php foreach (['360p', '480p', '720p', '1080p'] as $quality): ?><label><?= $quality ?><?php if (!empty($editEpisode['video_' . $quality . '_url'])): ?> · já carregada<?php endif; ?><input type="file" name="episode_video_<?= $quality ?>_file"></label><?php endforeach; ?>
      </div></fieldset><div class="inline-form"><button class="button button-primary">Guardar qualidades</button><a class="button button-outline" href="admin.php#series">Cancelar</a></div>
    </form><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="panel-card admin-form"><h3>Adicionar episódio</h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_episode">
      <label>Temporada<select name="season_id" required><?php foreach ($seasons as $season): ?><option value="<?= (int) $season['id'] ?>"><?= e($season['series_name'] . ' · Temporada ' . $season['season_number']) ?></option><?php endforeach; ?></select></label>
      <div class="form-grid"><label>Número do episódio<input type="number" name="episode_number" min="1" max="999" required></label><label>Nome<input name="episode_name" maxlength="180" required></label></div>
      <label>Sinopse<textarea name="episode_synopsis" maxlength="5000" rows="2"></textarea></label>
      <label>Vídeo por URL HTTPS (opcional)<input type="url" name="episode_video_url" maxlength="2048"></label>
      <label>Ou carregar vídeo (formatos de vídeo reconhecidos · máx. 10 GB)<input type="file" name="episode_video_file"></label>
      <fieldset class="video-quality-uploads"><legend>Versões de qualidade (opcional)</legend><div class="form-grid">
        <?php foreach (['360p', '480p', '720p', '1080p'] as $quality): ?><label><?= $quality ?><input type="file" name="episode_video_<?= $quality ?>_file"></label><?php endforeach; ?>
      </div></fieldset>
      <button class="button button-primary">Adicionar episódio</button>
    </form>
  </div>
  <div class="table-wrap"><table><thead><tr><th>Série</th><th>Temporada</th><th>Episódios</th><th>Controlo</th></tr></thead><tbody>
    <?php foreach ($seasons as $season): ?><tr><td><?= e($season['series_name']) ?></td><td><?= (int) $season['season_number'] ?><?= $season['name'] ? ' · ' . e($season['name']) : '' ?></td><td><?= (int) $season['episode_count'] ?></td><td><form method="post" class="inline-form" onsubmit="return confirm('Eliminar temporada e episódios?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_season"><input type="hidden" name="season_id" value="<?= (int) $season['id'] ?>"><button class="text-danger">Eliminar temporada</button></form></td></tr><?php endforeach; ?>
    <?php if (!$seasons): ?><tr><td colspan="4" class="table-empty">Ainda não há temporadas.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="table-wrap"><table><thead><tr><th>Série</th><th>Temporada</th><th>Episódio</th><th>Controlo</th></tr></thead><tbody>
    <?php foreach ($episodesList as $episode): ?><tr><td><?= e($episode['series_name']) ?></td><td><?= (int) $episode['season_number'] ?></td><td><?= (int) $episode['episode_number'] ?> · <?= e($episode['name']) ?></td><td><a href="admin.php?editar_episodio=<?= (int) $episode['id'] ?>#series">Qualidades</a> · <form method="post" class="inline-form" onsubmit="return confirm('Eliminar episódio?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_episode"><input type="hidden" name="episode_id" value="<?= (int) $episode['id'] ?>"><button class="text-danger">Eliminar episódio</button></form></td></tr><?php endforeach; ?>
    <?php if (!$episodesList): ?><tr><td colspan="4" class="table-empty">Ainda não há episódios.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="admin-section" id="pedidos">
  <div class="section-heading"><div><span class="eyebrow">Comunidade</span><h2>Pedidos de conteúdo</h2></div></div>
  <?php if ($requests): ?>
    <div class="request-filters">
      <label class="sr-only" for="request-search">Pesquisar pedidos</label><input id="request-search" type="search" placeholder="Pesquisar título ou solicitante">
      <label class="sr-only" for="request-type-filter">Filtrar por tipo</label><select id="request-type-filter"><option value="">Todos os tipos</option><option value="movie">Filmes</option><option value="series">Séries</option></select>
      <label class="sr-only" for="request-status-filter">Filtrar por estado</label><select id="request-status-filter"><option value="">Todos os estados</option><?php foreach ($statuses as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    </div>
    <div class="request-admin-list">
    <?php foreach ($requests as $request): ?>
      <article class="request-admin-card" data-type="<?= e($request['request_type']) ?>" data-status="<?= e($request['status']) ?>" data-search="<?= e(mb_strtolower($request['title'] . ' ' . ($request['requester_name'] ?? '') . ' ' . ($request['requester_email'] ?? ''))) ?>">
        <div class="request-admin-main"><div class="request-admin-title"><span class="request-type"><?= $request['request_type'] === 'series' ? 'SÉRIE' : 'FILME' ?></span><span class="status status-<?= e($request['status']) ?>"><?= e($statuses[$request['status']] ?? $request['status']) ?></span></div><h3><?= e($request['title']) ?><?= $request['release_year'] ? ' (' . (int) $request['release_year'] . ')' : '' ?></h3><p><?= e($request['details'] ?: 'Sem informação adicional.') ?></p><small>Pedido por <?= e($request['requester_name'] ?: 'Visitante') ?><?= $request['requester_email'] ? ' · ' . e($request['requester_email']) : '' ?> · <?= e(date('d/m/Y H:i', strtotime($request['created_at']))) ?></small></div>
        <form method="post" class="request-update-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_request"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><label>Estado<select name="status"><?php foreach ($statuses as $key => $label): if ($key === 'pending') continue; ?><option value="<?= e($key) ?>" <?= $request['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label>Resposta<textarea name="admin_response" rows="2" maxlength="2000" placeholder="Resposta para o visitante"><?= e($request['admin_response']) ?></textarea></label><button class="button button-outline button-small" type="submit">Atualizar pedido</button></form>
      </article>
    <?php endforeach; ?>
    </div>
    <div id="no-request-results" class="empty-state compact" hidden>Não foram encontrados pedidos com estes filtros.</div>
  <?php else: ?><div class="empty-state compact">Ainda não foram recebidos pedidos.</div><?php endif; ?>
</section>

<?php if ($isSuper): ?>
<section class="admin-section" id="utilizadores">
  <div class="section-heading"><div><span class="eyebrow">Contas</span><h2>Utilizadores e administradores</h2><p>Consulte os dados de perfil, bloqueie ou elimine contas. Senhas não são visíveis: são guardadas de forma protegida e não podem ser recuperadas.</p></div></div>
  <form method="post" class="panel-card admin-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_user">
    <div class="form-grid"><label>Nome<input name="display_name" maxlength="120" required></label><label>E-mail<input type="email" name="email" maxlength="190" required></label><label>Palavra-passe inicial (mín. 8)<input type="password" name="password" minlength="8" maxlength="4096" required autocomplete="new-password"></label><label>Função<select name="role"><option value="admin">Administrador</option><option value="moderator">Moderador</option></select></label></div>
    <button class="button button-primary" type="submit">Criar conta administrativa</button>
    <p class="form-note">A conta SUPER ADMIN principal não pode ser alterada nem removida por esta interface.</p>
  </form>
  <div class="table-wrap"><table><thead><tr><th>Nome e perfil</th><th>E-mail</th><th>Função</th><th>Estado</th><th>Registo</th><th>Premium</th><th>Controlo</th></tr></thead><tbody>
    <?php foreach ($users as $account): ?><tr><td><?= e($account['display_name']) ?><small class="table-subtitle">@<?= e($account['username'] ?? '') ?></small><details class="user-profile-details"><summary>Ver dados do perfil</summary><div><strong>Biografia:</strong> <?= e($account['bio'] ?: 'Não informada') ?><br><strong>Favoritos:</strong> <?= (int) $account['favorite_count'] ?><br><strong>Itens no histórico:</strong> <?= (int) $account['history_count'] ?></div></details></td><td><?= e($account['email']) ?></td><td><?= e($roles[$account['role']] ?? $account['role']) ?></td><td><?= $account['is_active'] ? 'Ativa' : 'Bloqueada' ?></td><td><?= e(date('d/m/Y H:i', strtotime($account['created_at']))) ?></td><td><?= $account['premium_expires_at'] ? 'Ativo até ' . e(date('d/m/Y H:i', strtotime($account['premium_expires_at']))) : 'Sem Premium ativo' ?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>"><button class="text-danger" type="submit"><?= $account['is_active'] ? 'Bloquear' : 'Desbloquear' ?></button></form> <form method="post" class="inline-form" onsubmit="return confirm('Eliminar permanentemente esta conta?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>"><button class="text-danger" type="submit">Eliminar</button></form></td></tr><?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="7" class="table-empty">Ainda não há outras contas para gerir.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="section-heading"><div><span class="eyebrow">Acesso após pagamento confirmado</span><h3>Desbloquear título ou ativar Premium</h3><p>O acesso individual não expira; o Premium abre todos os filmes e séries pagos durante 30 dias desde a ativação. Renová-lo reinicia o prazo.</p></div></div>
  <div class="profile-grid">
    <form method="post" class="panel-card admin-form">
      <h3>Desbloquear um filme ou série</h3>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="grant_title_access">
      <label>Utilizador<select name="user_id" required><option value="">Escolher utilizador</option><?php foreach ($customerUsers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= e($customer['display_name']) ?> · <?= e($customer['email']) ?></option><?php endforeach; ?></select></label>
      <label>Título pago<select name="title_id" required><option value="">Escolher título</option><?php foreach ($paidTitles as $paidTitle): ?><option value="<?= (int) $paidTitle['id'] ?>"><?= e($paidTitle['name']) ?> · <?= $paidTitle['kind'] === 'series' ? 'Série' : 'Filme' ?></option><?php endforeach; ?></select></label>
      <button class="button button-primary" type="submit" <?= !$customerUsers || !$paidTitles ? 'disabled' : '' ?>>Confirmar pagamento e desbloquear</button>
    </form>
    <form method="post" class="panel-card admin-form">
      <h3>Ativar pacote Premium — 30 dias</h3>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="activate_premium">
      <label>Utilizador<select name="user_id" required><option value="">Escolher utilizador</option><?php foreach ($customerUsers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= e($customer['display_name']) ?> · <?= e($customer['email']) ?></option><?php endforeach; ?></select></label>
      <p class="form-note">Confirme o pagamento antes de ativar. A contagem começa imediatamente e a renovação reinicia os 30 dias.</p>
      <button class="button button-primary" type="submit" <?= !$customerUsers ? 'disabled' : '' ?>>Confirmar pagamento e ativar Premium</button>
    </form>
  </div>
  <div class="profile-grid">
    <section class="panel-card"><h3>Acessos individuais</h3><?php if ($titleAccessGrants): ?><div class="table-wrap"><table><thead><tr><th>Utilizador</th><th>Título</th><th>Desde</th><th>Ação</th></tr></thead><tbody><?php foreach ($titleAccessGrants as $grant): ?><tr><td><?= e($grant['display_name']) ?><small class="table-subtitle"><?= e($grant['email']) ?></small></td><td><?= e($grant['title_name']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($grant['granted_at']))) ?></td><td><form method="post" onsubmit="return confirm('Remover o acesso deste utilizador?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_title_access"><input type="hidden" name="user_id" value="<?= (int) $grant['user_id'] ?>"><input type="hidden" name="title_id" value="<?= (int) $grant['title_id'] ?>"><button class="text-danger" type="submit">Bloquear</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="form-note">Ainda não há títulos desbloqueados individualmente.</p><?php endif; ?></section>
    <section class="panel-card"><h3>Subscrições Premium</h3><?php if ($premiumRecords): ?><div class="table-wrap"><table><thead><tr><th>Utilizador</th><th>Período</th><th>Estado</th><th>Ação</th></tr></thead><tbody><?php foreach ($premiumRecords as $premium): $premiumActive = strtotime($premium['expires_at']) > time() && strtotime($premium['started_at']) <= time(); ?><tr><td><?= e($premium['display_name']) ?><small class="table-subtitle"><?= e($premium['email']) ?></small></td><td><?= e(date('d/m/Y', strtotime($premium['started_at']))) ?> – <?= e(date('d/m/Y H:i', strtotime($premium['expires_at']))) ?></td><td><?= $premiumActive ? 'Ativo' : 'Expirado' ?></td><td><?php if ($premiumActive): ?><form method="post" onsubmit="return confirm('Encerrar o Premium desta conta agora?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_premium"><input type="hidden" name="user_id" value="<?= (int) $premium['user_id'] ?>"><button class="text-danger" type="submit">Encerrar</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="form-note">Ainda não há subscrições Premium.</p><?php endif; ?></section>
  </div>
</section>
<section class="admin-section" id="auditoria"><div class="section-heading"><div><span class="eyebrow">Segurança</span><h2>Atividade recente</h2><p>Registos recentes de ações administrativas.</p></div><form method="post" onsubmit="return confirm('Eliminar todos os registos de atividade? Esta ação não pode ser desfeita.')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear_audit_logs"><button class="button button-outline" type="submit">Eliminar todas as atividades</button></form></div>
  <div class="table-wrap"><table><thead><tr><th>Data</th><th>Utilizador</th><th>Ação</th><th>Detalhes</th><th>IP</th></tr></thead><tbody>
  <?php $logs = $pdo->query('SELECT a.created_at, u.email, a.action, a.details, a.ip_address FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 50')->fetchAll(); foreach ($logs as $log): ?><tr><td><?= e(date('d/m/Y H:i', strtotime($log['created_at']))) ?></td><td><?= e($log['email'] ?? 'Sistema') ?></td><td><?= e($log['action']) ?></td><td><?= e($log['details'] ?? '—') ?></td><td><?= e($log['ip_address'] ?? '—') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<section class="admin-section" id="redes">
  <div class="section-heading"><div><span class="eyebrow">Presença digital</span><h2>Redes sociais</h2><p>Ative os links que serão mostrados na plataforma.</p></div></div>
  <form method="post" class="panel-card admin-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_socials">
    <?php foreach ($socialLinks as $social): ?>
      <div class="social-edit-row">
        <label><?= e(ucfirst($social['platform'])) ?> URL<input type="url" name="social_url[<?= e($social['platform']) ?>]" maxlength="2048" placeholder="https://" value="<?= e($social['url'] ?? '') ?>"></label>
        <label>Exibição<select name="social_placement[<?= e($social['platform']) ?>]"><option value="footer" <?= $social['placement'] === 'footer' ? 'selected' : '' ?>>Rodapé</option><option value="header" <?= $social['placement'] === 'header' ? 'selected' : '' ?>>Topo</option><option value="both" <?= $social['placement'] === 'both' ? 'selected' : '' ?>>Topo e rodapé</option></select></label>
        <label class="check-label"><input type="checkbox" name="social_enabled[<?= e($social['platform']) ?>]" <?= $social['is_enabled'] ? 'checked' : '' ?>> Ativa</label>
      </div>
    <?php endforeach; ?>
    <button class="button button-primary" type="submit">Guardar redes sociais</button>
  </form>
</section>
<section class="admin-section" id="pagamentos">
  <div class="section-heading"><div><span class="eyebrow">Conteúdo pago</span><h2>Contacto WhatsApp</h2><p>Os títulos marcados como pagos mostram um botão para falar contigo. A plataforma não processa pagamentos.</p></div></div>
  <form method="post" class="panel-card admin-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_payment_settings">
    <label>Link WhatsApp com indicativo do país<input type="url" name="payment_whatsapp_url" maxlength="2048" placeholder="https://wa.me/351900000000" value="<?= e(is_string($paymentWhatsappUrl) ? $paymentWhatsappUrl : '') ?>"></label>
    <button class="button button-primary" type="submit">Guardar link de pagamento</button>
    <p class="form-note">Exemplo: https://wa.me/351900000000. Ao abrir, o utilizador poderá combinar contigo os detalhes. Não coloques dados de pagamento sensíveis neste link.</p>
  </form>
</section>
<section class="admin-section" id="notificacoes">
  <div class="section-heading"><div><span class="eyebrow">Comunicação</span><h2>Enviar notificação</h2><p>A mensagem ficará visível no perfil de todos os utilizadores ativos.</p></div></div>
  <form method="post" class="panel-card admin-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_notification">
    <label>Título<input name="notification_title" maxlength="180" required></label>
    <label>Mensagem<textarea name="notification_message" maxlength="2000" rows="4" required></textarea></label>
    <button class="button button-primary" type="submit">Enviar notificação</button>
  </form>
</section>
<section class="admin-section" id="comentarios">
  <div class="section-heading"><div><span class="eyebrow">Comunidade</span><h2>Moderação de comentários</h2></div></div>
  <?php if ($comments): ?><div class="notification-list"><?php foreach ($comments as $comment): ?><article class="notification-item"><b><?= e($comment['title_name']) ?> · <?= e($comment['display_name'] ?? 'Conta removida') ?></b><p><?= nl2br(e($comment['body'])) ?></p><time><?= e(date('d/m/Y H:i', strtotime($comment['created_at']))) ?> · <?= $comment['is_approved'] ? 'Aprovado' : 'Pendente' ?></time><div class="inline-form"><?php if (!$comment['is_approved']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="moderate_comment"><input type="hidden" name="decision" value="approve"><input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>"><button class="button button-outline button-small">Aprovar</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Remover comentário?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="moderate_comment"><input type="hidden" name="decision" value="delete"><input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>"><button class="text-danger">Remover</button></form></div></article><?php endforeach; ?></div><?php else: ?><div class="empty-state compact">Ainda não existem comentários.</div><?php endif; ?>
</section>
<?php endif; ?>
<section class="admin-section" id="seguranca">
  <div class="section-heading"><div><span class="eyebrow">Proteção da conta</span><h2>Alterar palavra-passe</h2></div></div>
  <form method="post" class="panel-card admin-form password-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_password">
    <label>Palavra-passe atual<input type="password" name="current_password" required autocomplete="current-password"></label>
    <div class="form-grid"><label>Nova palavra-passe (mínimo 8 caracteres)<input type="password" name="new_password" minlength="8" maxlength="4096" required autocomplete="new-password"></label><label>Confirmar nova palavra-passe<input type="password" name="confirm_password" minlength="8" maxlength="4096" required autocomplete="new-password"></label></div>
    <button class="button button-primary" type="submit">Alterar palavra-passe</button>
    <p class="form-note">Não reutilize palavras-passe partilhadas em mensagens ou noutros serviços.</p>
  </form>
</section>
<script>
(() => {
  const titleFilters = [...document.querySelectorAll('[data-title-filter]')];
  const titleRows = [...document.querySelectorAll('.title-row')];
  titleFilters.forEach((button) => button.addEventListener('click', () => {
    titleFilters.forEach((item) => item.classList.remove('active'));
    button.classList.add('active');
    titleRows.forEach((row) => {
      row.hidden = button.dataset.titleFilter !== 'all' && row.dataset.titleKind !== button.dataset.titleFilter;
    });
  }));
  const search = document.querySelector('#request-search');
  const type = document.querySelector('#request-type-filter');
  const status = document.querySelector('#request-status-filter');
  const cards = [...document.querySelectorAll('.request-admin-card')];
  const empty = document.querySelector('#no-request-results');
  const update = () => {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    cards.forEach((card) => {
      const matches = (card.dataset.search || '').includes(query)
        && (!type?.value || card.dataset.type === type.value)
        && (!status?.value || card.dataset.status === status.value);
      card.hidden = !matches;
      if (matches) visible++;
    });
    if (empty) empty.hidden = visible > 0;
  };
  search?.addEventListener('input', update);
  type?.addEventListener('change', update);
  status?.addEventListener('change', update);
})();
</script>
<?php render_footer(); ?>
