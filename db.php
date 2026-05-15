<?php
declare(strict_types=1);

// Credenciales por defecto (XAMPP). Si existe `db_config.php` en esta carpeta,
// sus valores sustituyen a los de abajo. Plantilla: `db_config.example.php`.
$dbHost = "127.0.0.1";
$dbUser = "root";
$dbPass = "";
$dbName = "web_personal";

$dbConfigPath = __DIR__ . "/db_config.php";
if (is_readable($dbConfigPath)) {
    $dbConfig = require $dbConfigPath;
    if (is_array($dbConfig)) {
        $dbHost = (string)($dbConfig["host"] ?? $dbHost);
        $dbUser = (string)($dbConfig["user"] ?? $dbUser);
        $dbPass = (string)($dbConfig["password"] ?? $dbPass);
        $dbName = (string)($dbConfig["database"] ?? $dbName);
    }
}

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    http_response_code(500);
    die("Error de conexión a la base de datos.");
}

$conn->set_charset("utf8mb4");

function getBootstrapAdminCredentials(): ?array
{
    $bootstrapPath = __DIR__ . "/admin_bootstrap.php";
    if (!is_readable($bootstrapPath)) {
        return null;
    }

    $bootstrap = require $bootstrapPath;
    if (!is_array($bootstrap)) {
        return null;
    }

    $email = trim((string)($bootstrap["email"] ?? ""));
    $password = (string)($bootstrap["password"] ?? "");
    if ($email === "" || $password === "") {
        return null;
    }

    return [
        "email" => $email,
        "password" => $password,
    ];
}

// Auto-inicialización mínima para evitar errores si no se importó setup.sql.
$conn->query("
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL
)");

$conn->query("
CREATE TABLE IF NOT EXISTS admin_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_password_resets_admin (admin_id),
  INDEX idx_admin_password_resets_expires (expires_at)
)");

$conn->query("
CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(180) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  display_name VARCHAR(180) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_notify_outbound TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clients_active (is_active)
)");

