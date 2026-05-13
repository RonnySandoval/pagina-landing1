<?php
declare(strict_types=1);

/**
 * Registro público (sin sesión) cuando el visitante pulsa "Escribir por WhatsApp".
 * Nombre de archivo sin la cadena "whatsapp" para reducir bloqueos de extensiones
 * del navegador que interceptan peticiones a URLs que la contienen.
 */

header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false]);
    exit;
}

require __DIR__ . "/db.php";

$nombre = trim((string)($_POST["nombre"] ?? ""));
$email = trim((string)($_POST["email"] ?? ""));
$servicio = trim((string)($_POST["servicio"] ?? ""));
$mensaje = trim((string)($_POST["mensaje"] ?? ""));
$composed = trim((string)($_POST["composed_text"] ?? ""));

if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = "";
}

$trunc = static function (string $s, int $max): string {
    if (function_exists("mb_strlen") && function_exists("mb_substr")) {
        if (mb_strlen($s, "UTF-8") <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max, "UTF-8");
    }
    if (strlen($s) <= $max) {
        return $s;
    }

    return substr($s, 0, $max);
};

$nombre = $trunc($nombre, 180);
$email = $trunc($email, 180);
$servicio = $trunc($servicio, 180);
$mensaje = $trunc($mensaje, 8000);
$composed = $trunc($composed, 8000);

$stmt = $conn->prepare(
    "INSERT INTO contact_whatsapp_clicks (nombre, email, servicio, mensaje, composed_text) VALUES (?, ?, ?, ?, ?)"
);
if ($stmt === false) {
    error_log("contact_click_log: prepare failed " . $conn->error);
    echo json_encode(["ok" => false]);
    exit;
}
$stmt->bind_param("sssss", $nombre, $email, $servicio, $mensaje, $composed);
if (!$stmt->execute()) {
    error_log("contact_click_log: execute failed " . $stmt->error);
    $stmt->close();
    echo json_encode(["ok" => false]);
    exit;
}
$stmt->close();

echo json_encode(["ok" => true]);
