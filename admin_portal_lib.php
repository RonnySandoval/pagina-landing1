<?php
declare(strict_types=1);

require_once __DIR__ . "/smtp_mail.php";
require_once __DIR__ . "/app_urls.php";

/**
 * Sesión y autenticación del panel admin (independiente del portal cliente).
 */

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $scopeId = substr(md5(__DIR__), 0, 8);
    $cookiePath = "/";
    $scriptName = (string)($_SERVER["SCRIPT_NAME"] ?? "");
    if ($scriptName !== "") {
        $rawDir = str_replace("\\", "/", dirname($scriptName));
        if ($rawDir !== "" && $rawDir !== ".") {
            $cookiePath = rtrim($rawDir, "/") . "/";
        }
    }

    session_set_cookie_params([
        "path" => $cookiePath,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    session_name("admin_session_" . $scopeId);
    session_start();
}

function admin_session_destroy(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
}

function admin_set_session_credentials(int $adminId, string $email): void
{
    session_regenerate_id(true);
    $_SESSION["admin_logged"] = true;
    $_SESSION["admin_id"] = $adminId;
    $_SESSION["admin_email"] = $email;
}

function admin_ajax_trace(string $message): void
{
    $path = __DIR__ . "/contact_send_trace.log";
    $line = date("c") . " [admin] " . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function admin_send_password_reset_email(string $toEmail, string $resetUrl): bool
{
    $subject = "Recuperacion de clave de administrador";
    $body = "Recibimos una solicitud para restablecer tu clave del panel.\n\n";
    $body .= "Usa este enlace (expira en 30 minutos):\n";
    $body .= $resetUrl . "\n\n";
    $body .= "Si no solicitaste este cambio, ignora este correo.\n";

    $mailConfigPath = __DIR__ . "/mail_config.php";
    $mailConfig = is_readable($mailConfigPath) ? require $mailConfigPath : [];
    $mailConfig = is_array($mailConfig) ? $mailConfig : [];

    $useSmtp = !empty($mailConfig["use_smtp"]);
    $smtpFrom = mail_config_resolve_smtp_from($mailConfig);
    $smtpReady = $useSmtp
        && !empty($mailConfig["host"])
        && !empty($mailConfig["username"])
        && !empty($mailConfig["password"])
        && $smtpFrom !== "";

    if ($smtpReady) {
        $smtpCfg = $mailConfig;
        $smtpCfg["from_email"] = $smtpFrom;
        $smtpSent = send_mail_smtp($smtpCfg, $toEmail, $subject, $body, $smtpFrom);
        admin_ajax_trace("password_reset smtp send result=" . ($smtpSent ? "ok" : "fail") . " to=" . $toEmail);
        if ($smtpSent) {
            return true;
        }
    }

    $fromEmail = $smtpFrom;
    $fromName = trim((string)($mailConfig["from_name"] ?? "Panel Administrador"));
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($fromEmail !== "") {
        $headers .= "From: " . smtp_format_from_header($fromName, $fromEmail) . "\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
    }

    $mailSent = (bool)@mail($toEmail, $subject, $body, $headers);
    admin_ajax_trace("password_reset php_mail result=" . ($mailSent ? "ok" : "fail") . " to=" . $toEmail);

    return $mailSent;
}

function admin_strong_password(string $password): bool
{
    if (strlen($password) < 10) {
        return false;
    }

    return (bool)preg_match('/[a-z]/', $password)
        && (bool)preg_match('/[A-Z]/', $password)
        && (bool)preg_match('/\d/', $password);
}

/**
 * @return null|string null = éxito
 */
function admin_try_login(mysqli $conn, string $email, string $password): ?string
{
    $email = trim($email);
    $password = (string)$password;

    $stmt = $conn->prepare("SELECT id, password FROM admins WHERE email = ? LIMIT 1");
    if ($stmt === false) {
        return "No se pudo comprobar el acceso.";
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result || $result->num_rows !== 1) {
        return "Credenciales invalidas.";
    }

    $adminRow = $result->fetch_assoc();
    $adminId = (int)($adminRow["id"] ?? 0);
    $passwordFromDb = (string)($adminRow["password"] ?? "");
    $isValidPassword = false;
    $needsRehash = false;

    if ($passwordFromDb !== "") {
        $isValidPassword = password_verify($password, $passwordFromDb);
        if ($isValidPassword) {
            $needsRehash = password_needs_rehash($passwordFromDb, PASSWORD_DEFAULT);
        } elseif (hash_equals($passwordFromDb, $password)) {
            $isValidPassword = true;
            $needsRehash = true;
        }
    }

    if (!$isValidPassword || $adminId <= 0) {
        return "Credenciales invalidas.";
    }

    if ($needsRehash) {
        $newHashedPassword = password_hash($password, PASSWORD_DEFAULT);
        if ($newHashedPassword !== false) {
            $updateStmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
            if ($updateStmt !== false) {
                $updateStmt->bind_param("si", $newHashedPassword, $adminId);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }
    }

    admin_set_session_credentials($adminId, $email);

    return null;
}

/**
 * Valida que la sesión admin siga siendo válida en esta BD (misma landing).
 */
function admin_resume_session(mysqli $conn): bool
{
    if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
        return false;
    }

    $sessionEmail = (string)($_SESSION["admin_email"] ?? "");
    if ($sessionEmail === "") {
        $_SESSION = [];
        return false;
    }

    $stmt = $conn->prepare("SELECT id, email FROM admins WHERE email = ? LIMIT 1");
    if ($stmt === false) {
        $_SESSION = [];
        return false;
    }
    $stmt->bind_param("s", $sessionEmail);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if (!$res || $res->num_rows !== 1) {
        $_SESSION = [];
        return false;
    }

    $row = $res->fetch_assoc();
    $_SESSION["admin_id"] = (int)($row["id"] ?? 0);
    $_SESSION["admin_email"] = (string)($row["email"] ?? $sessionEmail);

    return true;
}

/**
 * @return array{id: int, email: string}|null
 */
function admin_current_user(mysqli $conn): ?array
{
    if (!admin_resume_session($conn)) {
        return null;
    }

    return [
        "id" => (int)($_SESSION["admin_id"] ?? 0),
        "email" => (string)($_SESSION["admin_email"] ?? ""),
    ];
}

/**
 * Siempre devuelve el mismo mensaje genérico (no revelar si el correo existe).
 *
 * @return array{ok: true, message: string}
 */
function admin_request_password_reset(mysqli $conn, string $email): array
{
    $genericMessage = "Si el correo existe, enviamos un enlace de recuperacion.";
    $email = trim($email);
    admin_ajax_trace("password_reset request email=" . $email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_ajax_trace("password_reset abort invalid_email");

        return ["ok" => true, "message" => $genericMessage];
    }

    $stmt = $conn->prepare("SELECT id, email FROM admins WHERE email = ? LIMIT 1");
    if ($stmt === false) {
        admin_ajax_trace("password_reset abort prepare_failed err=" . $conn->error);

        return ["ok" => true, "message" => $genericMessage];
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result || $result->num_rows !== 1) {
        admin_ajax_trace("password_reset abort no_admin_with_this_email");

        return ["ok" => true, "message" => $genericMessage];
    }

    $adminRow = $result->fetch_assoc();
    $adminId = (int)($adminRow["id"] ?? 0);
    $adminEmail = (string)($adminRow["email"] ?? "");
    if ($adminId <= 0 || $adminEmail === "") {
        return ["ok" => true, "message" => $genericMessage];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash("sha256", $token);
    $expiresAt = date("Y-m-d H:i:s", time() + (30 * 60));
    $insertStmt = $conn->prepare("INSERT INTO admin_password_resets (admin_id, token_hash, expires_at) VALUES (?, ?, ?)");
    if ($insertStmt === false) {
        admin_ajax_trace("password_reset abort insert_prepare_failed err=" . $conn->error);

        return ["ok" => true, "message" => $genericMessage];
    }
    $insertStmt->bind_param("iss", $adminId, $tokenHash, $expiresAt);
    $inserted = $insertStmt->execute();
    $insertStmt->close();
    if (!$inserted) {
        return ["ok" => true, "message" => $genericMessage];
    }

    $resetUrl = app_public_base_url() . "/admin.php?reset_token=" . urlencode($token);
    admin_ajax_trace("password_reset token_created admin_id={$adminId}");
    $sent = admin_send_password_reset_email($adminEmail, $resetUrl);
    admin_ajax_trace("password_reset final_send=" . ($sent ? "ok" : "fail"));

    return ["ok" => true, "message" => $genericMessage];
}

/**
 * @return null|string null = éxito
 */
function admin_reset_password_with_token(mysqli $conn, string $token, string $newPassword, string $confirmPassword): ?string
{
    $token = trim($token);
    if ($token === "") {
        return "Token de recuperacion invalido.";
    }
    if ($newPassword === "" || $confirmPassword === "") {
        return "Completa los campos de nueva clave.";
    }
    if ($newPassword !== $confirmPassword) {
        return "La nueva clave y su confirmacion no coinciden.";
    }
    if (!admin_strong_password($newPassword)) {
        return "La nueva clave debe tener al menos 10 caracteres e incluir mayuscula, minuscula y numero.";
    }

    $tokenHash = hash("sha256", $token);
    $resetStmt = $conn->prepare(
        "SELECT id, admin_id FROM admin_password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1"
    );
    if ($resetStmt === false) {
        return "No se pudo validar el token de recuperacion.";
    }
    $resetStmt->bind_param("s", $tokenHash);
    $resetStmt->execute();
    $resetResult = $resetStmt->get_result();
    $resetStmt->close();

    if (!$resetResult || $resetResult->num_rows !== 1) {
        return "El enlace de recuperacion no es valido o ya expiro.";
    }

    $resetRow = $resetResult->fetch_assoc();
    $resetId = (int)($resetRow["id"] ?? 0);
    $adminId = (int)($resetRow["admin_id"] ?? 0);
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($adminId <= 0 || $resetId <= 0 || $hashedPassword === false) {
        return "No se pudo restablecer la clave.";
    }

    $conn->begin_transaction();
    try {
        $updateAdminStmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
        if ($updateAdminStmt === false) {
            throw new RuntimeException("prepare_admin_update_failed");
        }
        $updateAdminStmt->bind_param("si", $hashedPassword, $adminId);
        if (!$updateAdminStmt->execute()) {
            throw new RuntimeException("execute_admin_update_failed");
        }
        $updateAdminStmt->close();

        $markUsedStmt = $conn->prepare("UPDATE admin_password_resets SET used_at = NOW() WHERE id = ?");
        if ($markUsedStmt === false) {
            throw new RuntimeException("prepare_reset_update_failed");
        }
        $markUsedStmt->bind_param("i", $resetId);
        if (!$markUsedStmt->execute()) {
            throw new RuntimeException("execute_reset_update_failed");
        }
        $markUsedStmt->close();

        $invalidateStmt = $conn->prepare(
            "UPDATE admin_password_resets SET used_at = NOW()
             WHERE admin_id = ? AND used_at IS NULL AND id <> ?"
        );
        if ($invalidateStmt !== false) {
            $invalidateStmt->bind_param("ii", $adminId, $resetId);
            $invalidateStmt->execute();
            $invalidateStmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();

        return "No se pudo restablecer la clave.";
    }

    return null;
}
