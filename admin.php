<?php
declare(strict_types=1);

// Panel admin: script admin.php. Rutas y ejemplos local/producción: ver app_urls.php (bloque «MAPA DE RUTAS»).
// Aislar sesiones por landing.
// Si varias landings comparten host (p. ej. localhost/pag-laura, localhost/pag-juan),
// la cookie PHPSESSID por defecto (path "/") se comparte entre ellas y los datos
// de sesión se "cuelan": admin_email de una landing aparece logueado en otra y la
// pantalla "Credenciales Admin" muestra/usa el correo equivocado.
// Solución: nombre de cookie único por instalación + cookie path = directorio del script.
$_adminSessionScopeId = substr(md5(__DIR__), 0, 8);
$_adminSessionCookiePath = '/';
$_adminSessionScriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
if ($_adminSessionScriptName !== '') {
    $_adminSessionRawDir = str_replace('\\', '/', dirname($_adminSessionScriptName));
    if ($_adminSessionRawDir !== '' && $_adminSessionRawDir !== '.') {
        $_adminSessionCookiePath = rtrim($_adminSessionRawDir, '/') . '/';
    }
}
session_set_cookie_params([
    'path' => $_adminSessionCookiePath,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('admin_session_' . $_adminSessionScopeId);
session_start();

require __DIR__ . "/db.php";
require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/smtp_mail.php";

$adminInboxUi = app_feature_enabled("admin_inbox");
$adminWhatsappClicksUi = app_feature_enabled("admin_whatsapp_clicks");
$adminExpertAgendaUi = app_feature_enabled("expert_agenda");

$adminAssetStylesVer = is_file(__DIR__ . "/styles.css") ? (string) filemtime(__DIR__ . "/styles.css") : "1";
$adminAssetScriptVer = is_file(__DIR__ . "/script.js") ? (string) filemtime(__DIR__ . "/script.js") : "1";

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

/**
 * Hilos de contacto por raíz de in_reply_to (misma lógica que el área cliente).
 *
 * @param array<int, list<array<string, mixed>>> $repliesByMessageId
 * @return list<array{root_id:int, messages:list<array<string, mixed>>, latest_ts:int, has_admin_reply:bool}>
 */
function admin_group_messages_threads(array $messages, array $repliesByMessageId): array
{
    $byId = [];
    foreach ($messages as $m) {
        $id = (int)($m["id"] ?? 0);
        if ($id > 0) {
            $byId[$id] = $m;
        }
    }
    $buckets = [];
    foreach ($messages as $m) {
        $mid = (int)($m["id"] ?? 0);
        if ($mid <= 0) {
            continue;
        }
        $root = $mid;
        $p = (int)($m["in_reply_to"] ?? 0);
        $guard = 0;
        while ($p > 0 && isset($byId[$p]) && $guard++ < 64) {
            $root = $p;
            $p = (int)($byId[$p]["in_reply_to"] ?? 0);
        }
        if (!isset($buckets[$root])) {
            $buckets[$root] = [];
        }
        $buckets[$root][] = $m;
    }
    $threads = [];
    foreach ($buckets as $rootId => $rows) {
        usort($rows, static function (array $a, array $b): int {
            $ta = strtotime((string)($a["created_at"] ?? "")) ?: 0;
            $tb = strtotime((string)($b["created_at"] ?? "")) ?: 0;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ((int)($a["id"] ?? 0)) <=> ((int)($b["id"] ?? 0));
        });
        $latestTs = 0;
        foreach ($rows as $r) {
            $t = strtotime((string)($r["created_at"] ?? "")) ?: 0;
            if ($t > $latestTs) {
                $latestTs = $t;
            }
        }
        $hasAdmin = false;
        foreach ($rows as $r) {
            $rid = (int)($r["id"] ?? 0);
            if ($rid > 0 && !empty($repliesByMessageId[$rid])) {
                $hasAdmin = true;
                break;
            }
        }
        $threads[] = [
            "root_id" => (int)$rootId,
            "messages" => $rows,
            "latest_ts" => $latestTs,
            "has_admin_reply" => $hasAdmin,
        ];
    }
    usort($threads, static function (array $a, array $b): int {
        return ($b["latest_ts"] ?? 0) <=> ($a["latest_ts"] ?? 0);
    });

    return $threads;
}

/**
 * Sin leídos y total en la misma ventana que la lista del inbox (últimos 100).
 *
 * @return array{unread:int,total:int}
 */
function admin_contact_inbox_counts(mysqli $conn): array
{
    $unread = 0;
    $total = 0;
    $q = $conn->query(
        "SELECT COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS u, COUNT(*) AS t
         FROM (
             SELECT is_read FROM contact_messages ORDER BY created_at DESC, id DESC LIMIT 100
         ) AS inbox_window"
    );
    if ($q) {
        $row = $q->fetch_assoc();
        if (is_array($row)) {
            $unread = (int)($row["u"] ?? 0);
            $total = (int)($row["t"] ?? 0);
        }
    }

    return ["unread" => $unread, "total" => $total];
}

/**
 * @return array{unread:int,total:int}
 */
function admin_whatsapp_inbox_counts(mysqli $conn): array
{
    $unread = 0;
    $total = 0;
    $q = $conn->query(
        "SELECT COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS u, COUNT(*) AS t
         FROM (
             SELECT is_read FROM contact_whatsapp_clicks ORDER BY created_at DESC, id DESC LIMIT 100
         ) AS wa_window"
    );
    if ($q) {
        $row = $q->fetch_assoc();
        if (is_array($row)) {
            $unread = (int)($row["u"] ?? 0);
            $total = (int)($row["t"] ?? 0);
        }
    }

    return ["unread" => $unread, "total" => $total];
}

/**
 * Correo al visitante (respuesta desde el admin). Reply-To = correo receptor del sitio.
 * El From SMTP se toma de mail_config (from_email o username si es correo).
 *
 * Si host, usuario, contraseña y remitente están bien configurados, se usa SMTP
 * aunque `use_smtp` sea false (útil cuando solo el formulario público dejaba mail()
 * y las respuestas del panel necesitan el mismo servidor SMTP).
 *
 * @return array{ok: bool, code: string} code: ok | smtp_failed | php_mail_failed | bad_config
 */
function admin_send_visitor_reply_mail(
    array $mailConfig,
    string $to,
    string $subject,
    string $bodyPlain,
    string $replyToEmail,
    string $fromDisplayName
): array {
    $replyToEmail = trim($replyToEmail);
    $fromDisplayName = trim($fromDisplayName);
    $fromEmail = mail_config_resolve_smtp_from($mailConfig);
    if ($replyToEmail === "" || !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "code" => "bad_config"];
    }
    if ($fromEmail === "" || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "code" => "bad_config"];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "code" => "bad_config"];
    }

    $host = trim((string)($mailConfig["host"] ?? ""));
    $user = trim((string)($mailConfig["username"] ?? ""));
    $pass = preg_replace('/\s+/', '', (string)($mailConfig["password"] ?? ""));
    $smtpFullyConfigured = $host !== "" && $user !== "" && $pass !== "";

    // Respuestas del admin: SMTP si la config está completa (no dependemos solo de use_smtp).
    $smtpReady = $smtpFullyConfigured && $fromEmail !== "";

    if ($smtpReady) {
        $smtpCfg = $mailConfig;
        $smtpCfg["from_email"] = $fromEmail;
        $smtpCfg["from_name"] = $fromDisplayName;

        $ok = send_mail_smtp($smtpCfg, $to, $subject, $bodyPlain, $replyToEmail);
        if ($ok) {
            return ["ok" => true, "code" => "smtp"];
        }
        if (!empty($mailConfig["debug"]) && !empty($mailConfig["debug_log"])) {
            smtp_debug_log($mailConfig, "Respuesta admin: envio SMTP fallo (To=" . $to . ")");
        }
        $fromHeaderLine = smtp_format_from_header($fromDisplayName !== "" ? $fromDisplayName : "Web", $fromEmail);
        $headers = "From: " . $fromHeaderLine . "\r\n";
        $headers .= "Reply-To: " . $replyToEmail . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $mailOk = (bool)@mail($to, $subject, $bodyPlain, $headers);
        if ($mailOk) {
            return ["ok" => true, "code" => "php_mail_fallback"];
        }

        return ["ok" => false, "code" => "smtp_failed"];
    }

    $fromHeaderLine = smtp_format_from_header($fromDisplayName !== "" ? $fromDisplayName : "Web", $fromEmail);
    $headers = "From: " . $fromHeaderLine . "\r\n";
    $headers .= "Reply-To: " . $replyToEmail . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    $mailOk = (bool)@mail($to, $subject, $bodyPlain, $headers);

    return $mailOk ? ["ok" => true, "code" => "php_mail"] : ["ok" => false, "code" => "php_mail_failed"];
}

function admin_ajax_trace(string $message): void
{
    $path = __DIR__ . "/contact_send_trace.log";
    $line = date("c") . " [admin] " . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function admin_set_flash(string $type, string $msg): void
{
    $_SESSION["admin_flash"] = ["type" => $type, "msg" => $msg];
}

function admin_consume_flash(): array
{
    if (isset($_SESSION["admin_flash"]) && is_array($_SESSION["admin_flash"])) {
        $flash = $_SESSION["admin_flash"];
        unset($_SESSION["admin_flash"]);
        return [
            "type" => (string)($flash["type"] ?? ""),
            "msg" => (string)($flash["msg"] ?? "")
        ];
    }
    return ["type" => "", "msg" => ""];
}

function admin_redirect_after_action(): void
{
    header("Location: admin.php");
    exit;
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
        $replyTo = $smtpFrom;
        $smtpCfg = $mailConfig;
        $smtpCfg["from_email"] = $smtpFrom;
        $smtpSent = send_mail_smtp($smtpCfg, $toEmail, $subject, $body, $replyTo);
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

/**
 * Guarda un archivo subido como imagen en uploads/<subdir>/. Devuelve la ruta
 * relativa (uploads/<subdir>/<nombre>) lista para guardar en BD.
 *
 * Sirve tanto para imágenes de servicios como para el logo del sitio: el
 * único cambio es la subcarpeta de destino y el prefijo del nombre generado.
 */
function storeUploadedImage(array $file, string $subdir, string $prefix): array {
    if (!isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) {
        return ["path" => null, "error" => ""];
    }
    if ($file["error"] !== UPLOAD_ERR_OK) {
        return ["path" => null, "error" => "No se pudo subir la imagen."];
    }

    $tmpPath = $file["tmp_name"] ?? "";
    if ($tmpPath === "" || !is_uploaded_file($tmpPath)) {
        return ["path" => null, "error" => "Archivo de imagen inválido."];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmpPath) : "";
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedMimeMap = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
        "image/gif" => "gif",
        "image/svg+xml" => "svg",
    ];
    if (!isset($allowedMimeMap[$mime])) {
        return ["path" => null, "error" => "Formato no permitido. Usa JPG, PNG, WEBP, GIF o SVG."];
    }

    $safeSubdir = preg_replace('/[^a-z0-9_-]/i', '', $subdir);
    if ($safeSubdir === "") {
        return ["path" => null, "error" => "Subcarpeta de uploads inválida."];
    }

    $uploadsDir = __DIR__ . "/uploads/" . $safeSubdir;
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
        return ["path" => null, "error" => "No se pudo crear la carpeta de imágenes."];
    }

    $extension = $allowedMimeMap[$mime];
    $fileName = $prefix . bin2hex(random_bytes(8)) . "." . $extension;
    $targetPath = $uploadsDir . "/" . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ["path" => null, "error" => "No se pudo guardar la imagen en el servidor."];
    }

    return ["path" => "uploads/" . $safeSubdir . "/" . $fileName, "error" => ""];
}

function storeServiceImage(array $file): array {
    return storeUploadedImage($file, "services", "service_");
}

function storeLogoImage(array $file): array {
    return storeUploadedImage($file, "logo", "logo_");
}

$message = "";
$error = "";
$messageAlertClass = "alert-success";
$resetTokenFromUrl = trim((string)($_GET["reset_token"] ?? ""));
// Cuando el usuario envía el form de "Olvidaste tu clave?" (POST de reset request),
// preservamos esa vista al re-renderizar para mostrarle el mensaje genérico.
$showResetView = (isset($_POST["action"]) && $_POST["action"] === "request_admin_password_reset");

$adminFlash = admin_consume_flash();
if ($adminFlash["msg"] !== "") {
    if ($adminFlash["type"] === "error") {
        $error = $adminFlash["msg"];
    } elseif ($adminFlash["type"] === "warning") {
        $message = $adminFlash["msg"];
        $messageAlertClass = "alert-warning";
    } else {
        $message = $adminFlash["msg"];
        $messageAlertClass = "alert-success";
    }
}
$iconOptions = [
    "fa-solid fa-star" => "Estrella",
    "fa-solid fa-code" => "Codigo",
    "fa-solid fa-book-open-reader" => "Asesoria / Estudio",
    "fa-solid fa-guitar" => "Guitarra",
    "fa-solid fa-laptop-code" => "Desarrollo web",
    "fa-solid fa-graduation-cap" => "Educacion",
    "fa-solid fa-chalkboard-user" => "Tutor personalizado",
    "fa-solid fa-lightbulb" => "Ideas / Creatividad",
    "fa-solid fa-brain" => "Pensamiento critico",
    "fa-solid fa-calculator" => "Matematicas",
    "fa-solid fa-language" => "Idiomas",
    "fa-solid fa-music" => "Musica",
    "fa-solid fa-microphone-lines" => "Voz / Oratoria",
    "fa-solid fa-pen-ruler" => "Diseno",
    "fa-solid fa-briefcase" => "Asesoria profesional",
    "fa-solid fa-chart-line" => "Crecimiento",
    "fa-solid fa-rocket" => "Impulso de proyectos",
    "fa-solid fa-gear" => "Soporte tecnico",
    "fa-solid fa-headset" => "Atencion / Soporte",
    "fa-solid fa-handshake" => "Acompanamiento",
    "fa-solid fa-bullseye" => "Objetivos",
    "fa-solid fa-wand-magic-sparkles" => "Soluciones creativas"
];

if (isset($_POST["action"]) && $_POST["action"] === "login") {
    $email = trim($_POST["email"] ?? "");
    $password = trim($_POST["password"] ?? "");

    $stmt = $conn->prepare("SELECT id, password FROM admins WHERE email = ? LIMIT 1");
    $adminId = 0;
    $passwordFromDb = "";
    $isValidPassword = false;
    $needsRehash = false;
    $newHashedPassword = "";

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $adminRow = $result->fetch_assoc();
        $adminId = (int)($adminRow["id"] ?? 0);
        $passwordFromDb = (string)($adminRow["password"] ?? "");
        if ($passwordFromDb !== "") {
            $isValidPassword = password_verify($password, $passwordFromDb);
            if ($isValidPassword) {
                $needsRehash = password_needs_rehash($passwordFromDb, PASSWORD_DEFAULT);
            } elseif (hash_equals($passwordFromDb, $password)) {
                // Compatibilidad con contraseñas antiguas guardadas en texto plano.
                $isValidPassword = true;
                $needsRehash = true;
            }
        }
    }

    if ($isValidPassword) {
        if ($needsRehash && $adminId > 0) {
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
        $_SESSION["admin_logged"] = true;
        $_SESSION["admin_email"] = $email;
        header("Location: admin.php");
        exit;
    }
    $error = "Credenciales invalidas.";
}

if (isset($_POST["action"]) && $_POST["action"] === "request_admin_password_reset") {
    $email = trim((string)($_POST["reset_email"] ?? ""));
    $genericMessage = "Si el correo existe, enviamos un enlace de recuperacion.";
    admin_ajax_trace("password_reset request email=" . $email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_ajax_trace("password_reset abort invalid_email");
    } else {
        $stmt = $conn->prepare("SELECT id, email FROM admins WHERE email = ? LIMIT 1");
        if ($stmt === false) {
            admin_ajax_trace("password_reset abort prepare_failed err=" . $conn->error);
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();

            if (!$result || $result->num_rows !== 1) {
                admin_ajax_trace("password_reset abort no_admin_with_this_email (debe coincidir con el correo del panel)");
            } else {
                $adminRow = $result->fetch_assoc();
                $adminId = (int)($adminRow["id"] ?? 0);
                $adminEmail = (string)($adminRow["email"] ?? "");

                if ($adminId <= 0 || $adminEmail === "") {
                    admin_ajax_trace("password_reset abort bad_admin_row");
                } else {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash("sha256", $token);
                    $expiresAt = date("Y-m-d H:i:s", time() + (30 * 60));
                    $insertStmt = $conn->prepare("INSERT INTO admin_password_resets (admin_id, token_hash, expires_at) VALUES (?, ?, ?)");
                    if ($insertStmt === false) {
                        admin_ajax_trace("password_reset abort insert_prepare_failed err=" . $conn->error);
                    } else {
                        $insertStmt->bind_param("iss", $adminId, $tokenHash, $expiresAt);
                        $inserted = $insertStmt->execute();
                        $insertErr = $insertStmt->error;
                        $insertStmt->close();
                        if (!$inserted) {
                            admin_ajax_trace("password_reset abort insert_execute_failed err=" . $insertErr);
                        } else {
                            $baseUrl = app_public_base_url();
                            $resetUrl = $baseUrl . "/admin.php?reset_token=" . urlencode($token);
                            admin_ajax_trace("password_reset token_created admin_id={$adminId} expires_at={$expiresAt}");
                            admin_ajax_trace("password_reset link=" . $resetUrl);
                            $sent = admin_send_password_reset_email($adminEmail, $resetUrl);
                            admin_ajax_trace("password_reset final_send=" . ($sent ? "ok" : "fail"));
                        }
                    }
                }
            }
        }
    }

    $message = $genericMessage;
}

