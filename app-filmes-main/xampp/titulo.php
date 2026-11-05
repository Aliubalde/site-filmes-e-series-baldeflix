<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(404);
    exit('Conteúdo não encontrado.');
}
$stmt = $pdo->prepare("SELECT t.*, c.name AS category_name FROM titles t LEFT JOIN categories c ON c.id = t.category_id WHERE t.id = ? AND t.published = 1");
$stmt->execute([$id]);
$title = $stmt->fetch();
if (!$title) {
    http_response_code(404);
    exit('Conteúdo não encontrado.');
}
$hasPaidAccess = !$title['is_paid'] || has_paid_title_access($pdo, (int) $user['id'], (int) $title['id']);
$paymentWhatsappUrl = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'payment_whatsapp_url'")->fetchColumn();
$purchaseUrl = null;
$premiumPurchaseUrl = null;
if (is_string($paymentWhatsappUrl) && $paymentWhatsappUrl !== '') {
    $parts = parse_url($paymentWhatsappUrl);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $phone = '';
    if ($host === 'wa.me') {
        $phone = preg_replace('/\D+/', '', (string) ($parts['path'] ?? '')) ?? '';
    } elseif ($host === 'api.whatsapp.com' && ($parts['path'] ?? '') === '/send') {
        parse_str((string) ($parts['query'] ?? ''), $query);
        $phone = preg_replace('/\D+/', '', (string) ($query['phone'] ?? '')) ?? '';
    }
    if (($parts['scheme'] ?? '') === 'https' && strlen($phone) >= 8 && strlen($phone) <= 15) {
        $purchaseUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode('Olá! Tenho interesse em ' . $title['name'] . ' na BALDÉ FLIX.');
        $premiumPurchaseUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode('Olá! Tenho interesse no pacote Premium de 30 dias da BALDÉ FLIX.');
    }
}
$qualityColumns = ['360p' => 'video_360p_url', '480p' => 'video_480p_url', '720p' => 'video_720p_url', '1080p' => 'video_1080p_url'];
$playbackUrl = static function (?string $videoUrl, ?int $episodeId = null, ?string $quality = null) use ($id): ?string {
    if (!$videoUrl) {
        return null;
    }
    if (is_uploaded_video_path($videoUrl)) {
        $query = ['title_id' => $id];
        if ($episodeId !== null) {
            $query['episode_id'] = $episodeId;
        }
        if ($quality !== null) {
            $query['quality'] = $quality;
        }
        return 'video.php?' . http_build_query($query);
    }
    return $videoUrl;
};
$qualitySources = static function (?string $videoUrl, array $item, ?int $episodeId = null) use ($playbackUrl, $qualityColumns): array {
    $sources = [];
    if ($videoUrl) {
        $sources['Original'] = $playbackUrl($videoUrl, $episodeId);
    }
    foreach ($qualityColumns as $quality => $column) {
        if (is_uploaded_video_path((string) ($item[$column] ?? ''))) {
            $sources[$quality] = $playbackUrl($item[$column], $episodeId, $quality);
        }
    }
    return $sources;
};
$defaultQuality = static function (array $sources): string {
    if (isset($sources['Original'])) {
        return 'Original';
    }
    $qualities = array_reverse(array_keys($sources));
    return $qualities[0] ?? '';
};
$downloadUrl = static function (?string $videoUrl, ?int $episodeId = null, array $item = []) use ($id, $qualityColumns): ?string {
    $query = ['title_id' => $id, 'download' => 1];
    if ($episodeId !== null) {
        $query['episode_id'] = $episodeId;
    }
    if ($videoUrl && is_uploaded_video_path($videoUrl)) {
        return 'video.php?' . http_build_query($query);
    }
    foreach (array_reverse($qualityColumns, true) as $quality => $column) {
        if (is_uploaded_video_path((string) ($item[$column] ?? ''))) {
            $query['quality'] = $quality;
            return 'video.php?' . http_build_query($query);
        }
    }
    return null;
};
$sourceAttribute = static function (array $sources): string {
    return e((string) json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
};
$isFavorite = false;
$notice = '';
$noticeType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'comment') {
        $comment = trim((string) ($_POST['body'] ?? ''));
        if ($comment === '' || mb_strlen($comment) > 2000) {
            $notice = 'O comentário deve ter entre 1 e 2000 caracteres.';
            $noticeType = 'error';
        } else {
            $pdo->prepare('INSERT INTO comments (user_id, title_id, body) VALUES (?, ?, ?)')->execute([$user['id'], $id, $comment]);
            $notice = 'Comentário enviado para moderação.';
            audit($pdo, (int) $user['id'], 'comment.created', 'Título #' . $id);
        }
    } elseif ($action === 'rate') {
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        if ($rating === false) {
            $notice = 'Selecione uma avaliação entre 1 e 5 estrelas.';
            $noticeType = 'error';
        } else {
            $pdo->prepare('INSERT INTO ratings (user_id, title_id, rating) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), created_at = CURRENT_TIMESTAMP')->execute([$user['id'], $id, $rating]);
            $notice = 'Avaliação guardada.';
        }
    } else {
        http_response_code(403);
        exit('Ação não autorizada.');
    }
}
$stmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND title_id = ?');
$stmt->execute([$user['id'], $id]);
$isFavorite = (bool) $stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT progress_seconds FROM watch_history WHERE user_id = ? AND title_id = ? AND episode_id = 0');
$stmt->execute([$user['id'], $id]);
$progress = (int) ($stmt->fetchColumn() ?: 0);
$stmt = $pdo->prepare('SELECT e.id, e.episode_number, e.name, e.synopsis, e.video_url, e.video_360p_url, e.video_480p_url, e.video_720p_url, e.video_1080p_url, s.season_number, s.name AS season_name, COALESCE(h.progress_seconds, 0) AS progress_seconds FROM seasons s LEFT JOIN episodes e ON e.season_id = s.id LEFT JOIN watch_history h ON h.episode_id = e.id AND h.user_id = ? AND h.title_id = s.title_id WHERE s.title_id = ? ORDER BY s.season_number, e.episode_number');
$stmt->execute([$user['id'], $id]);
$episodes = $stmt->fetchAll();
$titleVideoSources = $qualitySources($title['video_url'], $title);
$titleDefaultQuality = $defaultQuality($titleVideoSources);
$titleDefaultSource = $titleVideoSources[$titleDefaultQuality] ?? '';
$hasTitleVideo = $titleVideoSources !== [];
$stmt = $pdo->prepare('SELECT AVG(rating), COUNT(*) FROM ratings WHERE title_id = ?');
$stmt->execute([$id]);
[$averageRating, $ratingCount] = $stmt->fetch(PDO::FETCH_NUM);
$stmt = $pdo->prepare('SELECT r.rating FROM ratings r WHERE r.user_id = ? AND r.title_id = ?');
$stmt->execute([$user['id'], $id]);
$myRating = (int) ($stmt->fetchColumn() ?: 0);
$stmt = $pdo->prepare('SELECT c.body, c.created_at, u.display_name, u.username FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.title_id = ? AND c.is_approved = 1 ORDER BY c.created_at DESC LIMIT 50');
$stmt->execute([$id]);
$comments = $stmt->fetchAll();
render_header($title['name']);
?>
<article class="detail-page">
  <?php if ($notice): ?><div class="notice notice-<?= e($noticeType) ?>"><?= e($notice) ?></div><?php endif; ?>
  <a class="back-link" href="index.php#catalogo">← Voltar ao catálogo</a>
  <div class="detail-layout">
    <div class="detail-poster"><?php if ($title['poster_path']): ?><img src="<?= e($title['poster_path']) ?>" alt="Cartaz de <?= e($title['name']) ?>"><?php else: ?><span class="poster-placeholder">BF</span><?php endif; ?></div>
    <div class="detail-copy">
      <span class="eyebrow"><?= $title['kind'] === 'series' ? 'SÉRIE' : 'FILME' ?><?= $title['category_name'] ? ' · ' . e($title['category_name']) : '' ?></span>
      <h1><?= e($title['name']) ?></h1>
      <p class="detail-meta"><?= e((string) ($title['release_year'] ?? '')) ?> <span>•</span> <?= e($title['category_name'] ?? 'Catálogo BALDÉ FLIX') ?></p>
      <p class="detail-synopsis"><?= nl2br(e($title['synopsis'])) ?></p>
      <?php if ($title['is_paid'] && !$hasPaidAccess): ?>
        <div class="paid-access-card">
          <span class="eyebrow">Conteúdo pago</span>
          <h2>Fale connosco pelo WhatsApp</h2>
          <p>O acesso individual é desbloqueado pelo proprietário após confirmar o pagamento. Também pode escolher o pacote Premium de 30 dias.</p>
          <?php if ($purchaseUrl): ?><a class="button button-primary" href="<?= e($purchaseUrl) ?>" target="_blank" rel="noopener noreferrer">Pedir acesso a este título</a> <a class="button button-outline" href="<?= e($premiumPurchaseUrl) ?>" target="_blank" rel="noopener noreferrer">Consultar Premium (30 dias)</a><?php else: ?><div class="notice notice-info">O contacto para conteúdos pagos ainda não foi configurado.</div><?php endif; ?>
        </div>
      <?php elseif ($hasTitleVideo || ($title['kind'] === 'series' && $episodes)): ?>
        <?php if ($title['is_paid']): ?><div class="notice notice-success">Acesso desbloqueado para a sua conta.</div><?php endif; ?>
        <?php if ($hasTitleVideo): ?><div class="video-frame"><video id="title-video" controls controlslist="nodownload noplaybackrate noremoteplayback" disablepictureinpicture playsinline preload="metadata" data-progress="<?= $progress ?>" data-quality-sources="<?= $sourceAttribute($titleVideoSources) ?>" data-quality-default="<?= e($titleDefaultQuality) ?>" src="<?= e($titleDefaultSource) ?>">O seu navegador não suporta a reprodução de vídeo.</video></div><?php if ($download = $downloadUrl($title['video_url'], null, $title)): ?><a class="button button-outline video-download" href="<?= e($download) ?>">↓ Baixar <?= $title['kind'] === 'series' ? 'série' : 'filme' ?></a><?php endif; ?><?php else: ?><div class="notice notice-info">A reprodução deste título ainda não está disponível.</div><?php endif; ?>
        <p class="form-note">A reprodução depende do formato e da disponibilidade da fonte autorizada.</p>
      <?php else: ?>
        <div class="notice notice-info">A reprodução deste título ainda não está disponível.</div>
      <?php endif; ?>
        <form method="post" action="index.php" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="favorite">
          <input type="hidden" name="title_id" value="<?= (int) $title['id'] ?>">
          <button class="button button-outline" type="submit"><?= $isFavorite ? '♥ Na minha lista' : '♡ Adicionar à minha lista' ?></button>
        </form>
      <div class="rating-summary"><span class="rating-stars">★</span> <?= $ratingCount ? number_format((float) $averageRating, 1) . ' / 5 · ' . (int) $ratingCount . ' avaliações' : 'Ainda sem avaliações' ?></div>
    </div>
  </div>
