<?php
declare(strict_types=1);

require_once __DIR__ . "/smtp_mail.php";
require_once __DIR__ . "/app_urls.php";

/**
 * Sesión y auth del portal de clientes (independiente del admin).
 * Cookie y nombre de sesión propios para no mezclar con admin_session_*.
 * Registro e inicio de sesión se gestionan desde la landing (`index.php`).
 */

function client_session_start(): void
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
    session_name("client_session_" . $scopeId);
    session_start();
}

function client_session_destroy(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
}

function client_set_flash(string $type, string $msg): void
{
    $_SESSION["client_flash"] = ["type" => $type, "msg" => $msg];
}

/** @return array{type: string, msg: string} */
function client_take_flash(): array
{
    if (!isset($_SESSION["client_flash"]) || !is_array($_SESSION["client_flash"])) {
        return ["type" => "", "msg" => ""];
    }
    $f = $_SESSION["client_flash"];
    unset($_SESSION["client_flash"]);
    return [
        "type" => (string)($f["type"] ?? ""),
        "msg" => (string)($f["msg"] ?? ""),
    ];
}

function client_set_session_credentials(int $id, string $email, string $displayName): void
{
    session_regenerate_id(true);
    $_SESSION["client_id"] = $id;
    $_SESSION["client_email"] = $email;
    $_SESSION["client_display_name"] = $displayName;
}

/**
 * Indica si un mensaje de contacto pertenece al cliente (misma cuenta o mismo correo en envíos sin client_id).
 */
function client_contact_message_owned_by(mysqli $conn, int $sessionClientId, string $sessionEmailNorm, int $messageId): bool
{
    if ($messageId <= 0 || $sessionClientId <= 0) {
        return false;
    }
    $em = strtolower(trim($sessionEmailNorm));
    if ($em === "") {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT 1 FROM contact_messages WHERE id = ? AND (client_id = ? OR (client_id IS NULL AND LOWER(TRIM(email)) = ?)) LIMIT 1"
    );
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param("iis", $messageId, $sessionClientId, $em);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res !== false && $res->num_rows === 1;
    $stmt->close();

    return $ok;
}

/**
 * @return null|string null = éxito
 */
function client_try_login(mysqli $conn, string $email, string $password): ?string
{
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Introduce un correo válido.";
    }
    if ($password === "") {
        return "Introduce tu clave.";
    }

    $stmt = $conn->prepare("SELECT id, password, display_name FROM clients WHERE email = ? AND is_active = 1 LIMIT 1");
    if ($stmt === false) {
        return "No se pudo comprobar el acceso.";
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result || $result->num_rows !== 1) {
        return "Credenciales incorrectas o cuenta desactivada.";
    }

    $row = $result->fetch_assoc();
    $id = (int)($row["id"] ?? 0);
    $hash = (string)($row["password"] ?? "");
    $displayName = (string)($row["display_name"] ?? "");

    $ok = $hash !== "" && password_verify($password, $hash);
    if (!$ok && $hash !== "" && hash_equals($hash, $password)) {
        $ok = true;
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if ($newHash !== false && $id > 0) {
            $up = $conn->prepare("UPDATE clients SET password = ? WHERE id = ?");
            if ($up !== false) {
                $up->bind_param("si", $newHash, $id);
                $up->execute();
                $up->close();
            }
        }
    }

    if (!$ok || $id <= 0) {
        return "Credenciales incorrectas o cuenta desactivada.";
    }

    client_set_session_credentials($id, $email, $displayName);
    return null;
}

/** Tras fallo SMTP: no guardar hash de clave en sesión demasiado tiempo. */
const CLIENT_REG_PENDING_MAX_AGE_FAIL = 900;
/** Tras envío aceptado por SMTP: mismo margen que el token del enlace (48 h). */
const CLIENT_REG_PENDING_MAX_AGE_MAIL_SENT = 172800;

function client_register_pending_prune(): void
{
    if (!isset($_SESSION["client_reg_pending"]) || !is_array($_SESSION["client_reg_pending"])) {
        return;
    }
    $t = (int)($_SESSION["client_reg_pending"]["ts"] ?? 0);
    $mailSent = !empty($_SESSION["client_reg_pending"]["verification_sent"]);
    $maxAge = $mailSent ? CLIENT_REG_PENDING_MAX_AGE_MAIL_SENT : CLIENT_REG_PENDING_MAX_AGE_FAIL;
    if ($t <= 0 || (time() - $t) > $maxAge) {
        unset($_SESSION["client_reg_pending"]);
    }
}

