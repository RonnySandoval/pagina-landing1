<?php
declare(strict_types=1);

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

/**
 * @return null|string null = éxito (sesión iniciada)
 */
function client_try_register(
    mysqli $conn,
    string $email,
    string $password,
    string $passwordConfirm,
    string $displayName
): ?string {
    $email = trim($email);
    $displayName = trim(mb_substr($displayName, 0, 180, "UTF-8"));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Correo no válido.";
    }
    if ($password !== $passwordConfirm) {
        return "Las claves no coinciden.";
    }
    if (!client_strong_password($password)) {
        return "La clave debe tener al menos 10 caracteres e incluir mayúscula, minúscula y número.";
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
        return "Ese correo ya está registrado. Inicia sesión.";
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return "No se pudo guardar la clave.";
    }

    $ins = $conn->prepare("INSERT INTO clients (email, password, display_name, is_active) VALUES (?, ?, ?, 1)");
    if ($ins === false) {
        return "No se pudo crear la cuenta.";
    }
    $ins->bind_param("sss", $email, $hash, $displayName);
    if (!$ins->execute()) {
        $ins->close();
        if ($conn->errno === 1062) {
            return "Ese correo ya está registrado. Inicia sesión.";
        }
        return "No se pudo crear la cuenta.";
    }
    $newId = (int)$ins->insert_id;
    $ins->close();

    if ($newId <= 0) {
        return "No se pudo crear la cuenta.";
    }

    client_set_session_credentials($newId, $email, $displayName);
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
