<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 01/02/2026 10:03
 * @File name           : kiosk_layout.inc.php
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
  die('can not access this file directly');
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($page_title ?? 'Self Circulation', ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- CSS bawaan SLiMS -->
  <link rel="stylesheet" href="<?= SWB ?>template/default/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= SWB ?>template/default/assets/plugin/font-awesome/css/fontawesome-all.min.css">

  <style>
    html, body { height:100%; }
    body { margin:0; padding:0; background:#f6f7fb; }

    /* print helper */
    @media print {
      .no-print { display:none !important; }
      body { background:#fff !important; }
    }
  </style>
</head>

<body>
  <?= $main_content ?>

  <!-- JS bawaan SLiMS -->
  <script src="<?= SWB ?>template/default/assets/js/jquery.min.js"></script>
  <script src="<?= SWB ?>template/default/assets/js/bootstrap.bundle.min.js"></script>

  <script>
  // Disable right-click (kiosk)
  document.addEventListener('contextmenu', function(e){ e.preventDefault(); }, {capture:true});

  // Block some shortcuts (opsional, bisa Anda tambah)
  document.addEventListener('keydown', function(e){
    // F12, Ctrl+Shift+I/J/C, Ctrl+U, Ctrl+S, Ctrl+P (kita tetap izinkan print via tombol)
    const key = (e.key || '').toLowerCase();
    if (e.key === 'F12') { e.preventDefault(); return; }

    if (e.ctrlKey && (key === 'u' || key === 's')) { e.preventDefault(); return; }
    if (e.ctrlKey && e.shiftKey && (key === 'i' || key === 'j' || key === 'c')) { e.preventDefault(); return; }

    // Jika Anda mau blok Ctrl+P juga (recommended kiosk):
    if (e.ctrlKey && key === 'p') { e.preventDefault(); return; }
  }, {capture:true});
  </script>
</body>
</html>
