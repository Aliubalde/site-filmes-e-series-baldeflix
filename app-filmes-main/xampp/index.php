<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$onlyFavorites = ($_GET['lista'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'favorite') {
    verify_csrf();
    $user = require_login();
    $titleId = filter_var($_POST['title_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$titleId) {
        http_response_code(400);
        exit('Conteúdo inválido.');
    }
    $check = $pdo->prepare('SELECT id FROM titles WHERE id = ? AND published = 1');
    $check->execute([$titleId]);
    if (!$check->fetch()) {
        http_response_code(404);
        exit('Conteúdo não encontrado.');
    }
    $favorite = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND title_id = ?');
    $favorite->execute([$user['id'], $titleId]);
    if ($favorite->fetchColumn()) {
        $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND title_id = ?')->execute([$user['id'], $titleId]);
    } else {
        $pdo->prepare('INSERT IGNORE INTO favorites (user_id, title_id) VALUES (?, ?)')->execute([$user['id'], $titleId]);
    }
    redirect('index.php#catalogo');
}

$sql = "SELECT t.*, c.name AS category_name FROM titles t LEFT JOIN categories c ON c.id = t.category_id WHERE t.published = 1";
if ($onlyFavorites) {
    $stmt = $pdo->prepare($sql . ' AND EXISTS (SELECT 1 FROM favorites f WHERE f.user_id = ? AND f.title_id = t.id) ORDER BY t.featured DESC, t.created_at DESC');
    $stmt->execute([$user['id']]);
    $titles = $stmt->fetchAll();
} else {
    $sql .= ' ORDER BY t.featured DESC, t.created_at DESC';
    $titles = $pdo->query($sql)->fetchAll();
}
$featured = null;
foreach ($titles as $item) {
    if ($item['featured']) {
        $featured = $item;
        break;
    }
}
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$favoriteIds = [];
$hasActivePremium = false;
$unlockedTitleIds = [];
if ($user) {
    $favorites = $pdo->prepare('SELECT title_id FROM favorites WHERE user_id = ?');
    $favorites->execute([$user['id']]);
    $favoriteIds = array_map('intval', $favorites->fetchAll(PDO::FETCH_COLUMN));
    $premium = $pdo->prepare('SELECT 1 FROM premium_subscriptions WHERE user_id = ? AND started_at <= CURRENT_TIMESTAMP AND expires_at > CURRENT_TIMESTAMP');
    $premium->execute([$user['id']]);
    $hasActivePremium = (bool) $premium->fetchColumn();
    $access = $pdo->prepare('SELECT title_id FROM user_title_access WHERE user_id = ?');
    $access->execute([$user['id']]);
    $unlockedTitleIds = array_fill_keys(array_map('intval', $access->fetchAll(PDO::FETCH_COLUMN)), true);
}
render_header($onlyFavorites ? 'Minha lista' : 'Início', $onlyFavorites ? 'my-list' : 'home');
?>
<?php if (!$onlyFavorites): ?>
<section class="hero">
  <?php if (!$onlyFavorites && $featured && $featured['poster_path']): ?>
    <img class="hero-image" src="<?= e($featured['poster_path']) ?>" alt="">
  <?php endif; ?>
  <div class="hero-shade"></div>
  <div class="hero-content">
    <span class="eyebrow"><span class="live-dot"></span> A sua próxima história começa aqui</span>
    <h1>Grandes histórias.<br><span>Uma só casa.</span></h1>
    <p>Descubra filmes e séries escolhidos para si, explore novos mundos e peça o próximo título da sua lista.</p>
    <?php if ($featured): ?>
      <a class="button button-primary" href="titulo.php?id=<?= (int) $featured['id'] ?>">▶ &nbsp; Ver destaque</a>
    <?php else: ?>
      <a class="button button-primary" href="#catalogo">Explorar catálogo</a>
    <?php endif; ?>
  </div>
  <?php if (!$onlyFavorites): ?><div class="hero-caption"><?= $featured ? 'EM DESTAQUE · ' . e($featured['name']) : 'BALDÉ FLIX · FILMES E SÉRIES' ?></div><?php endif; ?>
</section>
<?php endif; ?>

<section class="content-section" id="catalogo">
  <div class="section-heading">
    <div><span class="eyebrow"><?= $onlyFavorites ? 'A sua seleção' : 'Explore o catálogo' ?></span><h2><?= $onlyFavorites ? 'Minha lista' : 'Histórias para todos os momentos' ?></h2></div>
    <form class="search-form" role="search" onsubmit="return false">
      <label class="sr-only" for="catalog-search">Pesquisar títulos</label>
      <input id="catalog-search" type="search" placeholder="Pesquisar filmes e séries...">
    </form>
  </div>
  <div class="filter-row">
    <button class="chip active" type="button" data-filter="all">Tudo</button>
    <button class="chip" type="button" data-filter="movie">Filmes</button>
    <button class="chip" type="button" data-filter="series">Séries</button>
    <?php foreach ($categories as $category): ?>
      <button class="chip" type="button" data-category="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></button>
    <?php endforeach; ?>
  </div>
  <?php if ($titles): ?>
    <div class="catalog-grid" id="catalog-grid">
      <?php foreach ($titles as $item): ?>
        <article class="title-card" data-kind="<?= e($item['kind']) ?>" data-category="<?= (int) ($item['category_id'] ?? 0) ?>" data-search="<?= e(mb_strtolower($item['name'] . ' ' . $item['synopsis'] . ' ' . ($item['category_name'] ?? ''))) ?>">
          <a class="poster" href="titulo.php?id=<?= (int) $item['id'] ?>">
            <?php if ($item['poster_path']): ?><img src="<?= e($item['poster_path']) ?>" alt="Cartaz de <?= e($item['name']) ?>" loading="lazy"><?php else: ?><span class="poster-placeholder">BF</span><?php endif; ?>
            <span class="poster-gradient"></span><span class="play-badge">▶</span>
            <span class="tag"><?= $item['kind'] === 'series' ? 'SÉRIE' : 'FILME' ?></span>
            <?php if ($item['is_paid']): ?><span class="paid-badge"><?= $hasActivePremium || isset($unlockedTitleIds[(int) $item['id']]) ? 'DESBLOQUEADO' : 'PAGO' ?></span><?php endif; ?>
            <?php if ($item['featured']): ?><span class="featured-badge">DESTAQUE</span><?php endif; ?>
          </a>
          <div class="title-info">
            <div class="title-meta"><span><?= e($item['category_name'] ?? 'Sem categoria') ?></span><span><?= e((string) ($item['release_year'] ?? '')) ?></span></div>
            <h3><a href="titulo.php?id=<?= (int) $item['id'] ?>"><?= e($item['name']) ?></a></h3>
            <p><?= e(mb_strimwidth($item['synopsis'], 0, 110, '…')) ?></p>
            <div class="card-actions">
              <a href="titulo.php?id=<?= (int) $item['id'] ?>">Ver detalhes <span>→</span></a>
              <?php if ($user): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="favorite">
                  <input type="hidden" name="title_id" value="<?= (int) $item['id'] ?>">
                  <button class="icon-action <?= in_array((int) $item['id'], $favoriteIds, true) ? 'is-favorite' : '' ?>" aria-label="Alternar favorito" title="Minha lista" type="submit">♥</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <p id="no-results" class="empty-state" hidden>Nenhum título corresponde à pesquisa.</p>
  <?php else: ?>
    <div class="empty-state"><span class="empty-icon">✦</span><h3><?= $onlyFavorites ? 'A sua lista ainda está vazia' : 'O catálogo está a começar' ?></h3><p><?= $onlyFavorites ? 'Guarde os títulos de que gosta e eles aparecerão aqui.' : 'Estamos a preparar novas histórias. Volte em breve ou envie um pedido.' ?></p><a class="button button-outline" href="<?= $onlyFavorites ? 'index.php#catalogo' : 'pedido.php' ?>"><?= $onlyFavorites ? 'Explorar catálogo' : 'Pedir um título' ?></a></div>
  <?php endif; ?>
</section>
<section class="request-banner">
  <div><span class="eyebrow">Não encontrou o que procura?</span><h2>A próxima escolha pode ser sua.</h2><p>Envie o seu pedido e acompanhe o estado diretamente na plataforma.</p></div>
  <a class="button button-primary" href="pedido.php">Pedir filme ou série <span>→</span></a>
</section>
<script>
(() => {
  const search = document.querySelector('#catalog-search');
  const cards = [...document.querySelectorAll('.title-card')];
  const buttons = [...document.querySelectorAll('.filter-row .chip')];
  const noResults = document.querySelector('#no-results');
  let filter = { kind: 'all', category: '' };
  const update = () => {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    cards.forEach((card) => {
      const matchKind = filter.kind === 'all' || card.dataset.kind === filter.kind;
      const matchCategory = !filter.category || card.dataset.category === filter.category;
      const matchQuery = (card.dataset.search || '').includes(query);
      card.hidden = !(matchKind && matchCategory && matchQuery);
      if (!card.hidden) visible++;
    });
    if (noResults) noResults.hidden = visible > 0;
  };
  search?.addEventListener('input', update);
  buttons.forEach((button) => button.addEventListener('click', () => {
    buttons.forEach((item) => item.classList.remove('active'));
    button.classList.add('active');
    filter = button.dataset.filter
      ? { kind: button.dataset.filter, category: '' }
      : { kind: 'all', category: button.dataset.category || '' };
    update();
  }));
})();
</script>
<?php render_footer(); ?>