</article>
<?php if ($title['kind'] === 'series'): ?>
  <section class="content-section series-episodes">
    <div class="section-heading"><div><span class="eyebrow">Série</span><h2>Temporadas e episódios</h2></div></div>
    <?php if ($episodes): ?><div class="episode-list">
      <?php foreach ($episodes as $episode): ?>
        <?php if (!$episode['id']) continue; ?>
        <article class="episode-card">
          <div class="episode-heading"><span class="episode-number">T<?= (int) $episode['season_number'] ?> · E<?= (int) $episode['episode_number'] ?></span><h3><?= e($episode['name']) ?></h3></div>
          <?php if ($episode['synopsis']): ?><p><?= e($episode['synopsis']) ?></p><?php endif; ?>
          <?php
            $episodeSources = $qualitySources($episode['video_url'], $episode, (int) $episode['id']);
            $episodeDefaultQuality = $defaultQuality($episodeSources);
            $episodeDefaultSource = $episodeSources[$episodeDefaultQuality] ?? '';
          ?>
          <?php if ($title['is_paid'] && !$hasPaidAccess): ?><span class="muted">Contacte-nos pelo WhatsApp para obter acesso.</span><?php elseif ($episodeSources): ?><video class="episode-video" controls controlslist="nodownload noplaybackrate noremoteplayback" disablepictureinpicture playsinline preload="metadata" data-episode-id="<?= (int) $episode['id'] ?>" data-progress="<?= (int) $episode['progress_seconds'] ?>" data-quality-sources="<?= $sourceAttribute($episodeSources) ?>" data-quality-default="<?= e($episodeDefaultQuality) ?>" src="<?= e($episodeDefaultSource) ?>"></video><?php if ($download = $downloadUrl($episode['video_url'], (int) $episode['id'], $episode)): ?><a class="button button-outline video-download" href="<?= e($download) ?>">↓ Baixar episódio</a><?php endif; ?><?php else: ?><span class="muted">Episódio ainda não disponível.</span><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div><?php else: ?><div class="empty-state compact">Ainda não foram adicionados episódios.</div><?php endif; ?>
  </section>