/** @return array<string, mixed>|null */
function client_register_pending_get(): ?array
{
    client_register_pending_prune();
    if (!isset($_SESSION["client_reg_pending"]) || !is_array($_SESSION["client_reg_pending"])) {
        return null;
    }

    return $_SESSION["client_reg_pending"];
}

function client_register_retry_clear(?mysqli $conn = null): void
{
    if ($conn !== null && isset($_SESSION["client_reg_pending"]["email"])) {
        $em = trim((string)($_SESSION["client_reg_pending"]["email"]));
        if ($em !== "" && filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $st = $conn->prepare("DELETE FROM client_registration_tokens WHERE LOWER(TRIM(email)) = LOWER(?)");
            if ($st !== false) {
                $st->bind_param("s", $em);
                $st->execute();
                $st->close();
            }
        }
    }
    unset($_SESSION["client_reg_pending"]);
}

function client_smtp_fully_ready(array $mailConfig): bool
{
    $host = trim((string)($mailConfig["host"] ?? ""));
    $pass = preg_replace('/\s+/', '', (string)($mailConfig["password"] ?? ""));
    $user = trim((string)($mailConfig["username"] ?? ""));
    $from = mail_config_resolve_smtp_from($mailConfig);

    return $host !== "" && $user !== "" && $pass !== "" && $from !== "";
}

/**
 * Envía un correo al visitante por SMTP (misma configuración que el panel). Subject y cuerpo en texto plano.
 */
function client_registration_send_plain_smtp(mysqli $conn, string $toEmail, string $subject, string $bodyPlain): bool
{
    $path = __DIR__ . "/mail_config.php";
    $mailConfig = is_readable($path) ? require $path : [];
    if (!is_array($mailConfig) || !client_smtp_fully_ready($mailConfig)) {
        smtp_trace_public("registro: envío SMTP no ejecutado (config incompleta)");

        return false;
    }
    $st = $conn->query("SELECT contact_email, person_name, brand_name FROM site_settings WHERE id = 1 LIMIT 1");
    $contactEmail = "";
    $personName = "";
    $brandName = "";
    if ($st && $row = $st->fetch_assoc()) {
        $contactEmail = trim((string)($row["contact_email"] ?? ""));
        $personName = trim((string)($row["person_name"] ?? ""));
        $brandName = trim((string)($row["brand_name"] ?? ""));
    }
    if ($contactEmail === "" || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        smtp_trace_public("registro: envío SMTP no ejecutado (contact_email del sitio inválido)");

        return false;
    }
    $fromEmail = mail_config_resolve_smtp_from($mailConfig);
    $fromDisplayName = trim((string)($mailConfig["from_name"] ?? ""));
    if ($fromDisplayName === "") {
        $fromDisplayName = $personName !== "" ? $personName : ($brandName !== "" ? $brandName : "Web");
    }
    $smtpCfg = $mailConfig;
    $smtpCfg["from_email"] = $fromEmail;
    $smtpCfg["from_name"] = $fromDisplayName;

    return send_mail_smtp($smtpCfg, $toEmail, $subject, $bodyPlain, $contactEmail);
}

/**
 * Activa la cuenta al abrir el enlace del correo. @return null|string error
 */
