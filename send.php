<?php
declare(strict_types=1);

// POST del formulario de contacto → send.php (misma carpeta que index.php).
// Lógica de negocio: contact_service.php · API JSON: api/v1/contact/messages.php

/** Tras POST en send.php: `client_contact` en área cliente o `status` en #contacto. */
function contact_send_redirect_to_landing(string $returnAnchor, string $outcome, string $reason = ""): void
{
    $fragment = $returnAnchor === "area-cliente" ? "area-cliente" : "contacto";
    if ($returnAnchor === "area-cliente") {
        if ($outcome === "ok") {
            header("Location: index.php?client_contact=ok#" . $fragment);
        } elseif ($outcome === "saved") {
            header("Location: index.php?client_contact=saved#" . $fragment);
        } else {
            $q = $reason !== "" ? ("client_contact=error&reason=" . urlencode($reason)) : "client_contact=error";
            header("Location: index.php?" . $q . "#" . $fragment);
        }
    } elseif ($outcome === "ok") {
        header("Location: index.php?status=ok#contacto");
    } elseif ($outcome === "saved") {
        header("Location: index.php?status=saved#contacto");
    } else {
        $q = $reason !== "" ? ("status=error&reason=" . urlencode($reason)) : "status=error";
        header("Location: index.php?" . $q . "#" . $fragment);
    }
    exit;
}

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)($e["type"] ?? 0), $fatal, true)) {
        return;
    }
    if (function_exists("contact_send_trace")) {
        contact_send_trace("FATAL: " . ($e["message"] ?? "") . " en " . ($e["file"] ?? "") . ":" . (string)($e["line"] ?? 0));
    }
});

require __DIR__ . "/db.php";
require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/contact_service.php";

client_session_start();

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {
    contact_send_trace("POST recibido en send.php");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    contact_send_redirect_to_landing("contacto", "error");
}

$returnAnchor = trim((string)($_POST["return_anchor"] ?? ""));
if ($returnAnchor !== "area-cliente") {
    $returnAnchor = "contacto";
}

$sessionClientId = 0;
$sessionEmailNorm = "";
if (client_portal_resume_session($conn)) {
    $sessionClientId = (int)($_SESSION["client_id"] ?? 0);
    $sessionEmailNorm = strtolower(trim((string)($_SESSION["client_email"] ?? "")));
}

$result = contact_service_submit($conn, $_POST, [
    "session_client_id" => $sessionClientId,
    "session_email_norm" => $sessionEmailNorm,
    "require_client_inbox_for_area" => true,
]);

if (!$result["ok"]) {
    $reason = (string)($result["error"] ?? "error");
    contact_send_redirect_to_landing($returnAnchor, "error", $reason);
}

$outcome = (string)($result["outcome"] ?? "saved");
contact_send_redirect_to_landing($returnAnchor, $outcome);
