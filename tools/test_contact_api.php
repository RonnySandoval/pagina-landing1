<?php
declare(strict_types=1);

/**
 * Prueba local de contact_service y API (CLI).
 * Uso: php tools/test_contact_api.php
 */

$root = dirname(__DIR__);
chdir($root);

require $root . "/db.php";
require_once $root . "/app_urls.php";
require_once $root . "/smtp_mail.php";
require_once $root . "/contact_service.php";

$failures = 0;
$passed = 0;

function test_assert(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) {
        echo "  OK  {$label}\n";
        $passed++;
        return;
    }
    echo "  FAIL {$label}\n";
    $failures++;
}

echo "=== contact_service (CLI, mysqli) ===\n\n";

// 1) Validación: falta nombre
$r = contact_service_submit($conn, [
    "nombre" => "",
    "email" => "valid@test.local",
    "servicio" => "Demo",
    "asunto" => "Asunto test",
    "mensaje" => "Cuerpo",
], []);
test_assert($r["ok"] === false && ($r["error"] ?? "") === "nombre", "rechaza nombre vacío");

// 2) Validación: email inválido
$r = contact_service_submit($conn, [
    "nombre" => "Tester API",
    "email" => "no-es-email",
    "servicio" => "Demo",
    "asunto" => "Asunto test",
    "mensaje" => "Cuerpo",
], []);
test_assert($r["ok"] === false && ($r["error"] ?? "") === "email_invalido", "rechaza email inválido");

// 3) Validación: falta asunto (sin in_reply_to)
$r = contact_service_submit($conn, [
    "nombre" => "Tester API",
    "email" => "api-test-" . time() . "@example.local",
    "servicio" => "Demo",
    "asunto" => "",
    "mensaje" => "Cuerpo de prueba automatizada.",
], []);
test_assert($r["ok"] === false && ($r["error"] ?? "") === "asunto", "rechaza asunto vacío");

// 4) Envío válido
$uniqueEmail = "api-test-" . time() . "@example.local";
$r = contact_service_submit($conn, [
    "nombre" => "Tester API",
    "email" => $uniqueEmail,
    "servicio" => "Servicio demo",
    "asunto" => "Prueba tools/test_contact_api.php",
    "mensaje" => "Mensaje generado por test automatizado " . date("c"),
], []);
test_assert($r["ok"] === true, "acepta envío válido");
$messageId = (int)($r["message_id"] ?? 0);
test_assert($messageId > 0, "devuelve message_id > 0");
test_assert(in_array($r["outcome"] ?? "", ["ok", "saved"], true), "outcome ok o saved");

if ($messageId > 0) {
    $chk = $conn->prepare("SELECT id, nombre, email, servicio, subject, mensaje FROM contact_messages WHERE id = ? LIMIT 1");
    $chk->bind_param("i", $messageId);
    $chk->execute();
    $res = $chk->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $chk->close();
    test_assert($row !== null && (string)$row["email"] === $uniqueEmail, "fila existe en contact_messages");
    test_assert((string)($row["subject"] ?? "") === "Prueba tools/test_contact_api.php", "asunto persistido");
}

// 5) client_inbox_disabled con return_anchor area-cliente
$prevFeatures = null;
$cfgPath = $root . "/app_config.php";
if (is_readable($cfgPath)) {
    $cfg = require $cfgPath;
    if (is_array($cfg) && array_key_exists("features", $cfg)) {
        $prevFeatures = $cfg["features"];
    }
}
// Simular desactivado vía contexto: el servicio consulta app_feature_enabled — solo probamos si podemos mockear
// En su lugar: verificar que require_client_inbox_for_area=false permite area-cliente sin feature
// (no cambiamos app_config en disco)

// 6) Seguimiento sin sesión
$r = contact_service_submit($conn, [
    "nombre" => "Tester",
    "email" => "x@y.local",
    "servicio" => "Demo",
    "mensaje" => "Seguimiento sin sesión",
    "in_reply_to" => 1,
], ["session_client_id" => 0, "session_email_norm" => ""]);
test_assert($r["ok"] === false && ($r["error"] ?? "") === "sesion_seguimiento", "seguimiento sin sesión → sesion_seguimiento");

echo "\n=== HTTP API (curl.exe si localhost responde) ===\n\n";

// En CLI, SCRIPT_NAME apunta a tools/ y la base URL sale mal en Windows; usar localhost fijo.
$apiUrl = getenv("CONTACT_API_TEST_URL");
if (!is_string($apiUrl) || $apiUrl === "") {
    $apiUrl = "http://localhost/pag-template/api/v1/contact/messages.php";
}
echo "URL: {$apiUrl}\n";

$httpOk = false;
if (function_exists("curl_init")) {
    $payload = json_encode([
        "nombre" => "HTTP Tester",
        "email" => "http-test-" . time() . "@example.local",
        "servicio" => "API curl",
        "asunto" => "Prueba HTTP",
        "mensaje" => "Desde curl contra messages.php",
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== "") {
        echo "  SKIP HTTP (curl error: {$err})\n";
    } else {
        echo "  HTTP status: {$code}\n";
        echo "  Body: {$body}\n";
        $decoded = json_decode((string)$body, true);
        test_assert($code === 201, "HTTP 201 Created");
        test_assert(is_array($decoded) && ($decoded["ok"] ?? false) === true, "JSON ok:true");
        test_assert(isset($decoded["data"]["message_id"]) && (int)$decoded["data"]["message_id"] > 0, "JSON data.message_id");
        $httpOk = $code === 201;
    }
} else {
    echo "  SKIP HTTP (ext-curl no disponible en CLI)\n";
}

// GET debe ser 405
if (function_exists("curl_init")) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        test_assert($code === 405, "GET → 405 method_not_allowed");
    }
}

// POST validación vía HTTP
if (function_exists("curl_init")) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(["email" => "a@b.com"], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false) {
        $decoded = json_decode((string)$body, true);
        test_assert($code === 400 && ($decoded["ok"] ?? null) === false, "POST incompleto → 400");
        test_assert(isset($decoded["fields"]) && is_array($decoded["fields"]), "POST incompleto incluye fields");
    }
}

echo "\n=== Resumen ===\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron: {$failures}\n";

exit($failures > 0 ? 1 : 0);