function client_try_register_confirm_token(mysqli $conn, string $rawToken): ?string
{
    $rawToken = trim($rawToken);
    if (strlen($rawToken) < 16 || strlen($rawToken) > 512) {
        return "Enlace de verificación no válido.";
    }
    $th = hash("sha256", $rawToken);
    $stmt = $conn->prepare(
        "SELECT email, password_hash, display_name FROM client_registration_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1"
    );
    if ($stmt === false) {
        return "No se pudo validar el enlace.";
    }
    $stmt->bind_param("s", $th);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row === null) {
        return "El enlace no es válido o ha caducado (48 horas). Vuelve a registrarte.";
    }
    $email = trim((string)($row["email"] ?? ""));
    $hash = (string)($row["password_hash"] ?? "");
    $displayName = trim(mb_substr((string)($row["display_name"] ?? ""), 0, 180, "UTF-8"));
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL) || $hash === "") {
        $delB = $conn->prepare("DELETE FROM client_registration_tokens WHERE token_hash = ?");
        if ($delB !== false) {
            $delB->bind_param("s", $th);
            $delB->execute();
            $delB->close();
        }

        return "Datos de verificación no válidos. Regístrate de nuevo.";
    }
    $dup = $conn->prepare("SELECT id FROM clients WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
    if ($dup === false) {
        return "No se pudo comprobar el correo.";
    }
    $dup->bind_param("s", $email);
    $dup->execute();
    $dupRes = $dup->get_result();
    $dup->close();
    if ($dupRes && $dupRes->num_rows > 0) {
        $delE = $conn->prepare("DELETE FROM client_registration_tokens WHERE LOWER(TRIM(email)) = LOWER(?)");
        if ($delE !== false) {
            $delE->bind_param("s", $email);
            $delE->execute();
            $delE->close();
        }

        return "Ese correo ya está registrado. Inicia sesión.";
    }
    $ins = $conn->prepare(
        "INSERT INTO clients (email, password, display_name, is_active, email_notify_outbound) VALUES (?, ?, ?, 1, 1)"
    );
    if ($ins === false) {
        return "No se pudo crear la cuenta.";
    }
    $ins->bind_param("sss", $email, $hash, $displayName);
    if (!$ins->execute()) {
        $ins->close();
        if ($conn->errno === 1062) {
            $delE2 = $conn->prepare("DELETE FROM client_registration_tokens WHERE LOWER(TRIM(email)) = LOWER(?)");
            if ($delE2 !== false) {
                $delE2->bind_param("s", $email);
                $delE2->execute();
                $delE2->close();
            }

            return "Ese correo ya está registrado. Inicia sesión.";
        }

        return "No se pudo crear la cuenta.";
    }
    $newId = (int)$ins->insert_id;
    $ins->close();
    if ($newId <= 0) {
        return "No se pudo crear la cuenta.";
    }
    $delT = $conn->prepare("DELETE FROM client_registration_tokens WHERE LOWER(TRIM(email)) = LOWER(?)");
    if ($delT !== false) {
        $delT->bind_param("s", $email);
        $delT->execute();
        $delT->close();
    }
    client_set_session_credentials($newId, $email, $displayName);

    if (isset($_SESSION["client_reg_pending"]) && is_array($_SESSION["client_reg_pending"])) {
        $pendEm = trim((string)($_SESSION["client_reg_pending"]["email"] ?? ""));
        if ($pendEm !== "" && strcasecmp($pendEm, $email) === 0) {
            unset($_SESSION["client_reg_pending"]);
        }
    }

    return null;
}

/**
 * @return array{ok:true, awaiting_verification?:true, email?:string}|array{ok:false, error:string}|array{ok:false, need_email_choice:true}
 */