$adminsPasswordLength = 255;
$adminsPasswordColumnResult = $conn->query("
SELECT CHARACTER_MAXIMUM_LENGTH AS max_len
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'admins'
  AND COLUMN_NAME = 'password'
LIMIT 1
");
if ($adminsPasswordColumnResult && $adminsPasswordColumnResult->num_rows === 1) {
    $adminsPasswordColumn = $adminsPasswordColumnResult->fetch_assoc();
    $adminsPasswordLength = (int)($adminsPasswordColumn["max_len"] ?? 255);
}
if ($adminsPasswordLength < 255) {
    $conn->query("ALTER TABLE admins MODIFY password VARCHAR(255) NOT NULL");
}

$adminsCountResult = $conn->query("SELECT COUNT(*) AS total FROM admins");
$adminsTotal = 0;
if ($adminsCountResult) {
    $adminsRow = $adminsCountResult->fetch_assoc();
    $adminsTotal = (int)($adminsRow["total"] ?? 0);
}
if ($adminsTotal === 0) {
    $bootstrapAdmin = getBootstrapAdminCredentials();
    if (is_array($bootstrapAdmin)) {
        $hashedPassword = password_hash($bootstrapAdmin["password"], PASSWORD_DEFAULT);
        if ($hashedPassword !== false) {
            $stmt = $conn->prepare("INSERT INTO admins (email, password) VALUES (?, ?)");
            if ($stmt !== false) {
                $stmt->bind_param("ss", $bootstrapAdmin["email"], $hashedPassword);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

$conn->query("
CREATE TABLE IF NOT EXISTS site_settings (
  id INT PRIMARY KEY,
  person_name VARCHAR(180) NOT NULL,
  brand_name VARCHAR(120) NOT NULL,
  hero_title TEXT NOT NULL,
  hero_intro TEXT NOT NULL,
  about_text TEXT NOT NULL,
  contact_intro TEXT NOT NULL,
  contact_email VARCHAR(180) NOT NULL,
  contact_whatsapp VARCHAR(32) DEFAULT NULL,
  contact_whatsapp_country_code VARCHAR(8) DEFAULT NULL,
  footer_text VARCHAR(180) NOT NULL,
  logo_image_path VARCHAR(255) DEFAULT NULL
)");

// Migración: si la BD ya existía con el schema viejo, agrega logo_image_path.
$siteSettingsHasLogo = false;
$siteSettingsLogoColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'site_settings'
  AND COLUMN_NAME = 'logo_image_path'
LIMIT 1
");
if ($siteSettingsLogoColumnResult && $siteSettingsLogoColumnResult->num_rows === 1) {
    $siteSettingsHasLogo = true;
}
if (!$siteSettingsHasLogo) {
    $conn->query("ALTER TABLE site_settings ADD COLUMN logo_image_path VARCHAR(255) DEFAULT NULL AFTER footer_text");
}

// Migración: agrega contact_whatsapp si la BD ya existía sin esa columna.
$siteSettingsHasWhatsapp = false;
$siteSettingsWhatsappColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'site_settings'
  AND COLUMN_NAME = 'contact_whatsapp'
LIMIT 1
");
if ($siteSettingsWhatsappColumnResult && $siteSettingsWhatsappColumnResult->num_rows === 1) {
    $siteSettingsHasWhatsapp = true;
}
if (!$siteSettingsHasWhatsapp) {
    $conn->query("ALTER TABLE site_settings ADD COLUMN contact_whatsapp VARCHAR(32) DEFAULT NULL AFTER contact_email");
}

$siteSettingsHasWhatsappCc = false;
$siteSettingsWhatsappCcColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'site_settings'
  AND COLUMN_NAME = 'contact_whatsapp_country_code'
LIMIT 1
");
if ($siteSettingsWhatsappCcColumnResult && $siteSettingsWhatsappCcColumnResult->num_rows === 1) {
    $siteSettingsHasWhatsappCc = true;
}
if (!$siteSettingsHasWhatsappCc) {
    $conn->query("ALTER TABLE site_settings ADD COLUMN contact_whatsapp_country_code VARCHAR(8) DEFAULT NULL AFTER contact_whatsapp");
}

$siteSettingsHasAgendaShowNames = false;
$siteSettingsAgendaNamesCol = $conn->query("
SELECT 1 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_settings' AND COLUMN_NAME = 'agenda_show_expert_names' LIMIT 1
");
if ($siteSettingsAgendaNamesCol && $siteSettingsAgendaNamesCol->num_rows === 1) {
    $siteSettingsHasAgendaShowNames = true;
}
if (!$siteSettingsHasAgendaShowNames) {
    $conn->query("ALTER TABLE site_settings ADD COLUMN agenda_show_expert_names TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_image_path");
}

$conn->query("
CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  icon_class VARCHAR(120) NOT NULL DEFAULT 'fa-solid fa-star',
  image_path VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 999,
  is_active TINYINT(1) NOT NULL DEFAULT 1
)");

$servicesHasImagePath = false;
$servicesImageColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'services'
  AND COLUMN_NAME = 'image_path'
LIMIT 1
");
if ($servicesImageColumnResult && $servicesImageColumnResult->num_rows === 1) {
    $servicesHasImagePath = true;
}
if (!$servicesHasImagePath) {
    $conn->query("ALTER TABLE services ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER icon_class");
}

$conn->query("
CREATE TABLE IF NOT EXISTS contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL,
  email VARCHAR(180) NOT NULL,
  servicio VARCHAR(180) NOT NULL,
  subject VARCHAR(200) NOT NULL DEFAULT '',
  mensaje TEXT NOT NULL,
  sent_to VARCHAR(180) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  client_has_unseen_reply TINYINT(1) NOT NULL DEFAULT 0,
  client_id INT NULL DEFAULT NULL,
  in_reply_to INT NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_messages_client (client_id),
  INDEX idx_contact_messages_in_reply (in_reply_to)
)");

$messagesHasIsRead = false;
$messagesIsReadColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contact_messages'
  AND COLUMN_NAME = 'is_read'
LIMIT 1
");
if ($messagesIsReadColumnResult && $messagesIsReadColumnResult->num_rows === 1) {
    $messagesHasIsRead = true;
}
if (!$messagesHasIsRead) {
    $conn->query("ALTER TABLE contact_messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER sent_to");
}

$messagesHasClientId = false;
$messagesClientIdColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contact_messages'
  AND COLUMN_NAME = 'client_id'
LIMIT 1
");
if ($messagesClientIdColumnResult && $messagesClientIdColumnResult->num_rows === 1) {
    $messagesHasClientId = true;
}
if (!$messagesHasClientId) {
    $conn->query("ALTER TABLE contact_messages ADD COLUMN client_id INT NULL DEFAULT NULL AFTER is_read");
    $conn->query("CREATE INDEX idx_contact_messages_client ON contact_messages (client_id)");
}

$messagesHasInReplyTo = false;
$messagesInReplyToColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contact_messages'
  AND COLUMN_NAME = 'in_reply_to'
LIMIT 1
");
if ($messagesInReplyToColumnResult && $messagesInReplyToColumnResult->num_rows === 1) {
    $messagesHasInReplyTo = true;
}
if (!$messagesHasInReplyTo) {
    $conn->query("ALTER TABLE contact_messages ADD COLUMN in_reply_to INT NULL DEFAULT NULL AFTER client_id");
    $conn->query("CREATE INDEX idx_contact_messages_in_reply ON contact_messages (in_reply_to)");
}

$messagesHasSubject = false;
$messagesSubjectColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contact_messages'
  AND COLUMN_NAME = 'subject'
LIMIT 1
");
if ($messagesSubjectColumnResult && $messagesSubjectColumnResult->num_rows === 1) {
    $messagesHasSubject = true;
}
if (!$messagesHasSubject) {
    $conn->query("ALTER TABLE contact_messages ADD COLUMN subject VARCHAR(200) NOT NULL DEFAULT '' AFTER servicio");
}

$messagesHasClientUnseenReply = false;
$messagesClientUnseenReplyColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contact_messages'
  AND COLUMN_NAME = 'client_has_unseen_reply'
LIMIT 1
");
if ($messagesClientUnseenReplyColumnResult && $messagesClientUnseenReplyColumnResult->num_rows === 1) {
    $messagesHasClientUnseenReply = true;
}
if (!$messagesHasClientUnseenReply) {
    $conn->query("ALTER TABLE contact_messages ADD COLUMN client_has_unseen_reply TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
}

$clientsHasEmailNotifyOutbound = false;
$clientsEmailNotifyColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'clients'
  AND COLUMN_NAME = 'email_notify_outbound'
LIMIT 1
");
if ($clientsEmailNotifyColumnResult && $clientsEmailNotifyColumnResult->num_rows === 1) {
    $clientsHasEmailNotifyOutbound = true;
}
if (!$clientsHasEmailNotifyOutbound) {
    $conn->query("ALTER TABLE clients ADD COLUMN email_notify_outbound TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
}

$conn->query("
CREATE TABLE IF NOT EXISTS client_registration_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(180) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(180) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_crt_token (token_hash),
  INDEX idx_crt_email (email),
  INDEX idx_crt_expires (expires_at)
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS contact_message_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_message_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_message_replies_msg (contact_message_id)
)");

$conn->query("
CREATE TABLE IF NOT EXISTS contact_whatsapp_clicks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(180) NOT NULL DEFAULT '',
  email VARCHAR(180) NOT NULL DEFAULT '',
  servicio VARCHAR(180) NOT NULL DEFAULT '',
  mensaje TEXT NOT NULL,
  composed_text TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_whatsapp_clicks_created (created_at)
)");

$waClicksHasIsRead = false;
$waClicksIsReadColumnResult = $conn->query("
SELECT 1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'contact_whatsapp_clicks'
  AND COLUMN_NAME = 'is_read'
LIMIT 1
");
if ($waClicksIsReadColumnResult && $waClicksIsReadColumnResult->num_rows === 1) {
    $waClicksHasIsRead = true;
}
if (!$waClicksHasIsRead) {
    $conn->query("ALTER TABLE contact_whatsapp_clicks ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER composed_text");
}

$conn->query("
CREATE TABLE IF NOT EXISTS service_gallery (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_id INT NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  caption VARCHAR(180) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 999,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_service_gallery_service (service_id),
  CONSTRAINT fk_service_gallery_service
    FOREIGN KEY (service_id) REFERENCES services(id)
    ON DELETE CASCADE
)");

$sgHasImageTitle = false;
$sgTitleCol = $conn->query("
SELECT 1 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_gallery' AND COLUMN_NAME = 'image_title' LIMIT 1
");
if ($sgTitleCol && $sgTitleCol->num_rows === 1) {
    $sgHasImageTitle = true;
}
if (!$sgHasImageTitle) {
    $conn->query("ALTER TABLE service_gallery ADD COLUMN image_title VARCHAR(220) DEFAULT NULL AFTER caption");
}
$sgHasImageDesc = false;
$sgDescCol = $conn->query("
SELECT 1 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_gallery' AND COLUMN_NAME = 'image_description' LIMIT 1
");
if ($sgDescCol && $sgDescCol->num_rows === 1) {
    $sgHasImageDesc = true;
}
if (!$sgHasImageDesc) {
    $conn->query("ALTER TABLE service_gallery ADD COLUMN image_description TEXT NULL AFTER image_title");
}
$conn->query("UPDATE service_gallery SET image_title = caption WHERE (image_title IS NULL OR TRIM(image_title) = '') AND caption IS NOT NULL AND TRIM(caption) != ''");

$conn->query("
CREATE TABLE IF NOT EXISTS experts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(180) NOT NULL,
  email VARCHAR(180) DEFAULT NULL,
  phone VARCHAR(48) DEFAULT NULL,
  notes TEXT NULL,
  sort_order INT NOT NULL DEFAULT 999,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_experts_active_sort (is_active, sort_order, id)
)");

$exHasEmail = false;
$exEmailCol = $conn->query("
SELECT 1 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'experts' AND COLUMN_NAME = 'email' LIMIT 1
");
if ($exEmailCol && $exEmailCol->num_rows === 1) {
    $exHasEmail = true;
}
if (!$exHasEmail) {
    $conn->query("ALTER TABLE experts ADD COLUMN email VARCHAR(180) DEFAULT NULL AFTER display_name");
}
$exHasPhone = false;
$exPhoneCol = $conn->query("
SELECT 1 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'experts' AND COLUMN_NAME = 'phone' LIMIT 1
");
if ($exPhoneCol && $exPhoneCol->num_rows === 1) {
    $exHasPhone = true;
}
if (!$exHasPhone) {
    $conn->query("ALTER TABLE experts ADD COLUMN phone VARCHAR(48) DEFAULT NULL AFTER email");
}
$exHasNotes = false;
$exNotesCol = $conn->query("
SELECT 1 FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'experts' AND COLUMN_NAME = 'notes' LIMIT 1
");
if ($exNotesCol && $exNotesCol->num_rows === 1) {
    $exHasNotes = true;
}
if (!$exHasNotes) {
    $conn->query("ALTER TABLE experts ADD COLUMN notes TEXT NULL AFTER phone");
}

$conn->query("
CREATE TABLE IF NOT EXISTS expert_services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expert_id INT NOT NULL,
  service_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_expert_service (expert_id, service_id),
  INDEX idx_expert_services_expert (expert_id),
  INDEX idx_expert_services_service (service_id),
  CONSTRAINT fk_expert_services_expert
    FOREIGN KEY (expert_id) REFERENCES experts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_expert_services_service
    FOREIGN KEY (service_id) REFERENCES services(id)
    ON DELETE CASCADE
)");

$conn->query("
CREATE TABLE IF NOT EXISTS expert_availability (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expert_id INT NOT NULL,
  weekday TINYINT NOT NULL COMMENT '0=Dom..6=Sab (PHP date w)',
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expert_availability_expert (expert_id),
  INDEX idx_expert_availability_lookup (expert_id, weekday, start_time),
  CONSTRAINT fk_expert_availability_expert
    FOREIGN KEY (expert_id) REFERENCES experts(id)
    ON DELETE CASCADE
)");

$conn->query("
INSERT INTO expert_availability (expert_id, weekday, start_time, end_time)
SELECT e.id, d.w, '09:00:00', '18:00:00'
FROM experts e
INNER JOIN (
  SELECT 1 AS w UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
) AS d
WHERE NOT EXISTS (SELECT 1 FROM expert_availability ea WHERE ea.expert_id = e.id LIMIT 1)
");

$conn->query("
CREATE TABLE IF NOT EXISTS expert_availability_date (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expert_id INT NOT NULL,
  calendar_date DATE NOT NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  start_time TIME NULL,
  end_time TIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expert_av_date_lookup (expert_id, calendar_date),
  CONSTRAINT fk_expert_av_date_expert
    FOREIGN KEY (expert_id) REFERENCES experts(id)
    ON DELETE CASCADE
)");

$conn->query("
CREATE TABLE IF NOT EXISTS expert_appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expert_id INT NOT NULL,
  service_id INT NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  guest_name VARCHAR(180) NOT NULL,
  guest_email VARCHAR(180) NOT NULL,
  guest_phone VARCHAR(48) NOT NULL DEFAULT '',
  notes TEXT NULL,
  client_id INT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'confirmed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_expert_appt_start (expert_id, starts_at),
  INDEX idx_expert_appt_window (expert_id, status, starts_at, ends_at),
  CONSTRAINT fk_expert_appt_expert
    FOREIGN KEY (expert_id) REFERENCES experts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_expert_appt_service
    FOREIGN KEY (service_id) REFERENCES services(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_expert_appt_client
    FOREIGN KEY (client_id) REFERENCES clients(id)
    ON DELETE SET NULL
)");

$conn->query("
INSERT IGNORE INTO site_settings (
  id, person_name, brand_name, hero_title, hero_intro, about_text, contact_intro, contact_email, footer_text
) VALUES (
  1,
  'Tu Nombre',
  'Tu Marca',
  'Describe aquí tu propuesta principal de valor.',
  'Agrega una breve introducción para tu portada.',
  'Escribe una descripción corta sobre ti y tus servicios.',
  'Invita a tus visitantes a contactarte para más información.',
  'contacto@tu-dominio.com',
  'Todos los derechos reservados.'
)
");

$servicesCountResult = $conn->query("SELECT COUNT(*) AS total FROM services");
$servicesTotal = 0;
if ($servicesCountResult) {
    $servicesRow = $servicesCountResult->fetch_assoc();
    $servicesTotal = (int)($servicesRow["total"] ?? 0);
}

if ($servicesTotal === 0) {
    $conn->query("
    INSERT INTO services (title, description, icon_class, image_path, sort_order, is_active)
    VALUES
      ('Servicio 1', 'Describe aquí el primer servicio que ofreces.', 'fa-solid fa-book-open-reader', NULL, 1, 1),
      ('Servicio 2', 'Describe aquí el segundo servicio con su beneficio principal.', 'fa-solid fa-code', NULL, 2, 1),
      ('Servicio 3', 'Describe aquí el tercer servicio de forma breve y clara.', 'fa-solid fa-guitar', NULL, 3, 1)
    ");
}
