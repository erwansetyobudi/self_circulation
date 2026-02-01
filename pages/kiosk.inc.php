<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 01/02/2026 10:03
 * @File name           : kiosk.inc.php
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

// plugins/self_circulation/pages/kiosk.inc.php
if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
  die('can not access this file directly');
}

use SLiMS\DB;

$db = DB::getInstance();

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

if (!function_exists('sc_h')) {
  function sc_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

/**
 * =========================
 *  AJAX ENDPOINT (POST)
 * =========================
 * action = borrow | return
 * member_id, pin, item_code
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  $action    = trim((string)($_POST['action'] ?? ''));
  $member_id = trim((string)($_POST['member_id'] ?? ''));
  $pin       = trim((string)($_POST['pin'] ?? ''));
  $item_code = trim((string)($_POST['item_code'] ?? ''));

  if ($action === '' || $member_id === '' || $pin === '' || $item_code === '') {
    sc_json(['ok' => false, 'msg' => 'Data belum lengkap.'], 422);
  }

  // Optional enable/disable via setting
  $enabled = sc_get_setting($db, 'enable_self_circulation', true);
  if (!$enabled) {
    sc_json(['ok' => false, 'msg' => 'Self circulation sedang dinonaktifkan.'], 403);
  }

  $today = date('Y-m-d');

  // 1) Validasi member + PIN
  $st = $db->prepare("SELECT member_id, member_name, expire_date, is_pending, pin, mpasswd, member_type_id
                      FROM member WHERE member_id = ? LIMIT 1");
  $st->execute([$member_id]);
  $m = $st->fetch(\PDO::FETCH_ASSOC);
  if (!$m) sc_json(['ok' => false, 'msg' => 'Member ID tidak ditemukan.'], 404);

  if ((int)$m['is_pending'] === 1) sc_json(['ok' => false, 'msg' => 'Keanggotaan masih pending.'], 403);

  if (!empty($m['expire_date']) && $m['expire_date'] < $today) {
    sc_json(['ok' => false, 'msg' => 'Keanggotaan sudah kedaluwarsa.'], 403);
  }

  // PIN check: prefer mpasswd hash
  $pin_ok = false;
  if (!empty($m['mpasswd'])) {
    $pin_ok = password_verify($pin, $m['mpasswd']);
  } else {
    $pin_ok = hash_equals((string)$m['pin'], (string)$pin);
  }
  if (!$pin_ok) sc_json(['ok' => false, 'msg' => 'PIN salah.'], 403);

  // 2) Validasi item + cek no_loan dari mst_item_status
  $st = $db->prepare("
    SELECT
      i.item_code, i.biblio_id, i.item_status_id, i.coll_type_id,
      b.title, b.gmd_id, b.classification,
      s.item_status_name, COALESCE(s.no_loan,0) AS no_loan
    FROM item i
    LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
    LEFT JOIN mst_item_status s ON s.item_status_id = i.item_status_id
    WHERE i.item_code = ? LIMIT 1
  ");
  $st->execute([$item_code]);
  $it = $st->fetch(\PDO::FETCH_ASSOC);
  if (!$it) sc_json(['ok' => false, 'msg' => 'Barcode/Item code tidak ditemukan.'], 404);

  if ((int)($it['no_loan'] ?? 0) === 1) {
    $name = $it['item_status_name'] ?: 'No Loan';
    sc_json(['ok' => false, 'msg' => "Item tidak dapat dipinjam (status: {$name})."], 403);
  }

  // cek loan terakhir untuk item
  $st = $db->prepare("SELECT loan_id, due_date, is_return, is_lent
                      FROM loan
                      WHERE item_code = ?
                      ORDER BY loan_id DESC
                      LIMIT 1");
  $st->execute([$item_code]);
  $lastLoan = $st->fetch(\PDO::FETCH_ASSOC);

  // helper: ambil loan_rules  dari mst_loan_rules
  // prioritas: (member_type_id + coll_type_id + gmd_id) -> (member_type_id + coll_type_id) -> (member_type_id)
  $memberType = (int)($m['member_type_id'] ?? 0);
  $collType   = (int)($it['coll_type_id'] ?? 0);
  $gmdId      = (int)($it['gmd_id'] ?? 0);

  $rules = null;

  // 1) exact
  $st = $db->prepare("SELECT * FROM mst_loan_rules
                      WHERE member_type_id=? AND coll_type_id=? AND gmd_id=?
                      LIMIT 1");
  $st->execute([$memberType, $collType, $gmdId]);
  $rules = $st->fetch(\PDO::FETCH_ASSOC);

  // 2) member+coll only
  if (!$rules) {
    $st = $db->prepare("SELECT * FROM mst_loan_rules
                        WHERE member_type_id=? AND coll_type_id=? AND (gmd_id=0 OR gmd_id IS NULL)
                        LIMIT 1");
    $st->execute([$memberType, $collType]);
    $rules = $st->fetch(\PDO::FETCH_ASSOC);
  }

  // 3) member only (coll_type 0, gmd 0)
  if (!$rules) {
    $st = $db->prepare("SELECT * FROM mst_loan_rules
                        WHERE member_type_id=? AND (coll_type_id=0 OR coll_type_id IS NULL) AND (gmd_id=0 OR gmd_id IS NULL)
                        LIMIT 1");
    $st->execute([$memberType]);
    $rules = $st->fetch(\PDO::FETCH_ASSOC);
  }

  // fallback default
  $loan_rules_id = (int)($rules['loan_rules_id'] ?? 0);
  $loan_limit    = (int)($rules['loan_limit'] ?? 2);
  $loan_periode  = (int)($rules['loan_periode'] ?? 7);

  if ($loan_limit <= 0) $loan_limit = 2;
  if ($loan_periode <= 0) $loan_periode = 7;

  /**
   * 3) BORROW
   */
  if ($action === 'borrow') {

    if ($lastLoan && (int)$lastLoan['is_lent'] === 1 && (int)$lastLoan['is_return'] === 0) {
      sc_json(['ok' => false, 'msg' => 'Item sedang dipinjam, tidak bisa dipinjam lagi.'], 409);
    }

    // cek limit pinjam aktif member
    $st = $db->prepare("SELECT COUNT(*)
                        FROM loan
                        WHERE member_id = ? AND is_lent = 1 AND is_return = 0");
    $st->execute([$member_id]);
    $activeLoans = (int)$st->fetchColumn();
    if ($activeLoans >= $loan_limit) {
      sc_json(['ok' => false, 'msg' => 'Limit pinjam tercapai.'], 403);
    }

    $loan_date = $today;
    $due_date  = date('Y-m-d', strtotime($today . " +{$loan_periode} day"));

    $st = $db->prepare("INSERT INTO loan
        (item_code, member_id, loan_date, due_date, renewed, loan_rules_id, actual, is_lent, is_return, return_date, input_date, last_update, uid)
        VALUES
        (?, ?, ?, ?, 0, ?, NULL, 1, 0, NULL, NOW(), NOW(), NULL)");
    $ok = $st->execute([$item_code, $member_id, $loan_date, $due_date, $loan_rules_id]);
    if (!$ok) sc_json(['ok' => false, 'msg' => 'Gagal membuat transaksi pinjam.'], 500);

    // log
    $st = $db->prepare("INSERT INTO system_log (log_type, id, log_location, sub_module, action, log_msg, log_date)
                        VALUES ('system', ?, 'opac', 'self_circulation', 'borrow', ?, NOW())");
    $st->execute([$member_id, "Borrow item={$item_code}; title=" . ($it['title'] ?? '-')]);

    sc_json([
      'ok' => true,
      'msg' => 'Berhasil PINJAM.',
      'data' => [
        'action' => 'borrow',
        'member_id' => $member_id,
        'member' => $m['member_name'],
        'item_code' => $item_code,
        'title' => $it['title'] ?? '',
        'loan_date' => $loan_date,
        'due_date' => $due_date,
        'loan_rules_id' => $loan_rules_id
      ]
    ]);
  }

  /**
   * 4) RETURN
   */
  if ($action === 'return') {

    // cari loan aktif untuk item
    $st = $db->prepare("SELECT loan_id, member_id, due_date, loan_date
                        FROM loan
                        WHERE item_code = ? AND is_lent = 1 AND is_return = 0
                        ORDER BY loan_id DESC
                        LIMIT 1");
    $st->execute([$item_code]);
    $loan = $st->fetch(\PDO::FETCH_ASSOC);

    if (!$loan) {
      sc_json(['ok' => false, 'msg' => 'Tidak ada transaksi pinjam aktif untuk item ini.'], 404);
    }

    if ((string)$loan['member_id'] !== (string)$member_id) {
      sc_json(['ok' => false, 'msg' => 'Item ini dipinjam oleh member lain.'], 403);
    }

    $st = $db->prepare("UPDATE loan
                        SET is_return=1, is_lent=0, return_date=?, last_update=NOW()
                        WHERE loan_id=? LIMIT 1");
    $ok = $st->execute([$today, $loan['loan_id']]);
    if (!$ok) sc_json(['ok' => false, 'msg' => 'Gagal memproses pengembalian.'], 500);

    $st = $db->prepare("INSERT INTO system_log (log_type, id, log_location, sub_module, action, log_msg, log_date)
                        VALUES ('system', ?, 'opac', 'self_circulation', 'return', ?, NOW())");
    $st->execute([$member_id, "Return item={$item_code}; loan_id={$loan['loan_id']}"]);

    sc_json([
      'ok' => true,
      'msg' => 'Berhasil KEMBALI.',
      'data' => [
        'action' => 'return',
        'member_id' => $member_id,
        'member' => $m['member_name'],
        'item_code' => $item_code,
        'title' => $it['title'] ?? '',
        'loan_date' => $loan['loan_date'] ?? '',
        'due_date' => $loan['due_date'] ?? '',
        'return_date' => $today,
        'loan_rules_id' => $loan_rules_id
      ]
    ]);
  }

  sc_json(['ok' => false, 'msg' => 'Action tidak dikenal.'], 422);
}


/**
 * =========================
 * UI KIOSK (GET)
 * =========================
 */
ob_start();

$libraryName = $sysconf['library_name'] ?? 'Perpustakaan';
$libraryAddr = $sysconf['library_address'] ?? '';
$librarySub  = $sysconf['library_subname'] ?? '';
$showSub     = !empty($sysconf['template']['classic_library_subname']) && !empty($librarySub);

// logo resolver
$logoHtml = '';
$logoPathSetting = $sysconf['logo_image'] ?? '';

$rootDir = dirname(__DIR__, 3); // .../plugins/self_circulation/pages -> ROOT
$imgDefaultDisk = $rootDir . '/images/default/';

if (!empty($logoPathSetting) && file_exists($imgDefaultDisk . $logoPathSetting)) {
  // pakai thumb generator (lebih ringan untuk layar kiosk)
  $logoHtml = '<img class="sc-logo-img" alt="logo" src="'
    . SWB . 'lib/minigalnano/createthumb.php?filename=images/default/' . rawurlencode($logoPathSetting) . '&width=220">';
} elseif (file_exists(__DIR__ . '/../assets/images/logo.png')) {
  // logo plugin (optional)
  $logoHtml = '<img class="sc-logo-img" alt="logo" src="' . SWB . 'plugins/self_circulation/assets/images/logo.png">';
} else {
  // fallback logo template
  $logoHtml = '<img class="sc-logo-img" alt="logo" src="' . SWB . 'template/default/assets/images/logo.png">';
}

?>
<style>
  .sc-wrap{max-width:860px;margin:36px auto;background:#fff;border-radius:16px;padding:26px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
  .sc-title{margin:0 0 6px;font-weight:800}
  .sc-sub{color:#666;font-size:14px;margin:0 0 18px}
  .sc-row{display:flex;gap:12px;flex-wrap:wrap}
  .sc-col{flex:1 1 240px}
  label{font-size:12px;color:#444;margin:10px 0 6px;display:block}
  input{width:100%;padding:12px;border:1px solid #ddd;border-radius:10px;font-size:16px}
  .sc-btns{display:flex;gap:12px;margin-top:14px}
  .sc-btns button{flex:1;padding:14px;border:0;border-radius:12px;font-size:16px;cursor:pointer}
  .btn-borrow{background:#0d6efd;color:#fff}
  .btn-return{background:#198754;color:#fff}
  .sc-result{margin-top:16px;padding:14px;border-radius:12px;background:#f3f5ff;border:1px solid #dfe6ff;display:none}
  .sc-result.ok{background:#eaffea;border-color:#bfe8bf}
  .sc-result.err{background:#ffecec;border-color:#ffd0d0}
  .sc-kv div{margin:4px 0;font-size:14px}
  .sc-actions{display:flex;gap:10px;margin-top:12px}
  .sc-actions button{padding:10px 12px;border-radius:10px;border:1px solid #ddd;background:#fff;cursor:pointer}
  .sc-actions .primary{background:#111;color:#fff;border-color:#111}
  .sc-hint{font-size:12px;color:#666;margin-top:12px}

  /* Receipt (hidden in main page) */
  #scReceipt { display:none; }

  /* Thermal print width helpers */
  .receipt { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:12px; }
  .receipt h2 { font-size:14px; margin:0 0 6px; text-align:center; }
  .receipt .muted { opacity:.85; text-align:center; margin:0 0 8px; }
  .receipt .line { border-top:1px dashed #000; margin:8px 0; }
  .receipt .row { display:flex; justify-content:space-between; gap:12px; }
  .receipt .small { font-size:11px; opacity:.9; }
    .sc-pin-wrap{position:relative}
  .sc-pin-wrap input{padding-right:44px}
  .sc-pin-toggle{
    position:absolute; right:8px; top:50%; transform:translateY(-50%);
    width:36px; height:36px; border-radius:10px;
    border:1px solid #ddd; background:#fff; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
  }
  .sc-pin-toggle:active{transform:translateY(-50%) scale(.98)}
  .sc-brand{display:flex; align-items:center; gap:14px; margin-bottom:14px}
  .sc-logo-img{width:58px; height:58px; object-fit:contain; border-radius:10px; background:#fff}
  .sc-brand-text{line-height:1.15}
  .sc-brand-name{font-weight:800; font-size:18px; margin:0}
  .sc-brand-sub{font-size:13px; color:#666; margin-top:3px}


</style>

<div class="sc-wrap">
<div class="d-flex align-items-start justify-content-between gap-2 no-print">
  <div>

    <!-- BRANDING -->
    <div class="sc-brand">
      <?= $logoHtml ?>
      <div class="sc-brand-text">
        <div class="sc-brand-name"><?= sc_h($libraryName) ?></div>
        <?php if ($showSub): ?>
          <div class="sc-brand-sub"><?= sc_h($librarySub) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <h1 class="sc-title">Self Circulation</h1>
      <p class="sc-sub">Masukkan <b>Member ID</b>, <b>Password</b>, lalu scan <b>Barcode Item</b>. Pilih PINJAM atau KEMBALI.</p>
    </div>

    <!-- tombol fullscreen: wajib user gesture -->
    <div class="text-end">
      <button class="btn btn-sm btn-dark" id="scEnterFullscreen">
        <i class="fas fa-expand"></i> Mode Kiosk
      </button>
      <div class="sc-hint" style="margin-top:6px;">(klik sekali untuk fullscreen)</div>
    </div>
  </div>

  <div class="sc-row">
    <div class="sc-col">
      <label>Member ID</label>
      <input id="member_id" autocomplete="off" placeholder="contoh: 2200123">
    </div>
    <div class="sc-col">
      <label>PIN</label>

      <div class="sc-pin-wrap">
        <input id="pin" type="password" autocomplete="off" placeholder="••••">
        <button type="button" class="sc-pin-toggle" id="scTogglePin" aria-label="Lihat PIN" title="Lihat PIN">
          <i class="fas fa-eye" id="scTogglePinIcon"></i>
        </button>
      </div>
    </div>

  </div>

  <label>Barcode / Item Code</label>
  <input id="item_code" autocomplete="off" placeholder="scan barcode di sini">

  <div class="sc-btns no-print">
    <button class="btn-borrow" onclick="doAction('borrow')"><i class="fas fa-arrow-circle-up"></i> PINJAM</button>
    <button class="btn-return" onclick="doAction('return')"><i class="fas fa-arrow-circle-down"></i> KEMBALI</button>
  </div>

  <div id="result" class="sc-result">
    <div id="resultMsg"></div>

    <div class="sc-actions no-print" id="resultActions" style="display:none;">
      <button class="primary" onclick="printReceipt('thermal')"><i class="fas fa-print"></i> Cetak Thermal</button>
      <button onclick="printReceipt('a4')"><i class="fas fa-print"></i> Cetak A4</button>
      <button onclick="resetScreen(true)"><i class="fas fa-redo"></i> Reset</button>
    </div>
    <div class="sc-hint no-print" id="timeoutHint" style="display:none;"></div>
  </div>
</div>

<!-- Template receipt (diisi via JS) -->
<div id="scReceipt">
  <div class="receipt" id="scReceiptInner"></div>
</div>

<script>
  // ========= CONFIG =========
  const RESET_SECONDS_SUCCESS = 30;  // timeout reset setelah sukses
  const RESET_SECONDS_ERROR   = 15;  // timeout reset setelah gagal
  const CLEAR_MEMBER_ON_SUCCESS = false; // kalau mau auto-clear member id: true

  // ========= Elements =========
  const elMember = document.getElementById('member_id');
  const elPin    = document.getElementById('pin');
  const elItem   = document.getElementById('item_code');

  const elRes       = document.getElementById('result');
  const elResMsg    = document.getElementById('resultMsg');
  const elActions   = document.getElementById('resultActions');
  const elTimeout   = document.getElementById('timeoutHint');

  const elReceiptInner = document.getElementById('scReceiptInner');

  let lastReceiptData = null;
  let resetTimer = null;

function isTypingOnCredentialField(){
  const a = document.activeElement;
  return a === elMember || a === elPin;
}

function focusBarcode(force=false) {
  // kalau user sedang aktif di member/pin, jangan ganggu
  if (!force && isTypingOnCredentialField()) return;

  setTimeout(() => {
    elItem.focus();
    elItem.select?.();
  }, 80);
}

// Auto-focus hanya saat load 
focusBarcode(false);


elMember.addEventListener('focus', () => clearTimeout(resetTimer));
elPin.addEventListener('focus', () => clearTimeout(resetTimer));


elPin.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') focusBarcode(true);
});


elMember.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') elPin.focus();
});


  // elItem.addEventListener('keydown', (e) => { if(e.key==='Enter') doAction('borrow'); });

  // ========= Fullscreen =========
  document.getElementById('scEnterFullscreen').addEventListener('click', async () => {
    const root = document.documentElement;
    try {
      if (!document.fullscreenElement) {
        await root.requestFullscreen();
      } else {
        await document.exitFullscreen();
      }
    } catch (e) {
      // ignore (browser policy)
      alert('Browser menolak fullscreen. Coba klik lagi / cek izin browser.');
    }
    focusBarcode();
  });

  // ========= Result UI helpers =========
  function showBox(ok, html){
    elRes.style.display = 'block';
    elRes.className = 'sc-result ' + (ok ? 'ok' : 'err');
    elResMsg.innerHTML = html;
    elActions.style.display = 'none';
    elTimeout.style.display = 'none';
  }

  function scheduleReset(seconds){
    clearTimeout(resetTimer);
    if (!seconds || seconds <= 0) return;

    let remain = seconds;
    elTimeout.style.display = 'block';
    elTimeout.textContent = `Layar akan reset otomatis dalam ${remain} detik...`;

    const tick = () => {
      remain--;
      if (remain <= 0) return;
      elTimeout.textContent = `Layar akan reset otomatis dalam ${remain} detik...`;
      resetTimer = setTimeout(tick, 1000);
    };
    resetTimer = setTimeout(tick, 1000);

    // hard reset at end
    setTimeout(() => resetScreen(true), seconds * 1000);
  }

  function resetScreen(hard=false){
    clearTimeout(resetTimer);
    elRes.style.display = 'none';
    elResMsg.innerHTML = '';
    elActions.style.display = 'none';
    elTimeout.style.display = 'none';

    // clear fields
    elItem.value = '';
    elPin.value = '';
    if (hard && CLEAR_MEMBER_ON_SUCCESS) elMember.value = '';
    focusBarcode();
  }

  // ========= Receipt builder =========
  function buildReceiptHTML(d){
    
    const now = new Date();
    const ts = now.toLocaleString('id-ID');

    const actLabel = (d.action === 'borrow') ? 'PEMINJAMAN' : 'PENGEMBALIAN';

    let lines = '';
    lines += `<h2><?= sc_h($libraryName) ?></h2>`;

    if (`<?= sc_h($libraryAddr) ?>`) lines += `<div class="muted small"><?= sc_h($libraryAddr) ?>`;
    if (`<?= sc_h($librarySub) ?>`) lines += `<div class="muted small"><?= sc_h($librarySub) ?></div>`;

    lines += `<div class="muted small">${ts}</div>`;
    lines += `<div class="line"></div>`;

    lines += `<div class="row"><div>Transaksi</div><div><b>${actLabel}</b></div></div>`;
    lines += `<div class="row"><div>Member</div><div>${escapeHtml(d.member || '')}</div></div>`;
    lines += `<div class="row"><div>ID</div><div>${escapeHtml(d.member_id || '')}</div></div>`;
    lines += `<div class="line"></div>`;

    lines += `<div><b>${escapeHtml(d.title || '')}</b></div>`;
    lines += `<div class="row"><div>Item</div><div>${escapeHtml(d.item_code || '')}</div></div>`;

    if (d.loan_date) lines += `<div class="row"><div>Tgl Pinjam</div><div>${escapeHtml(d.loan_date)}</div></div>`;
    if (d.due_date)  lines += `<div class="row"><div>Jatuh Tempo</div><div><b>${escapeHtml(d.due_date)}</b></div></div>`;
    if (d.return_date) lines += `<div class="row"><div>Tgl Kembali</div><div><b>${escapeHtml(d.return_date)}</b></div></div>`;

    if (typeof d.loan_rules_id !== 'undefined') {
      lines += `<div class="row"><div>Loan Rules</div><div>${escapeHtml(String(d.loan_rules_id))}</div></div>`;
    }

    lines += `<div class="line"></div>`;
    lines += `<div class="muted small" style="text-align:center;">Terima kasih 🙏. Salam Literasi.</div>`;
    return lines;
  }

  function escapeHtml(str){
    return String(str)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

    // ========= Show/Hide PIN =========
  const btnTogglePin = document.getElementById('scTogglePin');
  const icTogglePin  = document.getElementById('scTogglePinIcon');

  function setPinVisible(visible){
    elPin.type = visible ? 'text' : 'password';
    if (icTogglePin){
      icTogglePin.className = visible ? 'fas fa-eye-slash' : 'fas fa-eye';
    }
    if (btnTogglePin){
      btnTogglePin.title = visible ? 'Sembunyikan PIN' : 'Lihat PIN';
      btnTogglePin.setAttribute('aria-label', btnTogglePin.title);
    }
  }

  // klik = toggle
  btnTogglePin && btnTogglePin.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation(); // supaya tidak kena handler click lain
    setPinVisible(elPin.type === 'password');
    elPin.focus();
  });

  // opsi UX: tekan & tahan untuk melihat, lepas untuk sembunyikan
  btnTogglePin && btnTogglePin.addEventListener('mousedown', (e) => {
    e.preventDefault(); e.stopPropagation();
    setPinVisible(true);
  });
  btnTogglePin && btnTogglePin.addEventListener('mouseup', (e) => {
    e.preventDefault(); e.stopPropagation();
    setPinVisible(false);
  });
  btnTogglePin && btnTogglePin.addEventListener('mouseleave', () => {
    setPinVisible(false);
  });
  btnTogglePin && btnTogglePin.addEventListener('touchstart', (e) => {
    e.preventDefault(); e.stopPropagation();
    setPinVisible(true);
  }, {passive:false});
  btnTogglePin && btnTogglePin.addEventListener('touchend', (e) => {
    e.preventDefault(); e.stopPropagation();
    setPinVisible(false);
  }, {passive:false});


  // ========= Print =========
  function printReceipt(mode){
    if (!lastReceiptData) return;

    const receiptHtml = buildReceiptHTML(lastReceiptData);

    // buat window print terpisah agar thermal size tidak ganggu halaman utama
    const w = window.open('', '_blank', 'width=420,height=600');
    if (!w) { alert('Popup diblokir browser. Izinkan pop-up untuk print.'); return; }

    const isThermal = (mode === 'thermal');

    w.document.open();
    w.document.write(`
      <!doctype html>
      <html lang="id">
      <head>
        <meta charset="utf-8"/>
        <title>Struk - Self Circulation</title>
        <style>
          body{ margin:0; padding:10px; background:#fff; }
          .receipt{ font-family: ui-monospace, Menlo, Monaco, Consolas, "Courier New", monospace; font-size:12px; }
          .receipt h2{ font-size:14px; margin:0 0 6px; text-align:center; }
          .muted{ opacity:.85; text-align:center; margin:0 0 8px; }
          .line{ border-top:1px dashed #000; margin:8px 0; }
          .row{ display:flex; justify-content:space-between; gap:12px; }
          .small{ font-size:11px; opacity:.9; }

          @media print {
            /* thermal paper settings */
            ${isThermal ? `
              @page { size: 80mm auto; margin: 4mm; }
              body { width: 80mm; }
            ` : `
              @page { size: A4; margin: 12mm; }
            `}
          }
        </style>
      </head>
      <body>
        <div class="receipt">${receiptHtml}</div>
        <script>
          window.onload = function(){
            window.focus();
            window.print();
            setTimeout(()=>window.close(), 300);
          };
        <\/script>
      </body>
      </html>
    `);
    w.document.close();
  }

  // ========= Main action =========
  async function doAction(action){
    const member_id = elMember.value.trim();
    const pin       = elPin.value.trim();
    const item_code = elItem.value.trim();

    if(!member_id || !pin || !item_code){
      showBox(false, 'Lengkapi Member ID, PIN, dan Barcode.');
      scheduleReset(RESET_SECONDS_ERROR);
      return;
    }

    const fd = new FormData();
    fd.append('action', action);
    fd.append('member_id', member_id);
    fd.append('pin', pin);
    fd.append('item_code', item_code);

    try{
      const res = await fetch(window.location.href, { method:'POST', body: fd });
      const j = await res.json();

      if(!j.ok){
        showBox(false, `<b>GAGAL:</b> ${escapeHtml(j.msg || 'Error')}`);
        // reset barcode + pin biar cepat scan ulang
        elItem.value = '';
        elPin.value = '';
        focusBarcode();
        scheduleReset(RESET_SECONDS_ERROR);
        return;
      }

      const d = j.data || {};
      lastReceiptData = d;

      // UI sukses
      let html = `<b>SUKSES:</b> ${escapeHtml(j.msg)}<div class="sc-kv" style="margin-top:10px;">`;
      if(d.member) html += `<div><b>Member:</b> ${escapeHtml(d.member)}</div>`;
      if(d.item_code) html += `<div><b>Item:</b> ${escapeHtml(d.item_code)}</div>`;
      if(d.title) html += `<div><b>Judul:</b> ${escapeHtml(d.title)}</div>`;
      if(d.due_date) html += `<div><b>Jatuh Tempo:</b> ${escapeHtml(d.due_date)}</div>`;
      if(d.return_date) html += `<div><b>Tanggal Kembali:</b> ${escapeHtml(d.return_date)}</div>`;
      if(typeof d.loan_rules_id !== 'undefined') html += `<div><b>Loan Rules ID:</b> ${escapeHtml(String(d.loan_rules_id))}</div>`;
      html += `</div>`;

      showBox(true, html);

      // tampilkan tombol print
      elActions.style.display = 'flex';

      // auto-clear PIN setelah sukses
      elPin.value = '';
      // reset barcode untuk transaksi berikutnya
      elItem.value = '';

      // opsional: clear member
      if (CLEAR_MEMBER_ON_SUCCESS) elMember.value = '';

      focusBarcode(true);

      // timeout reset layar
      scheduleReset(RESET_SECONDS_SUCCESS);

    }catch(e){
      showBox(false, 'Error koneksi/JSON. Coba ulang.');
      scheduleReset(RESET_SECONDS_ERROR);
    }
  }

document.addEventListener('click', (e) => {
  if (e.target.closest('#member_id') || e.target.closest('#pin') || e.target.closest('#scTogglePin')) return;
  focusBarcode();
}, {capture:true});


</script>

<?php
$main_content = ob_get_clean();
$page_title   = 'Self Circulation';

$main_template_path = __DIR__ . '/../templates/kiosk_layout.inc.php';
require $main_template_path;
exit;
