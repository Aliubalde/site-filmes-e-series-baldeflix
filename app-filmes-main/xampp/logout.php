<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}
verify_csrf();
if ($user = current_user()) {
    audit($pdo, (int) $user['id'], 'auth.logout');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}
session_destroy();
redirect('index.php');