<?php endif; ?>
<section class="content-section detail-community">
  <div class="profile-grid">
    <section class="panel-card"><span class="eyebrow">A sua opinião</span><h2>Avaliar título</h2>
      <form method="post" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="rate">
        <label>Classificação<select name="rating" required><?php for ($star = 5; $star >= 1; $star--): ?><option value="<?= $star ?>" <?= $myRating === $star ? 'selected' : '' ?>><?= $star ?> <?= $star === 1 ? 'estrela' : 'estrelas' ?></option><?php endfor; ?></select></label>
        <button class="button button-outline">Guardar avaliação</button>
      </form>
    </section>
    <section class="panel-card"><span class="eyebrow">Comunidade</span><h2>Comentários</h2>
      <form method="post" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="comment">
        <label>Escreva um comentário<textarea name="body" rows="3" maxlength="2000" required></textarea></label>
        <button class="button button-primary">Enviar para moderação</button>
      </form>
    </section>
  </div>
  <div class="profile-section"><div class="section-heading"><div><span class="eyebrow">Opiniões</span><h2>Comentários aprovados</h2></div></div>
    <?php if ($comments): ?><div class="notification-list"><?php foreach ($comments as $comment): ?><article class="notification-item"><b><?= e($comment['display_name'] ?? 'Utilizador') ?><?= $comment['username'] ? ' · @' . e($comment['username']) : '' ?></b><p><?= nl2br(e($comment['body'])) ?></p><time><?= e(date('d/m/Y', strtotime($comment['created_at']))) ?></time></article><?php endforeach; ?></div><?php else: ?><div class="empty-state compact">Ainda não há comentários neste título.</div><?php endif; ?>
  </div>
