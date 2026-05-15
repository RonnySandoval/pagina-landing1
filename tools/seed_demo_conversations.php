<?php
declare(strict_types=1);

/**
 * Simula varias conversaciones (asuntos distintos, hilos con seguimientos y respuestas).
 *
 * Uso (desde la raíz del proyecto):
 *   php tools/seed_demo_conversations.php
 *   php tools/seed_demo_conversations.php --clean   # borra datos demo previos y vuelve a insertar
 *
 * Credenciales de prueba (tras ejecutar):
 *   Correo: demo.conv.seed@example.com
 *   Clave:  SeedDemo2026!
 *
 * Luego abre index.php#area-cliente (sesión cliente) y admin (bandeja) para ver el resultado.
 *
 * Registro persistente (no versionado; carpeta var/ en .gitignore):
 *   var/log/seed_demo_conversations.log
 * Misma salida que en consola, más cabecera con fecha y argumentos.
 */

if (PHP_SAPI !== "cli") {
    fwrite(STDERR, "Este script solo debe ejecutarse por CLI.\n");
    exit(1);
}

$clean = in_array("--clean", $argv, true);

$GLOBALS["seed_log_fp"] = false;

/**
 * Abre el log en append. Devuelve la ruta absoluta o cadena vacía si no se pudo escribir.
 */
function seed_log_open(string $projectRoot): string
{
    $path = $projectRoot . DIRECTORY_SEPARATOR . "var" . DIRECTORY_SEPARATOR . "log"
        . DIRECTORY_SEPARATOR . "seed_demo_conversations.log";
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return "";
        }
    }
    $fp = @fopen($path, "ab");
    if ($fp === false) {
        return "";
    }
    $GLOBALS["seed_log_fp"] = $fp;
    fwrite($fp, "\n" . str_repeat("=", 72) . "\n");
    fwrite($fp, date("c") . "  argv: " . implode(" ", $GLOBALS["argv"] ?? []) . "\n");
    fwrite($fp, str_repeat("-", 72) . "\n");
    fflush($fp);

    return $path;
}

function seed_log(string $msg): void
{
    fwrite(STDOUT, $msg);
    $fp = $GLOBALS["seed_log_fp"] ?? false;
    if ($fp !== false && is_resource($fp)) {
        fwrite($fp, $msg);
        fflush($fp);
    }
}

function seed_err(string $msg): void
{
    fwrite(STDERR, $msg);
    $fp = $GLOBALS["seed_log_fp"] ?? false;
    if ($fp !== false && is_resource($fp)) {
        fwrite($fp, $msg);
        fflush($fp);
    }
}

function seed_log_close(): void
{
    $fp = $GLOBALS["seed_log_fp"] ?? false;
    if ($fp !== false && is_resource($fp)) {
        fwrite($fp, str_repeat("-", 72) . "\n");
        fwrite($fp, date("c") . "  fin ejecución\n");
        fclose($fp);
    }
    $GLOBALS["seed_log_fp"] = false;
}

require_once __DIR__ . "/../db.php";

$seedLogPath = seed_log_open(dirname(__DIR__));
register_shutdown_function(static function (): void {
    seed_log_close();
});
if ($seedLogPath !== "") {
    seed_log("[seed] Registro completo en: {$seedLogPath}\n");
} else {
    fwrite(STDOUT, "[seed] Aviso: no se pudo abrir var/log/seed_demo_conversations.log (solo salida por consola).\n");
}

/**
 * Misma lógica que admin_group_messages_threads / index_client_group_messages_threads.
 *
 * @param array<int, array<string, mixed>> $messages
 * @param array<int, list<array<string, mixed>>> $repliesByMessageId
 * @return list<array{root_id:int, messages:list<array<string, mixed>>, latest_ts:int, has_admin_reply:bool}>
 */
function seed_group_messages_threads(array $messages, array $repliesByMessageId): array
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

const DEMO_EMAIL = "demo.conv.seed@example.com";
const DEMO_PASSWORD_PLAIN = "SeedDemo2026!";
const DEMO_DISPLAY_NAME = "Cliente simulación";

$chk = $conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
if ($chk !== false) {
    $de = DEMO_EMAIL;
    $chk->bind_param("s", $de);
    $chk->execute();
    $ex = $chk->get_result();
    $exists = $ex && $ex->num_rows > 0;
    $chk->close();
    if ($exists && !$clean) {
        seed_err("[seed] Ya existe el cliente demo. Ejecuta con --clean para borrarlo y volver a sembrar.\n");
        exit(2);
    }
}