if (isset($_POST["action"]) && $_POST["action"] === "reset_admin_password") {
    $token = trim((string)($_POST["reset_token"] ?? ""));
    $newPassword = (string)($_POST["new_admin_password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_admin_password"] ?? "");

    if ($token === "") {
        $error = "Token de recuperacion invalido.";
    } elseif ($newPassword === "" || $confirmPassword === "") {
        $error = "Completa los campos de nueva clave.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "La nueva clave y su confirmacion no coinciden.";
    } elseif (strlen($newPassword) < 10) {
        $error = "La nueva clave debe tener al menos 10 caracteres.";
    } elseif (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        $error = "La nueva clave debe incluir mayuscula, minuscula y numero.";
    } else {
        $tokenHash = hash("sha256", $token);
        $resetStmt = $conn->prepare("
            SELECT id, admin_id
            FROM admin_password_resets
            WHERE token_hash = ?
              AND used_at IS NULL
              AND expires_at >= NOW()
            LIMIT 1
        ");
        if ($resetStmt === false) {
            $error = "No se pudo validar el token de recuperacion.";
        } else {
            $resetStmt->bind_param("s", $tokenHash);
            $resetStmt->execute();
            $resetResult = $resetStmt->get_result();
            $resetStmt->close();

            if (!$resetResult || $resetResult->num_rows !== 1) {
                $error = "El enlace de recuperacion no es valido o ya expiro.";
            } else {
                $resetRow = $resetResult->fetch_assoc();
                $resetId = (int)($resetRow["id"] ?? 0);
                $adminId = (int)($resetRow["admin_id"] ?? 0);
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                if ($adminId <= 0 || $resetId <= 0 || $hashedPassword === false) {
                    $error = "No se pudo restablecer la clave.";
                } else {
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

                        $invalidateStmt = $conn->prepare("
                            UPDATE admin_password_resets
                            SET used_at = NOW()
                            WHERE admin_id = ?
                              AND used_at IS NULL
                              AND id <> ?
                        ");
                        if ($invalidateStmt !== false) {
                            $invalidateStmt->bind_param("ii", $adminId, $resetId);
                            $invalidateStmt->execute();
                            $invalidateStmt->close();
                        }

                        $conn->commit();
                        $message = "Clave restablecida. Ya puedes iniciar sesion.";
                        $resetTokenFromUrl = "";
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = "No se pudo restablecer la clave.";
                    }
                }
            }
        }
    }
}

if (isset($_GET["logout"])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

$isLogged = isset($_SESSION["admin_logged"]) && $_SESSION["admin_logged"] === true;

// Defensa adicional contra sesiones "huérfanas" entre landings.
// Si la cookie de una landing previa sobrevivió (p. ej. instalaciones anteriores al
// aislamiento, o usuarios que comparten navegador entre /pag-laura y /pag-juan),
// validamos que admin_email exista en ESTA BD. Si no, descartamos la sesión.
if ($isLogged) {
    $_sessionEmail = (string)($_SESSION["admin_email"] ?? "");
    $_sessionValid = false;
    if ($_sessionEmail !== "") {
        $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
        if ($stmt !== false) {
            $stmt->bind_param("s", $_sessionEmail);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $_sessionValid = true;
            }
            $stmt->close();
        }
    }
    if (!$_sessionValid) {
        $_SESSION = [];
        $isLogged = false;
    }
}

$adminInboxWide = $isLogged && $adminInboxUi && isset($_GET["inbox"]) && (string)$_GET["inbox"] === "1";

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "save_settings") {
    $personName = trim($_POST["person_name"] ?? "");
    $brandName = trim($_POST["brand_name"] ?? "");
    $heroTitle = trim($_POST["hero_title"] ?? "");
    $heroIntro = trim($_POST["hero_intro"] ?? "");
    $aboutText = trim($_POST["about_text"] ?? "");
    $contactIntro = trim($_POST["contact_intro"] ?? "");
    $contactEmail = trim($_POST["contact_email"] ?? "");
    $waCountryRaw = trim((string)($_POST["contact_whatsapp_country"] ?? ""));
    $waLocalRaw = trim((string)($_POST["contact_whatsapp_local"] ?? ""));
    $waCountryDigits = preg_replace('/\D+/', '', $waCountryRaw) ?? "";
    $waLocalDigits = preg_replace('/\D+/', '', $waLocalRaw) ?? "";
    $waCountryDigits = substr($waCountryDigits, 0, 3);
    $waLocalDigits = substr($waLocalDigits, 0, 32);
    // wa.me: solo dígitos E.164. Con código de país: columna país + número nacional.
    // Sin código de país: todo en contact_whatsapp (compatibilidad con datos antiguos).
    if ($waLocalDigits === "") {
        $contactWhatsappForDb = null;
        $contactWhatsappCountryForDb = null;
    } elseif ($waCountryDigits === "") {
        $contactWhatsappForDb = $waLocalDigits;
        $contactWhatsappCountryForDb = null;
    } else {
        $contactWhatsappForDb = $waLocalDigits;
        $contactWhatsappCountryForDb = $waCountryDigits;
    }
    $footerText = trim($_POST["footer_text"] ?? "");

    // Logo: la ruta actual viaja en hidden para preservarla si no se sube otra.
    // Si se sube un archivo nuevo, reemplaza. Si se marca "remove_logo_image"
    // o se sube un reemplazo, el archivo viejo se borra del disco.
    $currentLogoPath = trim((string)($_POST["current_logo_image_path"] ?? ""));
    $logoPath = $currentLogoPath;
    $logoError = "";
    $logoUpload = storeLogoImage($_FILES["logo_image_file"] ?? []);
    if ($logoUpload["error"] !== "") {
        $logoError = $logoUpload["error"];
    } elseif ($logoUpload["path"] !== null) {
        if ($currentLogoPath !== "" && is_file(__DIR__ . "/" . $currentLogoPath)) {
            @unlink(__DIR__ . "/" . $currentLogoPath);
        }
        $logoPath = $logoUpload["path"];
    } elseif (!empty($_POST["remove_logo_image"])) {
        if ($currentLogoPath !== "" && is_file(__DIR__ . "/" . $currentLogoPath)) {
            @unlink(__DIR__ . "/" . $currentLogoPath);
        }
        $logoPath = "";
    }

    if ($logoError !== "") {
        $error = $logoError;
    } else {
        $logoForDb = $logoPath !== "" ? $logoPath : null;
        $stmt = $conn->prepare("
          UPDATE site_settings
          SET person_name = ?, brand_name = ?, hero_title = ?, hero_intro = ?, about_text = ?, contact_intro = ?, contact_email = ?, contact_whatsapp = ?, contact_whatsapp_country_code = ?, footer_text = ?, logo_image_path = ?
          WHERE id = 1
        ");
        $stmt->bind_param("sssssssssss", $personName, $brandName, $heroTitle, $heroIntro, $aboutText, $contactIntro, $contactEmail, $contactWhatsappForDb, $contactWhatsappCountryForDb, $footerText, $logoForDb);
        $stmt->execute();
        $message = "Configuración general actualizada.";
    }
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "change_admin_credentials") {
    $currentSessionEmail = (string)($_SESSION["admin_email"] ?? "");
    $newEmail = trim((string)($_POST["new_admin_email"] ?? ""));
    $currentPassword = (string)($_POST["current_admin_password"] ?? "");
    $newPassword = (string)($_POST["new_admin_password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_admin_password"] ?? "");

    if ($currentSessionEmail === "") {
        $error = "Sesion invalida. Vuelve a iniciar sesion.";
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = "Ingresa un correo valido para el admin.";
    } elseif ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
        $error = "Completa todos los campos para cambiar credenciales.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "La nueva clave y su confirmacion no coinciden.";
    } elseif (strlen($newPassword) < 10) {
        $error = "La nueva clave debe tener al menos 10 caracteres.";
    } elseif (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        $error = "La nueva clave debe incluir mayuscula, minuscula y numero.";
    } else {
        $stmt = $conn->prepare("SELECT id, password FROM admins WHERE email = ? LIMIT 1");
        if ($stmt === false) {
            $error = "No se pudo validar el usuario admin.";
        } else {
            $stmt->bind_param("s", $currentSessionEmail);
            $stmt->execute();
            $adminResult = $stmt->get_result();
            $stmt->close();

            if (!$adminResult || $adminResult->num_rows !== 1) {
                $error = "No se encontro la cuenta admin actual.";
            } else {
                $adminRow = $adminResult->fetch_assoc();
                $adminId = (int)($adminRow["id"] ?? 0);
                $storedPassword = (string)($adminRow["password"] ?? "");
                $validCurrentPassword = false;

                if ($storedPassword !== "") {
                    $validCurrentPassword = password_verify($currentPassword, $storedPassword) || hash_equals($storedPassword, $currentPassword);
                }

                if (!$validCurrentPassword) {
                    $error = "La clave actual no es correcta.";
                } else {
                    $emailCheckStmt = $conn->prepare("SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1");
                    if ($emailCheckStmt === false) {
                        $error = "No se pudo validar el nuevo correo.";
                    } else {
                        $emailCheckStmt->bind_param("si", $newEmail, $adminId);
                        $emailCheckStmt->execute();
                        $emailExistsResult = $emailCheckStmt->get_result();
                        $emailCheckStmt->close();

                        if ($emailExistsResult && $emailExistsResult->num_rows > 0) {
                            $error = "Ese correo ya esta en uso por otro admin.";
                        } else {
                            $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                            if ($newHashedPassword === false) {
                                $error = "No se pudo asegurar la nueva clave.";
                            } else {
                                $updateStmt = $conn->prepare("UPDATE admins SET email = ?, password = ? WHERE id = ?");
                                if ($updateStmt === false) {
                                    $error = "No se pudieron actualizar las credenciales.";
                                } else {
                                    $updateStmt->bind_param("ssi", $newEmail, $newHashedPassword, $adminId);
                                    $updated = $updateStmt->execute();
                                    $updateStmt->close();
                                    if ($updated) {
                                        $_SESSION["admin_email"] = $newEmail;
                                        $message = "Credenciales de admin actualizadas correctamente.";
                                    } else {
                                        $error = "No se pudieron guardar las nuevas credenciales.";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "client_delete") {
    $cid = (int)($_POST["client_id"] ?? 0);
    if ($cid <= 0) {
        admin_set_flash("error", "Cliente no válido.");
    } else {
        $del = $conn->prepare("DELETE FROM clients WHERE id = ?");
        if ($del === false) {
            admin_set_flash("error", "No se pudo eliminar el cliente.");
        } else {
            $del->bind_param("i", $cid);
            if ($del->execute()) {
                admin_set_flash("success", "Cliente eliminado.");
            } else {
                admin_set_flash("error", "No se pudo eliminar el cliente.");
            }
            $del->close();
        }
    }
    header("Location: admin.php#admin-tool-clients");
    exit;
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "client_toggle_active") {
    $cid = (int)($_POST["client_id"] ?? 0);
    if ($cid <= 0) {
        admin_set_flash("error", "Cliente no válido.");
    } else {
        $tog = $conn->prepare("UPDATE clients SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        if ($tog === false) {
            admin_set_flash("error", "No se pudo actualizar la cuenta.");
        } else {
            $tog->bind_param("i", $cid);
            if ($tog->execute()) {
                admin_set_flash("success", "Estado de la cuenta actualizado.");
            } else {
                admin_set_flash("error", "No se pudo guardar el estado de la cuenta.");
            }
            $tog->close();
        }
    }
    header("Location: admin.php#admin-tool-clients");
    exit;
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "add_service") {
    $title = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $iconClass = trim($_POST["icon_class"] ?? "fa-solid fa-star");
    $sortOrder = (int)($_POST["sort_order"] ?? 999);
    if ($sortOrder < 0) {
        $sortOrder = 999;
    }
    if ($sortOrder > 999999) {
        $sortOrder = 999999;
    }
    $isActive = isset($_POST["is_active"]) ? 1 : 0;

    $uploadResult = storeServiceImage($_FILES["image_file"] ?? []);
    if ($uploadResult["error"] !== "") {
        $error = $uploadResult["error"];
    } elseif ($title !== "" && $description !== "") {
        $imagePath = $uploadResult["path"];
        if ($imagePath === null) {
            $imagePath = "";
        }
        $stmt = $conn->prepare(
            "INSERT INTO services (title, description, icon_class, image_path, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssssii", $title, $description, $iconClass, $imagePath, $sortOrder, $isActive);
        $stmt->execute();
        $stmt->close();
        $message = "Servicio agregado.";
    } else {
        $error = "Título y descripción son obligatorios.";
    }
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "save_services" && isset($_POST["services"])) {
    $removeGalleryIds = array_values(array_filter(array_map("intval", $_POST["remove_gallery_ids"] ?? []), static fn(int $id): bool => $id > 0));
    if (count($removeGalleryIds) > 0) {
        $placeholders = implode(",", array_fill(0, count($removeGalleryIds), "?"));
        $types = str_repeat("i", count($removeGalleryIds));
        $deleteGalleryStmt = $conn->prepare("DELETE FROM service_gallery WHERE id IN ($placeholders)");
        $deleteGalleryStmt->bind_param($types, ...$removeGalleryIds);
        $deleteGalleryStmt->execute();
    }

    if (isset($_POST["gallery_image_titles"]) && is_array($_POST["gallery_image_titles"])) {
        $galMetaStmt = $conn->prepare("UPDATE service_gallery SET image_title = ?, image_description = ?, caption = ? WHERE id = ?");
        if ($galMetaStmt !== false) {
            foreach ($_POST["gallery_image_titles"] as $galleryIdRaw => $titleRaw) {
                $galleryId = (int)$galleryIdRaw;
                if ($galleryId <= 0) {
                    continue;
                }
                if (in_array($galleryId, $removeGalleryIds, true)) {
                    continue;
                }
                $imgTitle = trim((string)$titleRaw);
                $imgDesc = trim((string)($_POST["gallery_image_descriptions"][$galleryIdRaw] ?? ""));
                $capSync = $imgTitle;
                if (function_exists("mb_substr")) {
                    $capSync = mb_substr($imgTitle, 0, 180, "UTF-8");
                } elseif (strlen($capSync) > 180) {
                    $capSync = substr($capSync, 0, 180);
                }
                $galMetaStmt->bind_param("sssi", $imgTitle, $imgDesc, $capSync, $galleryId);
                $galMetaStmt->execute();
            }
            $galMetaStmt->close();
        }
    }

    foreach ($_POST["services"] as $id => $serviceData) {
        $serviceId = (int)$id;
        $title = trim($serviceData["title"] ?? "");
        $description = trim($serviceData["description"] ?? "");
        $iconClass = trim($serviceData["icon_class"] ?? "fa-solid fa-star");
        $sortOrder = (int)($serviceData["sort_order"] ?? 999);
        $isActive = isset($serviceData["is_active"]) ? 1 : 0;

        $currentImagePath = trim((string)($serviceData["current_image_path"] ?? ""));
        $serviceFile = [];
        if (isset($_FILES["service_images"]["error"][$serviceId])) {
            $serviceFile = [
                "name" => $_FILES["service_images"]["name"][$serviceId] ?? "",
                "type" => $_FILES["service_images"]["type"][$serviceId] ?? "",
                "tmp_name" => $_FILES["service_images"]["tmp_name"][$serviceId] ?? "",
                "error" => (int)($_FILES["service_images"]["error"][$serviceId] ?? UPLOAD_ERR_NO_FILE),
                "size" => (int)($_FILES["service_images"]["size"][$serviceId] ?? 0)
            ];
        }
        $uploadResult = storeServiceImage($serviceFile);
        if ($uploadResult["error"] !== "") {
            $error = $uploadResult["error"];
            break;
        }
        $imagePath = $uploadResult["path"] ?? null;
        if ($imagePath === null) {
            $imagePath = $currentImagePath !== "" ? $currentImagePath : null;
        }

        $stmt = $conn->prepare("UPDATE services SET title = ?, description = ?, icon_class = ?, image_path = ?, sort_order = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("ssssiii", $title, $description, $iconClass, $imagePath, $sortOrder, $isActive, $serviceId);
        $stmt->execute();

        $galleryOrderRaw = (string)($serviceData["gallery_order"] ?? "");
        $orderedIds = array_values(array_unique(array_filter(array_map("intval", explode(",", $galleryOrderRaw)), static fn(int $value): bool => $value > 0)));
        if (count($orderedIds) > 0) {
            $resetOrderStmt = $conn->prepare("UPDATE service_gallery SET sort_order = 999 WHERE service_id = ?");
            $resetOrderStmt->bind_param("i", $serviceId);
            $resetOrderStmt->execute();

            $position = 1;
            foreach ($orderedIds as $galleryId) {
                $orderStmt = $conn->prepare("UPDATE service_gallery SET sort_order = ? WHERE id = ? AND service_id = ?");
                $orderStmt->bind_param("iii", $position, $galleryId, $serviceId);
                $orderStmt->execute();
                $position++;
            }
        }

        if (isset($_FILES["gallery_images"]["error"][$serviceId]) && is_array($_FILES["gallery_images"]["error"][$serviceId])) {
            foreach ($_FILES["gallery_images"]["error"][$serviceId] as $index => $fileError) {
                $galleryFile = [
                    "name" => $_FILES["gallery_images"]["name"][$serviceId][$index] ?? "",
                    "type" => $_FILES["gallery_images"]["type"][$serviceId][$index] ?? "",
                    "tmp_name" => $_FILES["gallery_images"]["tmp_name"][$serviceId][$index] ?? "",
                    "error" => (int)$fileError,
                    "size" => (int)($_FILES["gallery_images"]["size"][$serviceId][$index] ?? 0)
                ];
                $galleryUpload = storeServiceImage($galleryFile);
                if ($galleryUpload["error"] !== "") {
                    $error = $galleryUpload["error"];
                    break 2;
                }
                if (!empty($galleryUpload["path"])) {
                    $galleryStmt = $conn->prepare("INSERT INTO service_gallery (service_id, image_path, sort_order, is_active, image_title, image_description) VALUES (?, ?, 999, 1, NULL, NULL)");
                    $galleryStmt->bind_param("is", $serviceId, $galleryUpload["path"]);
                    $galleryStmt->execute();
                }
            }
        }
    }
    if ($error === "") {
      $message = "Servicios actualizados.";
    }
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "delete_service") {
    $serviceId = (int)($_POST["service_id"] ?? 0);
    if ($serviceId > 0) {
        $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
        $stmt->bind_param("i", $serviceId);
        $stmt->execute();
        $message = "Servicio eliminado.";
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "add_expert") {
    $displayName = trim((string)($_POST["display_name"] ?? ""));
    if ($displayName === "") {
        $error = "Indica el nombre visible del experto.";
    } elseif (mb_strlen($displayName, "UTF-8") > 180) {
        $error = "El nombre es demasiado largo (máx. 180 caracteres).";
    } else {
        $email = trim((string)($_POST["email"] ?? ""));
        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "El correo del experto no es válido.";
        }
        $phone = trim((string)($_POST["phone"] ?? ""));
        if (mb_strlen($phone, "UTF-8") > 48) {
            $phone = mb_substr($phone, 0, 48, "UTF-8");
        }
        $notes = (string)($_POST["notes"] ?? "");
        if (strlen($notes) > 12000) {
            $notes = substr($notes, 0, 12000);
        }
        $sortOrder = (int)($_POST["sort_order"] ?? 999);
        if ($sortOrder < 0) {
            $sortOrder = 0;
        }
        if ($sortOrder > 999999) {
            $sortOrder = 999999;
        }
        $isActive = isset($_POST["is_active"]) ? 1 : 0;
        if ($error === "") {
            $emailDb = $email;
            $phoneDb = $phone;
            $ins = $conn->prepare("INSERT INTO experts (display_name, email, phone, notes, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            if ($ins === false) {
                $error = "No se pudo crear el experto.";
            } else {
                $ins->bind_param("ssssii", $displayName, $emailDb, $phoneDb, $notes, $sortOrder, $isActive);
                if (!$ins->execute()) {
                    $error = "No se pudo crear el experto.";
                    $ins->close();
                } else {
                    $newExpertId = (int)$conn->insert_id;
                    $ins->close();
                    $validServiceIds = [];
                    $vs = $conn->query("SELECT id FROM services");
                    if ($vs) {
                        while ($z = $vs->fetch_assoc()) {
                            $validServiceIds[(int)$z["id"]] = true;
                        }
                    }
                    $list = $_POST["expert_services"] ?? [];
                    if (!is_array($list)) {
                        $list = [];
                    }
                    $insEs = $conn->prepare("INSERT INTO expert_services (expert_id, service_id) VALUES (?, ?)");
                    if ($insEs === false) {
                        $error = "No se pudo vincular servicios al experto.";
                    } else {
                        foreach ($list as $sidRaw) {
                            $sid = (int)$sidRaw;
                            if ($sid <= 0 || !isset($validServiceIds[$sid])) {
                                continue;
                            }
                            $insEs->bind_param("ii", $newExpertId, $sid);
                            if (!$insEs->execute()) {
                                $error = "No se pudo vincular un servicio.";
                                break;
                            }
                        }
                        $insEs->close();
                    }
                    if ($error === "") {
                        admin_set_flash("success", "Experto creado.");
                        header("Location: admin.php?expert_id=" . $newExpertId . "#admin-tools-experts");
                        exit;
                    }
                }
            }
        }
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "save_expert") {
    $eid = (int)($_POST["expert_id"] ?? 0);
    $chk = $conn->prepare("SELECT id FROM experts WHERE id = ? LIMIT 1");
    if ($chk === false || $eid <= 0) {
        $error = "Experto no válido.";
    } else {
        $chk->bind_param("i", $eid);
        $chk->execute();
        $chkRes = $chk->get_result();
        $chk->close();
        if (!$chkRes || $chkRes->num_rows !== 1) {
            $error = "Ese experto no existe.";
        } else {
            $name = trim((string)($_POST["display_name"] ?? ""));
            if ($name === "") {
                $name = "Sin nombre";
            }
            if (mb_strlen($name, "UTF-8") > 180) {
                $name = mb_substr($name, 0, 180, "UTF-8");
            }
            $email = trim((string)($_POST["email"] ?? ""));
            if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "El correo del experto no es válido.";
            }
            $phone = trim((string)($_POST["phone"] ?? ""));
            if (mb_strlen($phone, "UTF-8") > 48) {
                $phone = mb_substr($phone, 0, 48, "UTF-8");
            }
            $notes = (string)($_POST["notes"] ?? "");
            if (strlen($notes) > 12000) {
                $notes = substr($notes, 0, 12000);
            }
            $sort = (int)($_POST["sort_order"] ?? 999);
            $active = isset($_POST["is_active"]) ? 1 : 0;
            if ($error === "") {
                $emailDb = $email;
                $phoneDb = $phone;
                $upd = $conn->prepare("UPDATE experts SET display_name = ?, email = ?, phone = ?, notes = ?, sort_order = ?, is_active = ? WHERE id = ?");
                if ($upd === false) {
                    $error = "No se pudo guardar el experto.";
                } else {
                    $upd->bind_param("ssssiii", $name, $emailDb, $phoneDb, $notes, $sort, $active, $eid);
                    if (!$upd->execute()) {
                        $error = "No se pudo guardar el experto.";
                    }
                    $upd->close();
                }
            }
            if ($error === "") {
                $validServiceIds = [];
                $vs = $conn->query("SELECT id FROM services");
                if ($vs) {
                    while ($z = $vs->fetch_assoc()) {
                        $validServiceIds[(int)$z["id"]] = true;
                    }
                }
                $list = $_POST["expert_services"] ?? [];
                if (!is_array($list)) {
                    $list = [];
                }
                $delEs = $conn->prepare("DELETE FROM expert_services WHERE expert_id = ?");
                $insEs = $conn->prepare("INSERT INTO expert_services (expert_id, service_id) VALUES (?, ?)");
                if ($delEs === false || $insEs === false) {
                    $error = "No se pudo actualizar los servicios del experto.";
                } else {
                    $delEs->bind_param("i", $eid);
                    if (!$delEs->execute()) {
                        $error = "No se pudo actualizar los servicios del experto.";
                    } else {
                        foreach ($list as $sidRaw) {
                            $sid = (int)$sidRaw;
                            if ($sid <= 0 || !isset($validServiceIds[$sid])) {
                                continue;
                            }
                            $insEs->bind_param("ii", $eid, $sid);
                            if (!$insEs->execute()) {
                                $error = "No se pudo vincular un servicio.";
                                break;
                            }
                        }
                    }
                    $delEs->close();
                    $insEs->close();
                }
            }
            if ($error === "") {
                admin_set_flash("success", "Experto actualizado.");
                header("Location: admin.php?expert_id=" . $eid . "#admin-tools-experts");
                exit;
            }
        }
    }
}

if ($isLogged && $adminExpertAgendaUi && isset($_POST["action"]) && $_POST["action"] === "delete_expert") {
    $expertId = (int)($_POST["expert_id"] ?? 0);
    if ($expertId > 0) {
        $stmt = $conn->prepare("DELETE FROM experts WHERE id = ?");
        $stmt->bind_param("i", $expertId);
        $stmt->execute();
        $stmt->close();
        admin_set_flash("success", "Experto eliminado.");
        header("Location: admin.php#admin-tools-experts");
        exit;
    }
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_message_read") {
    $messageId = (int)($_POST["message_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $ok = false;
    $affected = 0;
    $err = "";
    if (!$isLogged) {
        $err = "no_session";
    } elseif ($messageId <= 0) {
        $err = "id_invalido";
    } else {
        $stmt = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
        if ($stmt === false) {
            $err = "prepare_failed: " . $conn->error;
        } else {
            $stmt->bind_param("i", $messageId);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            if (!$ok) {
                $err = "execute_failed: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    if ($isAjax) {
        $counts = admin_contact_inbox_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)$ok,
            "id" => $messageId,
            "affected" => $affected,
            "err" => $err,
            "logged" => (bool)$isLogged,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    if ($isLogged) {
        admin_set_flash("success", "Mensaje marcado como leído.");
        admin_redirect_after_action();
    }
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_messages_read") {
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $ok = (bool)$conn->query("UPDATE contact_messages SET is_read = 1 WHERE is_read = 0");
    if ($isAjax) {
        $counts = admin_contact_inbox_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => $ok,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Todos los mensajes marcados como leídos.");
    admin_redirect_after_action();
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_message_unread") {
    $messageId = (int)($_POST["message_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $ok = false;
    if ($messageId > 0) {
        $stmt = $conn->prepare("UPDATE contact_messages SET is_read = 0 WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        $ok = $stmt->execute();
    }
    if ($isAjax) {
        $counts = admin_contact_inbox_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)$ok,
            "id" => $messageId,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Mensaje marcado como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_messages_unread") {
    $conn->query("UPDATE contact_messages SET is_read = 0 WHERE is_read = 1");
    admin_set_flash("success", "Todos los mensajes marcados como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "delete_message") {
    $messageId = (int)($_POST["message_id"] ?? 0);
    if ($messageId > 0) {
        $delReplies = $conn->prepare("DELETE FROM contact_message_replies WHERE contact_message_id = ?");
        if ($delReplies !== false) {
            $delReplies->bind_param("i", $messageId);
            $delReplies->execute();
            $delReplies->close();
        }
        $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
    }
    admin_set_flash("success", "Mensaje eliminado.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "delete_whatsapp_click") {
    $whatsappClickId = (int)($_POST["whatsapp_click_id"] ?? 0);
    if ($whatsappClickId > 0) {
        $waDel = $conn->prepare("DELETE FROM contact_whatsapp_clicks WHERE id = ?");
        if ($waDel !== false) {
            $waDel->bind_param("i", $whatsappClickId);
            $waDel->execute();
            $waDel->close();
        }
    }
    admin_set_flash("success", "Entrada eliminada.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_whatsapp_read") {
    $whatsappClickId = (int)($_POST["whatsapp_click_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $ok = false;
    if ($whatsappClickId > 0) {
        $wst = $conn->prepare("UPDATE contact_whatsapp_clicks SET is_read = 1 WHERE id = ?");
        if ($wst !== false) {
            $wst->bind_param("i", $whatsappClickId);
            $ok = $wst->execute();
            $wst->close();
        }
    }
    if ($isAjax) {
        $counts = admin_whatsapp_inbox_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)$ok,
            "id" => $whatsappClickId,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Marcado como leído.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_whatsapp_unread") {
    $whatsappClickId = (int)($_POST["whatsapp_click_id"] ?? 0);
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $ok = false;
    if ($whatsappClickId > 0) {
        $wst = $conn->prepare("UPDATE contact_whatsapp_clicks SET is_read = 0 WHERE id = ?");
        if ($wst !== false) {
            $wst->bind_param("i", $whatsappClickId);
            $ok = $wst->execute();
            $wst->close();
        }
    }
    if ($isAjax) {
        $counts = admin_whatsapp_inbox_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => (bool)$ok,
            "id" => $whatsappClickId,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Marcado como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_whatsapp_read") {
    $isAjax = isset($_POST["ajax"]) && $_POST["ajax"] === "1";
    $ok = (bool)$conn->query("UPDATE contact_whatsapp_clicks SET is_read = 1 WHERE is_read = 0");
    if ($isAjax) {
        $counts = admin_whatsapp_inbox_counts($conn);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "ok" => $ok,
            "unread_total" => $counts["unread"],
            "messages_total" => $counts["total"],
        ]);
        exit;
    }
    admin_set_flash("success", "Todos los clics de WhatsApp marcados como leídos.");
    admin_redirect_after_action();
}

if ($isLogged && $adminWhatsappClicksUi && isset($_POST["action"]) && $_POST["action"] === "mark_all_whatsapp_unread") {
    $conn->query("UPDATE contact_whatsapp_clicks SET is_read = 0 WHERE is_read = 1");
    admin_set_flash("success", "Todos los clics de WhatsApp marcados como sin leer.");
    admin_redirect_after_action();
}

if ($isLogged && isset($_POST["action"]) && $_POST["action"] === "client_toggle_email_notify") {
    $cid = (int)($_POST["client_id"] ?? 0);
    if ($cid <= 0) {
        admin_set_flash("error", "Cliente no válido.");
    } else {
        $tog = $conn->prepare("UPDATE clients SET email_notify_outbound = IF(email_notify_outbound = 1, 0, 1) WHERE id = ?");
        if ($tog === false) {
            admin_set_flash("error", "No se pudo actualizar la preferencia de correo.");
        } else {
            $tog->bind_param("i", $cid);
            if ($tog->execute()) {
                admin_set_flash("success", "Preferencia de envío por SMTP al cliente actualizada.");
            } else {
                admin_set_flash("error", "No se pudo guardar el cambio de envío por correo.");
            }
            $tog->close();
        }
    }
    header("Location: admin.php#admin-tool-clients");
    exit;
}

if ($isLogged && $adminInboxUi && isset($_POST["action"]) && $_POST["action"] === "reply_contact_message") {
    $messageId = (int)($_POST["message_id"] ?? 0);
    $replyBody = trim((string)($_POST["reply_body"] ?? ""));
    if ($messageId <= 0) {
        admin_set_flash("error", "Mensaje no valido.");
        admin_redirect_after_action();
    }
    if ($replyBody === "") {
        admin_set_flash("error", "Escribe el texto de la respuesta.");
        admin_redirect_after_action();
    }
    if (strlen($replyBody) > 20000) {
        admin_set_flash("error", "La respuesta es demasiado larga.");
        admin_redirect_after_action();
    }
    $stmt = $conn->prepare(
        "SELECT id, nombre, email, servicio, subject, mensaje, created_at, in_reply_to, client_id FROM contact_messages WHERE id = ? LIMIT 1"
    );
    if ($stmt === false) {
        admin_set_flash("error", "No se pudo cargar el mensaje.");
        admin_redirect_after_action();
    }
    $stmt->bind_param("i", $messageId);
    $stmt->execute();
    $msgRes = $stmt->get_result();
    $stmt->close();
    if (!$msgRes || $msgRes->num_rows !== 1) {
        admin_set_flash("error", "Ese mensaje ya no existe.");
        admin_redirect_after_action();
    }
    $msgRow = $msgRes->fetch_assoc();
    $visitorEmail = trim((string)($msgRow["email"] ?? ""));
    if (!filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
        admin_set_flash("error", "El correo del visitante no es valido.");
        admin_redirect_after_action();
    }

    $ins = $conn->prepare("INSERT INTO contact_message_replies (contact_message_id, body) VALUES (?, ?)");
    if ($ins === false) {
        admin_set_flash("error", "No se pudo guardar la respuesta.");
        admin_redirect_after_action();
    }
    $ins->bind_param("is", $messageId, $replyBody);
    if (!$ins->execute()) {
        $ins->close();
        admin_set_flash("error", "No se pudo guardar la respuesta.");
        admin_redirect_after_action();
    }
    $ins->close();
    $mark = $conn->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?");
    if ($mark !== false) {
        $mark->bind_param("i", $messageId);
        $mark->execute();
        $mark->close();
    }
    $clientUnseen = $conn->prepare("UPDATE contact_messages SET client_has_unseen_reply = 1 WHERE id = ?");
    if ($clientUnseen !== false) {
        $clientUnseen->bind_param("i", $messageId);
        $clientUnseen->execute();
        $clientUnseen->close();
    }

    $msgClientId = (int)($msgRow["client_id"] ?? 0);
    $skipOutbound = false;
    if ($msgClientId > 0) {
        $cst = $conn->prepare("SELECT email_notify_outbound FROM clients WHERE id = ? LIMIT 1");
        if ($cst !== false) {
            $cst->bind_param("i", $msgClientId);
            $cst->execute();
            $cres = $cst->get_result();
            $crow = $cres ? $cres->fetch_assoc() : null;
            $cst->close();
            if ($crow !== null && (int)($crow["email_notify_outbound"] ?? 1) === 0) {
                $skipOutbound = true;
            }
        }
    }
    if ($skipOutbound) {
        admin_set_flash(
            "success",
            "Respuesta guardada. Este cliente no recibe correos del sitio (solo verá el mensaje en la web)."
        );
        admin_redirect_after_action();
    }

    $settingsRes = $conn->query("SELECT contact_email, person_name, brand_name FROM site_settings WHERE id = 1 LIMIT 1");
    $contactEmail = "";
    $personName = "";
    $brandName = "";
    if ($settingsRes && $settingsRes->num_rows === 1) {
        $srow = $settingsRes->fetch_assoc();
        $contactEmail = trim((string)($srow["contact_email"] ?? ""));
        $personName = trim((string)($srow["person_name"] ?? ""));
        $brandName = trim((string)($srow["brand_name"] ?? ""));
    }
    if ($contactEmail === "" || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        admin_set_flash(
            "warning",
            "Respuesta guardada en el panel. Configura un correo receptor valido en Configuracion general para poder enviar por SMTP al visitante."
        );
        admin_redirect_after_action();
    }
    $mailConfigPath = __DIR__ . "/mail_config.php";
    $mailConfig = is_readable($mailConfigPath) ? require $mailConfigPath : [];
    $mailConfig = is_array($mailConfig) ? $mailConfig : [];
    $fromEmail = mail_config_resolve_smtp_from($mailConfig);
    if ($fromEmail === "") {
        admin_set_flash(
            "warning",
            "Respuesta guardada. En mail_config indica from_email o un username que sea un correo valido (cuenta SMTP) para enviar al visitante."
        );
        admin_redirect_after_action();
    }
    $smtpHost = strtolower((string)($mailConfig["host"] ?? ""));
    $smtpUser = trim((string)($mailConfig["username"] ?? ""));
    if (!empty($mailConfig["use_smtp"]) && str_contains($smtpHost, "gmail") && strcasecmp($fromEmail, $smtpUser) !== 0) {
        admin_set_flash(
            "warning",
            "Respuesta guardada. Con Gmail (smtp.gmail.com), username y remitente SMTP deben ser el mismo correo para poder enviar al visitante."
        );
        admin_redirect_after_action();
    }
    $fromDisplayName = trim((string)($mailConfig["from_name"] ?? ""));
    if ($fromDisplayName === "") {
        $fromDisplayName = $personName !== "" ? $personName : $brandName;
    }
    if ($fromDisplayName === "") {
        $localPart = str_contains($fromEmail, "@") ? trim(explode("@", $fromEmail, 2)[0] ?? "") : "";
        $fromDisplayName = $localPart !== "" ? $localPart : "Web";
    }
    $visitorNombre = trim((string)($msgRow["nombre"] ?? ""));
    $servicio = trim((string)($msgRow["servicio"] ?? ""));
    $msgSubject = trim((string)($msgRow["subject"] ?? ""));
    $mensajeOriginal = trim((string)($msgRow["mensaje"] ?? ""));
    $createdOriginal = (string)($msgRow["created_at"] ?? "");
    $subject = "Respuesta a tu mensaje de contacto";
    if ($msgSubject !== "") {
        $subject = "Re: " . $msgSubject;
    }
    if ($brandName !== "") {
        $subject .= " (" . $brandName . ")";
    }
    $body = "Hola" . ($visitorNombre !== "" ? " " . $visitorNombre : "") . ",\n\n";
    $body .= "Gracias por escribirnos. Respondemos a continuacion:\n\n";
    $body .= $replyBody . "\n\n";
    $body .= "---\nTu mensaje (" . $createdOriginal . ")\n";
    if ($msgSubject !== "") {
        $body .= "Asunto: " . $msgSubject . "\n";
    }
    $body .= "Servicio: " . $servicio . "\n\n" . $mensajeOriginal . "\n\n---\n";
    $inReplyRow = isset($msgRow["in_reply_to"]) ? (int)$msgRow["in_reply_to"] : 0;
    if ($inReplyRow > 0) {
        $body .= "Nota: este envío es un seguimiento enlazado al mensaje n.º " . $inReplyRow . " en el panel.\n\n";
    }
    $body .= "Puedes responder a este correo y llegara a nuestra bandeja.\n\n";
    $body .= app_mail_plain_text_links_footer("visitor_reply");
    $sendResult = admin_send_visitor_reply_mail(
        $mailConfig,
        $visitorEmail,
        $subject,
        $body,
        $contactEmail,
        $fromDisplayName
    );
    $sent = (bool)($sendResult["ok"] ?? false);
    if (!$sent) {
        $traceLine = date("c") . " admin_reply fallo To=" . $visitorEmail
            . " code=" . ($sendResult["code"] ?? "?")
            . " smtp_cfg=" . (!empty($mailConfig["host"]) && trim((string)($mailConfig["username"] ?? "")) !== "" ? "1" : "0")
            . "\n";
        @file_put_contents(__DIR__ . "/contact_send_trace.log", $traceLine, FILE_APPEND | LOCK_EX);
        $code = (string)($sendResult["code"] ?? "");
        if ($code === "smtp_failed") {
            admin_set_flash(
                "warning",
                "Respuesta guardada en el panel, pero SMTP falló al enviar al visitante (revisa contact_send_trace.log). Con Gmail usa contraseña de aplicación; username y from_email deben coincidir."
            );
        } elseif ($code === "php_mail_failed") {
            admin_set_flash(
                "warning",
                "Respuesta guardada. Falta SMTP completo en mail_config y mail() del servidor también falló; el visitante no recibió correo."
            );
        } elseif ($code === "bad_config") {
            admin_set_flash("warning", "Respuesta guardada. Revisa correo del visitante, remitente SMTP y contacto del sitio.");
        } else {
            admin_set_flash(
                "warning",
                "Respuesta guardada. No se pudo completar el envío por correo; revisa mail_config y el log de depuración."
            );
        }
        admin_redirect_after_action();
    }
    admin_set_flash("success", "Respuesta guardada y enviada a " . $visitorEmail . ".");
    admin_redirect_after_action();
}

$settings = [
    "person_name" => "",
    "brand_name" => "",
    "hero_title" => "",
    "hero_intro" => "",
    "about_text" => "",
    "contact_intro" => "",
    "contact_email" => "",
    "contact_whatsapp" => "",
    "contact_whatsapp_country_code" => null,
    "footer_text" => ""
];

$settingsQuery = $conn->query("SELECT * FROM site_settings WHERE id = 1 LIMIT 1");
if ($settingsQuery && $settingsQuery->num_rows === 1) {
    $settings = $settingsQuery->fetch_assoc();
}

$services = [];
$servicesQuery = $conn->query("SELECT * FROM services ORDER BY sort_order ASC, id ASC");
if ($servicesQuery) {
    while ($row = $servicesQuery->fetch_assoc()) {
        $services[] = $row;
    }
}

$galleryByService = [];
$galleryQuery = $conn->query("SELECT id, service_id, image_path, caption, image_title, image_description FROM service_gallery WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
if ($galleryQuery) {
    while ($galleryRow = $galleryQuery->fetch_assoc()) {
        $serviceId = (int)$galleryRow["service_id"];
        if (!isset($galleryByService[$serviceId])) {
            $galleryByService[$serviceId] = [];
        }
        $galleryByService[$serviceId][] = $galleryRow;
    }
}

$experts = [];
$expertServiceIds = [];
$expertEditId = 0;
$expertEdit = null;
$expertEditNotFound = false;
$expertsPanelOpen = false;
if ($isLogged && $adminExpertAgendaUi) {
    $expertEditId = (int)($_GET["expert_id"] ?? 0);
    $expertsQuery = $conn->query(
        "SELECT id, display_name, email, phone, notes, sort_order, is_active, created_at FROM experts ORDER BY sort_order ASC, id ASC"
    );
    if ($expertsQuery) {
        while ($er = $expertsQuery->fetch_assoc()) {
            $experts[] = $er;
        }
    }
    if ($expertEditId > 0) {
        foreach ($experts as $er) {
            if ((int)($er["id"] ?? 0) === $expertEditId) {
                $expertEdit = $er;
                break;
            }
        }
        if ($expertEdit === null) {
            $exf = $conn->prepare(
                "SELECT id, display_name, email, phone, notes, sort_order, is_active, created_at FROM experts WHERE id = ? LIMIT 1"
            );
            if ($exf !== false) {
                $exf->bind_param("i", $expertEditId);
                $exf->execute();
                $exr = $exf->get_result();
                if ($exr && ($row = $exr->fetch_assoc())) {
                    $expertEdit = $row;
                } else {
                    $expertEditNotFound = true;
                }
                $exf->close();
            } else {
                $expertEditNotFound = true;
            }
        }
    }
    $eidList = array_values(array_filter(array_map(static fn(array $e): int => (int)($e["id"] ?? 0), $experts), static fn(int $id): bool => $id > 0));
    if ($expertEditId > 0 && $expertEdit !== null && !in_array($expertEditId, $eidList, true)) {
        $eidList[] = $expertEditId;
    }
    if (count($eidList) > 0) {
        $ph = implode(",", array_fill(0, count($eidList), "?"));
        $types = str_repeat("i", count($eidList));
        $esStmt = $conn->prepare("SELECT expert_id, service_id FROM expert_services WHERE expert_id IN ($ph)");
        if ($esStmt !== false) {
            $esStmt->bind_param($types, ...$eidList);
            $esStmt->execute();
            $esRes = $esStmt->get_result();
            if ($esRes) {
                while ($pair = $esRes->fetch_assoc()) {
                    $eid = (int)$pair["expert_id"];
                    $sid = (int)$pair["service_id"];
                    if (!isset($expertServiceIds[$eid])) {
                        $expertServiceIds[$eid] = [];
                    }
                    $expertServiceIds[$eid][$sid] = true;
                }
            }
            $esStmt->close();
        }
    }
    $expertsPanelOpen = $expertEditId > 0;
}

$contactMessages = [];
$contactMessagesUnread = 0;
if ($isLogged && $adminInboxUi) {
    $contactMessagesQuery = $conn->query(
        "SELECT id, nombre, email, servicio, subject, mensaje, sent_to, is_read, created_at, client_id, in_reply_to
         FROM contact_messages
         ORDER BY created_at DESC, id DESC
         LIMIT 100"
    );
    if ($contactMessagesQuery) {
        while ($row = $contactMessagesQuery->fetch_assoc()) {
            $contactMessages[] = $row;
            if ((int)($row["is_read"] ?? 0) === 0) {
                $contactMessagesUnread++;
            }
        }
    }
}

$contactRepliesByMessageId = [];
if ($isLogged && $adminInboxUi && count($contactMessages) > 0) {
    $msgIds = [];
    foreach ($contactMessages as $row) {
        $mid = (int)($row["id"] ?? 0);
        if ($mid > 0) {
            $msgIds[$mid] = true;
        }
    }
    $idList = array_keys($msgIds);
    if (count($idList) > 0) {
        $inList = implode(",", $idList);
        $repQuery = $conn->query(
            "SELECT id, contact_message_id, body, created_at FROM contact_message_replies WHERE contact_message_id IN ($inList) ORDER BY created_at ASC, id ASC"
        );
        if ($repQuery) {
            while ($r = $repQuery->fetch_assoc()) {
                $mid = (int)$r["contact_message_id"];
                if (!isset($contactRepliesByMessageId[$mid])) {
                    $contactRepliesByMessageId[$mid] = [];
                }
                $contactRepliesByMessageId[$mid][] = $r;
            }
        }
    }
}

$whatsappClicks = [];
$whatsappClicksUnread = 0;
if ($isLogged && $adminWhatsappClicksUi) {
    $whatsappClicksQuery = $conn->query(
        "SELECT id, nombre, email, servicio, mensaje, composed_text, created_at, is_read
         FROM contact_whatsapp_clicks
         ORDER BY created_at DESC, id DESC
         LIMIT 100"
    );
    if ($whatsappClicksQuery) {
        while ($waRow = $whatsappClicksQuery->fetch_assoc()) {
            $whatsappClicks[] = $waRow;
            if ((int)($waRow["is_read"] ?? 0) === 0) {
                $whatsappClicksUnread++;
            }
        }
    }
}

$portalClients = [];
if ($isLogged) {
    $pcQuery = $conn->query("SELECT id, email, display_name, is_active, email_notify_outbound, created_at FROM clients ORDER BY id ASC");
    if ($pcQuery) {
        while ($pcRow = $pcQuery->fetch_assoc()) {
            $portalClients[] = $pcRow;
        }
    }
}

/** Agrupa mensajes de contacto: por cuenta de cliente o, si no, por correo. */
$contactMessageGroups = [];
if ($isLogged && $adminInboxUi && count($contactMessages) > 0) {
    $clientDirectoryById = [];
    foreach ($portalClients as $pcRow) {
        $clientDirectoryById[(int)$pcRow["id"]] = $pcRow;
    }
    $groupsAccum = [];
    foreach ($contactMessages as $row) {
        $cid = (int)($row["client_id"] ?? 0);
        if ($cid > 0) {
            $gkey = "c:" . $cid;
        } else {
            $em = strtolower(trim((string)($row["email"] ?? "")));
            $gkey = "e:" . ($em !== "" ? $em : "__sin_correo__");
        }
        if (!isset($groupsAccum[$gkey])) {
            $groupsAccum[$gkey] = [
                "key" => $gkey,
                "slug" => "h" . substr(md5($gkey), 0, 14),
                "type" => $cid > 0 ? "client" : "email",
                "client_id" => $cid > 0 ? $cid : 0,
                "anchor_email" => trim((string)($row["email"] ?? "")),
                "messages" => [],
                "unread" => 0,
                "latest_ts" => 0,
            ];
        }
        $groupsAccum[$gkey]["messages"][] = $row;
        if ((int)($row["is_read"] ?? 0) === 0) {
            $groupsAccum[$gkey]["unread"]++;
        }
        $ts = strtotime((string)($row["created_at"] ?? "")) ?: 0;
        if ($ts > $groupsAccum[$gkey]["latest_ts"]) {
            $groupsAccum[$gkey]["latest_ts"] = $ts;
        }
    }
    foreach ($groupsAccum as &$g) {
        usort($g["messages"], static function (array $a, array $b): int {
            $ta = strtotime((string)($a["created_at"] ?? "")) ?: 0;
            $tb = strtotime((string)($b["created_at"] ?? "")) ?: 0;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            return ((int)($a["id"] ?? 0)) <=> ((int)($b["id"] ?? 0));
        });
    }
    unset($g);
    $contactMessageGroups = array_values($groupsAccum);
    usort($contactMessageGroups, static function (array $a, array $b): int {
        return ($b["latest_ts"] ?? 0) <=> ($a["latest_ts"] ?? 0);
    });
    foreach ($contactMessageGroups as &$g) {
        if ($g["type"] === "client") {
            $pid = (int)$g["client_id"];
            $cl = $clientDirectoryById[$pid] ?? null;
            $g["head_badge"] = "Cliente";
            if ($cl !== null) {
                $dn = trim((string)($cl["display_name"] ?? ""));
                $g["head_title"] = $dn !== "" ? $dn : (string)$cl["email"];
                $g["head_email"] = (string)$cl["email"];
            } else {
                $first = $g["messages"][0] ?? [];
                $g["head_title"] = "Cliente n.º " . $pid;
                $g["head_email"] = (string)($first["email"] ?? "");
            }
            $g["head_sub"] = "";
        } else {
            $first = $g["messages"][0] ?? [];
            $nm = trim((string)($first["nombre"] ?? ""));
            $em = (string)($g["anchor_email"] ?? "");
            $g["head_badge"] = "Correo";
            $g["head_title"] = $em !== "" ? $em : "Sin correo";
            $g["head_email"] = $em;
            if ($nm !== "") {
                $g["head_sub"] = $nm;
            } else {
                $g["head_sub"] = "";
            }
        }
        $g["msg_count"] = count($g["messages"]);
        $g["threads"] = admin_group_messages_threads($g["messages"], $contactRepliesByMessageId);
        $g["conv_count"] = count($g["threads"]);
        $ts = (int)($g["latest_ts"] ?? 0);
        $g["latest_label"] = "";
        if ($ts > 0) {
            $g["latest_label"] = date("d/m H:i", $ts);
        }
    }
    unset($g);
}

$waSideTotal = count($whatsappClicks);
$waSideUnread = $whatsappClicksUnread;
$waSideCounterClass = $waSideUnread > 0 ? "text-bg-warning" : "text-bg-secondary";
$waSideCounterTitle = $waSideUnread > 0
    ? sprintf("%d sin leer de %d", $waSideUnread, $waSideTotal)
    : sprintf("%d en total", $waSideTotal);

?>
<!doctype html>
<html lang="es" data-theme-context="admin">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script>
    (function () {
      try {
        var mode = localStorage.getItem("admin-ui-mode") || "dark";
        var palette = localStorage.getItem("admin-ui-palette") || "blue";
        document.documentElement.setAttribute("data-theme", mode);
        document.documentElement.setAttribute("data-palette", palette);
      } catch (e) {}
    })();
  </script>
  <title>Panel de Administración</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="styles.css?v=<?= h($adminAssetStylesVer) ?>">
  <style>
    .admin-wrap {
      width: min(1280px, 96%);
      margin: 2rem auto;
      color: var(--text);
      /* Bootstrap 5.3 usa variables propias; las alineamos al tema de la plantilla para herencia correcta */
      --bs-body-color: var(--text);
      --bs-body-bg: var(--bg);
      --bs-secondary-color: var(--muted);
      --bs-tertiary-color: color-mix(in srgb, var(--muted) 78%, transparent);
      --bs-emphasis-color: var(--text);
      --bs-heading-color: inherit;
      --bs-border-color: var(--border);
      --bs-link-color: var(--accent);
      --bs-link-hover-color: var(--accent-strong);
      --bs-light-text-emphasis: var(--muted);
      --bs-dark-text-emphasis: var(--text);
      --bs-card-color: var(--text);
      --bs-card-bg: color-mix(in srgb, var(--surface) 96%, transparent);
      --bs-card-border-color: var(--border);
      --bs-accordion-color: var(--text);
      --bs-accordion-bg: var(--surface);
      --bs-accordion-btn-color: var(--text);
      --bs-accordion-btn-bg: var(--surface-2);
      --bs-accordion-active-color: var(--text);
      --bs-accordion-active-bg: color-mix(in srgb, var(--accent) 18%, var(--surface));
      --bs-accordion-border-color: var(--border);
    }
    .admin-layout {
      display: grid;
      gap: 1rem;
      grid-template-columns: 1fr;
      isolation: isolate;
    }
    /* Columna lateral después en el DOM + sticky: sin capa clara, compite con el main.
       Bootstrap además pone z-index 2/3 en .accordion-button:hover:focus → parpadeo
       entre columnas; aislamos el main y anulamos esos z-index en todo el admin. */
    .admin-main {
      min-width: 0;
      position: relative;
      z-index: 8;
      isolation: isolate;
    }
    .admin-side { min-width: 0; }
    @media (min-width: 992px) {
      .admin-layout {
        grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
        align-items: start;
      }
      .admin-side {
        position: sticky;
        top: 1rem;
        align-self: start;
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
        z-index: 0;
        isolation: isolate;
      }
    }
    .admin-wrap .accordion-button:hover,
    .admin-wrap .accordion-button:focus {
      z-index: auto !important;
    }
    .admin-tools-accordion .accordion-button {
      background-color: var(--surface);
      color: var(--text);
      font-weight: 600;
    }
    .admin-tools-accordion .accordion-button:not(.collapsed) {
      background-color: color-mix(in srgb, var(--accent) 18%, var(--surface));
      box-shadow: none;
    }
    .admin-tools-accordion .accordion-body {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
    .wa-phone-input-group .input-group-text {
      font-weight: 700;
      padding-inline: 0.65rem;
    }
    .wa-phone-input-group .wa-cc-field {
      flex: 0 0 auto;
      width: 3.35rem;
      max-width: 3.35rem;
      text-align: center;
      padding-inline: 0.35rem;
    }
    .wa-phone-input-group .wa-local-field {
      min-width: 0;
    }
    .admin-grid { display: grid; gap: .8rem; }
    .admin-grid-2 { display: grid; gap: .8rem; grid-template-columns: 1fr; }
    .admin-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
    .admin-msg { color: #86efac; font-weight: 700; }
    .admin-err { color: #fda4af; font-weight: 700; }
    .service-row { border: 1px solid var(--border); border-radius: 10px; padding: .8rem; margin-bottom: .8rem; }
    .login-shell {
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: radial-gradient(circle at top, color-mix(in srgb, var(--accent) 45%, transparent) 0%, var(--bg) 45%, var(--bg-strong) 100%);
      padding: 1rem;
      color: var(--text);
      --bs-body-color: var(--text);
      --bs-secondary-color: var(--muted);
      --bs-light-text-emphasis: var(--muted);
    }
    .login-card {
      width: min(460px, 96%);
      border: 1px solid var(--border);
      border-radius: 18px;
      background: color-mix(in srgb, var(--surface) 92%, transparent);
      box-shadow: 0 30px 60px var(--shadow-soft);
    }
    .login-title {
      font-size: 1.35rem;
      font-weight: 800;
      color: #eef2ff;
    }
    .login-subtitle { color: var(--muted); }
    .login-input {
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
    }
    .login-input::placeholder { color: var(--muted); }
    .login-input:focus {
      border-color: var(--ring);
      box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--ring) 30%, transparent);
      background: var(--field-bg);
      color: var(--text);
    }
    .password-wrap { position: relative; }
    .password-wrap .login-input { padding-right: 2.8rem; }
    .password-toggle {
      position: absolute;
      right: .35rem;
      top: 50%;
      transform: translateY(-50%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.1rem;
      height: 2.1rem;
      background: transparent;
      border: 1px solid transparent;
      color: var(--muted);
      border-radius: 8px;
      cursor: pointer;
      transition: color .15s ease, background .15s ease, border-color .15s ease;
    }
    .password-toggle:hover { color: var(--text); background: var(--field-bg); border-color: var(--border); }
    .password-toggle:focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
    .login-help { display: flex; justify-content: center; }
    .login-link {
      color: var(--muted);
      text-decoration: none;
      font-size: .9rem;
      padding: .3rem .55rem;
      border-radius: 6px;
      transition: color .15s ease, background .15s ease;
    }
    .login-link:hover,
    .login-link:focus-visible {
      color: var(--text);
      background: color-mix(in srgb, var(--surface-2) 60%, transparent);
      outline: none;
    }
    .login-view[hidden] { display: none !important; }
    .logo-preview-admin {
      width: 56px;
      height: 56px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--logo-bg);
      padding: 4px;
      object-fit: contain;
    }
    .credential-box {
      background: color-mix(in srgb, var(--surface-2) 88%, transparent);
      border: 1px dashed var(--border);
      color: var(--text);
      border-radius: 12px;
      font-size: .95rem;
    }
    .admin-wrap .card {
      background: var(--bs-card-bg);
      border: 1px solid var(--border);
      color: var(--bs-card-color);
      border-radius: 14px;
    }
    /* Encabezado (tema/colores) por encima del grid: el panel de paleta sigue al frente
       hasta cerrarse; si no, la columna mensajes (sticky) lo tapa al interactuar. */
    .admin-wrap > .admin-header-card {
      position: relative;
      z-index: 400;
    }
    .admin-theme-toolbar {
      position: relative;
      z-index: 1;
    }
    /* Menú tema: reglas completas aquí (el admin antes solo “pinteaba” encima de styles.css;
       con CSS en caché sin .theme-dropdown*, el panel quedaba en flujo y los botones nativos). */
    .admin-wrap .theme-sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
    .admin-wrap .theme-dropdown {
      position: relative;
    }
    .admin-wrap .theme-btn {
      width: 38px;
      height: 38px;
      display: grid;
      place-items: center;
      cursor: pointer;
      border: 1px solid var(--border);
      background:
        linear-gradient(155deg, var(--palette-soft), transparent 58%),
        var(--surface-2);
      color: var(--text);
      border-radius: 10px;
      font-family: inherit;
      font-size: 1rem;
      line-height: 1;
    }
    .admin-wrap .theme-dropdown-toggle.theme-btn {
      flex-shrink: 0;
      background: var(--field-bg);
      border-color: var(--border);
      color: var(--text);
    }
    .admin-wrap .theme-dropdown-toggle.theme-btn:hover {
      border-color: var(--ring);
      background: color-mix(in srgb, var(--field-bg) 88%, var(--palette-soft));
    }
    .admin-wrap .theme-dropdown-toggle[aria-expanded="true"] {
      border-color: var(--ring);
      box-shadow: 0 0 0 2px color-mix(in srgb, var(--ring) 40%, transparent);
    }
    .admin-wrap .theme-dropdown-panel {
      position: absolute;
      right: 0;
      top: calc(100% + 6px);
      z-index: 500;
      width: min(320px, calc(100vw - 1.5rem));
      padding: 0.75rem 0.8rem 0.85rem;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--surface);
      color: var(--text);
      font-family: inherit;
      box-shadow:
        0 22px 56px color-mix(in srgb, #000 58%, transparent),
        0 0 0 1px color-mix(in srgb, var(--border) 55%, transparent);
    }
    .admin-wrap .theme-dropdown-panel[hidden] {
      display: none !important;
    }
    .admin-wrap .theme-dropdown-section + .theme-dropdown-section {
      margin-top: 0.75rem;
      padding-top: 0.75rem;
      border-top: 1px solid var(--border);
    }
    .admin-wrap .theme-dropdown-section-title {
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 0.45rem;
    }
    .admin-wrap .theme-mode-toggle {
      display: flex;
      gap: 0.35rem;
    }
    .admin-wrap .theme-mode-btn {
      flex: 1;
      min-width: 0;
      min-height: 2.5rem;
      padding: 0.35rem 0.5rem;
      display: grid;
      place-items: center;
      font-size: 1.05rem;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
      line-height: 1;
      transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .admin-wrap .theme-mode-btn:hover {
      border-color: var(--ring);
      background: color-mix(in srgb, var(--field-bg) 88%, var(--palette-soft));
    }
    .admin-wrap .theme-mode-btn.is-active {
      border-color: var(--ring);
      box-shadow: 0 0 0 2px color-mix(in srgb, var(--ring) 35%, transparent);
      background: color-mix(in srgb, var(--field-bg) 68%, var(--palette-soft));
    }
    .admin-wrap .palette-picker {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 0.35rem;
    }
    .admin-wrap .palette-swatch-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0;
      width: 100%;
      min-width: 0;
      min-height: 2.35rem;
      padding: 0.3rem;
      font-size: 0.72rem;
      font-weight: 600;
      font-family: inherit;
      line-height: 1;
      cursor: pointer;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
      white-space: nowrap;
      transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .admin-wrap .palette-swatch-btn:hover {
      border-color: var(--ring);
      background: color-mix(in srgb, var(--field-bg) 88%, var(--palette-soft));
    }
    .admin-wrap .palette-swatch-btn.is-active {
      border-color: var(--ring);
      box-shadow: 0 0 0 2px color-mix(in srgb, var(--ring) 45%, transparent);
      background: color-mix(in srgb, var(--field-bg) 70%, var(--palette-soft));
    }
    .admin-wrap .palette-swatch {
      display: inline-block;
      vertical-align: middle;
      width: 1.1rem;
      height: 1.1rem;
      border-radius: 50%;
      flex-shrink: 0;
      border: 1px solid rgb(255 255 255 / 22%);
      box-shadow: inset 0 0 0 1px rgb(0 0 0 / 15%);
    }
    html[data-theme="light"] .admin-wrap .palette-swatch {
      border-color: rgb(0 0 0 / 12%);
      box-shadow: inset 0 0 0 1px rgb(255 255 255 / 35%);
    }
    html[data-theme="light"] .admin-wrap .theme-dropdown-panel {
      box-shadow:
        0 16px 44px color-mix(in srgb, #000 14%, transparent),
        0 0 0 1px var(--border);
    }
    .admin-wrap .form-label {
      color: var(--text);
      font-weight: 600;
      margin-bottom: .35rem;
    }
    .admin-wrap .form-control,
    .admin-wrap .form-select {
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
    }
    .admin-wrap .form-control::placeholder {
      color: var(--muted);
    }
    .admin-wrap .form-control:focus,
    .admin-wrap .form-select:focus {
      border-color: var(--ring);
      box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--ring) 30%, transparent);
      background: var(--field-bg);
      color: var(--text);
    }
    .admin-wrap .form-check-input {
      border-color: var(--border);
      background-color: var(--field-bg);
    }
    .admin-wrap .form-check-input:checked {
      background-color: var(--accent);
      border-color: var(--accent-strong);
    }
    .admin-wrap .btn-outline-light {
      border-color: var(--border);
      color: var(--text);
    }
    .admin-wrap .btn-outline-light:hover {
      background: var(--accent);
      border-color: var(--accent);
      color: #fff;
    }
    .icon-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(46px, 1fr));
      gap: .5rem;
      margin-top: .5rem;
    }
    .icon-option {
      border: 1px solid var(--border);
      background: var(--field-bg);
      color: var(--text);
      border-radius: 10px;
      width: 100%;
      aspect-ratio: 1 / 1;
      padding: .25rem;
      text-align: center;
      display: flex;
      justify-content: center;
      align-items: center;
      font-size: 1rem;
    }
    .icon-option i {
      color: var(--icon-soft);
    }
    .icon-option.is-active {
      border-color: var(--ring);
      background: color-mix(in srgb, var(--accent) 35%, var(--field-bg));
      box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--ring) 28%, transparent);
    }
    #adminToolsAccordion.admin-tools-ordered {
      display: flex;
      flex-direction: column;
    }
    #adminToolsAccordion.admin-tools-ordered > .accordion-item {
      width: 100%;
      min-width: 0;
    }
    /* Orden por responsabilidad: sistema → contenido → equipo → portal */
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-config { order: 1; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-credentials { order: 2; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-routes { order: 3; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-service-edit { order: 4; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tools-experts,
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-experts-off { order: 5; }
    #adminToolsAccordion.admin-tools-ordered > #admin-tool-clients { order: 6; }
    .admin-portal-clients-body {
      padding-inline: 0.5rem;
      max-width: 100%;
    }
    .admin-portal-clients-body .table-responsive {
      margin-inline: -0.25rem;
      width: calc(100% + 0.5rem);
      max-width: none;
    }
    .admin-portal-clients-body .table td,
    .admin-portal-clients-body .table th {
      vertical-align: middle;
    }
    .admin-portal-clients-body .table td.font-monospace {
      word-break: break-word;
      max-width: 14rem;
    }
    .admin-portal-client-actions {
      gap: 0.35rem !important;
    }
    .admin-portal-client-actions form {
      margin: 0;
    }
    .admin-portal-client-actions .btn {
      min-width: 2.35rem;
      padding: 0.28rem 0.45rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .admin-portal-client-actions .btn i {
      font-size: 0.95rem;
      line-height: 1;
    }
    .admin-portal-clients-body .badge.portal-client-pill {
      font-weight: 600;
      font-size: 0.78rem;
      padding: 0.35em 0.65em;
      gap: 0.35em;
    }
    .admin-portal-clients-table .portal-client-toggle-btn {
      cursor: pointer;
      font-weight: 600;
      font-size: 0.78rem;
      padding: 0.35em 0.65em;
      gap: 0.35em;
      line-height: 1.2;
      text-decoration: none;
      transition: filter 0.12s ease, box-shadow 0.12s ease;
    }
    .admin-portal-clients-table .portal-client-toggle-btn:hover {
      filter: brightness(1.08);
    }
    .admin-portal-clients-table .portal-client-toggle-btn:focus-visible {
      outline: 2px solid color-mix(in srgb, var(--accent, #0d6efd) 85%, #fff);
      outline-offset: 2px;
    }
    .admin-experts-table.table-hover tbody tr {
      transition: background-color 0.12s ease;
    }
    .admin-expert-row-actions {
      gap: 0.35rem !important;
    }
    .admin-expert-row-actions form {
      margin: 0;
      display: inline-flex;
    }
    .admin-expert-row-actions .btn {
      min-width: 2.35rem;
      padding: 0.28rem 0.45rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .admin-expert-row-actions .btn i {
      font-size: 0.95rem;
      line-height: 1;
    }
    .admin-experts-table .badge.expert-pill {
      font-weight: 600;
      font-size: 0.78rem;
      padding: 0.35em 0.65em;
      gap: 0.35em;
    }
    .admin-experts-table {
      table-layout: fixed;
      width: 100%;
    }
    .admin-experts-table th.expert-services-col,
    .admin-experts-table td.expert-services-col {
      max-width: 14rem;
      width: 22%;
      min-width: 0;
      vertical-align: middle;
    }
    .admin-experts-table .expert-row-services {
      width: 100%;
      min-width: 0;
      justify-content: flex-start;
    }
    .admin-experts-table .expert-svc-icons-slot {
      flex: 1 1 auto;
      min-width: 0;
      overflow: hidden;
    }
    .admin-experts-table .expert-svc-icons-inner {
      display: inline-flex;
      flex-wrap: nowrap;
      align-items: center;
      gap: 0.25rem;
      vertical-align: middle;
    }
    .admin-experts-table .expert-svc-icon {
      width: 1.75rem;
      height: 1.75rem;
      font-size: 0.82rem;
      line-height: 1;
      cursor: help;
      flex-shrink: 0;
    }
    .admin-experts-table .expert-svc-overflow-plus {
      display: none;
      cursor: help;
    }
    .admin-experts-table .expert-svc-overflow-plus.is-visible {
      display: inline-flex;
    }
    .admin-experts-table .expert-svc-count-badge {
      flex-shrink: 0;
    }
    .admin-experts-table .expert-svc-icon i {
      line-height: 1;
    }
    .admin-services-accordion .accordion-item {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: .65rem;
    }
    .admin-services-accordion .accordion-button {
      background: var(--surface-2);
      color: var(--text);
      font-weight: 700;
      box-shadow: none;
    }
    .admin-services-accordion .accordion-button:not(.collapsed) {
      background: color-mix(in srgb, var(--accent) 22%, var(--surface-2));
      color: var(--text);
    }
    .admin-services-accordion .accordion-button::after {
      filter: invert(0.85);
    }
    html[data-theme="light"] .admin-services-accordion .accordion-button::after {
      filter: none;
    }
    .admin-services-accordion .accordion-body {
      background: color-mix(in srgb, var(--surface) 94%, transparent);
    }
    .admin-services-accordion .accordion-header-service-title {
      flex: 1;
      min-width: 0;
      text-align: left;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .gallery-tools {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: .6rem;
    }
    .gallery-input-hidden {
      display: none;
    }
    .gallery-thumbs {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
    }
    .gallery-thumb-wrap {
      display: flex;
      flex-direction: column;
      gap: 4px;
      width: 78px;
    }
    .gallery-thumb-wrap.is-draggable {
      cursor: grab;
    }
    .gallery-thumb-wrap.is-dragging {
      opacity: .45;
      cursor: grabbing;
    }
    .gallery-thumb-item {
      position: relative;
      width: 78px;
      height: 78px;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
    }
    .gallery-caption-input {
      width: 100%;
      font-size: 11px;
      padding: 2px 6px;
      height: auto;
      min-height: 24px;
    }
    .gallery-thumb-item img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .gallery-thumb-check {
      position: absolute;
      top: 4px;
      right: 4px;
      width: 16px;
      height: 16px;
      margin: 0;
      accent-color: var(--accent);
    }
    .gallery-thumb-check:checked + .gallery-mark-overlay {
      opacity: 1;
    }
    .gallery-mark-overlay {
      position: absolute;
      inset: 0;
      border: 2px solid #ef4444;
      background: rgba(239, 68, 68, 0.16);
      opacity: 0;
      pointer-events: none;
    }
    @media (min-width: 768px) { .admin-grid-2 { grid-template-columns: 1fr 1fr; } }

    .admin-messages-accordion .accordion-item {
      background-color: var(--surface);
      border: 1px solid var(--border);
    }
    .admin-messages-accordion .message-row { position: relative; }
    .admin-messages-accordion .message-header-row {
      display: grid;
      grid-template-columns: 1fr auto;
      align-items: center;
      background-color: var(--surface);
    }
    .admin-messages-accordion .message-header-row .accordion-button {
      min-width: 0;
    }
    .admin-messages-accordion .accordion-button {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-messages-accordion .accordion-button:not(.collapsed) {
      box-shadow: none;
    }
    .admin-messages-accordion .message-row.is-unread {
      border-left: 4px solid #ffc107;
      box-shadow: 0 0 0 1px color-mix(in srgb, #ffc107 35%, transparent);
    }
    .admin-inbox-threads .message-row.is-unread {
      border-left: 4px solid #ffc107;
      box-shadow: 0 0 0 1px color-mix(in srgb, #ffc107 35%, transparent);
    }
    .admin-messages-accordion .message-row.is-unread .accordion-button,
    .admin-messages-accordion .message-row.is-unread .message-header-row {
      background-color: color-mix(in srgb, #ffc107 14%, var(--surface));
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-turn-toolbar {
      background-color: color-mix(in srgb, #ffc107 14%, var(--surface));
    }
    .admin-messages-accordion .message-row.is-unread .accordion-button {
      font-weight: 700;
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-turn-head {
      font-weight: 700;
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-bubble--visitor {
      box-shadow: inset 3px 0 0 #ffc107;
      border-color: color-mix(in srgb, #ffc107 40%, var(--border));
    }
    .admin-messages-accordion .message-row.is-unread .accordion-button::before {
      content: "";
      width: .6rem;
      height: .6rem;
      border-radius: 50%;
      background-color: #ffc107;
      box-shadow: 0 0 0 4px color-mix(in srgb, #ffc107 35%, transparent);
      margin-right: .6rem;
      flex-shrink: 0;
      animation: msgUnreadPulse 1.6s ease-in-out infinite;
    }
    .admin-inbox-threads .message-row.is-unread .admin-msg-turn-head::before {
      content: "";
      width: .55rem;
      height: .55rem;
      border-radius: 50%;
      background-color: #ffc107;
      box-shadow: 0 0 0 4px color-mix(in srgb, #ffc107 35%, transparent);
      margin-right: .5rem;
      flex-shrink: 0;
      animation: msgUnreadPulse 1.6s ease-in-out infinite;
    }
    /* Mostrar el botón correcto según el estado del mensaje. Renderizamos
       siempre ambos forms (y el badge "Nuevo") para que tras un toggle por
       AJAX el opuesto quede disponible sin necesidad de refrescar la página. */
    .admin-messages-accordion .message-row.is-unread .js-mark-unread-form,
    .admin-inbox-threads .message-row.is-unread .js-mark-unread-form { display: none; }
    .admin-messages-accordion .message-row:not(.is-unread) .js-mark-read-form,
    .admin-inbox-threads .message-row:not(.is-unread) .js-mark-read-form { display: none; }
    .admin-messages-accordion .message-row.is-unread .js-wa-mark-unread-form { display: none; }
    .admin-messages-accordion .message-row:not(.is-unread) .js-wa-mark-read-form { display: none; }
    .admin-messages-accordion .message-row:not(.is-unread) .js-msg-new-badge,
    .admin-inbox-threads .message-row:not(.is-unread) .js-msg-new-badge { display: none; }
    @keyframes msgUnreadPulse {
      0%, 100% { box-shadow: 0 0 0 4px color-mix(in srgb, #ffc107 35%, transparent); }
      50%      { box-shadow: 0 0 0 7px color-mix(in srgb, #ffc107 10%, transparent); }
    }

    .admin-conv-groups-accordion .conv-group-row {
      border: 1px solid var(--border);
      border-radius: 10px;
      margin-bottom: 0.5rem;
      overflow: hidden;
      background-color: color-mix(in srgb, var(--surface) 92%, transparent);
    }
    .admin-conv-groups-accordion .conv-group-row.is-unread {
      border-left: 4px solid #0d6efd;
      box-shadow: 0 0 0 1px color-mix(in srgb, #0d6efd 25%, transparent);
    }
    .admin-conv-groups-accordion .conv-group-header .accordion-button {
      background-color: color-mix(in srgb, var(--surface) 88%, transparent);
      font-size: 0.9rem;
    }

    .admin-inbox-threads {
      display: flex;
      flex-direction: column;
      gap: 0.65rem;
    }
    .admin-msg-thread {
      border: 1px solid var(--border);
      border-radius: 10px;
      background: color-mix(in srgb, var(--surface) 96%, var(--muted));
      overflow: hidden;
    }
    .admin-msg-thread-summary {
      list-style: none;
      cursor: pointer;
      padding: 0.55rem 0.65rem;
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      background: color-mix(in srgb, var(--surface) 90%, transparent);
    }
    .admin-msg-thread-asunto-block {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 0.35rem 0.5rem;
      line-height: 1.35;
    }
    .admin-msg-thread-asunto-label {
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
      flex-shrink: 0;
    }
    .admin-msg-thread-asunto-text {
      font-weight: 600;
      font-size: 0.95rem;
      color: var(--text);
      min-width: 0;
      flex: 1;
    }
    .admin-msg-thread-summary::-webkit-details-marker { display: none; }
    .admin-msg-thread-summary::marker { content: ""; }
    .admin-msg-thread > summary {
      position: relative;
      padding-right: 1.25rem;
    }
    .admin-msg-thread > summary::after {
      content: "\f078";
      font-family: "Font Awesome 6 Free";
      font-weight: 900;
      position: absolute;
      right: 0.55rem;
      top: 50%;
      transform: translateY(-50%) rotate(0deg);
      font-size: 0.7rem;
      color: var(--muted);
      transition: transform 0.15s ease;
    }
    .admin-msg-thread[open] > summary::after {
      transform: translateY(-50%) rotate(180deg);
    }
    .admin-msg-thread-summary-main {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.35rem 0.55rem;
    }
    .admin-msg-conv-meta {
      font-size: 0.82rem;
      color: var(--muted);
    }
    .admin-msg-thread-snippet {
      display: block;
      font-size: 0.82rem;
      line-height: 1.35;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
    }
    .admin-msg-thread-body {
      padding: 0.65rem 0.7rem 0.85rem;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      border-top: 1px solid var(--border);
      background: var(--surface);
    }
    .admin-msg-chat-stream {
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
    }
    .admin-msg-chat-stream .admin-msg-turn + .admin-msg-turn {
      margin-top: 0.35rem;
      padding-top: 0.35rem;
      border-top: none;
    }
    .admin-msg-chat-stream .admin-msg-turn--continuation .admin-msg-turn-toolbar {
      margin-bottom: 0.15rem;
    }
    .admin-msg-chat-stream .admin-msg-turn--continuation .admin-msg-bubble--visitor .admin-msg-bubble-label,
    .admin-msg-chat-stream .admin-msg-turn--continuation .admin-msg-bubble--admin .admin-msg-bubble-label {
      display: none;
    }
    .admin-msg-chat-stream .admin-msg-turn--continuation .message-mark-actions {
      margin-top: 0.05rem;
    }
    .admin-msg-turn {
      border-left: none;
      margin-left: 0;
      padding-left: 0;
      border-radius: 0;
    }
    /* Línea guía en todos los pasos (incl. el 1); mayor especificidad que .admin-msg-turn */
    .admin-msg-chat-stream > .admin-msg-turn {
      padding-left: 0.45rem;
      border-left: 2px solid color-mix(in srgb, var(--accent) 35%, var(--border));
      margin-left: 0.1rem;
    }
    .admin-msg-thread-id {
      display: inline-block;
      margin-top: 0.35rem;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      font-variant-numeric: tabular-nums;
      padding: 0.18rem 0.42rem;
      border-radius: 5px;
      background: color-mix(in srgb, var(--muted) 18%, var(--surface));
      border: 1px solid var(--border);
      color: var(--muted);
    }
    .admin-msg-id-chip {
      display: inline-block;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      font-variant-numeric: tabular-nums;
      padding: 0.18rem 0.42rem;
      border-radius: 5px;
      background: color-mix(in srgb, var(--accent) 16%, var(--surface));
      border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--border));
      color: var(--text);
    }
    .admin-msg-turn-main {
      min-width: 0;
    }
    .admin-msg-turn-inner {
      display: flex;
      flex-direction: column;
      gap: 0.55rem;
      align-items: stretch;
    }
    .admin-side-inbox-card .admin-msg-turn-inner {
      gap: 0.45rem;
      min-width: 0;
    }
    /* Chat: visitante a la izquierda, respuestas admin a la derecha; ancho según panel */
    .admin-msg-bubble {
      border-radius: 10px;
      padding: 0.65rem 0.75rem;
      margin-bottom: 0;
      border: 1px solid var(--border);
      box-sizing: border-box;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .admin-msg-bubble--visitor {
      align-self: flex-start;
      max-width: min(100%, 32rem);
      width: fit-content;
      min-width: 0;
      margin-right: auto;
      background: color-mix(in srgb, var(--accent) 16%, var(--field-bg));
      border-color: color-mix(in srgb, var(--accent) 30%, var(--border));
      border-left-width: 3px;
      border-left-style: solid;
      border-left-color: color-mix(in srgb, var(--accent) 65%, var(--border));
    }
    .admin-msg-bubble--admin {
      align-self: flex-end;
      max-width: min(100%, 32rem);
      width: fit-content;
      min-width: 0;
      margin-left: auto;
      margin-right: 0;
      text-align: start;
      background: color-mix(in srgb, var(--muted) 12%, var(--field-bg));
      border-color: color-mix(in srgb, var(--muted) 28%, var(--border));
      border-right-width: 3px;
      border-right-style: solid;
      border-right-color: color-mix(in srgb, var(--accent) 45%, var(--muted));
    }
    .admin-side-inbox-card .admin-msg-turn-toolbar {
      margin-bottom: 0.25rem;
    }
    .admin-side-inbox-card .admin-msg-id-chip {
      font-size: 0.65rem;
      padding: 0.12rem 0.32rem;
    }
    .admin-side-inbox-card .admin-msg-thread {
      border-radius: 8px;
    }
    .admin-side-inbox-card .admin-msg-thread-summary {
      padding: 0.4rem 0.5rem;
      gap: 0.2rem;
    }
    .admin-side-inbox-card .admin-msg-thread-asunto-label {
      display: none;
    }
    .admin-side-inbox-card .admin-msg-thread-asunto-text {
      font-size: 0.88rem;
      line-height: 1.25;
    }
    .admin-side-inbox-card .admin-msg-thread-id {
      display: none;
    }
    .admin-side-inbox-card .admin-msg-thread-summary-main {
      gap: 0.25rem 0.4rem;
      font-size: 0.78rem;
    }
    .admin-side-inbox-card .admin-msg-thread-summary-main .message-meta-service {
      max-width: 9rem;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .admin-side-inbox-card .admin-msg-thread-snippet {
      font-size: 0.76rem;
    }
    .admin-side-inbox-card .admin-msg-thread-body {
      padding: 0.45rem 0.5rem 0.6rem;
      gap: 0.55rem;
    }
    .admin-side-inbox-card .admin-conv-groups-accordion .conv-group-header .accordion-button {
      font-size: 0.82rem;
      line-height: 1.25;
      padding-block: 0.45rem;
    }
    .admin-side-inbox-card .admin-inbox-grp-meta {
      font-size: 0.72rem;
      color: var(--muted);
      margin-bottom: 0.35rem;
    }
    .admin-side-inbox-card .message-mark-actions {
      justify-content: flex-end;
      margin-top: 0.15rem;
    }
    .admin-msg-bubble-label {
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      margin-bottom: 0.4rem;
      opacity: 0.95;
    }
    .admin-msg-reply-below {
      margin-top: 0.45rem;
    }
    .admin-msg-reply-thread-end {
      margin-top: 0.75rem;
      padding-top: 0.85rem;
      border-top: 1px solid color-mix(in srgb, var(--border) 85%, transparent);
    }
    .admin-msg-turn-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.35rem;
      margin-bottom: 0.35rem;
    }
    .admin-msg-turn-head {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.35rem 0.5rem;
      min-width: 0;
      flex: 1;
    }

    .admin-layout--inbox-wide {
      display: flex !important;
      flex-direction: column;
      gap: 1rem;
    }
    .admin-layout--inbox-wide .admin-main {
      display: block !important;
      order: 2;
      width: 100%;
    }
    .admin-layout--inbox-wide .admin-side {
      order: 1;
      position: static;
      max-height: none;
      overflow: visible;
      width: 100%;
    }
    .admin-layout--inbox-wide .admin-side-inbox-card {
      max-width: 960px;
      margin-left: auto;
      margin-right: auto;
    }

    .admin-messages-accordion .message-delete-form,
    .admin-inbox-threads .message-delete-form {
      display: flex;
      align-items: center;
      flex: 0 0 auto;
      flex-shrink: 0;
      margin: 0;
      padding: 0 .55rem;
      background-color: transparent;
    }
    .admin-messages-accordion .btn-message-delete,
    .admin-inbox-threads .btn-message-delete {
      width: 2rem;
      height: 2rem;
      border-radius: 50%;
      border: 1px solid var(--border);
      padding: 0;
      background-color: var(--field-bg);
      color: var(--muted);
      display: grid;
      place-items: center;
      font-size: .8rem;
      line-height: 1;
      cursor: pointer;
      transition: background-color .15s ease, color .15s ease, border-color .15s ease;
    }
    .admin-messages-accordion .btn-message-delete:hover,
    .admin-messages-accordion .btn-message-delete:focus-visible,
    .admin-inbox-threads .btn-message-delete:hover,
    .admin-inbox-threads .btn-message-delete:focus-visible {
      background-color: #dc3545;
      border-color: #dc3545;
      color: #fff;
      outline: none;
    }
    .admin-messages-accordion .btn-message-delete i,
    .admin-inbox-threads .btn-message-delete i {
      display: block;
      line-height: 1;
      width: 1em;
      height: 1em;
      text-align: center;
      font-size: inherit;
    }
    .admin-messages-counter {
      font-variant-numeric: tabular-nums;
      letter-spacing: .02em;
    }
    .admin-side-inbox-accordion .accordion-item {
      background: transparent;
      border-color: var(--border);
    }
    .admin-side-inbox-accordion .accordion-button {
      background-color: var(--surface);
      color: var(--text);
      font-weight: 600;
      font-size: 1.05rem;
      box-shadow: none;
    }
    .admin-side-inbox-accordion .accordion-button:not(.collapsed) {
      background-color: color-mix(in srgb, var(--accent) 14%, var(--surface));
      color: var(--text);
    }
    .admin-side-inbox-accordion .accordion-button:focus {
      box-shadow: none;
      border-color: var(--border);
    }
    .admin-side-inbox-accordion .accordion-body {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-messages-accordion .message-meta-date,
    .admin-inbox-threads .message-meta-date {
      font-variant-numeric: tabular-nums;
      font-size: .9rem;
    }
    .admin-messages-accordion .message-meta-service,
    .admin-inbox-threads .message-meta-service {
      font-size: .9rem;
    }
    .admin-messages-accordion .message-meta-email {
      font-size: .85rem;
    }
    .admin-messages-accordion .accordion-body {
      background-color: var(--surface);
      color: var(--text);
    }
    .admin-messages-accordion .message-body-text,
    .admin-inbox-threads .message-body-text {
      white-space: pre-wrap;
      background-color: var(--field-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: .8rem;
      max-height: 320px;
      overflow: auto;
    }
    .admin-messages-accordion .message-replies-sent,
    .admin-inbox-threads .message-replies-sent {
      border-left: 3px solid color-mix(in srgb, var(--accent) 55%, var(--border));
      padding-left: .75rem;
      margin-bottom: 1rem;
    }
    .admin-messages-accordion .message-reply-item,
    .admin-inbox-threads .message-reply-item {
      margin-bottom: .75rem;
    }
    .admin-messages-accordion .message-reply-item:last-child,
    .admin-inbox-threads .message-reply-item:last-child {
      margin-bottom: 0;
    }
    .admin-messages-accordion .message-reply-form textarea,
    .admin-inbox-threads .message-reply-form textarea {
      min-height: 5rem;
    }
    .gallery-meta-stack {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      flex: 1;
      min-width: 0;
    }
    .gallery-desc-input {
      min-height: 4.5rem;
      resize: vertical;
    }
    .expert-service-checks {
      display: grid;
      gap: 0.35rem;
      max-height: 240px;
      overflow: auto;
    }
  </style>
</head>
<body>
  <?php if (!$isLogged): ?>
    <div class="login-shell">
      <div class="card login-card p-4 p-md-4">
        <div class="text-center mb-4">
          <div class="mb-2"><i class="fa-solid fa-shield-halved fa-2x text-light"></i></div>
          <h1 class="login-title mb-1">Panel de Administración</h1>
          <p class="login-subtitle mb-0">Ingresa para gestionar tu web personal.</p>
        </div>

        <?php if ($message !== ""): ?><p class="admin-msg"><?= h($message) ?></p><?php endif; ?>
        <?php if ($error !== ""): ?><p class="admin-err"><?= h($error) ?></p><?php endif; ?>

        <?php if ($resetTokenFromUrl !== ""): ?>
          <form method="post" class="d-grid gap-3">
            <input type="hidden" name="action" value="reset_admin_password">
            <input type="hidden" name="reset_token" value="<?= h($resetTokenFromUrl) ?>">
            <div>
              <label for="new_admin_password" class="form-label text-light fw-semibold">Nueva clave</label>
              <div class="password-wrap">
                <input id="new_admin_password" type="password" name="new_admin_password" class="form-control login-input" required minlength="10" autocomplete="new-password" placeholder="••••••••">
                <button type="button" class="password-toggle js-password-toggle" data-target="new_admin_password" aria-label="Mostrar clave" aria-pressed="false">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>
            <div>
              <label for="confirm_admin_password" class="form-label text-light fw-semibold">Confirmar nueva clave</label>
              <div class="password-wrap">
                <input id="confirm_admin_password" type="password" name="confirm_admin_password" class="form-control login-input" required minlength="10" autocomplete="new-password" placeholder="••••••••">
                <button type="button" class="password-toggle js-password-toggle" data-target="confirm_admin_password" aria-label="Mostrar clave" aria-pressed="false">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
              <div class="form-text text-light-emphasis">Mínimo 10 caracteres con mayúscula, minúscula y número.</div>
            </div>
            <button class="btn btn-primary btn-lg fw-semibold" type="submit">
              <i class="fa-solid fa-key me-2"></i>Restablecer clave
            </button>
            <div class="login-help">
              <a href="admin.php" class="login-link">Volver al inicio de sesión</a>
            </div>
          </form>
        <?php else: ?>
          <form method="post" class="d-grid gap-3 login-view" data-view="login" <?= $showResetView ? "hidden" : "" ?>>
            <input type="hidden" name="action" value="login">
            <div>
              <label for="email" class="form-label text-light fw-semibold">Correo</label>
              <input id="email" type="email" name="email" class="form-control login-input" required placeholder="tu@correo.com" autocomplete="username">
            </div>
            <div>
              <label for="password" class="form-label text-light fw-semibold">Clave</label>
              <div class="password-wrap">
                <input id="password" type="password" name="password" class="form-control login-input" required placeholder="••••••••" autocomplete="current-password">
                <button type="button" class="password-toggle js-password-toggle" data-target="password" aria-label="Mostrar clave" aria-pressed="false">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>
            <button class="btn btn-primary btn-lg fw-semibold" type="submit">
              <i class="fa-solid fa-right-to-bracket me-2"></i>Iniciar sesión
            </button>
            <div class="login-help">
              <a href="#" class="login-link js-show-view" data-target-view="reset">¿Olvidaste tu clave?</a>
            </div>
          </form>

          <form method="post" class="d-grid gap-3 login-view" data-view="reset" <?= $showResetView ? "" : "hidden" ?>>
            <input type="hidden" name="action" value="request_admin_password_reset">
            <p class="login-subtitle small mb-0">Te enviaremos un enlace al correo del administrador para restablecer tu clave.</p>
            <div>
              <label for="reset_email" class="form-label text-light fw-semibold">Correo del administrador</label>
              <input id="reset_email" type="email" name="reset_email" class="form-control login-input" required placeholder="tu@correo.com" autocomplete="username">
            </div>
            <button class="btn btn-primary btn-lg fw-semibold" type="submit">
              <i class="fa-solid fa-envelope me-2"></i>Enviar enlace
            </button>
            <div class="login-help">
              <a href="#" class="login-link js-show-view" data-target-view="login">Volver al inicio de sesión</a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
  <div class="admin-wrap">
    <div class="card admin-header-card p-3 p-md-4 mb-3">
      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div>
          <h1 class="h3 mb-1"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Panel de Administración</h1>
          <p class="mb-0 text-light-emphasis">Sesión: <?= h($_SESSION["admin_email"] ?? "") ?></p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap admin-theme-toolbar">
          <?php require __DIR__ . "/palette_picker.php"; ?>
          <a href="admin.php?logout=1" class="btn btn-outline-light">
            <i class="fa-solid fa-right-from-bracket me-2"></i>Cerrar sesión
          </a>
        </div>
      </div>
      <?php if ($message !== ""): ?><div class="alert <?= h($messageAlertClass) ?> mt-3 mb-0"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error !== ""): ?><div class="alert alert-danger mt-3 mb-0"><?= h($error) ?></div><?php endif; ?>
    </div>

    <div class="admin-layout<?= $adminInboxWide ? " admin-layout--inbox-wide" : "" ?>">
      <?php if ($adminInboxWide): ?>
      <div class="alert alert-info py-2 px-3 mb-0 order-0" style="width:100%;">
        <strong>Vista amplia de mensajes</strong> (<code>?inbox=1</code>): la bandeja va primero; el panel de herramientas (servicios, expertos, configuración) sigue debajo.
        <a href="admin.php" class="alert-link ms-1">Quitar vista amplia</a>
      </div>
      <?php endif; ?>
      <div class="admin-main">
        <div class="accordion admin-tools-accordion admin-tools-ordered mb-3" id="adminToolsAccordion">

          <div class="accordion-item" id="admin-tool-routes">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_routes_panel"
                aria-expanded="false" aria-controls="tools_routes_panel">
                <i class="fa-solid fa-link me-2"></i>Rutas (landing y admin)
              </button>
            </h2>
            <div id="tools_routes_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <p class="small text-light-emphasis mb-3"><?= h(app_public_url_source_description()) ?></p>
                <div class="mb-3">
                  <label class="form-label mb-1">Landing pública</label>
                  <div class="input-group">
                    <input type="text" class="form-control font-monospace small" readonly value="<?= h(app_landing_url()) ?>">
                    <a class="btn btn-outline-secondary" href="<?= h(app_landing_url()) ?>" target="_blank" rel="noopener">Abrir</a>
                  </div>
                </div>
                <div class="mb-3">
                  <label class="form-label mb-1">Panel de administración</label>
                  <div class="input-group">
                    <input type="text" class="form-control font-monospace small" readonly value="<?= h(app_admin_url()) ?>">
                    <a class="btn btn-outline-secondary" href="<?= h(app_admin_url()) ?>" target="_blank" rel="noopener">Abrir</a>
                  </div>
                </div>
                <div class="mb-0">
                  <label class="form-label mb-1">Área de clientes en la landing</label>
                  <div class="input-group">
                    <input type="text" class="form-control font-monospace small" readonly value="<?= h(app_client_portal_url()) ?>">
                    <a class="btn btn-outline-secondary" href="<?= h(app_client_portal_url()) ?>" target="_blank" rel="noopener">Abrir</a>
                  </div>
                  <div class="form-text text-light-emphasis mt-1">Misma página pública: sección «Clientes».</div>
                </div>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-clients">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_clients_panel"
                aria-expanded="false" aria-controls="tools_clients_panel">
                <i class="fa-solid fa-user-group me-2"></i>Portal de clientes
              </button>
            </h2>
            <div id="tools_clients_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body admin-portal-clients-body">
                <p class="small text-light-emphasis mb-3">
                  Los visitantes se registran solos en la landing (sección <strong>Área de clientes</strong>).
                  Desde aquí solo moderas: desactivar o borrar cuentas. La sesión de cliente es independiente del admin.
                  En <strong>Cuenta</strong> y <strong>Correo SMTP</strong>, las pastillas muestran el estado actual: <strong>pulsa</strong> una para alternar (icono + texto).
                  <strong>Correo SMTP:</strong> si está en «Correo», al responder desde Mensajes se intentará enviar por SMTP; en «Solo web» la respuesta queda solo en la bandeja del área de cliente.
                </p>
                <?php if (count($portalClients) === 0): ?>
                  <p class="small text-light-emphasis mb-0">Aún no hay cuentas. Comparte la URL del acordeón «Rutas» o el enlace «Clientes» del menú de la web.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-hover table-borderless align-middle mb-0 admin-portal-clients-table">
                      <thead>
                        <tr class="text-secondary small">
                          <th scope="col">Correo</th>
                          <th scope="col">Nombre</th>
                          <th scope="col">Cuenta</th>
                          <th scope="col">Correo SMTP</th>
                          <th scope="col" class="text-end">Acciones</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($portalClients as $pc): ?>
                          <?php
                            $pid = (int)($pc["id"] ?? 0);
                            $active = (int)($pc["is_active"] ?? 0) === 1;
                            $notifyOut = (int)($pc["email_notify_outbound"] ?? 1) === 1;
                          ?>
                          <tr>
                            <td class="font-monospace small text-break"><?= h((string)($pc["email"] ?? "")) ?></td>
                            <td class="small"><?= h((string)($pc["display_name"] ?? "")) ?></td>
                            <td>
                              <form method="post" class="d-inline m-0 js-portal-client-toggle" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="client_toggle_active">
                                <input type="hidden" name="client_id" value="<?= $pid ?>">
                                <button
                                  type="submit"
                                  class="btn btn-sm rounded-pill portal-client-pill portal-client-toggle-btn border-0 d-inline-flex align-items-center text-nowrap <?= $active ? "text-bg-success" : "text-bg-secondary" ?>"
                                  title="<?= $active ? "Cuenta activa: puede iniciar sesión. Pulsa para desactivar (no podrá entrar)." : "Cuenta inactiva. Pulsa para reactivar el acceso." ?>"
                                  aria-label="<?= $active ? "Cuenta activa, pulsar para desactivar" : "Cuenta inactiva, pulsar para activar" ?>"
                                  onclick="event.stopPropagation();"
                                >
                                  <?php if ($active): ?>
                                    <i class="fa-solid fa-user-check" aria-hidden="true"></i><span class="ms-1">Activo</span>
                                  <?php else: ?>
                                    <i class="fa-solid fa-user-slash" aria-hidden="true"></i><span class="ms-1">Inactivo</span>
                                  <?php endif; ?>
                                </button>
                              </form>
                            </td>
                            <td>
                              <form method="post" class="d-inline m-0 js-portal-client-toggle" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="client_toggle_email_notify">
                                <input type="hidden" name="client_id" value="<?= $pid ?>">
                                <button
                                  type="submit"
                                  class="btn btn-sm rounded-pill portal-client-pill portal-client-toggle-btn border-0 d-inline-flex align-items-center text-nowrap <?= $notifyOut ? "text-bg-info" : "text-bg-secondary border border-secondary" ?>"
                                  title="<?= $notifyOut ? "Envío por correo activo (SMTP al responder). Pulsa para solo bandeja web." : "Solo bandeja web. Pulsa para intentar envío SMTP al responder desde Mensajes." ?>"
                                  aria-label="<?= $notifyOut ? "Correo SMTP activo, pulsar para desactivar" : "Solo web, pulsar para activar envío SMTP" ?>"
                                  onclick="event.stopPropagation();"
                                >
                                  <?php if ($notifyOut): ?>
                                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span class="ms-1">Correo</span>
                                  <?php else: ?>
                                    <i class="fa-solid fa-display" aria-hidden="true"></i><span class="ms-1">Solo web</span>
                                  <?php endif; ?>
                                </button>
                              </form>
                            </td>
                            <td class="text-end">
                              <div class="d-inline-flex flex-wrap align-items-center justify-content-end admin-portal-client-actions">
                                <form method="post" class="m-0" onsubmit="return confirm('¿Eliminar este cliente? No se puede deshacer.');" onclick="event.stopPropagation();">
                                  <input type="hidden" name="action" value="client_delete">
                                  <input type="hidden" name="client_id" value="<?= $pid ?>">
                                  <button
                                    type="submit"
                                    class="btn btn-outline-danger btn-sm"
                                    title="Eliminar cliente de forma permanente"
                                    aria-label="Eliminar cliente"
                                    onclick="event.stopPropagation();"
                                  >
                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden"> Eliminar</span>
                                  </button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-config">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_config_panel"
                aria-expanded="false" aria-controls="tools_config_panel">
                <i class="fa-solid fa-sliders me-2"></i>Configuración general
              </button>
            </h2>
            <div id="tools_config_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="action" value="save_settings">
        <div class="row g-3">
          <div>
            <label class="form-label">Nombre persona</label>
            <input class="form-control" type="text" name="person_name" value="<?= h($settings["person_name"] ?? "") ?>" required>
          </div>
          <div>
            <label class="form-label">Marca / nombre corto</label>
            <input class="form-control" type="text" name="brand_name" value="<?= h($settings["brand_name"] ?? "") ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Correo receptor del formulario</label>
            <input class="form-control" type="email" name="contact_email" value="<?= h($settings["contact_email"] ?? "") ?>" required>
            <div class="form-text text-light-emphasis">Los mensajes del formulario se intentarán enviar aquí (al correo receptor).</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">WhatsApp de contacto (opcional)</label>
            <?php
            $waCcDigits = preg_replace('/\D+/', '', (string)($settings["contact_whatsapp_country_code"] ?? "")) ?? "";
            $waCcDigits = substr($waCcDigits, 0, 3);
            $waLocalDigits = preg_replace('/\D+/', '', (string)($settings["contact_whatsapp"] ?? "")) ?? "";
            ?>
            <div class="input-group wa-phone-input-group">
              <span class="input-group-text" title="Prefijo internacional">+</span>
              <input class="form-control wa-cc-field" type="text" name="contact_whatsapp_country" value="<?= h($waCcDigits) ?>" placeholder="57" maxlength="3" inputmode="numeric" pattern="[0-9]{0,3}" autocomplete="tel-country-code" aria-label="Indicativo (máx. 3 dígitos)">
              <input class="form-control wa-local-field" type="tel" name="contact_whatsapp_local" value="<?= h($waLocalDigits) ?>" placeholder="300 123 4567" inputmode="numeric" autocomplete="tel-national" aria-label="Número de teléfono (sin indicativo)">
            </div>
            <div class="form-text text-light-emphasis">Vacío: sin botón WhatsApp en la web.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Texto footer</label>
            <input class="form-control" type="text" name="footer_text" value="<?= h($settings["footer_text"] ?? "") ?>" required>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto principal hero</label>
          <textarea class="form-control" name="hero_title" rows="2" required><?= h($settings["hero_title"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto secundario hero</label>
          <textarea class="form-control" name="hero_intro" rows="2" required><?= h($settings["hero_intro"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto sobre mí</label>
          <textarea class="form-control" name="about_text" rows="3" required><?= h($settings["about_text"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Texto contacto</label>
          <textarea class="form-control" name="contact_intro" rows="2" required><?= h($settings["contact_intro"] ?? "") ?></textarea>
        </div>
        <div class="mt-3">
          <label class="form-label">Logo del sitio (opcional)</label>
          <input type="hidden" name="current_logo_image_path" value="<?= h((string)($settings["logo_image_path"] ?? "")) ?>">
          <?php if (!empty($settings["logo_image_path"])): ?>
            <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
              <img src="<?= h((string)$settings["logo_image_path"]) ?>" alt="Logo actual" class="logo-preview-admin">
              <label class="form-check m-0 d-inline-flex align-items-center gap-2">
                <input class="form-check-input" type="checkbox" name="remove_logo_image" value="1">
                <span class="form-check-label">Quitar logo personalizado</span>
              </label>
            </div>
          <?php endif; ?>
          <input class="form-control" type="file" name="logo_image_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
          <div class="form-text text-light-emphasis">
            Si no subes nada, la web muestra un logo automático con la(s) primera(s)
            letra(s) de la marca. Formatos: PNG, JPG, WEBP, GIF o SVG.
          </div>
        </div>
                  <button class="btn btn-primary mt-3" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar configuración</button>
                </form>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-credentials">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_credentials_panel"
                aria-expanded="false" aria-controls="tools_credentials_panel">
                <i class="fa-solid fa-key me-2"></i>Credenciales Admin
              </button>
            </h2>
            <div id="tools_credentials_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <form method="post" class="row g-3">
                  <input type="hidden" name="action" value="change_admin_credentials">
                  <div class="col-md-6">
                    <label class="form-label">Correo admin nuevo</label>
                    <input
                      class="form-control"
                      type="email"
                      name="new_admin_email"
                      value="<?= h((string)($_SESSION["admin_email"] ?? "")) ?>"
                      required
                    >
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Clave actual</label>
                    <input class="form-control" type="password" name="current_admin_password" autocomplete="current-password" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Clave nueva</label>
                    <input class="form-control" type="password" name="new_admin_password" autocomplete="new-password" minlength="10" required>
                    <div class="form-text text-light-emphasis">Minimo 10 caracteres con mayuscula, minuscula y numero.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Confirmar clave nueva</label>
                    <input class="form-control" type="password" name="confirm_admin_password" autocomplete="new-password" minlength="10" required>
                  </div>
                  <div class="col-12">
                    <button class="btn btn-primary" type="submit">
                      <i class="fa-solid fa-shield-halved me-2"></i>Actualizar credenciales
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <div class="accordion-item" id="admin-tool-service-edit">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_edit_panel"
                aria-expanded="false" aria-controls="tools_edit_panel">
                <i class="fa-solid fa-briefcase me-2"></i>Servicios
              </button>
            </h2>
            <div id="tools_edit_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <p class="small text-light-emphasis mb-3">
                  Pulsa <strong><i class="fa-solid fa-circle-plus me-1" aria-hidden="true"></i>Agregar servicio</strong> para desplegar el mismo esquema que al editar (vacío). Abajo, cada servicio guardado tiene su bloque con carrusel y opción de borrar.
                </p>
                <div class="mb-3">
                  <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-toggle="collapse"
                    data-bs-target="#collapse_new_service_panel"
                    aria-expanded="false"
                    aria-controls="collapse_new_service_panel"
                    title="Agregar servicio"
                  >
                    <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Agregar servicio
                  </button>
                </div>
                <div id="collapse_new_service_panel" class="collapse mb-4 service-add-panel-collapse">
                  <div class="border border-secondary rounded-3 p-3 bg-body-tertiary bg-opacity-25">
                    <form method="post" enctype="multipart/form-data" id="form-add-service">
                      <input type="hidden" name="action" value="add_service">
                      <div class="row g-3">
                        <div class="col-md-6">
                          <label class="form-label" for="new_service_title">Título</label>
                          <input id="new_service_title" class="form-control" type="text" name="title" placeholder="Título del servicio" required>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Icono</label>
                          <div id="new_service_icon_picker" class="icon-grid icon-picker" data-target-input="new_service_icon_input">
                            <?php foreach ($iconOptions as $iconClass => $iconLabel): ?>
                              <button type="button" class="icon-option <?= ($iconClass === "fa-solid fa-star") ? "is-active" : "" ?>" data-icon="<?= h($iconClass) ?>">
                                <i class="<?= h($iconClass) ?>"></i>
                              </button>
                            <?php endforeach; ?>
                          </div>
                          <input id="new_service_icon_input" type="hidden" name="icon_class" value="fa-solid fa-star">
                        </div>
                        <div class="col-md-2">
                          <label class="form-label" for="new_service_sort">Orden</label>
                          <input id="new_service_sort" class="form-control" type="number" name="sort_order" value="999" min="0" max="999999">
                        </div>
                        <div class="col-md-10">
                          <label class="form-label" for="new_service_description">Descripción</label>
                          <textarea id="new_service_description" class="form-control" name="description" rows="2" placeholder="Descripción visible en la web" required></textarea>
                        </div>
                        <div class="col-md-12">
                          <label class="form-label" for="new_service_image">Imagen del servicio (opcional)</label>
                          <input id="new_service_image" class="form-control" type="file" name="image_file" accept="image/png,image/jpeg,image/webp,image/gif">
                        </div>
                        <div class="col-md-12">
                          <label class="form-label">Carrusel / galería</label>
                          <p class="form-text text-light-emphasis mb-0">Cuando exista el servicio en el listado inferior, podrás subir imágenes, títulos y orden del carrusel desde su bloque.</p>
                        </div>
                        <div class="col-md-12">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="new_service_is_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="new_service_is_active">Activo</label>
                          </div>
                        </div>
                      </div>
                      <div class="admin-actions mt-3 mb-0 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">
                          <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Crear servicio
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="cancel_new_service_btn" aria-controls="collapse_new_service_panel">
                          <i class="fa-solid fa-xmark me-2" aria-hidden="true"></i>Cancelar
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="action" value="save_services">
        <div class="accordion admin-services-accordion" id="adminServicesAccordion">
        <?php foreach ($services as $service): ?>
          <?php
            $svcId = (int)$service["id"];
            $collapseId = "collapse_svc_" . $svcId;
          ?>
          <div class="accordion-item service-row">
            <h3 class="accordion-header m-0">
              <button
                class="accordion-button collapsed d-flex align-items-center gap-2"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#<?= h($collapseId) ?>"
                aria-expanded="false"
                aria-controls="<?= h($collapseId) ?>"
              >
                <i class="<?= h($service["icon_class"] ?: "fa-solid fa-star") ?>"></i>
                <span class="accordion-header-service-title"><?= h($service["title"]) ?></span>
                <?php if ((int)$service["is_active"] !== 1): ?>
                  <span class="badge text-bg-secondary ms-1">Inactivo</span>
                <?php endif; ?>
              </button>
            </h3>
            <div id="<?= h($collapseId) ?>" class="accordion-collapse collapse" data-bs-parent="#adminServicesAccordion">
              <div class="accordion-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Título</label>
                <input class="form-control" type="text" name="services[<?= (int)$service["id"] ?>][title]" value="<?= h($service["title"]) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Icono</label>
                <div class="icon-grid icon-picker" data-target-input="icon_input_<?= (int)$service["id"] ?>">
                  <?php foreach ($iconOptions as $iconClass => $iconLabel): ?>
                    <button type="button" class="icon-option <?= ($iconClass === $service["icon_class"]) ? "is-active" : "" ?>" data-icon="<?= h($iconClass) ?>">
                      <i class="<?= h($iconClass) ?>"></i>
                    </button>
                  <?php endforeach; ?>
                </div>
                <input
                  id="icon_input_<?= (int)$service["id"] ?>"
                  type="hidden"
                  name="services[<?= (int)$service["id"] ?>][icon_class]"
                  value="<?= h($service["icon_class"]) ?>"
                >
              </div>
              <div class="col-md-2">
                <label class="form-label">Orden</label>
                <input class="form-control" type="number" name="services[<?= (int)$service["id"] ?>][sort_order]" value="<?= (int)$service["sort_order"] ?>">
              </div>
              <div class="col-md-10">
                <label class="form-label">Descripción</label>
                <textarea class="form-control" name="services[<?= (int)$service["id"] ?>][description]" rows="2" required><?= h($service["description"]) ?></textarea>
              </div>
              <div class="col-md-12">
                <label class="form-label">Imagen del servicio</label>
                <?php if (!empty($service["image_path"])): ?>
                  <div class="mb-2">
                    <img src="<?= h($service["image_path"]) ?>" alt="Imagen servicio" style="width:120px;height:80px;object-fit:cover;border-radius:10px;border:1px solid var(--border);">
                  </div>
                <?php endif; ?>
                <input class="form-control" type="file" name="service_images[<?= (int)$service["id"] ?>]" accept="image/png,image/jpeg,image/webp,image/gif">
                <input type="hidden" name="services[<?= (int)$service["id"] ?>][current_image_path]" value="<?= h((string)($service["image_path"] ?? "")) ?>">
              </div>
              <div class="col-md-12">
                <label class="form-label">Carrusel / galería (título y descripción por imagen)</label>
                <div class="gallery-tools">
                  <button
                    type="button"
                    class="btn btn-outline-light btn-sm js-gallery-pick-btn"
                    data-input-id="gallery_input_<?= (int)$service["id"] ?>"
                  >
                    <i class="fa-solid fa-images me-2"></i>Elegir más
                  </button>
                  <button
                    type="button"
                    class="btn btn-outline-danger btn-sm js-gallery-remove-btn"
                    data-service-id="<?= (int)$service["id"] ?>"
                  >
                    <i class="fa-solid fa-trash me-2"></i>Eliminar seleccionadas
                  </button>
                </div>
                <input
                  id="gallery_input_<?= (int)$service["id"] ?>"
                  class="gallery-input-hidden"
                  type="file"
                  name="gallery_images[<?= (int)$service["id"] ?>][]"
                  accept="image/png,image/jpeg,image/webp,image/gif"
                  data-preview-target="gallery_preview_<?= (int)$service["id"] ?>"
                  multiple
                >
                <div id="gallery_preview_<?= (int)$service["id"] ?>" class="gallery-thumbs"></div>
                <?php $serviceGallery = $galleryByService[(int)$service["id"]] ?? []; ?>
                <?php if (count($serviceGallery) > 0): ?>
                  <div class="gallery-thumbs js-gallery-sortable" data-service-id="<?= (int)$service["id"] ?>">
                    <?php foreach ($serviceGallery as $galleryItem): ?>
                      <div class="gallery-thumb-wrap is-draggable" title="Arrastra para ordenar" draggable="true" data-gallery-id="<?= (int)$galleryItem["id"] ?>">
                        <label class="gallery-thumb-item">
                          <img src="<?= h($galleryItem["image_path"]) ?>" alt="Imagen carrusel">
                          <input class="gallery-thumb-check js-gallery-check-<?= (int)$service["id"] ?>" type="checkbox" name="remove_gallery_ids[]" value="<?= (int)$galleryItem["id"] ?>">
                          <span class="gallery-mark-overlay"></span>
                        </label>
                        <div class="gallery-meta-stack">
                          <input
                            class="form-control form-control-sm mb-1"
                            type="text"
                            name="gallery_image_titles[<?= (int)$galleryItem["id"] ?>]"
                            value="<?= h(trim((string)($galleryItem["image_title"] ?? "")) !== "" ? (string)$galleryItem["image_title"] : (string)($galleryItem["caption"] ?? "")) ?>"
                            placeholder="Título de la imagen"
                            maxlength="220">
                          <textarea
                            class="form-control form-control-sm gallery-desc-input"
                            name="gallery_image_descriptions[<?= (int)$galleryItem["id"] ?>]"
                            rows="2"
                            placeholder="Descripción (opcional)"><?= h((string)($galleryItem["image_description"] ?? "")) ?></textarea>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <input
                  class="js-gallery-order-input"
                  type="hidden"
                  name="services[<?= (int)$service["id"] ?>][gallery_order]"
                  value="<?= h(implode(",", array_map(static fn($row): string => (string)((int)$row["id"]), $serviceGallery))) ?>"
                >
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="is_active_<?= (int)$service["id"] ?>" name="services[<?= (int)$service["id"] ?>][is_active]" <?= ((int)$service["is_active"] === 1) ? "checked" : "" ?>>
                  <label class="form-check-label" for="is_active_<?= (int)$service["id"] ?>">Activo</label>
                </div>
              </div>
            </div>
            <div class="admin-actions mt-3">
              <button class="btn btn-outline-light" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar cambios</button>
              <button
                class="btn btn-outline-danger"
                type="submit"
                formaction="admin.php"
                formmethod="post"
                name="action"
                value="delete_service"
                onclick="this.form.service_id.value='<?= (int)$service["id"] ?>'; return confirm('¿Eliminar este servicio?');"
              >
                <i class="fa-solid fa-trash me-2"></i>Eliminar
              </button>
            </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
                  <input type="hidden" name="service_id" value="">
                </form>
              </div>
            </div>
          </div>

          <?php if ($adminExpertAgendaUi): ?>
          <div class="accordion-item" id="admin-tools-experts">
            <h2 class="accordion-header m-0">
              <button
                class="accordion-button <?= $expertsPanelOpen ? "" : "collapsed" ?>"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#tools_experts_panel"
                aria-expanded="<?= $expertsPanelOpen ? "true" : "false" ?>"
                aria-controls="tools_experts_panel"
              >
                <i class="fa-solid fa-users me-2"></i>Expertos
              </button>
            </h2>
            <div id="tools_experts_panel" class="accordion-collapse collapse <?= $expertsPanelOpen ? "show" : "" ?>" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <p class="small text-light-emphasis mb-3">
                  Tabla de expertos y ficha por persona. Pulsa <strong><i class="fa-solid fa-circle-plus me-1" aria-hidden="true"></i>Agregar experto</strong> para el mismo esquema que en la ficha (vacío). La <strong>cuenta de acceso</strong> para que cada experto entre al sistema se implementará después.
                </p>
                <?php if (is_array($expertEdit) && isset($expertEdit["id"])): ?>
                  <?php
                    $eid = (int)$expertEdit["id"];
                    $svcSet = $expertServiceIds[$eid] ?? [];
                  ?>
                  <nav class="mb-3">
                    <a href="admin.php#admin-tools-experts" class="link-light"><i class="fa-solid fa-arrow-left me-1"></i>Volver al listado</a>
                  </nav>
                  <h3 class="h5 mb-3 border-bottom border-secondary pb-2">Ficha: <?= h((string)($expertEdit["display_name"] ?? "")) ?></h3>
                  <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="save_expert">
                    <input type="hidden" name="expert_id" value="<?= $eid ?>">
                    <div class="col-md-8">
                      <label class="form-label">Nombre visible</label>
                      <input class="form-control" type="text" name="display_name" value="<?= h((string)($expertEdit["display_name"] ?? "")) ?>" maxlength="180" required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Orden</label>
                      <input class="form-control" type="number" name="sort_order" value="<?= (int)($expertEdit["sort_order"] ?? 999) ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Correo (opcional)</label>
                      <input class="form-control" type="email" name="email" value="<?= h((string)($expertEdit["email"] ?? "")) ?>" maxlength="180" placeholder="contacto@ejemplo.com" autocomplete="off">
                      <div class="form-text text-light-emphasis">Útil para contacto o para enlazar luego la cuenta de experto.</div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Teléfono (opcional)</label>
                      <input class="form-control" type="text" name="phone" value="<?= h((string)($expertEdit["phone"] ?? "")) ?>" maxlength="48" placeholder="+34 …" autocomplete="off">
                    </div>
                    <div class="col-12">
                      <label class="form-label">Notas / información adicional</label>
                      <textarea class="form-control" name="notes" rows="4" maxlength="12000" placeholder="Especialidad, disponibilidad general, comentarios internos…"><?= h((string)($expertEdit["notes"] ?? "")) ?></textarea>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Servicios que puede ofrecer</label>
                      <div class="expert-service-checks border rounded px-3 py-2 border-secondary">
                        <?php if (count($services) === 0): ?>
                          <span class="text-light-emphasis">No hay servicios en el catálogo.</span>
                        <?php else: ?>
                          <?php foreach ($services as $svc): ?>
                            <?php $sid = (int)$svc["id"]; ?>
                            <div class="form-check">
                              <input
                                class="form-check-input"
                                type="checkbox"
                                id="ex_detail_svc_<?= $sid ?>"
                                name="expert_services[]"
                                value="<?= $sid ?>"
                                <?= isset($svcSet[$sid]) ? "checked" : "" ?>
                              >
                              <label class="form-check-label" for="ex_detail_svc_<?= $sid ?>">
                                <i class="<?= h((string)($svc["icon_class"] ?: "fa-solid fa-star")) ?> me-1"></i><?= h((string)($svc["title"] ?? "")) ?>
                              </label>
                            </div>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="expert_detail_active" name="is_active" <?= ((int)($expertEdit["is_active"] ?? 0) === 1) ? "checked" : "" ?>>
                        <label class="form-check-label" for="expert_detail_active">Activo</label>
                      </div>
                    </div>
                    <div class="col-12 admin-actions">
                      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Guardar ficha</button>
                    </div>
                  </form>
                  <form method="post" class="mt-3 pt-3 border-top border-secondary" onsubmit="return confirm('¿Eliminar este experto? Se quitarán sus vínculos con servicios.');">
                    <input type="hidden" name="action" value="delete_expert">
                    <input type="hidden" name="expert_id" value="<?= $eid ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash me-2"></i>Eliminar experto</button>
                  </form>
                <?php elseif ($expertEditNotFound): ?>
                  <div class="alert alert-warning mb-3">No hay ningún experto con ese identificador.</div>
                  <a href="admin.php#admin-tools-experts" class="btn btn-outline-light btn-sm">Volver al listado</a>
                <?php else: ?>
                  <div class="mb-3">
                    <button
                      type="button"
                      class="btn btn-primary"
                      data-bs-toggle="collapse"
                      data-bs-target="#collapse_new_expert_panel"
                      aria-expanded="false"
                      aria-controls="collapse_new_expert_panel"
                      title="Agregar experto"
                    >
                      <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Agregar experto
                    </button>
                  </div>
                  <div id="collapse_new_expert_panel" class="collapse mb-4 service-add-panel-collapse">
                    <div class="border border-secondary rounded-3 p-3 bg-body-tertiary bg-opacity-25">
                      <form method="post" class="row g-3" id="form-add-expert">
                        <input type="hidden" name="action" value="add_expert">
                        <div class="col-md-8">
                          <label class="form-label" for="new_expert_display_name">Nombre visible</label>
                          <input id="new_expert_display_name" class="form-control" type="text" name="display_name" maxlength="180" placeholder="Ej. María López" required>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label" for="new_expert_sort">Orden</label>
                          <input id="new_expert_sort" class="form-control" type="number" name="sort_order" value="999" min="0" max="999999">
                        </div>
                        <div class="col-md-6">
                          <label class="form-label" for="new_expert_email">Correo (opcional)</label>
                          <input id="new_expert_email" class="form-control" type="email" name="email" maxlength="180" placeholder="contacto@ejemplo.com" autocomplete="off">
                          <div class="form-text text-light-emphasis">Útil para contacto o para enlazar luego la cuenta de experto.</div>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label" for="new_expert_phone">Teléfono (opcional)</label>
                          <input id="new_expert_phone" class="form-control" type="text" name="phone" maxlength="48" placeholder="+34 …" autocomplete="off">
                        </div>
                        <div class="col-12">
                          <label class="form-label" for="new_expert_notes">Notas / información adicional</label>
                          <textarea id="new_expert_notes" class="form-control" name="notes" rows="4" maxlength="12000" placeholder="Especialidad, disponibilidad general, comentarios internos…"></textarea>
                        </div>
                        <div class="col-12">
                          <label class="form-label">Servicios que puede ofrecer</label>
                          <div class="expert-service-checks border rounded px-3 py-2 border-secondary">
                            <?php if (count($services) === 0): ?>
                              <span class="text-light-emphasis">No hay servicios en el catálogo.</span>
                            <?php else: ?>
                              <?php foreach ($services as $svc): ?>
                                <?php $sid = (int)$svc["id"]; ?>
                                <div class="form-check">
                                  <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="ex_add_svc_<?= $sid ?>"
                                    name="expert_services[]"
                                    value="<?= $sid ?>"
                                  >
                                  <label class="form-check-label" for="ex_add_svc_<?= $sid ?>">
                                    <i class="<?= h((string)($svc["icon_class"] ?: "fa-solid fa-star")) ?> me-1"></i><?= h((string)($svc["title"] ?? "")) ?>
                                  </label>
                                </div>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </div>
                        </div>
                        <div class="col-12">
                          <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="new_expert_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="new_expert_active">Activo</label>
                          </div>
                        </div>
                        <div class="col-12 admin-actions d-flex flex-wrap gap-2 mb-0">
                          <button class="btn btn-primary" type="submit">
                            <i class="fa-solid fa-circle-plus me-2" aria-hidden="true"></i>Crear experto
                          </button>
                          <button type="button" class="btn btn-outline-secondary" id="cancel_new_expert_btn" aria-controls="collapse_new_expert_panel">
                            <i class="fa-solid fa-xmark me-2" aria-hidden="true"></i>Cancelar
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                  <?php if (count($experts) === 0): ?>
                    <p class="text-light-emphasis mb-0">Aún no hay expertos. Usa <strong>Agregar experto</strong> para crear el primero.</p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-hover table-borderless align-middle mb-0 admin-experts-table">
                        <thead>
                          <tr class="text-secondary small">
                            <th scope="col">Nombre</th>
                            <th scope="col" class="text-center">Orden</th>
                            <th scope="col" class="text-center">Estado</th>
                            <th scope="col" class="text-start expert-services-col">Servicios</th>
                            <th scope="col" class="text-end">Acciones</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($experts as $ex): ?>
                            <?php
                              $rowId = (int)($ex["id"] ?? 0);
                              $svcSet = $expertServiceIds[$rowId] ?? [];
                              $nSvc = count($svcSet);
                              $linkedTitlesStr = "";
                              if ($nSvc > 0) {
                                  $t = [];
                                  foreach ($services as $svc) {
                                      $sid = (int)($svc["id"] ?? 0);
                                      if ($sid > 0 && !empty($svcSet[$sid])) {
                                          $t[] = (string)($svc["title"] ?? "");
                                      }
                                  }
                                  $linkedTitlesStr = implode(", ", $t);
                              }
                              $em = trim((string)($ex["email"] ?? ""));
                              $ph = trim((string)($ex["phone"] ?? ""));
                              $hasExtra = ($em !== "" || $ph !== "" || trim((string)($ex["notes"] ?? "")) !== "");
                              $exActive = (int)($ex["is_active"] ?? 0) === 1;
                            ?>
                            <tr>
                              <td>
                                <strong><?= h((string)($ex["display_name"] ?? "")) ?></strong>
                                <?php if ($hasExtra): ?>
                                  <span class="badge rounded-pill text-bg-info expert-pill d-inline-flex align-items-center ms-1" title="Correo, teléfono o notas en la ficha">
                                    <i class="fa-solid fa-id-card" aria-hidden="true"></i><span class="visually-hidden"> </span>Info
                                  </span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center font-monospace small"><?= (int)($ex["sort_order"] ?? 999) ?></td>
                              <td class="text-center">
                                <?php if ($exActive): ?>
                                  <span class="badge rounded-pill text-bg-success expert-pill d-inline-flex align-items-center" title="Visible en la web según configuración">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Activo
                                  </span>
                                <?php else: ?>
                                  <span class="badge rounded-pill text-bg-secondary expert-pill d-inline-flex align-items-center" title="No se muestra en la agenda pública">
                                    <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Inactivo
                                  </span>
                                <?php endif; ?>
                              </td>
                              <td class="text-start expert-services-col">
                                <?php if ($nSvc === 0): ?>
                                  <span class="text-light-emphasis small font-monospace" title="Sin servicios vinculados">0</span>
                                <?php else: ?>
                                  <div class="d-flex align-items-center gap-1 expert-row-services js-expert-svc-fit">
                                    <div class="expert-svc-icons-slot">
                                      <div class="expert-svc-icons-inner">
                                        <?php foreach ($services as $svc): ?>
                                          <?php
                                            $sid = (int)($svc["id"] ?? 0);
                                            if ($sid <= 0 || empty($svcSet[$sid])) {
                                                continue;
                                            }
                                            $svcTitle = (string)($svc["title"] ?? "");
                                            $svcIcon = (string)($svc["icon_class"] ?: "fa-solid fa-star");
                                          ?>
                                          <span
                                            class="expert-svc-icon d-inline-flex align-items-center justify-content-center rounded-2 border border-secondary bg-body-tertiary text-body"
                                            title="<?= h($svcTitle) ?>"
                                            data-service-title="<?= h($svcTitle) ?>"
                                          >
                                            <i class="<?= h($svcIcon) ?>" aria-hidden="true"></i>
                                            <span class="visually-hidden"><?= h($svcTitle) ?></span>
                                          </span>
                                        <?php endforeach; ?>
                                      </div>
                                    </div>
                                    <span
                                      class="expert-svc-icon expert-svc-overflow-plus align-items-center justify-content-center rounded-2 border border-secondary bg-body-tertiary text-body"
                                      role="img"
                                      aria-hidden="true"
                                    >
                                      <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                    </span>
                                    <span
                                      class="badge rounded-pill text-bg-secondary border expert-pill d-inline-flex align-items-center expert-svc-count-badge"
                                      title="<?= h($linkedTitlesStr !== "" ? $linkedTitlesStr : "Servicios vinculados") ?>"
                                    >
                                      <?= (int)$nSvc ?>
                                    </span>
                                  </div>
                                <?php endif; ?>
                              </td>
                              <td class="text-end">
                                <div class="d-inline-flex flex-wrap align-items-center justify-content-end admin-expert-row-actions">
                                  <a
                                    class="btn btn-outline-light btn-sm"
                                    href="admin.php?expert_id=<?= $rowId ?>#admin-tools-experts"
                                    title="Abrir ficha y editar datos"
                                    aria-label="Ficha o editar <?= h((string)($ex["display_name"] ?? "experto")) ?>"
                                  >
                                    <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="visually-hidden"> Ficha o editar</span>
                                  </a>
                                  <form method="post" onsubmit="return confirm('¿Eliminar este experto?');">
                                    <input type="hidden" name="action" value="delete_expert">
                                    <input type="hidden" name="expert_id" value="<?= $rowId ?>">
                                    <button
                                      type="submit"
                                      class="btn btn-outline-danger btn-sm"
                                      title="Eliminar experto y quitar vínculos con servicios"
                                      aria-label="Eliminar <?= h((string)($ex["display_name"] ?? "experto")) ?>"
                                    >
                                      <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden"> Eliminar</span>
                                    </button>
                                  </form>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php else: ?>
          <div class="accordion-item" id="admin-tool-experts-off">
            <h2 class="accordion-header m-0">
              <button class="accordion-button collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#tools_expert_agenda_off_panel"
                aria-expanded="false" aria-controls="tools_expert_agenda_off_panel">
                <i class="fa-solid fa-users me-2"></i>Expertos
              </button>
            </h2>
            <div id="tools_expert_agenda_off_panel" class="accordion-collapse collapse" data-bs-parent="#adminToolsAccordion">
              <div class="accordion-body">
                <p class="small text-light-emphasis mb-0">
                  Módulo desactivado (<code class="small">features.expert_agenda</code> en <code class="small">app_config.php</code>).
                  Las tablas <code class="small">experts</code> y <code class="small">expert_services</code> existen; actívalo en <code class="small">true</code> para gestionarlos aquí.
                </p>
              </div>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <aside class="admin-side">
        <?php if (!$adminInboxUi && !$adminWhatsappClicksUi): ?>
        <p class="small text-light-emphasis mb-0 px-1">Módulos de bandeja del panel desactivados en <code class="small">app_config.php</code> (<code>features.admin_inbox</code>, <code>features.admin_whatsapp_clicks</code>). Los datos en la base de datos no se borran.</p>
        <?php else: ?>
        <div class="card admin-side-inbox-card overflow-hidden">
          <div class="accordion accordion-flush admin-side-inbox-accordion" id="adminSideInboxAccordion">
            <?php if ($adminInboxUi): ?>
            <?php
            $sideInboxTotal = count($contactMessages);
            $sideInboxUnread = (int)$contactMessagesUnread;
            $sideInboxCounterClass = $sideInboxUnread > 0 ? "text-bg-warning" : "text-bg-secondary";
            $sideInboxCounterTitle = $sideInboxUnread > 0
                ? sprintf("%d sin leer de %d", $sideInboxUnread, $sideInboxTotal)
                : sprintf("%d en total", $sideInboxTotal);
            ?>
            <div class="accordion-item border-0 border-bottom">
              <h2 class="accordion-header m-0" id="headingSideMessages">
                <button
                  class="accordion-button collapsed py-3 px-3"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#collapseSideMessages"
                  aria-expanded="false"
                  aria-controls="collapseSideMessages"
                >
                  <span class="d-flex flex-wrap align-items-center gap-2 me-auto">
                    <i class="fa-solid fa-inbox"></i>
                    <span>Mensajes</span>
                    <span
                      class="badge admin-messages-counter <?= h($sideInboxCounterClass) ?>"
                      title="<?= h($sideInboxCounterTitle) ?>"
                    ><?= $sideInboxUnread ?>/<?= $sideInboxTotal ?></span>
                  </span>
                </button>
              </h2>
              <div
                id="collapseSideMessages"
                class="accordion-collapse collapse"
                aria-labelledby="headingSideMessages"
              >
                <div class="accordion-body pt-0 px-3 pb-3">
                  <?php if ($sideInboxTotal > 0): ?>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom border-secondary">
                      <span class="small text-light-emphasis">Agrupado por cuenta de cliente o por correo del visitante.</span>
                      <?php if ($adminInboxWide): ?>
                        <a href="admin.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-compress me-1"></i>Vista normal</a>
                      <?php else: ?>
                        <a href="admin.php?inbox=1" class="btn btn-outline-info btn-sm"><i class="fa-solid fa-expand me-1"></i>Vista amplia</a>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($sideInboxUnread > 0 || $sideInboxTotal > 0): ?>
                    <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
                      <?php if ($sideInboxUnread > 0): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_messages_read">
                          <button class="btn btn-outline-light btn-sm" type="submit" title="Marcar todos como leídos">
                            <i class="fa-solid fa-check-double me-2"></i>Marcar todos
                          </button>
                        </form>
                      <?php elseif ($sideInboxTotal > 0): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_messages_unread">
                          <button class="btn btn-outline-warning btn-sm" type="submit" title="Marcar todos como sin leer">
                            <i class="fa-solid fa-rotate-left me-2"></i>Marcar todos como sin leer
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($sideInboxTotal === 0): ?>
                    <p class="text-light-emphasis mb-0">Aún no hay mensajes desde el formulario de contacto.</p>
                  <?php else: ?>
                    <div id="adminInboxMessagesRoot">
                    <div class="accordion admin-conv-groups-accordion" id="adminConvGroupsAccordion">
                      <?php foreach ($contactMessageGroups as $grp): ?>
                        <?php
                          $gSlug = (string)($grp["slug"] ?? "");
                          $gCollapse = "collapse_conv_" . $gSlug;
                          $gUnread = (int)($grp["unread"] ?? 0);
                          $gHasUnread = $gUnread > 0;
                        ?>
                        <div class="accordion-item conv-group-row<?= $gHasUnread ? " is-unread" : "" ?>">
                          <h3 class="accordion-header m-0 conv-group-header">
                            <button
                              class="accordion-button collapsed d-flex flex-wrap align-items-center gap-2 py-2 px-2"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#<?= h($gCollapse) ?>"
                              aria-expanded="false"
                              aria-controls="<?= h($gCollapse) ?>"
                            >
                              <span class="badge text-bg-primary"><?= h((string)($grp["head_badge"] ?? "")) ?></span>
                              <?php if ($gHasUnread): ?>
                                <span class="badge text-bg-warning js-group-unread-badge" title="Mensajes sin leer en este grupo"><?= (int)($grp["unread"]) ?></span>
                              <?php endif; ?>
                              <span class="text-truncate flex-grow-1" style="min-width: 0;"><strong><?= h((string)($grp["head_title"] ?? "")) ?></strong></span>
                              <?php if (($grp["head_sub"] ?? "") !== ""): ?>
                                <span class="text-light-emphasis small text-truncate d-none d-md-inline" style="max-width: 8rem;"><?= h((string)$grp["head_sub"]) ?></span>
                              <?php endif; ?>
                              <span class="text-light-emphasis small text-nowrap ms-auto" title="Conversaciones / mensajes totales en este grupo"><?= (int)($grp["conv_count"] ?? 0) ?> · <?= (int)($grp["msg_count"] ?? 0) ?></span>
                              <?php if (($grp["latest_label"] ?? "") !== ""): ?>
                                <span class="text-secondary small text-nowrap d-none d-lg-inline"><?= h((string)$grp["latest_label"]) ?></span>
                              <?php endif; ?>
                            </button>
                          </h3>
                          <div
                            id="<?= h($gCollapse) ?>"
                            class="accordion-collapse collapse"
                            data-bs-parent="#adminConvGroupsAccordion"
                          >
                            <div class="accordion-body p-2 pt-1">
                              <p class="admin-inbox-grp-meta mb-2">
                                <?php if ((string)($grp["head_email"] ?? "") !== ""): ?>
                                  <a href="mailto:<?= h((string)$grp["head_email"]) ?>"><?= h((string)$grp["head_email"]) ?></a>
                                  <span class="text-muted"> · </span>
                                <?php endif; ?>
                                <span title="Hilos ordenados por fecha; responde al final de cada hilo"><?= (int)($grp["conv_count"] ?? 0) ?> hilos</span>
                              </p>
                              <div class="admin-inbox-threads mt-1">
                                <?php foreach (($grp["threads"] ?? []) as $ti => $thread): ?>
                                  <?php
                                    $tMsgs = $thread["messages"] ?? [];
                                    $rootRow = $tMsgs[0] ?? [];
                                    $rootServ = trim((string)($rootRow["servicio"] ?? ""));
                                    $rootSubject = trim((string)($rootRow["subject"] ?? ""));
                                    $threadAsunto = $rootSubject !== "" ? $rootSubject : "Sin asunto";
                                    $rootCreated = (string)($rootRow["created_at"] ?? "");
                                    $rootCreatedLabel = $rootCreated;
                                    try {
                                        $rootCreatedLabel = (new DateTime($rootCreated))->format("d/m/Y H:i");
                                    } catch (Exception $e) {
                                    }
                                    $tCount = count($tMsgs);
                                    $tLatest = (int)($thread["latest_ts"] ?? 0);
                                    $tLatestLabel = $tLatest > 0 ? date("d/m/Y H:i", $tLatest) : "";
                                    $tUnread = 0;
                                    foreach ($tMsgs as $tm) {
                                        if ((int)($tm["is_read"] ?? 0) === 0) {
                                            $tUnread++;
                                        }
                                    }
                                    $rootBody = trim(preg_replace('/\s+/u', ' ', (string)($rootRow["mensaje"] ?? "")));
                                    $snippet = $rootBody;
                                    if (strlen($snippet) > 96) {
                                        $snippet = substr($snippet, 0, 96) . "…";
                                    }
                                    $threadConvIdAdmin = (int)($thread["root_id"] ?? 0);
                                  ?>
                                  <details class="admin-msg-thread admin-msg-conv-root" data-thread-id="<?= $threadConvIdAdmin ?>">
                                    <summary class="admin-msg-thread-summary" title="Referencia interna del hilo (mensaje raíz ID <?= (int)$threadConvIdAdmin ?>)<?= $rootServ !== "" ? " · " . htmlspecialchars($rootServ, ENT_QUOTES, "UTF-8") : "" ?>">
                                      <div class="admin-msg-thread-asunto-block">
                                        <span class="admin-msg-thread-asunto-label">Asunto</span>
                                        <span class="admin-msg-thread-asunto-text<?= $rootSubject === "" ? " text-secondary" : "" ?>"><?= h($threadAsunto) ?></span>
                                      </div>
                                      <span class="admin-msg-thread-summary-main">
                                        <span class="message-meta-date"><?= h($rootCreatedLabel) ?></span>
                                        <span class="message-meta-service"><i class="fa-solid fa-briefcase me-1"></i><?= h($rootServ !== "" ? $rootServ : "—") ?></span>
                                        <?php if ($tCount > 1): ?>
                                          <span class="admin-msg-conv-meta"><?= (int)$tCount ?> pasos</span>
                                        <?php endif; ?>
                                        <?php if ($tUnread > 0): ?>
                                          <span class="badge text-bg-warning js-thread-unread-badge" title="Pasos sin leer en este hilo"><?= (int)$tUnread ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($thread["has_admin_reply"])): ?>
                                          <span class="badge text-bg-success">Resp.</span>
                                        <?php endif; ?>
                                      </span>
                                      <?php if ($snippet !== ""): ?>
                                        <span class="admin-msg-thread-snippet"><?= h($snippet) ?></span>
                                      <?php endif; ?>
                                    </summary>
                                    <div class="admin-msg-thread-body">
                                      <div class="admin-msg-chat-stream">
                                      <?php foreach ($tMsgs as $tmi => $contactMsg): ?>
                                        <?php
                                          $msgId = (int)$contactMsg["id"];
                                          $isUnread = (int)($contactMsg["is_read"] ?? 0) === 0;
                                          $fromClientId = (int)($contactMsg["client_id"] ?? 0);
                                          $msgInReplyTo = (int)($contactMsg["in_reply_to"] ?? 0);
                                          $turnSubject = trim((string)($contactMsg["subject"] ?? ""));
                                          $createdAt = (string)($contactMsg["created_at"] ?? "");
                                          $createdLabel = $createdAt;
                                          try {
                                              $dt = new DateTime($createdAt);
                                              $createdLabel = $dt->format("d/m/Y H:i");
                                          } catch (Exception $e) {
                                          }
                                          $repliesForMsg = $contactRepliesByMessageId[$msgId] ?? [];
                                          $isContinuation = $tmi > 0;
                                          $prevRow = $isContinuation ? ($tMsgs[$tmi - 1] ?? []) : [];
                                          $prevEmail = strtolower(trim((string)($prevRow["email"] ?? "")));
                                          $curEmail = strtolower(trim((string)($contactMsg["email"] ?? "")));
                                          $hideContactFooter = $isContinuation && $prevEmail !== "" && $prevEmail === $curEmail;
                                          $rootServStr = trim((string)$rootServ);
                                          $turnServStr = trim((string)($contactMsg["servicio"] ?? ""));
                                          $hideTurnService = $isContinuation && $rootServStr !== "" && $turnServStr === $rootServStr;
                                        ?>
                                        <div class="message-row admin-msg-turn<?= $isUnread ? " is-unread" : "" ?><?= $isContinuation ? " admin-msg-turn--continuation" : "" ?>" data-message-id="<?= $msgId ?>">
                                          <div class="admin-msg-turn-toolbar">
                                            <div class="admin-msg-turn-head">
                                              <?php if ($msgId > 0): ?>
                                                <?php
                                                  $turnStepLabel = ($tmi + 1) . "/" . $tCount;
                                                  $turnChipTitle = "Referencia interna: mensaje ID " . $msgId . " · " . $createdLabel;
                                                ?>
                                                <span class="admin-msg-id-chip" title="<?= h($turnChipTitle) ?>"><?= h($turnStepLabel) ?></span>
                                              <?php endif; ?>
                                              <span class="badge text-bg-warning js-msg-new-badge">Nuevo</span>
                                              <?php if ($fromClientId > 0 && !$isContinuation): ?>
                                                <span class="badge text-bg-info">Cliente</span>
                                              <?php endif; ?>
                                              <?php if ($msgInReplyTo > 0 && !$isContinuation): ?>
                                                <span class="badge text-bg-secondary" title="Seguimiento">Seg.</span>
                                              <?php endif; ?>
                                              <span class="message-meta-date"><?= h($createdLabel) ?></span>
                                              <?php if (!$hideTurnService): ?>
                                              <span class="message-meta-service"><i class="fa-solid fa-tag me-1"></i><?= h((string)$contactMsg["servicio"]) ?></span>
                                              <?php endif; ?>
                                            </div>
                                            <form method="post" class="message-delete-form" onsubmit="return confirm('¿Eliminar este mensaje del historial?');">
                                              <input type="hidden" name="action" value="delete_message">
                                              <input type="hidden" name="message_id" value="<?= $msgId ?>">
                                              <button class="btn-message-delete" type="submit" title="Eliminar mensaje" aria-label="Eliminar mensaje">
                                                <i class="fa-solid fa-trash"></i>
                                              </button>
                                            </form>
                                          </div>
                                          <div class="admin-msg-turn-main">
                                            <div class="admin-msg-turn-inner">
                                            <?php if ($tmi > 0 || ($turnSubject !== "" && $turnSubject !== $rootSubject)): ?>
                                              <p class="admin-msg-asunto-line mb-2"><span class="text-secondary small text-uppercase fw-semibold">Asunto</span><br><?= h($turnSubject) ?></p>
                                            <?php endif; ?>
                                            <?php if ($msgInReplyTo > 0 && !$isContinuation): ?>
                                              <p class="small text-light-emphasis mb-2" title="Referencia interna del mensaje enlazado: ID <?= (int)$msgInReplyTo ?>">
                                                <i class="fa-solid fa-link me-1"></i>Seguimiento enlazado a otra entrada de este mismo hilo.
                                              </p>
                                            <?php endif; ?>
                                            <div class="admin-msg-bubble admin-msg-bubble--visitor">
                                              <div class="admin-msg-bubble-label text-secondary"><i class="fa-solid fa-user me-1"></i>Mensaje del visitante</div>
                                            <div class="message-body-text mb-2 mb-md-3"><?= nl2br(h((string)$contactMsg["mensaje"])) ?></div>
                                            <?php if (!$hideContactFooter): ?>
                                            <div class="d-flex flex-wrap gap-2 align-items-center text-light-emphasis small mb-0">
                                              <span><strong><?= h((string)$contactMsg["nombre"]) ?></strong></span>
                                              <span><i class="fa-solid fa-envelope me-1"></i><a href="mailto:<?= h((string)$contactMsg["email"]) ?>"><?= h((string)$contactMsg["email"]) ?></a></span>
                                            </div>
                                            <?php endif; ?>
                                            </div>
                                            <?php if (count($repliesForMsg) > 0): ?>
                                            <div class="admin-msg-bubble admin-msg-bubble--admin">
                                              <div class="admin-msg-bubble-label text-secondary"><i class="fa-solid fa-reply me-1"></i>Tus respuestas por correo (desde el panel)</div>
                                              <div class="message-replies-sent border-0 ps-0 pt-0 mt-0 mb-0">
                                                <?php foreach ($repliesForMsg as $repRow): ?>
                                                  <?php
                                                    $repCreated = (string)($repRow["created_at"] ?? "");
                                                    $repLabel = $repCreated;
                                                    try {
                                                        $repDt = new DateTime($repCreated);
                                                        $repLabel = $repDt->format("d/m/Y H:i");
                                                    } catch (Exception $e) {
                                                    }
                                                  ?>
                                                  <div class="message-reply-item small">
                                                    <span class="text-muted"><?= h($repLabel) ?></span>
                                                    <div class="mt-1 message-body-text mb-0" style="max-height: 12rem;"><?= nl2br(h((string)($repRow["body"] ?? ""))) ?></div>
                                                  </div>
                                                <?php endforeach; ?>
                                              </div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="d-flex flex-wrap gap-2 message-mark-actions">
                                              <form method="post" class="m-0 js-mark-read-form">
                                                <input type="hidden" name="action" value="mark_message_read">
                                                <input type="hidden" name="message_id" value="<?= $msgId ?>">
                                                <button class="btn btn-outline-light btn-msg-read-state" type="submit" title="Marcar como leído" aria-label="Marcar como leído">
                                                  <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </button>
                                              </form>
                                              <form method="post" class="m-0 js-mark-unread-form">
                                                <input type="hidden" name="action" value="mark_message_unread">
                                                <input type="hidden" name="message_id" value="<?= $msgId ?>">
                                                <button class="btn btn-outline-warning btn-msg-read-state" type="submit" title="Marcar como no leído" aria-label="Marcar como no leído">
                                                  <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                </button>
                                              </form>
                                            </div>
                                            </div>
                                          </div>
                                        </div>
                                      <?php endforeach; ?>
                                      </div>
                                      <?php
                                        $threadRootIdAdmin = (int)($thread["root_id"] ?? 0);
                                        $nAdminTm = count($tMsgs);
                                        $lastAdminTurn = $nAdminTm > 0 ? $tMsgs[$nAdminTm - 1] : [];
                                        $lastAdminMsgId = (int)($lastAdminTurn["id"] ?? 0);
                                        $lastVisitorName = trim((string)($lastAdminTurn["nombre"] ?? ""));
                                      ?>
                                      <?php if ($lastAdminMsgId > 0): ?>
                                        <form method="post" class="message-reply-form mb-0 mt-3 admin-msg-reply-thread-end">
                                          <input type="hidden" name="action" value="reply_contact_message">
                                          <input type="hidden" name="message_id" value="<?= $lastAdminMsgId ?>">
                                          <p class="small text-light-emphasis mb-2" title="Referencia interna del mensaje destino: ID <?= (int)$lastAdminMsgId ?>">Un solo envío al final del hilo: la respuesta queda asociada al <strong>último mensaje</strong> del hilo (el más reciente en esta conversación).</p>
                                          <label class="form-label small mb-1" for="reply_body_root_<?= $threadRootIdAdmin ?>">Responder por correo</label>
                                          <textarea id="reply_body_root_<?= $threadRootIdAdmin ?>" class="form-control form-control-sm" name="reply_body" rows="4" placeholder="Texto que recibirá <?= h($lastVisitorName !== "" ? $lastVisitorName : "el visitante") ?> en su correo…" required></textarea>
                                          <button class="btn btn-primary btn-sm mt-2" type="submit">
                                            <i class="fa-solid fa-paper-plane me-2"></i>Enviar respuesta
                                          </button>
                                        </form>
                                      <?php endif; ?>
                                    </div>
                                  </details>
                                <?php endforeach; ?>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($adminWhatsappClicksUi): ?>
            <div class="accordion-item border-0">
              <h2 class="accordion-header m-0" id="headingSideWhatsapp">
                <button
                  class="accordion-button collapsed py-3 px-3"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#collapseSideWhatsapp"
                  aria-expanded="false"
                  aria-controls="collapseSideWhatsapp"
                >
                  <span class="d-flex flex-wrap align-items-center gap-2 me-auto">
                    <i class="fa-brands fa-whatsapp text-success"></i>
                    <span>Clics WhatsApp</span>
                    <span
                      class="badge admin-whatsapp-counter <?= h($waSideCounterClass) ?>"
                      title="<?= h($waSideCounterTitle) ?>"
                    ><?= $waSideUnread ?>/<?= $waSideTotal ?></span>
                  </span>
                </button>
              </h2>
              <div
                id="collapseSideWhatsapp"
                class="accordion-collapse collapse"
                aria-labelledby="headingSideWhatsapp"
              >
                <div class="accordion-body pt-0 px-3 pb-3">
                  <p class="small text-light-emphasis mb-3">Cada fila es alguien que pulsó «Escribir por WhatsApp» en la web.</p>
                  <?php if ($waSideTotal > 0): ?>
                    <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
                      <?php if ($waSideUnread > 0): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_whatsapp_read">
                          <button class="btn btn-outline-light btn-sm" type="submit" title="Marcar todos como leídos">
                            <i class="fa-solid fa-check-double me-2"></i>Marcar todos
                          </button>
                        </form>
                      <?php else: ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="mark_all_whatsapp_unread">
                          <button class="btn btn-outline-warning btn-sm" type="submit" title="Marcar todos como sin leer">
                            <i class="fa-solid fa-rotate-left me-2"></i>Marcar todos como sin leer
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if (count($whatsappClicks) === 0): ?>
                    <p class="text-light-emphasis mb-0">Sin clics todavía.</p>
                  <?php else: ?>
                    <div class="accordion admin-messages-accordion" id="adminWhatsappAccordion">
                      <?php foreach ($whatsappClicks as $waClick): ?>
                        <?php
                          $waId = (int)$waClick["id"];
                          $waCollapseId = "collapse_wa_" . $waId;
                          $waCreated = (string)($waClick["created_at"] ?? "");
                          $waLabel = $waCreated;
                          try {
                              $waDt = new DateTime($waCreated);
                              $waLabel = $waDt->format("Y-m-d H:i");
                          } catch (Exception $e) {
                          }
                          $waNombre = trim((string)($waClick["nombre"] ?? ""));
                          $waEmail = trim((string)($waClick["email"] ?? ""));
                          $waServicio = trim((string)($waClick["servicio"] ?? ""));
                          $waUnreadRow = (int)($waClick["is_read"] ?? 0) === 0;
                        ?>
                        <div class="accordion-item message-row<?= $waUnreadRow ? " is-unread" : "" ?>" data-whatsapp-click-id="<?= $waId ?>">
                          <h3 class="accordion-header m-0 message-header-row">
                            <button
                              class="accordion-button collapsed d-flex flex-wrap align-items-center gap-2"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#<?= h($waCollapseId) ?>"
                              aria-expanded="false"
                              aria-controls="<?= h($waCollapseId) ?>"
                            >
                              <span class="badge text-bg-warning js-msg-new-badge">Nuevo</span>
                              <span class="message-meta-date"><?= h($waLabel) ?></span>
                              <span class="message-meta-name"><strong><?= $waNombre !== "" ? h($waNombre) : "—" ?></strong></span>
                              <span class="message-meta-service"><i class="fa-solid fa-tag me-1"></i><?= $waServicio !== "" ? h($waServicio) : "—" ?></span>
                              <?php if ($waEmail !== ""): ?>
                                <span class="message-meta-email text-light-emphasis"><?= h($waEmail) ?></span>
                              <?php endif; ?>
                            </button>
                            <form method="post" class="message-delete-form" onsubmit="return confirm('¿Eliminar esta entrada?');">
                              <input type="hidden" name="action" value="delete_whatsapp_click">
                              <input type="hidden" name="whatsapp_click_id" value="<?= $waId ?>">
                              <button class="btn-message-delete" type="submit" title="Eliminar" aria-label="Eliminar">
                                <i class="fa-solid fa-trash"></i>
                              </button>
                            </form>
                          </h3>
                          <div id="<?= h($waCollapseId) ?>" class="accordion-collapse collapse" data-bs-parent="#adminWhatsappAccordion">
                            <div class="accordion-body">
                              <div class="message-body-text mb-3"><?= nl2br(h((string)($waClick["composed_text"] ?? ""))) ?></div>
                              <div class="d-flex flex-wrap gap-2 message-mark-actions">
                                <form method="post" class="m-0 js-wa-mark-read-form">
                                  <input type="hidden" name="action" value="mark_whatsapp_read">
                                  <input type="hidden" name="whatsapp_click_id" value="<?= $waId ?>">
                                  <button class="btn btn-outline-light btn-sm" type="submit">
                                    <i class="fa-solid fa-check me-2"></i>Marcar como leído
                                  </button>
                                </form>
                                <form method="post" class="m-0 js-wa-mark-unread-form">
                                  <input type="hidden" name="action" value="mark_whatsapp_unread">
                                  <input type="hidden" name="whatsapp_click_id" value="<?= $waId ?>">
                                  <button class="btn btn-outline-warning btn-sm" type="submit">
                                    <i class="fa-solid fa-rotate-left me-2"></i>Marcar como sin leer
                                  </button>
                                </form>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </aside>
    </div>
  </div>
  <?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Controles de la pantalla de login: toggle de mostrar/ocultar clave y
    // alternancia entre la vista "Iniciar sesión" y "Recuperar clave".
    // Se ejecuta tanto si estás logueado como si no (los selectores quedan
    // vacíos cuando no aplica, así que no hay efectos colaterales).
    (function () {
      document.querySelectorAll(".js-password-toggle").forEach(function (btn) {
        btn.addEventListener("click", function () {
          const id = btn.getAttribute("data-target");
          if (!id) return;
          const input = document.getElementById(id);
          if (!input) return;
          const wasPwd = input.type === "password";
          input.type = wasPwd ? "text" : "password";
          btn.setAttribute("aria-pressed", wasPwd ? "true" : "false");
          btn.setAttribute("aria-label", wasPwd ? "Ocultar clave" : "Mostrar clave");
          const icon = btn.querySelector("i");
          if (icon) {
            icon.classList.toggle("fa-eye", !wasPwd);
            icon.classList.toggle("fa-eye-slash", wasPwd);
          }
        });
      });

      document.querySelectorAll(".js-show-view").forEach(function (link) {
        link.addEventListener("click", function (ev) {
          ev.preventDefault();
          const target = link.getAttribute("data-target-view");
          if (!target) return;
          let focusTarget = null;
          document.querySelectorAll(".login-view").forEach(function (view) {
            const isTarget = view.getAttribute("data-view") === target;
            if (isTarget) {
              view.removeAttribute("hidden");
              focusTarget = view.querySelector("input:not([type='hidden'])");
            } else {
              view.setAttribute("hidden", "");
            }
          });
          if (focusTarget) {
            setTimeout(function () {
              try { focusTarget.focus({ preventScroll: true }); } catch (e) { focusTarget.focus(); }
            }, 60);
          }
        });
      });

      document.addEventListener("DOMContentLoaded", function () {
        var hash = (window.location.hash || "").trim();
        if (hash !== "#admin-tool-clients" && hash !== "#tools_clients_panel") {
          return;
        }
        var panel = document.getElementById("tools_clients_panel");
        if (panel && window.bootstrap && bootstrap.Collapse) {
          try {
            bootstrap.Collapse.getOrCreateInstance(panel).show();
          } catch (e) {}
        }
        var item = document.getElementById("admin-tool-clients");
        if (item) {
          setTimeout(function () {
            item.scrollIntoView({ block: "nearest", behavior: "smooth" });
          }, 120);
        }
      });
    })();
  </script>
  <script>
    (function () {
      function updateGalleryOrderForRow(serviceRow) {
        if (!serviceRow) return;
        const orderInput = serviceRow.querySelector(".js-gallery-order-input");
        if (!orderInput) return;
        const ids = Array.from(serviceRow.querySelectorAll(".gallery-thumb-wrap[data-gallery-id]"))
          .map(function (el) { return el.getAttribute("data-gallery-id") || ""; })
          .filter(function (id) { return id !== ""; });
        orderInput.value = ids.join(",");
      }

      function syncActiveButton(containerEl, value) {
        const current = (value || "").trim();
        containerEl.querySelectorAll(".icon-option").forEach(function (btn) {
          const isMatch = btn.getAttribute("data-icon") === current;
          btn.classList.toggle("is-active", isMatch);
        });
      }

      document.querySelectorAll(".icon-picker").forEach(function (containerEl) {
        const inputId = containerEl.getAttribute("data-target-input");
        const inputEl = document.getElementById(inputId);
        if (!inputEl) return;

        containerEl.querySelectorAll(".icon-option").forEach(function (btn) {
          btn.addEventListener("click", function () {
            inputEl.value = btn.getAttribute("data-icon") || "";
            syncActiveButton(containerEl, inputEl.value);
          });
        });

        syncActiveButton(containerEl, inputEl.value);
      });

      (function () {
        var cancelBtn = document.getElementById("cancel_new_service_btn");
        var collapseEl = document.getElementById("collapse_new_service_panel");
        var formEl = document.getElementById("form-add-service");
        var pickerEl = document.getElementById("new_service_icon_picker");
        var iconInput = document.getElementById("new_service_icon_input");
        if (!cancelBtn || !collapseEl || !formEl) return;

        function syncNewServiceIconPicker() {
          if (!pickerEl || !iconInput) return;
          var v = (iconInput.value || "").trim();
          pickerEl.querySelectorAll(".icon-option").forEach(function (btn) {
            btn.classList.toggle("is-active", btn.getAttribute("data-icon") === v);
          });
        }

        cancelBtn.addEventListener("click", function () {
          formEl.reset();
          syncNewServiceIconPicker();
          if (window.bootstrap && bootstrap.Collapse) {
            var inst = bootstrap.Collapse.getOrCreateInstance(collapseEl);
            inst.hide();
          }
        });
      })();

      (function () {
        var cancelBtn = document.getElementById("cancel_new_expert_btn");
        var collapseEl = document.getElementById("collapse_new_expert_panel");
        var formEl = document.getElementById("form-add-expert");
        if (!cancelBtn || !collapseEl || !formEl) return;

        cancelBtn.addEventListener("click", function () {
          formEl.reset();
          if (window.bootstrap && bootstrap.Collapse) {
            bootstrap.Collapse.getOrCreateInstance(collapseEl).hide();
          }
        });
      })();

      (function () {
        if (typeof ResizeObserver === "undefined") {
          return;
        }
        function fitExpertServiceRow(container) {
          var slot = container.querySelector(".expert-svc-icons-slot");
          var inner = container.querySelector(".expert-svc-icons-inner");
          var plus = container.querySelector(".expert-svc-overflow-plus");
          if (!slot || !inner || !plus) {
            return;
          }
          var icons = Array.prototype.slice.call(inner.querySelectorAll(".expert-svc-icon"));
          icons.forEach(function (el) {
            el.style.display = "";
          });
          plus.classList.remove("is-visible");
          plus.removeAttribute("title");
          plus.removeAttribute("aria-label");
          plus.setAttribute("aria-hidden", "true");
          if (icons.length === 0) {
            return;
          }
          if (slot.clientWidth < 40) {
            return;
          }
          var maxIter = icons.length + 10;
          var iter = 0;
          while (inner.scrollWidth > slot.clientWidth + 1 && iter < maxIter) {
            var visible = icons.filter(function (i) {
              return i.style.display !== "none";
            });
            if (visible.length === 0) {
              break;
            }
            visible[visible.length - 1].style.display = "none";
            iter += 1;
          }
          var hidden = icons.filter(function (i) {
            return i.style.display === "none";
          });
          if (hidden.length === 0) {
            return;
          }
          plus.classList.add("is-visible");
          iter = 0;
          while (inner.scrollWidth > slot.clientWidth + 1 && iter < maxIter) {
            var visible2 = icons.filter(function (i) {
              return i.style.display !== "none";
            });
            if (visible2.length === 0) {
              break;
            }
            visible2[visible2.length - 1].style.display = "none";
            iter += 1;
          }
          var hidden2 = icons.filter(function (i) {
            return i.style.display === "none";
          });
          var titles = hidden2.map(function (i) {
            return i.getAttribute("data-service-title") || "";
          }).filter(Boolean);
          var t = titles.join(", ");
          if (t) {
            plus.setAttribute("title", t);
            plus.setAttribute("aria-label", "Servicios no mostrados en la fila: " + t);
            plus.removeAttribute("aria-hidden");
          } else {
            plus.setAttribute("aria-label", "Hay más servicios vinculados");
            plus.removeAttribute("aria-hidden");
          }
        }
        function fitAllExpertServiceRows() {
          document.querySelectorAll(".expert-row-services.js-expert-svc-fit").forEach(fitExpertServiceRow);
        }
        var ro = new ResizeObserver(function () {
          window.requestAnimationFrame(fitAllExpertServiceRows);
        });
        document.querySelectorAll(".expert-row-services.js-expert-svc-fit").forEach(function (el) {
          ro.observe(el);
        });
        window.addEventListener("load", fitAllExpertServiceRows);
        var expertsPanel = document.getElementById("tools_experts_panel");
        if (expertsPanel) {
          expertsPanel.addEventListener("shown.bs.collapse", fitAllExpertServiceRows);
        }
      })();

      document.querySelectorAll(".js-gallery-remove-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
          const serviceId = btn.getAttribute("data-service-id");
          if (!serviceId) return;
          const serviceRow = btn.closest(".service-row");
          if (!serviceRow) return;
          const checks = serviceRow.querySelectorAll(".js-gallery-check-" + serviceId);
          let marked = 0;
          checks.forEach(function (checkEl) {
            if (checkEl.checked) marked += 1;
          });
          if (marked === 0) {
            alert("Selecciona al menos una miniatura para eliminar.");
            return;
          }
          if (!confirm("Se eliminarán " + marked + " imagen(es) al guardar los cambios. ¿Continuar?")) {
            return;
          }

          // Oculta visualmente la miniatura y la saca del orden, pero
          // CONSERVA en el DOM el checkbox marcado para que name="remove_gallery_ids[]"
          // llegue al servidor al guardar y PHP pueda borrar el registro.
          checks.forEach(function (checkEl) {
            if (!checkEl.checked) return;
            const wrap = checkEl.closest(".gallery-thumb-wrap");
            if (!wrap) return;
            wrap.removeAttribute("data-gallery-id");
            wrap.setAttribute("draggable", "false");
            wrap.classList.add("is-removed");
            wrap.style.display = "none";
          });
          updateGalleryOrderForRow(serviceRow);
        });
      });

      document.querySelectorAll(".js-gallery-sortable").forEach(function (containerEl) {
        let draggedEl = null;

        containerEl.querySelectorAll(".gallery-thumb-wrap.is-draggable").forEach(function (item) {
          item.addEventListener("dragstart", function () {
            draggedEl = item;
            item.classList.add("is-dragging");
          });

          item.addEventListener("dragend", function () {
            item.classList.remove("is-dragging");
            draggedEl = null;
            updateGalleryOrderForRow(containerEl.closest(".service-row"));
          });

          item.addEventListener("dragover", function (event) {
            event.preventDefault();
          });

          item.addEventListener("drop", function (event) {
            event.preventDefault();
            if (!draggedEl || draggedEl === item) return;
            const items = Array.from(containerEl.querySelectorAll(".gallery-thumb-wrap"));
            const draggedIndex = items.indexOf(draggedEl);
            const targetIndex = items.indexOf(item);
            if (draggedIndex < 0 || targetIndex < 0) return;
            if (draggedIndex < targetIndex) {
              item.insertAdjacentElement("afterend", draggedEl);
            } else {
              item.insertAdjacentElement("beforebegin", draggedEl);
            }
            updateGalleryOrderForRow(containerEl.closest(".service-row"));
          });
        });
      });

      document.querySelectorAll(".js-gallery-pick-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
          const inputId = btn.getAttribute("data-input-id");
          if (!inputId) return;
          const inputEl = document.getElementById(inputId);
          if (inputEl) inputEl.click();
        });
      });

      document.addEventListener("change", function (event) {
        const inputEl = event.target;
        if (!(inputEl instanceof HTMLInputElement)) return;
        if (!inputEl.classList.contains("gallery-input-hidden")) return;

        const targetId = inputEl.getAttribute("data-preview-target");
        if (!targetId) return;
        const previewWrap = document.getElementById(targetId);
        if (!previewWrap) return;

        const files = inputEl.files ? Array.from(inputEl.files) : [];
        if (files.length === 0) return;

        files.forEach(function (file) {
          if (!file.type.startsWith("image/")) return;
          const objectUrl = URL.createObjectURL(file);
          const item = document.createElement("div");
          item.className = "gallery-thumb-item";
          item.innerHTML = '<img alt="Previsualización">';
          const img = item.querySelector("img");
          if (img) img.src = objectUrl;
          previewWrap.appendChild(item);
        });

        // Acumula selecciones de varios clics creando un nuevo input vacío.
        const nextInput = inputEl.cloneNode();
        nextInput.value = "";
        inputEl.removeAttribute("id");
        inputEl.insertAdjacentElement("afterend", nextInput);
      });
    })();

    // Marcar como leído automáticamente al desplegar un mensaje sin leer.
    (function () {
      const inboxRoot = document.getElementById("adminInboxMessagesRoot");

      function setInboxCounterFromServer(unread, total) {
        var list = document.querySelectorAll(".admin-messages-counter");
        if (!list.length) return;
        var u = Math.max(0, parseInt(String(unread), 10) || 0);
        var t = Math.max(0, parseInt(String(total), 10) || 0);
        var title = u > 0 ? u + " sin leer de " + t : t + " en total";
        var warn = u > 0;
        list.forEach(function (counter) {
          counter.textContent = u + "/" + t;
          counter.title = title;
          counter.classList.toggle("text-bg-warning", warn);
          counter.classList.toggle("text-bg-secondary", !warn);
        });
      }

      function syncThreadUnreadBadgeFromDom(row) {
        var det = row.closest("details.admin-msg-thread");
        if (!det) return;
        var n = det.querySelectorAll(".message-row.is-unread").length;
        var summaryMain = det.querySelector(".admin-msg-thread-summary-main");
        var badge = det.querySelector(".js-thread-unread-badge");
        if (n <= 0) {
          if (badge && badge.parentElement) badge.parentElement.removeChild(badge);
          return;
        }
        if (!badge && summaryMain) {
          badge = document.createElement("span");
          badge.className = "badge text-bg-warning js-thread-unread-badge";
          badge.title = "Pasos sin leer en este hilo";
          summaryMain.appendChild(badge);
        }
        if (badge) badge.textContent = String(n);
      }

      function syncGroupUnreadFromDom(row) {
        var item = row.closest(".accordion-item.conv-group-row");
        if (!item) return;
        var n = item.querySelectorAll(".admin-inbox-threads .message-row.is-unread").length;
        var badge = item.querySelector(".js-group-unread-badge");
        if (n <= 0) {
          item.classList.remove("is-unread");
          if (badge && badge.parentElement) badge.parentElement.removeChild(badge);
          return;
        }
        item.classList.add("is-unread");
        if (badge) {
          badge.textContent = String(n);
          return;
        }
        var btn = item.querySelector(".conv-group-header .accordion-button");
        if (!btn) return;
        badge = document.createElement("span");
        badge.className = "badge text-bg-warning js-group-unread-badge";
        badge.title = "Mensajes sin leer en este grupo";
        badge.textContent = String(n);
        var primary = btn.querySelector(".badge.text-bg-primary");
        if (primary) {
          primary.insertAdjacentElement("afterend", badge);
        } else {
          btn.insertBefore(badge, btn.firstChild);
        }
      }

      function applyReadStateToRow(row) {
        if (!row || !row.classList.contains("is-unread")) return;
        row.classList.remove("is-unread");
        syncThreadUnreadBadgeFromDom(row);
        syncGroupUnreadFromDom(row);
      }

      function applyUnreadStateToRow(row) {
        if (!row || row.classList.contains("is-unread")) return;
        row.classList.add("is-unread");
        syncThreadUnreadBadgeFromDom(row);
        syncGroupUnreadFromDom(row);
      }

      function postReadToggle(row, action) {
        if (!row) return Promise.resolve(false);
        const id = row.getAttribute("data-message-id");
        if (!id) return Promise.resolve(false);
        const fd = new FormData();
        fd.append("action", action);
        fd.append("message_id", id);
        fd.append("ajax", "1");
        return fetch(window.location.pathname, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
        }).then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          const ct = (r.headers.get("Content-Type") || "").toLowerCase();
          if (ct.indexOf("application/json") === -1) {
            return r.text().then(function (txt) {
              throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
            });
          }
          return r.json();
        }).then(function (data) {
          if (data && typeof data.unread_total === "number" && typeof data.messages_total === "number") {
            setInboxCounterFromServer(data.unread_total, data.messages_total);
          }
          return !!(data && data.ok);
        }).catch(function (err) {
          console.error("Error en " + action + ":", err);
          return false;
        });
      }

      function markRowAsRead(row) {
        postReadToggle(row, "mark_message_read").then(function (ok) {
          if (ok) applyReadStateToRow(row);
        });
      }

      function markRowAsUnread(row) {
        postReadToggle(row, "mark_message_unread").then(function (ok) {
          if (ok) applyUnreadStateToRow(row);
        });
      }

      if (!inboxRoot) {
        // no hay mensajes en esta vista
      } else {
      inboxRoot.querySelectorAll("details.admin-msg-thread").forEach(function (det) {
        det.addEventListener("toggle", function () {
          if (!det.open) return;
          det.querySelectorAll(".message-row.is-unread").forEach(function (row) {
            markRowAsRead(row);
          });
        });
      });

      // Forms explícitos: en lugar de submit normal con recarga, hacemos AJAX
      // y actualizamos la fila para que el toggle inverso quede disponible.
      inboxRoot.querySelectorAll(".js-mark-read-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markRowAsRead(row);
        });
      });
      inboxRoot.querySelectorAll(".js-mark-unread-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markRowAsUnread(row);
        });
      });

      const markAllForm = document.querySelector("form input[name='action'][value='mark_all_messages_read']");
      if (markAllForm && markAllForm.form) {
        markAllForm.form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const fd = new FormData(markAllForm.form);
          fd.append("ajax", "1");
          fetch(window.location.pathname, {
            method: "POST",
            body: fd,
            credentials: "same-origin",
            headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
          }).then(function (r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            const ct = (r.headers.get("Content-Type") || "").toLowerCase();
            if (ct.indexOf("application/json") === -1) {
              return r.text().then(function (txt) {
                throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
              });
            }
            return r.json();
          }).then(function (data) {
            if (!data || !data.ok) {
              console.error("mark_all_messages_read no actualizó la BD", data);
              return;
            }
            if (typeof data.unread_total === "number" && typeof data.messages_total === "number") {
              setInboxCounterFromServer(data.unread_total, data.messages_total);
            }
            inboxRoot.querySelectorAll(".message-row.is-unread").forEach(applyReadStateToRow);
            if (markAllForm.form.parentElement) markAllForm.form.parentElement.removeChild(markAllForm.form);
          }).catch(function (err) {
            console.error("Error marcando todos como leídos:", err);
          });
        });
      }
      }
    })();

    (function () {
      const waAccordion = document.getElementById("adminWhatsappAccordion");
      if (!waAccordion) return;

      function setWaCounterFromServer(unread, total) {
        var list = document.querySelectorAll(".admin-whatsapp-counter");
        if (!list.length) return;
        var u = Math.max(0, parseInt(String(unread), 10) || 0);
        var t = Math.max(0, parseInt(String(total), 10) || 0);
        var title = u > 0 ? u + " sin leer de " + t : t + " en total";
        var warn = u > 0;
        list.forEach(function (counter) {
          counter.textContent = u + "/" + t;
          counter.title = title;
          counter.classList.toggle("text-bg-warning", warn);
          counter.classList.toggle("text-bg-secondary", !warn);
        });
      }

      function applyWaReadStateToRow(row) {
        if (!row || !row.classList.contains("is-unread")) return;
        row.classList.remove("is-unread");
      }

      function applyWaUnreadStateToRow(row) {
        if (!row || row.classList.contains("is-unread")) return;
        row.classList.add("is-unread");
      }

      function postWaReadToggle(row, action) {
        if (!row) return Promise.resolve(false);
        const id = row.getAttribute("data-whatsapp-click-id");
        if (!id) return Promise.resolve(false);
        const fd = new FormData();
        fd.append("action", action);
        fd.append("whatsapp_click_id", id);
        fd.append("ajax", "1");
        return fetch(window.location.pathname, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
        }).then(function (r) {
          if (!r.ok) throw new Error("HTTP " + r.status);
          const ct = (r.headers.get("Content-Type") || "").toLowerCase();
          if (ct.indexOf("application/json") === -1) {
            return r.text().then(function (txt) {
              throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
            });
          }
          return r.json();
        }).then(function (data) {
          if (data && typeof data.unread_total === "number" && typeof data.messages_total === "number") {
            setWaCounterFromServer(data.unread_total, data.messages_total);
          }
          return !!(data && data.ok);
        }).catch(function (err) {
          console.error("Error en " + action + ":", err);
          return false;
        });
      }

      function markWaRowAsRead(row) {
        postWaReadToggle(row, "mark_whatsapp_read").then(function (ok) {
          if (ok) applyWaReadStateToRow(row);
        });
      }

      function markWaRowAsUnread(row) {
        postWaReadToggle(row, "mark_whatsapp_unread").then(function (ok) {
          if (ok) applyWaUnreadStateToRow(row);
        });
      }

      waAccordion.querySelectorAll(".accordion-collapse").forEach(function (coll) {
        coll.addEventListener("shown.bs.collapse", function () {
          const row = coll.closest(".message-row");
          if (row && row.getAttribute("data-whatsapp-click-id") && row.classList.contains("is-unread")) {
            markWaRowAsRead(row);
          }
        });
      });

      waAccordion.querySelectorAll(".js-wa-mark-read-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markWaRowAsRead(row);
        });
      });
      waAccordion.querySelectorAll(".js-wa-mark-unread-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const row = form.closest(".message-row");
          if (row) markWaRowAsUnread(row);
        });
      });

      const markAllWaForm = document.querySelector("form input[name='action'][value='mark_all_whatsapp_read']");
      if (markAllWaForm && markAllWaForm.form) {
        markAllWaForm.form.addEventListener("submit", function (ev) {
          ev.preventDefault();
          const fd = new FormData(markAllWaForm.form);
          fd.append("ajax", "1");
          fetch(window.location.pathname, {
            method: "POST",
            body: fd,
            credentials: "same-origin",
            headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
          }).then(function (r) {
            if (!r.ok) throw new Error("HTTP " + r.status);
            const ct = (r.headers.get("Content-Type") || "").toLowerCase();
            if (ct.indexOf("application/json") === -1) {
              return r.text().then(function (txt) {
                throw new Error("Respuesta no-JSON: " + txt.slice(0, 200));
              });
            }
            return r.json();
          }).then(function (data) {
            if (!data || !data.ok) return;
            if (typeof data.unread_total === "number" && typeof data.messages_total === "number") {
              setWaCounterFromServer(data.unread_total, data.messages_total);
            }
            waAccordion.querySelectorAll(".message-row.is-unread").forEach(function (row) {
              if (row.getAttribute("data-whatsapp-click-id")) {
                applyWaReadStateToRow(row);
              }
            });
            if (markAllWaForm.form.parentElement) markAllWaForm.form.parentElement.removeChild(markAllWaForm.form);
          }).catch(function (err) {
            console.error("Error marcando WhatsApp como leídos:", err);
          });
        });
      }
    })();
  </script>
  <script src="script.js?v=<?= h($adminAssetScriptVer) ?>"></script>
</body>
</html>
