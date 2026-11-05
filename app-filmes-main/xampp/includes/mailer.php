<?php
declare(strict_types=1);

function smtp_read($socket): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('O servidor SMTP encerrou a ligação.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');
    return $response;
}

function smtp_command($socket, string $command, array $expected): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Não foi possível enviar um comando ao servidor SMTP.');
    }
    $response = smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('O servidor SMTP recusou uma etapa do envio (' . $code . ').');
    }
    return $response;
}

function send_smtp_email(array $recipient, string $subject, string $html, string $text): void
{
    $settings = $GLOBALS['config']['smtp'] ?? [];
    foreach (['host', 'username', 'password', 'from_email'] as $required) {
        if (empty($settings[$required])) {
            throw new RuntimeException('SMTP ainda não configurado. Preencha as opções SMTP no ficheiro config.php.');
        }
    }
    $host = (string) $settings['host'];
    $port = (int) ($settings['port'] ?? 587);
    $encryption = (string) ($settings['encryption'] ?? 'tls');
    if (!in_array($encryption, ['tls', 'ssl'], true) || $port < 1 || $port > 65535) {
        throw new RuntimeException('A configuração de segurança SMTP não é válida.');
    }
    if (!filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL) || !filter_var($recipient['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('O endereço de e-mail SMTP ou do destinatário não é válido.');
    }
    if (preg_match('/[\r\n]/', $host . ($settings['username'] ?? '') . $settings['from_email'] . ($recipient['email'] ?? ''))) {
        throw new RuntimeException('A configuração SMTP contém cabeçalhos inválidos.');
    }

    $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        error_log('BALDÉ FLIX SMTP connection failed: ' . $errno . ' ' . $errstr);
        throw new RuntimeException('Não foi possível ligar ao SMTP. Verifique o servidor, a porta e a ligação à Internet.');
    }
    stream_set_timeout($socket, 15);
    try {
        $welcome = smtp_read($socket);
        if ((int) substr($welcome, 0, 3) !== 220) {
            throw new RuntimeException('O servidor SMTP não aceitou a ligação.');
        }
        $domain = preg_replace('/[^a-zA-Z0-9.-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
        smtp_command($socket, 'EHLO ' . $domain, [250]);
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('Não foi possível ativar TLS para o SMTP.');
            }
            smtp_command($socket, 'EHLO ' . $domain, [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode((string) $settings['username']), [334]);
        smtp_command($socket, base64_encode((string) $settings['password']), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $settings['from_email'] . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient['email'] . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $fromName = (string) ($settings['from_name'] ?? ($GLOBALS['config']['app_name'] ?? 'BALDÉ FLIX'));
        if (preg_match('/[\r\n]/', $fromName) || preg_match('/[\r\n]/', $subject)) {
            throw new RuntimeException('O assunto ou remetente contém caracteres inválidos.');
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $boundary = 'baldeflix_' . bin2hex(random_bytes(16));
        $body = implode("\r\n", [
            'From: ' . $encodedFrom . ' <' . $settings['from_email'] . '>',
            'To: <' . $recipient['email'] . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($text),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($html),
            '--' . $boundary . '--',
        ]);
        $body = preg_replace('/(^|\r\n)\./', '$1..', $body) ?? $body;
        if (fwrite($socket, $body . "\r\n.\r\n") === false) {
            throw new RuntimeException('Não foi possível enviar o conteúdo do e-mail.');
        }
        $response = smtp_read($socket);
        if ((int) substr($response, 0, 3) !== 250) {
            throw new RuntimeException('O servidor SMTP não confirmou o envio.');
        }
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function issue_account_token(PDO $pdo, int $userId, string $purpose, int $validMinutes, ?string $targetEmail = null): string
{
    if ($purpose !== 'password_reset') {
        throw new InvalidArgumentException('Tipo de token inválido.');
    }
    $recent = $pdo->prepare('SELECT COUNT(*) FROM account_tokens WHERE user_id = ? AND purpose = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $recent->execute([$userId, $purpose]);
    if ((int) $recent->fetchColumn() >= 3) {
        throw new RuntimeException('Foram pedidos demasiados links. Aguarde um minuto e tente novamente.');
    }
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE account_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND purpose = ? AND used_at IS NULL')
        ->execute([$userId, $purpose]);
    $validMinutes = max(1, min(1440, $validMinutes));
    $pdo->prepare('INSERT INTO account_tokens (user_id, token_hash, purpose, target_email, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $validMinutes . ' MINUTE))')
        ->execute([$userId, hash('sha256', $token), $purpose, $targetEmail]);
    return $token;
}

function account_action_url(string $page, string $token): string
{
    $baseUrl = rtrim((string) ($GLOBALS['config']['base_url'] ?? ''), '/');
    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    $host = parse_url($baseUrl, PHP_URL_HOST);
    $isLocalHttp = $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || ($scheme !== 'https' && !$isLocalHttp)) {
        throw new RuntimeException('Defina uma base_url válida no ficheiro config.php para criar links seguros.');
    }
    return $baseUrl . '/' . rawurlencode($page) . '?token=' . rawurlencode($token);
}