</section>
<?php if ($hasTitleVideo || ($title['kind'] === 'series' && $episodes)): ?>
<script>
(() => {
  document.querySelectorAll('#title-video, .episode-video').forEach((video) => {
    video.controls = false;
    video.setAttribute('controlslist', 'nodownload noplaybackrate');
    video.setAttribute('x-webkit-airplay', 'allow');
    video.disablePictureInPicture = true;
    video.disableRemotePlayback = false;
    video.tabIndex = 0;
    const player = document.createElement('div');
    player.className = 'video-player';
    video.parentNode.insertBefore(player, video);
    player.appendChild(video);
    const controls = document.createElement('div');
    controls.className = 'video-controls';
    controls.innerHTML = `
      <button class="video-control-button video-play-toggle" type="button" aria-label="Reproduzir" title="Reproduzir">
        <svg class="video-icon-play" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 12 7-12 7z"></path></svg>
        <svg class="video-icon-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM15 5h4v14h-4z"></path></svg>
      </button>
      <span class="video-time video-current-time">0:00</span>
      <input class="video-seek" type="range" min="0" max="1000" value="0" aria-label="Posição do vídeo">
      <span class="video-time video-duration">0:00</span>
      <button class="video-control-button video-mute-toggle" type="button" aria-label="Silenciar" title="Silenciar">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4h4l5 4V6l-5 4H4zM17 9a5 5 0 0 1 0 6M19 5a10 10 0 0 1 0 14"></path></svg>
      </button>
      <label class="video-setting"><span>Velocidade</span><select class="video-speed" aria-label="Velocidade de reprodução"><option value="0.5">0,5×</option><option value="0.75">0,75×</option><option value="1" selected>1×</option><option value="1.25">1,25×</option><option value="1.5">1,5×</option><option value="2">2×</option></select></label>
      <label class="video-setting video-quality"><span>Qualidade</span><select class="video-quality-select" aria-label="Qualidade do vídeo"></select></label>
      <label class="video-setting video-aspect"><span>Proporção</span><select class="video-aspect-select" aria-label="Proporção do vídeo"><option value="auto">Original</option><option value="16:9">16:9</option><option value="4:3">4:3</option><option value="21:9">21:9</option><option value="1:1">1:1</option><option value="fill">Preencher</option></select></label>
      <button class="video-control-button video-cast-toggle" type="button" aria-label="Transmitir para um dispositivo" title="Transmitir para um dispositivo">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18v2h2a2 2 0 0 0-2-2zm0-4v2a4 4 0 0 1 4 4h2a6 6 0 0 0-6-6zm0-4v2a8 8 0 0 1 8 8h2A10 10 0 0 0 4 10zm16-6H4a2 2 0 0 0-2 2v2h2V6h16v12h-6v2h6a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"></path></svg>
      </button>
      <button class="video-control-button video-fullscreen-toggle" type="button" aria-label="Ecrã inteiro" title="Ecrã inteiro">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3m13-5h3a2 2 0 0 1 2 2v3M3 16v3a2 2 0 0 0 2 2h3m13-5v3a2 2 0 0 1-2 2h-3"></path></svg>
      </button>`;
    player.appendChild(controls);
    const playbackError = document.createElement('div');
    playbackError.className = 'video-playback-error';
    playbackError.hidden = true;
    playbackError.setAttribute('role', 'alert');
    player.appendChild(playbackError);
    const castStatus = document.createElement('div');
    castStatus.className = 'video-cast-status';
    castStatus.hidden = true;
    castStatus.setAttribute('role', 'status');
    castStatus.setAttribute('aria-live', 'polite');
    player.appendChild(castStatus);
    const playButton = controls.querySelector('.video-play-toggle');
    const seek = controls.querySelector('.video-seek');
    const currentTime = controls.querySelector('.video-current-time');
    const duration = controls.querySelector('.video-duration');
    const muteButton = controls.querySelector('.video-mute-toggle');
    const castButton = controls.querySelector('.video-cast-toggle');
    const qualitySelect = controls.querySelector('.video-quality-select');
    const qualitySources = JSON.parse(video.dataset.qualitySources || '{}');
    const defaultQuality = video.dataset.qualityDefault || '';
    for (const quality of Object.keys(qualitySources)) {
      const option = document.createElement('option');
      option.value = quality;
      option.textContent = quality === 'Original' ? 'Original' : quality;
      option.selected = quality === defaultQuality;
      qualitySelect.appendChild(option);
    }
    qualitySelect.disabled = qualitySelect.options.length < 2;
    const aspectSelect = controls.querySelector('.video-aspect-select');
    aspectSelect.addEventListener('change', () => {
      player.dataset.aspectRatio = aspectSelect.value;
    });
    qualitySelect.addEventListener('change', () => {
      const nextSource = qualitySources[qualitySelect.value];
      if (!nextSource || video.src === new URL(nextSource, document.baseURI).href) return;
      const resumeAt = video.currentTime;
      const wasPlaying = !video.paused;
      video.src = nextSource;
      video.load();
      video.addEventListener('loadedmetadata', () => {
        if (Number.isFinite(resumeAt) && resumeAt > 0) video.currentTime = resumeAt;
        if (wasPlaying) video.play().catch(() => {});
      }, { once: true });
    });
    let castStatusTimeout;
    const updateCastStatus = (message) => {
      window.clearTimeout(castStatusTimeout);
      castStatus.textContent = message;
      castStatus.hidden = !message;
      if (message) {
        castStatusTimeout = window.setTimeout(() => {
          castStatus.hidden = true;
        }, 5000);
      }
    };
    const updateCastButton = (connected) => {
      castButton.classList.toggle('is-casting', connected);
      castButton.setAttribute('aria-label', connected ? 'Parar transmissão' : 'Transmitir para um dispositivo');
      castButton.title = connected ? 'Transmitir para um dispositivo conectado' : 'Transmitir para um dispositivo';
      castButton.setAttribute('aria-pressed', connected ? 'true' : 'false');
    };
    castButton.addEventListener('click', async () => {
      updateCastStatus('A procurar dispositivos de transmissão…');
      try {
        if (typeof video.webkitShowPlaybackTargetPicker === 'function') {
          video.webkitShowPlaybackTargetPicker();
          return;
        }
        if (!window.isSecureContext) {
          updateCastStatus('A transmissão requer uma ligação segura HTTPS. Abra o site pelo endereço HTTPS e tente novamente.');
          return;
        }
        if (video.remote && typeof video.remote.prompt === 'function') {
          await video.remote.prompt();
          if (video.remote.state === 'connected') {
            updateCastButton(true);
            updateCastStatus('Vídeo a ser transmitido.');
          } else {
            updateCastStatus('Se não aparecer nenhum dispositivo, confirme que está ligado, compatível e na mesma rede Wi-Fi.');
          }
          return;
        }
        updateCastStatus('Este navegador não disponibiliza transmissão para este vídeo. Experimente Chrome, Edge ou Safari e confirme que a TV/dispositivo suporta Cast ou AirPlay.');
      } catch (error) {
        if (error.name === 'AbortError') {
          updateCastStatus('A transmissão foi cancelada.');
        } else if (error.name === 'NotAllowedError') {
          updateCastStatus('O navegador bloqueou a transmissão. Use HTTPS, permita a descoberta de dispositivos e tente novamente.');
        } else {
          updateCastStatus('Não foi possível iniciar a transmissão. Verifique a compatibilidade do dispositivo e a rede Wi-Fi.');
        }
      }
    });
    if (video.remote) {
      video.remote.addEventListener('connecting', () => updateCastStatus('A ligar ao dispositivo…'));
      video.remote.addEventListener('connect', () => {
        updateCastButton(true);
        updateCastStatus('Vídeo a ser transmitido.');
      });
      video.remote.addEventListener('disconnect', () => {
        updateCastButton(false);
        updateCastStatus('');
      });
    }
    video.addEventListener('webkitcurrentplaybacktargetiswirelesschanged', () => {
      const connected = video.webkitCurrentPlaybackTargetIsWireless === true;
      updateCastButton(connected);
      updateCastStatus(connected ? 'Vídeo a ser transmitido por AirPlay.' : '');
    });
    const formatTime = (seconds) => {
      if (!Number.isFinite(seconds)) return '0:00';
      const total = Math.floor(seconds);
      const hours = Math.floor(total / 3600);
      const minutes = Math.floor((total % 3600) / 60);
      const remainder = total % 60;
      return hours ? `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}` : `${minutes}:${String(remainder).padStart(2, '0')}`;
    };
    const updatePlayButton = () => {
      const paused = video.paused;
      playButton.setAttribute('aria-label', paused ? 'Reproduzir' : 'Pausar');
      playButton.title = paused ? 'Reproduzir' : 'Pausar';
      player.classList.toggle('is-playing', !paused);
    };
    let controlsHideTimeout;
    const showControls = () => {
      window.clearTimeout(controlsHideTimeout);
      controls.classList.remove('is-hidden');
      if (!video.paused) {
        controlsHideTimeout = window.setTimeout(() => controls.classList.add('is-hidden'), 2500);
      }
    };
    const hideControls = () => {
      window.clearTimeout(controlsHideTimeout);
      controls.classList.add('is-hidden');
    };
    player.addEventListener('pointermove', showControls);
    player.addEventListener('pointerleave', hideControls);
    player.addEventListener('touchstart', showControls, { passive: true });
    video.addEventListener('click', () => {
      if (video.paused) video.play().catch(() => {});
      else video.pause();
      window.setTimeout(hideControls, 0);
    });
    playButton.addEventListener('click', () => {
      if (video.paused) video.play().catch(() => {});
      else video.pause();
    });
    video.addEventListener('play', updatePlayButton);
    video.addEventListener('play', showControls);
    video.addEventListener('pause', () => {
      updatePlayButton();
      window.clearTimeout(controlsHideTimeout);
      controls.classList.remove('is-hidden');
    });
    video.addEventListener('loadedmetadata', () => {
      duration.textContent = formatTime(video.duration);
      seek.disabled = !Number.isFinite(video.duration) || video.duration <= 0;
      if (video.duration === 0) {
        playbackError.textContent = 'O navegador não conseguiu ler este vídeo. Converta-o para MP4 com vídeo H.264 e áudio AAC e envie-o novamente.';
        playbackError.hidden = false;
      }
    });
    video.addEventListener('error', () => {
      playbackError.textContent = 'Este formato ou codec não é compatível com o navegador. Converta o vídeo para MP4 com vídeo H.264 e áudio AAC e envie-o novamente.';
      playbackError.hidden = false;
    });
    video.addEventListener('loadeddata', () => {
      playbackError.hidden = true;
    });
    video.addEventListener('timeupdate', () => {
      currentTime.textContent = formatTime(video.currentTime);
      if (Number.isFinite(video.duration) && video.duration > 0 && !seek.matches(':active')) {
        seek.value = String(Math.round((video.currentTime / video.duration) * 1000));
      }
    });
    seek.addEventListener('input', () => {
      if (Number.isFinite(video.duration) && video.duration > 0) {
        video.currentTime = (Number(seek.value) / 1000) * video.duration;
      }
    });
    controls.querySelector('.video-speed').addEventListener('change', (event) => {
      video.playbackRate = Number(event.currentTarget.value);
    });
    muteButton.addEventListener('click', () => {
      video.muted = !video.muted;
      muteButton.setAttribute('aria-label', video.muted ? 'Ativar som' : 'Silenciar');
      muteButton.title = video.muted ? 'Ativar som' : 'Silenciar';
      player.classList.toggle('is-muted', video.muted);
    });
    const fullscreenButton = controls.querySelector('.video-fullscreen-toggle');
    const updateFullscreenButton = () => {
      const active = document.fullscreenElement === player || document.webkitFullscreenElement === player;
      fullscreenButton.setAttribute('aria-label', active ? 'Sair do ecrã inteiro' : 'Ecrã inteiro');
      fullscreenButton.title = active ? 'Sair do ecrã inteiro' : 'Ecrã inteiro';
    };
    fullscreenButton.addEventListener('click', async () => {
      try {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
          if (document.exitFullscreen) await document.exitFullscreen();
          else document.webkitExitFullscreen?.();
        } else if (player.requestFullscreen) {
          await player.requestFullscreen();
        } else if (player.webkitRequestFullscreen) {
          player.webkitRequestFullscreen();
        } else if (video.webkitEnterFullscreen) {
          video.webkitEnterFullscreen();
        } else {
          updateCastStatus('O ecrã inteiro não está disponível neste navegador.');
        }
      } catch {
        updateCastStatus('Não foi possível abrir o ecrã inteiro. Tente novamente.');
      }
    });
    document.addEventListener('fullscreenchange', updateFullscreenButton);
    document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
    video.addEventListener('contextmenu', (event) => event.preventDefault());
    video.addEventListener('keydown', (event) => {
      if (event.target !== video) return;
      if (event.code === 'Space') {
        event.preventDefault();
        if (video.paused) video.play().catch(() => {});
        else video.pause();
      } else if (event.code === 'ArrowRight' || event.code === 'ArrowLeft') {
        event.preventDefault();
        video.currentTime = Math.max(0, Math.min(video.duration || 0, video.currentTime + (event.code === 'ArrowRight' ? 10 : -10)));
      } else if (event.key.toLowerCase() === 'm') {
        video.muted = !video.muted;
        muteButton.setAttribute('aria-label', video.muted ? 'Ativar som' : 'Silenciar');
        muteButton.title = video.muted ? 'Ativar som' : 'Silenciar';
        player.classList.toggle('is-muted', video.muted);
      }
    });
    updatePlayButton();
    const savedProgress = Number(video.dataset.progress || 0);
    const episodeId = video.dataset.episodeId || '0';
    video.addEventListener('loadedmetadata', () => {
      if (savedProgress > 5 && savedProgress < video.duration - 5) video.currentTime = savedProgress;
    }, { once: true });
    let lastSaved = savedProgress;
    const saveProgress = () => {
      const progress = Math.floor(video.currentTime || 0);
      if (progress === lastSaved) return;
      lastSaved = progress;
      const body = new URLSearchParams({
        csrf_token: <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        title_id: <?= (int) $title['id'] ?>,
        episode_id: episodeId,
        progress_seconds: progress
      });
      fetch('watch.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body, keepalive: true });
    };
    video.addEventListener('timeupdate', () => {
      if (Math.floor(video.currentTime) % 15 === 0) saveProgress();
    });
    video.addEventListener('pause', saveProgress);
    video.addEventListener('ended', () => {
      video.currentTime = 0;
      saveProgress();
    });
    window.addEventListener('pagehide', saveProgress);
  });
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
