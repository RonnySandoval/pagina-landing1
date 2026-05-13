<?php
declare(strict_types=1);

require_once __DIR__ . "/client_portal_lib.php";
require_once __DIR__ . "/app_urls.php";
client_session_start();
client_session_destroy();

header("Location: " . app_public_base_url() . "/index.php");
exit;