function client_try_register(
    mysqli $conn,
    string $email,
    string $password,
    string $passwordConfirm,
    string $displayName
): array {
    client_register_pending_prune();

    $email = trim($email);
    $displayName = trim(mb_substr($displayName, 0, 180, "UTF-8"));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "error" => "Correo no válido."];
    }
    if ($password !== $passwordConfirm) {
        return ["ok" => false, "error" => "Las claves no coinciden."];
    }
    if (!client_strong_password($password)) {
        return ["ok" => false, "error" => "La clave debe tener al menos 10 caracteres e incluir mayúscula, minúscula y número."];
    }

    $dup = $conn->prepare("SELECT id FROM clients WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
    if ($dup === false) {
        return ["ok" => false, "error" => "No se pudo comprobar el correo."];
    }
    $dup->bind_param("s", $email);
    $dup->execute();
    $dupRes = $dup->get_result();
    $dup->close();
    if ($dupRes && $dupRes->num_rows > 0) {
        return ["ok" => false, "error" => "Ese correo ya está registrado. Inicia sesión."];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return ["ok" => false, "error" => "No se pudo guardar la clave."];
    }

    $conn->query("DELETE FROM client_registration_tokens WHERE expires_at < NOW()");
    $delOld = $conn->prepare("DELETE FROM client_registration_tokens WHERE LOWER(TRIM(email)) = LOWER(?)");
    if ($delOld !== false) {
        $delOld->bind_param("s", $email);
        $delOld->execute();
        $delOld->close();
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash("sha256", $rawToken);
    $expiresAt = date("Y-m-d H:i:s", time() + 48 * 3600);
    $insTok = $conn->prepare(
        "INSERT INTO client_registration_tokens (email, token_hash, password_hash, display_name, expires_at) VALUES (?, ?, ?, ?, ?)"
    );
    if ($insTok === false) {
        return ["ok" => false, "error" => "No se pudo iniciar el registro."];
    }
    $insTok->bind_param("sssss", $email, $tokenHash, $hash, $displayName, $expiresAt);
    if (!$insTok->execute()) {
        $insTok->close();

        return ["ok" => false, "error" => "No se pudo iniciar el registro."];
    }
    $insTok->close();

    $base = rtrim(app_public_base_url(), "/");
    $verifyUrl = $base . "/index.php?client_verify=" . rawurlencode($rawToken);
    $subj = "Confirma tu cuenta en la web";
    $body = "Hola,\n\n"
        . "Activa la cuenta abriendo este enlace (48 h). Hasta entonces no existe en el sitio:\n\n"
        . $verifyUrl . "\n\n"
        . "Si no fuiste tú, ignora el mensaje. Si no llega nada, revisa spam o en la web: «sin correo» / otro correo.\n";

    $sent = client_registration_send_plain_smtp($conn, $email, $subj, $body);
    if (!$sent) {
        $d = $conn->prepare("DELETE FROM client_registration_tokens WHERE token_hash = ?");
        if ($d !== false) {
            $d->bind_param("s", $tokenHash);
            $d->execute();
            $d->close();
        }
        $_SESSION["client_reg_pending"] = [
            "email" => $email,
            "display_name" => $displayName,
            "password_hash" => $hash,
            "ts" => time(),
            "verification_sent" => false,
        ];

        return ["ok" => false, "need_email_choice" => true];
    }

    // No usar client_register_retry_clear aquí: si el envío falló antes con el mismo correo,
    // la sesión pendiente seguiría activa y borraría el token recién insertado en la base de datos.
    $_SESSION["client_reg_pending"] = [
        "email" => $email,
        "display_name" => $displayName,
        "password_hash" => $hash,
        "ts" => time(),
        "verification_sent" => true,
    ];

    return ["ok" => true, "awaiting_verification" => true, "email" => $email];
}

/** Crea la cuenta sin intentar más correos salientes hacia el cliente (solo bandeja web). @return null|string error */
function client_try_register_finalize_no_mail(mysqli $conn): ?string
{
    client_register_pending_prune();
    $p = client_register_pending_get();
    if ($p === null) {
        return "La solicitud de registro expiró o no existe. Vuelve a rellenar el formulario.";
    }
    $email = trim((string)($p["email"] ?? ""));
    $displayName = trim(mb_substr((string)($p["display_name"] ?? ""), 0, 180, "UTF-8"));
    $hash = (string)($p["password_hash"] ?? "");
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        client_register_retry_clear($conn);

        return "Datos de registro no válidos. Inténtalo de nuevo.";
    }
    if ($hash === "") {
        client_register_retry_clear($conn);

        return "Datos de registro incompletos. Inténtalo de nuevo.";
    }
    $dup = $conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
    if ($dup === false) {
        return "No se pudo comprobar el correo.";
    }
    $dup->bind_param("s", $email);
    $dup->execute();
    $dupRes = $dup->get_result();
    $dup->close();
    if ($dupRes && $dupRes->num_rows > 0) {
        client_register_retry_clear($conn);

        return "Ese correo ya está registrado. Inicia sesión.";
    }
    $ins = $conn->prepare(
        "INSERT INTO clients (email, password, display_name, is_active, email_notify_outbound) VALUES (?, ?, ?, 1, 0)"
    );
    if ($ins === false) {
        return "No se pudo crear la cuenta.";
    }
    $ins->bind_param("sss", $email, $hash, $displayName);
    if (!$ins->execute()) {
        $ins->close();
        if ($conn->errno === 1062) {
            client_register_retry_clear($conn);

            return "Ese correo ya está registrado. Inicia sesión.";
        }

        return "No se pudo crear la cuenta.";
    }
    $newId = (int)$ins->insert_id;
    $ins->close();
    if ($newId <= 0) {
        return "No se pudo crear la cuenta.";
    }
    client_register_retry_clear($conn);
    client_set_session_credentials($newId, $email, $displayName);

    if (isset($_SESSION["client_reg_pending"]) && is_array($_SESSION["client_reg_pending"])) {
        $pendEm = trim((string)($_SESSION["client_reg_pending"]["email"] ?? ""));
        if ($pendEm !== "" && strcasecmp($pendEm, $email) === 0) {
            unset($_SESSION["client_reg_pending"]);
        }
    }

    return null;
}

/**
 * Comprueba que la sesión de cliente siga siendo válida en esta BD (id existe y activo).
 */
function client_portal_resume_session(mysqli $conn): bool
{
    $id = (int)($_SESSION["client_id"] ?? 0);
    if ($id <= 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT id, email, display_name FROM clients WHERE id = ? AND is_active = 1 LIMIT 1");
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if (!$res || $res->num_rows !== 1) {
        $_SESSION = [];
        return false;
    }

    $row = $res->fetch_assoc();
    $_SESSION["client_id"] = (int)$row["id"];
    $_SESSION["client_email"] = (string)($row["email"]);
    $_SESSION["client_display_name"] = (string)($row["display_name"] ?? "");

    return true;
}

function client_strong_password(string $p): bool
{
    if (strlen($p) < 10) {
        return false;
    }
    return (bool)preg_match('/[a-z]/', $p)
        && (bool)preg_match('/[A-Z]/', $p)
        && (bool)preg_match('/\d/', $p);
}
