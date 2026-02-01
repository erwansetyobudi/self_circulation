<?php
/**
 * Plugin Name: Self Circulation (Kiosk)
 * Plugin URI: https://github.com/erwansetyobudi/self_circulation
 * Description: Plugin Sirkulasi Mandiri SLiMS via OPAC
 * Version: 1.0.0
 * Author: Erwan Setyo Budi (erwans818@gmail.com)
 * Author URI : https://github.com/erwansetyobudi/
 */

use SLiMS\Plugins;

$plugins = Plugins::getInstance();

// register path OPAC: index.php?p=self_circulation
$plugins->registerMenu('opac', 'self_circulation', __DIR__ . '/pages/kiosk.inc.php');



/* ---------- helpers ---------- */
if (!function_exists('sc_json')) {
  function sc_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
  }
}

if (!function_exists('sc_get_setting')) {
  function sc_get_setting($db, string $name, $default = null) {
    $st = $db->prepare("SELECT setting_value FROM setting WHERE setting_name = ? LIMIT 1");
    $st->execute([$name]);
    $val = $st->fetchColumn();
    if ($val === false || $val === null) return $default;

    $un = @unserialize($val);
    return ($un === false && $val !== 'b:0;') ? $val : $un;
  }
}