/** @return list<string> */
function seed_fetch_service_titles(mysqli $conn): array
{
    $out = [];
    $q = $conn->query("SELECT title FROM services WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 8");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $t = trim((string)($row["title"] ?? ""));
            if ($t !== "") {
                $out[] = $t;
            }
        }
    }
    if ($out === []) {
        $out = ["Servicio demo"];
    }

    return $out;
}

function seed_site_contact_email(mysqli $conn): string
{
    $q = $conn->query("SELECT contact_email FROM site_settings WHERE id = 1 LIMIT 1");
    if ($q && $row = $q->fetch_assoc()) {
        $em = trim((string)($row["contact_email"] ?? ""));
        if ($em !== "") {
            return $em;
        }
    }

    return "contacto@localhost";
}

/**
 * @param array{subject:string,servicio:string,rows:list<array{body:string,is_read:int,admin_reply:?string}>} $spec
 * @return list<int> inserted message ids in order
 */
function seed_insert_thread(
    mysqli $conn,
    int $clientId,
    string $sentTo,
    string $demoEmail,
    string $nombre,
    array $spec,
    DateTimeImmutable $threadAnchor
): array {
    $subject = $spec["subject"];
    $servicio = $spec["servicio"];
    $rows = $spec["rows"];
    $ids = [];
    $parentId = 0;
    $i = 0;
    foreach ($rows as $r) {
        $created = $threadAnchor->modify(sprintf("+%d hours", $i * 3));
        $createdStr = $created->format("Y-m-d H:i:s");
        $isRead = (int)$r["is_read"];
        $body = (string)$r["body"];
        if ($parentId <= 0) {
            $stmt = $conn->prepare(
                "INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read, client_id, in_reply_to, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)"
            );
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO contact_messages (nombre, email, servicio, subject, mensaje, sent_to, is_read, client_id, in_reply_to, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        if ($stmt === false) {
            throw new RuntimeException("prepare contact_messages: " . $conn->error);
        }
        if ($parentId <= 0) {
            $stmt->bind_param(
                "ssssssiis",
                $nombre,
                $demoEmail,
                $servicio,
                $subject,
                $body,
                $sentTo,
                $isRead,
                $clientId,
                $createdStr
            );
        } else {
            $pid = $parentId;
            $stmt->bind_param(
                "ssssssiiis",
                $nombre,
                $demoEmail,
                $servicio,
                $subject,
                $body,
                $sentTo,
                $isRead,
                $clientId,
                $pid,
                $createdStr
            );
        }
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new RuntimeException("insert message: " . $err);
        }
        $mid = (int)$stmt->insert_id;
        $stmt->close();
        $ids[] = $mid;
        $parentId = $mid;

        $reply = $r["admin_reply"] ?? null;
        if ($reply !== null && $reply !== "") {
            $rb = $conn->prepare(
                "INSERT INTO contact_message_replies (contact_message_id, body, created_at) VALUES (?, ?, ?)"
            );
            if ($rb === false) {
                throw new RuntimeException("prepare reply: " . $conn->error);
            }
            $replyAt = $created->modify("+25 minutes")->format("Y-m-d H:i:s");
            $rb->bind_param("iss", $mid, $reply, $replyAt);
            if (!$rb->execute()) {
                $e = $rb->error;
                $rb->close();
                throw new RuntimeException("insert reply: " . $e);
            }
            $rb->close();
        }
        $i++;
    }

    return $ids;
}

function seed_delete_demo_client(mysqli $conn, string $demoEmail): void
{
    $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param("s", $demoEmail);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $cid = $row ? (int)$row["id"] : 0;

    if ($cid > 0) {
        $conn->query(
            "DELETE r FROM contact_message_replies r
             INNER JOIN contact_messages m ON m.id = r.contact_message_id
             WHERE m.client_id = " . (int)$cid
        );
        $conn->query("DELETE FROM contact_messages WHERE client_id = " . (int)$cid);
        $conn->query("DELETE FROM clients WHERE id = " . (int)$cid);
    }

    $em = $conn->real_escape_string($demoEmail);
    $conn->query(
        "DELETE r FROM contact_message_replies r
         INNER JOIN contact_messages m ON m.id = r.contact_message_id
         WHERE LOWER(TRIM(m.email)) = LOWER('" . $em . "')"
    );
    $conn->query("DELETE FROM contact_messages WHERE LOWER(TRIM(email)) = LOWER('" . $em . "')");
}

// --- main -----------------------------------------------------------------

$services = seed_fetch_service_titles($conn);
$s0 = $services[0];
$s1 = $services[1 % count($services)];
$s2 = $services[2 % count($services)];
$s3 = $services[3 % count($services)];

$sentTo = seed_site_contact_email($conn);

if ($clean) {
    seed_delete_demo_client($conn, DEMO_EMAIL);
    seed_log("[seed] Datos demo anteriores eliminados (si existían).\n");
}

$hash = password_hash(DEMO_PASSWORD_PLAIN, PASSWORD_DEFAULT);
if ($hash === false) {
    seed_err("[seed] No se pudo generar hash de contraseña.\n");
    exit(1);
}

$ins = $conn->prepare(
    "INSERT INTO clients (email, password, display_name, is_active) VALUES (?, ?, ?, 1)"
);
if ($ins === false) {
    seed_err("[seed] Error preparando INSERT clients: " . $conn->error . "\n");
    exit(1);
}
$ins->bind_param("sss", $em, $hash, $dn);
$em = DEMO_EMAIL;
$dn = DEMO_DISPLAY_NAME;
if (!$ins->execute()) {
    seed_err("[seed] No se pudo crear el cliente demo: " . $ins->error . "\n");
    $ins->close();
    exit(1);
}
$clientId = (int)$ins->insert_id;
$ins->close();

$anchor = new DateTimeImmutable("now", new DateTimeZone(date_default_timezone_get()));

$threadSpecs = [
    [
        "subject" => "Presupuesto reforma baño",
        "servicio" => $s0,
        "rows" => [
            [
                "body" => "Hola, necesito una estimación aproximada para cambiar bañera por plato de ducha y alicatar. Gracias.",
                "is_read" => 1,
                "admin_reply" => "Buenos días, gracias por escribirnos. Para orientar el presupuesto: ¿mides aprox. el baño y tienes fotos? Quedamos atentos.",
            ],
            [
                "body" => "Mido unos 4 m²; subo fotos en el siguiente correo si hace falta. ¿Incluye retirada de escombros?",
                "is_read" => 0,
                "admin_reply" => null,
            ],
            [
                "body" => "Adjunto medidas: largo 2,40 m, ancho 1,70 m. Ventana al patio. ¿Podéis visitar la semana que viene?",
                "is_read" => 0,
                "admin_reply" => "Sí, podemos coordinar visita. Os proponemos martes o jueves por la mañana; confirmad día.",
            ],
        ],
    ],
    [
        "subject" => "Clases de guitarra — horarios tarde",
        "servicio" => $s2,
        "rows" => [
            [
                "body" => "Busco clases para adulto principiante, zona centro, a partir de las 18:30. ¿Tenéis plaza?",
                "is_read" => 0,
                "admin_reply" => null,
            ],
            [
                "body" => "Prefiero miércoles o jueves. ¿Cuál es la cuota mensual y la duración de cada clase?",
                "is_read" => 0,
                "admin_reply" => "Hola, tenemos hueco los jueves 19:00–19:45. Cuota y condiciones te las enviamos en PDF por correo.",
            ],
        ],
    ],
    [
        "subject" => "Consulta técnica — integración con mi web",
        "servicio" => $s1,
        "rows" => [
            [
                "body" => "Tengo una web en WordPress y quiero enlazar el formulario de contacto con un CRM sencillo. ¿Lo hacéis?",
                "is_read" => 1,
                "admin_reply" => "Sí, podemos valorarlo. Indícanos qué CRM usas o si partimos de cero.",
            ],
        ],
    ],
    [
        "subject" => "",
        "servicio" => $s3,
        "rows" => [
            [
                "body" => "Mensaje inicial sin asunto en el título (solo servicio). Quiero información general.",
                "is_read" => 0,
                "admin_reply" => null,
            ],
            [
                "body" => "Seguimiento: concretando, me interesa sobre todo plazos de entrega.",
                "is_read" => 0,
                "admin_reply" => "Plazos habituales 10–15 días laborables según alcance. ¿Te sirve una llamada breve?",
            ],
        ],
    ],
    [
        "subject" => "Evento corporativo — catering y sonido (junio)",
        "servicio" => $s0,
        "rows" => [
            [
                "body" => "Somos 80 personas, salón cerrado, 14 de junio. Necesitamos menú vegetariano y opción sin gluten.",
                "is_read" => 0,
                "admin_reply" => "Recibido. ¿Ciudad del evento y horario aproximado de servicio?",
            ],
            [
                "body" => "Valencia, servicio de 14:00 a 17:00. ¿Podéis enviar dos propuestas de menú?",
                "is_read" => 0,
                "admin_reply" => null,
            ],
            [
                "body" => "Confirmamos asistencia final 85 personas. ¿Necesitáis ficha técnica del salón?",
                "is_read" => 1,
                "admin_reply" => "Perfecto, enviadnos ficha técnica y acceso carga. Preparamos propuestas ajustadas.",
            ],
            [
                "body" => "Adjunto ficha en el correo externo; aquí resumo: enchufes trifásicos junto al escenario.",
                "is_read" => 0,
                "admin_reply" => null,
            ],
        ],
    ],
];

$allIds = [];
$ti = 0;
foreach ($threadSpecs as $spec) {
    $dayOffset = -18 + $ti * 4;
    $threadAnchor = $anchor->modify(sprintf("%d days", $dayOffset))->setTime(9, 15 + $ti);
    $ids = seed_insert_thread(
        $conn,
        $clientId,
        $sentTo,
        DEMO_EMAIL,
        DEMO_DISPLAY_NAME,
        $spec,
        $threadAnchor
    );
    $allIds = array_merge($allIds, $ids);
    $ti++;
}

seed_log("[seed] Cliente creado id={$clientId}, mensajes insertados: " . count($allIds) . ".\n");
seed_log("[seed] Acceso área cliente:\n");
seed_log("       Correo: " . DEMO_EMAIL . "\n");
seed_log("       Clave:  " . DEMO_PASSWORD_PLAIN . "\n");
seed_log("[seed] Bandeja admin: mensajes del grupo «Cliente» con varios hilos (Conv. / Msg.).\n\n");

// --- informe (misma agrupación que la web) --------------------------------
$stmt = $conn->prepare(
    "SELECT id, nombre, servicio, subject, mensaje, created_at, in_reply_to, is_read
     FROM contact_messages WHERE client_id = ? ORDER BY created_at ASC, id ASC"
);
if ($stmt === false) {
    seed_err("[seed] No se pudo leer informe.\n");
    exit(0);
}
$stmt->bind_param("i", $clientId);
$stmt->execute();
$res = $stmt->get_result();
$msgs = [];
while ($row = $res->fetch_assoc()) {
    $msgs[] = $row;
}
$stmt->close();

$msgIdSet = [];
foreach ($msgs as $m) {
    $mid = (int)($m["id"] ?? 0);
    if ($mid > 0) {
        $msgIdSet[$mid] = true;
    }
}
$repliesBy = [];
if ($msgIdSet !== []) {
    $inList = implode(",", array_map("intval", array_keys($msgIdSet)));
    $rq = $conn->query(
        "SELECT id, contact_message_id, body, created_at FROM contact_message_replies WHERE contact_message_id IN ($inList) ORDER BY created_at ASC, id ASC"
    );
    if ($rq) {
        while ($rp = $rq->fetch_assoc()) {
            $mid = (int)$rp["contact_message_id"];
            if (!isset($repliesBy[$mid])) {
                $repliesBy[$mid] = [];
            }
            $repliesBy[$mid][] = $rp;
        }
    }
}

$threads = seed_group_messages_threads($msgs, $repliesBy);
seed_log("=== Vista previa textual (orden admin: última actividad arriba) ===\n");
seed_log("Hilos: " . count($threads) . " | Mensajes totales: " . count($msgs) . "\n\n");

foreach ($threads as $idx => $th) {
    $rootId = (int)$th["root_id"];
    $tmsgs = $th["messages"];
    $first = $tmsgs[0] ?? [];
    $sub = trim((string)($first["subject"] ?? ""));
    $subDisp = $sub !== "" ? $sub : "(sin asunto)";
    $hasAd = !empty($th["has_admin_reply"]) ? "sí" : "no";
    seed_log(sprintf("— Hilo #%d  Conv.%d  «%s»  (%d mensajes, resp. admin: %s)\n", $idx + 1, $rootId, $subDisp, count($tmsgs), $hasAd));
    foreach ($tmsgs as $m) {
        $mid = (int)$m["id"];
        $ir = (int)($m["in_reply_to"] ?? 0);
        $link = $ir > 0 ? " in_reply_to={$ir}" : "";
        $snippet = preg_replace('/\s+/u', " ", (string)($m["mensaje"] ?? ""));
        if (strlen($snippet) > 72) {
            $snippet = substr($snippet, 0, 72) . "…";
        }
        $rc = isset($repliesBy[$mid]) ? count($repliesBy[$mid]) : 0;
        seed_log("    Msg.{$mid}{$link}  [{$rc} resp.]  {$snippet}\n");
    }
    seed_log("\n");
}

seed_log("[seed] Listo.\n");
exit(0);
