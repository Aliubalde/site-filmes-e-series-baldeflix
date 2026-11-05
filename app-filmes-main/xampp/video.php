<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit('Método não permitido.');
}

$titleId = filter_var($_GET['title_id'] ?? null, FILTER_VALIDATE_INT);
$episodeId = isset($_GET['episode_id']) ? filter_var($_GET['episode_id'], FILTER_VALIDATE_INT) : null;
$download = ($_GET['download'] ?? '') === '1';
$qualityColumns = ['360p' => 'video_360p_url', '480p' => 'video_480p_url', '720p' => 'video_720p_url', '1080p' => 'video_1080p_url'];
$quality = $_GET['quality'] ?? null;
if (!$titleId || ($episodeId !== null && (!$episodeId || $episodeId < 1)) || (isset($_GET['download']) && !$download) || (isset($_GET['quality']) && (!is_string($quality) || !isset($qualityColumns[$quality])))) {
    http_response_code(404);
    exit('Vídeo não encontrado.');
}
$videoColumn = $quality === null ? 'video_url' : $qualityColumns[$quality];

if ($episodeId !== null) {
    $stmt = $pdo->prepare("SELECT e.$videoColumn AS video_url, t.is_paid FROM episodes e JOIN seasons s ON s.id = e.season_id JOIN titles t ON t.id = s.title_id WHERE e.id = ? AND t.id = ? AND t.published = 1 LIMIT 1");
    $stmt->execute([$episodeId, $titleId]);
} else {
    $stmt = $pdo->prepare("SELECT $videoColumn AS video_url, is_paid FROM titles WHERE id = ? AND published = 1 LIMIT 1");
    $stmt->execute([$titleId]);
}
$video = $stmt->fetch();
if (!$video || ($video['is_paid'] && !has_paid_title_access($pdo, (int) $user['id'], (int) $titleId)) || !is_uploaded_video_path((string) $video['video_url'])) {
    http_response_code(404);
    exit('Vídeo não encontrado.');
}

$filePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $video['video_url']);
if (!is_file($filePath)) {
    http_response_code(404);
    exit('Ficheiro de vídeo não encontrado.');
}
$fileSize = filesize($filePath);
if ($fileSize === false || $fileSize === 0) {
    http_response_code(404);
    exit('Ficheiro de vídeo não encontrado.');
}

$videoTypes = uploaded_video_types();
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$isTransportStream = is_mpeg_transport_stream_file($filePath);
$knownVideoMime = is_string($detectedMime) && (
    str_starts_with($detectedMime, 'video/')
    || in_array($detectedMime, array_values($videoTypes), true)
    || in_array($detectedMime, ['application/mxf', 'application/x-matroska', 'application/vnd.rn-realmedia'], true)
);
if (!$knownVideoMime && !$isTransportStream) {
    http_response_code(415);
    exit('Formato de vídeo não suportado.');
}
$contentType = $isTransportStream
    ? 'video/mp2t'
    : (str_starts_with($detectedMime, 'video/')
        ? $detectedMime
        : ($videoTypes[$extension] ?? 'application/octet-stream'));
if ($detectedMime === 'application/x-matroska') {
    $contentType = 'video/x-matroska';
}
$start = 0;
$end = $fileSize - 1;
$status = 200;
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    if ($matches[1] === '') {
        $suffixLength = (int) $matches[2];
        if ($suffixLength < 1) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $end : min((int) $matches[2], $end);
    }
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    $status = 206;
}

$length = $end - $start + 1;
http_response_code($status);
header('Content-Type: ' . $contentType);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="balde-flix-video.' . $extension . '"');
if ($status === 206) {
    header("Content-Range: bytes $start-$end/$fileSize");
}
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Não foi possível abrir o vídeo.');
}
if (fseek($handle, $start) !== 0) {
    fclose($handle);
    http_response_code(500);
    exit('Não foi possível ler o vídeo.');
}
session_write_close();
set_time_limit(0);
while ($length > 0 && !feof($handle)) {
    $chunk = fread($handle, min(1024 * 1024, $length));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $length -= strlen($chunk);
    flush();
    if (connection_aborted()) {
        break;
    }
}
fclose($handle);
