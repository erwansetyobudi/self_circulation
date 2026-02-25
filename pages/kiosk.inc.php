<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 19/02/2026
 * @File name           : kiosk.inc.php
 * @Description         : Main router untuk self circulation
 */

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
    die('can not access this file directly');
}

use SLiMS\DB;

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = DB::getInstance();

/* ---------- helpers ---------- */
if (!function_exists('sc_h')) {
    function sc_h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Deteksi halaman yang diminta
$page = $_GET['page'] ?? 'login';

// Untuk API, langsung include file API dan exit
if ($page === 'api') {
    require __DIR__ . '/api.inc.php';
    exit;
}

// Route ke halaman dengan layout
switch ($page) {
    case 'login':
        require __DIR__ . '/login.inc.php';
        break;
    case 'dashboard':
        require __DIR__ . '/dashboard.inc.php';
        break;
    default:
        require __DIR__ . '/login.inc.php';
        break;
}
