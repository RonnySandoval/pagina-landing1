<?php
declare(strict_types=1);

require __DIR__ . "/db.php";
require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/app_urls.php";
require_once __DIR__ . "/agenda_service.php";

client_session_start();

if (!app_feature_enabled("expert_agenda")) {
    header("Location: " . app_landing_url() . "#inicio");
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    header("Location: " . app_public_base_url() . "/agenda.php");
    exit;
}

$returnPage = trim((string)($_POST["return_page"] ?? "agenda.php"));
if ($returnPage !== "agenda.php" && $returnPage !== "index.php") {
    $returnPage = "agenda.php";
}

$clientId = null;
if (client_portal_resume_session($conn)) {
    $cid = (int)($_SESSION["client_id"] ?? 0);
    if ($cid > 0) {
        $clientId = $cid;
    }
}

$result = agenda_service_create_booking($conn, $_POST, ["client_id" => $clientId]);

$serviceId = (int)($_POST["agenda_service_id"] ?? 0);
$slotTok = trim((string)($_POST["agenda_slot"] ?? ""));
$startsAt = "";
$p = strpos($slotTok, "@");
if ($p !== false) {
    $startsAt = trim(substr($slotTok, $p + 1));
}
$dateReturn = trim((string)($_POST["agenda_date_return"] ?? ""));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateReturn)) {
    $dateReturn = "";
}
if ($dateReturn === "" && preg_match('/^(\d{4}-\d{2}-\d{2})\s/', $startsAt, $m)) {
    $dateReturn = $m[1];
}

$qParts = [];
if ($serviceId > 0) {
    $qParts["agenda_service"] = (string)$serviceId;
}
if ($dateReturn !== "") {
    $qParts["agenda_date"] = $dateReturn;
}
$tail = $qParts === [] ? "" : ("?" . http_build_query($qParts));

if (!$result["ok"]) {
    $_SESSION["agenda_flash"] = [
        "type" => "danger",
        "msg" => (string)($result["message"] ?? "No se pudo completar la reserva."),
    ];
} else {
    $_SESSION["agenda_flash"] = [
        "type" => "success",
        "msg" => "Reserva registrada. Te contactaremos si hace falta confirmación adicional.",
    ];
}

$base = app_public_base_url() . "/" . $returnPage;
$hash = "#agenda-cita";
header("Location: " . $base . $tail . $hash);
exit;
