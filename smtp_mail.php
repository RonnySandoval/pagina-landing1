<?php
declare(strict_types=1);

/**
 * Envío mínimo por SMTP (AUTH LOGIN + STARTTLS o SSL directo).
 * Sin dependencias externas; pensado para XAMPP y hosting con SMTP real.
 * Configuración: `mail_config.php` (plantilla `mail_config.example.php`).
 */

function smtp_read_lines($fp): string {
    $buffer = "";
    while (($line = fgets($fp, 8192)) !== false) {
        $buffer .= $line;
        if (strlen($line) < 4) {
            break;
        }
        if ($line[3] === " ") {
            break;
        }
    }
    return $buffer;
}

function smtp_expect($fp, array $okPrefixes): void {
    $response = smtp_read_lines($fp);
    $code = substr($response, 0, 3);
    foreach ($okPrefixes as $prefix) {
        if (str_starts_with($response, $prefix)) {
            return;
        }
    }
    throw new RuntimeException("SMTP inesperado: " . trim($response));
}

function smtp_send_line($fp, string $line): void {
    fwrite($fp, $line . "\r\n");
}

/**
 * Cabecera From: nombre visible + correo (ASCII entre comillas suele verse mejor que UTF-8 encoded-word en Gmail).
 */
function smtp_format_from_header(string $fromName, string $fromEmail): string {
    $fromName = trim($fromName);
    $fromEmail = trim($fromEmail);
    if ($fromName === "") {
        return $fromEmail;
    }
    if (preg_match('/^[\x20-\x7E]+$/', $fromName)) {
        $escaped = str_replace(["\\", '"'], ["\\\\", '\\"'], $fromName);
        return "\"{$escaped}\" <{$fromEmail}>";
    }
    return "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>";
}

/**
 * Correo usado en SMTP como MAIL FROM / cabecera From (debe ser una cuenta que puedas autenticar).
 * Si from_email va vacío pero username es un correo válido (típico en Gmail), se usa username.
 */
function mail_config_resolve_smtp_from(array $cfg): string {
    $from = trim((string)($cfg["from_email"] ?? ""));
    if ($from !== "" && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return $from;
    }
    $user = trim((string)($cfg["username"] ?? ""));
    if ($user !== "" && filter_var($user, FILTER_VALIDATE_EMAIL)) {
        return $user;
    }

    return "";
}

function smtp_debug_log(array $cfg, string $message): void {
    $path = (string)($cfg["debug_log"] ?? "");
    if ($path === "" || empty($cfg["debug"])) {
        return;
    }
    $line = date("c") . " " . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND);
}

/** Una línea en contact_send_trace.log aunque no haya debug_log (diagnóstico SMTP). */
function smtp_trace_public(string $message): void {
    $path = __DIR__ . "/contact_send_trace.log";
    $line = date("c") . " [smtp] " . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function send_mail_smtp(array $cfg, string $to, string $subject, string $body, string $replyTo): bool {
    $host = (string)($cfg["host"] ?? "");
    $port = (int)($cfg["port"] ?? 587);
    $encryption = strtolower((string)($cfg["encryption"] ?? "tls"));
    $user = (string)($cfg["username"] ?? "");
    $pass = preg_replace('/\s+/', '', (string)($cfg["password"] ?? ""));
    $fromEmail = (string)($cfg["from_email"] ?? "");
    $fromName = (string)($cfg["from_name"] ?? "Formulario web");

    if ($host === "" || $fromEmail === "" || $user === "") {
        return false;
    }

    // Contexto SSL con peer_name: necesario para STARTTLS con Gmail en muchos entornos Windows/PHP.
    $relaxTls = !empty($cfg["smtp_relax_tls_verify"]);
    $sslContext = [
        "peer_name" => $host,
        "verify_peer" => !$relaxTls,
        "verify_peer_name" => !$relaxTls,
    ];
    $streamContext = stream_context_create([
        "ssl" => $sslContext,
    ]);

    $remote = ($encryption === "ssl")
        ? "ssl://{$host}:{$port}"
        : "tcp://{$host}:{$port}";

    $errno = 0;
    $errstr = "";
    $fp = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $encryption === "ssl" ? $streamContext : $streamContext
    );

    if ($fp === false) {
        $msg = "SMTP connect falló: {$errstr} ({$errno})";
        error_log($msg);
        smtp_debug_log($cfg, $msg);
        smtp_trace_public($msg . " host={$host} port={$port} enc={$encryption}");
        return false;
    }

    stream_set_timeout($fp, 30);

    try {
        smtp_expect($fp, ["220"]);

        smtp_send_line($fp, "EHLO " . $host);
        smtp_expect($fp, ["250"]);

        if ($encryption === "tls" && $port !== 465) {
            smtp_send_line($fp, "STARTTLS");
            smtp_expect($fp, ["220"]);
            $cryptoMethods = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined("STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT")) {
                $cryptoMethods |= (int)constant("STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT");
            }
            if (!@stream_socket_enable_crypto($fp, true, $cryptoMethods)) {
                throw new RuntimeException("STARTTLS falló (revisa OpenSSL/ca-bundle en php.ini)");
            }
            smtp_send_line($fp, "EHLO " . $host);
            smtp_expect($fp, ["250"]);
        }

        smtp_send_line($fp, "AUTH LOGIN");
        smtp_expect($fp, ["334"]);

        smtp_send_line($fp, base64_encode($user));
        smtp_expect($fp, ["334"]);

        smtp_send_line($fp, base64_encode($pass));
        smtp_expect($fp, ["235"]);

        smtp_send_line($fp, "MAIL FROM:<{$fromEmail}>");
        smtp_expect($fp, ["250"]);

        smtp_send_line($fp, "RCPT TO:<{$to}>");
        smtp_expect($fp, ["250", "251"]);

        smtp_send_line($fp, "DATA");
        smtp_expect($fp, ["354"]);

        $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $fromHeader = smtp_format_from_header($fromName, $fromEmail);

        $data = "";
        $data .= "From: {$fromHeader}\r\n";
        $data .= "To: <{$to}>\r\n";
        $data .= "Subject: {$encodedSubject}\r\n";
        $data .= "MIME-Version: 1.0\r\n";
        $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $data .= "Content-Transfer-Encoding: 8bit\r\n";
        $data .= "Reply-To: {$replyTo}\r\n";
        $data .= "\r\n";
        $data .= str_replace(["\r\n", "\r"], "\n", $body);
        $data = str_replace("\n", "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);

        fwrite($fp, $data . "\r\n.\r\n");
        smtp_expect($fp, ["250"]);

        smtp_send_line($fp, "QUIT");
    } catch (Throwable $e) {
        $err = "SMTP error: " . $e->getMessage();
        error_log($err);
        smtp_debug_log($cfg, $err);
        smtp_trace_public($err . " user=" . $user);
        fclose($fp);
        return false;
    }

    fclose($fp);
    smtp_debug_log($cfg, "OK enviado a {$to}");
    return true;
}
