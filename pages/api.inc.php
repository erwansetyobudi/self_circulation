<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 19/02/2026
 * @File name           : api.inc.php
 * @Description         : API endpoint untuk self circulation
 */

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
    die('can not access this file directly');
}

// ==================== DETEKSI REQUEST GAMBAR ====================
// Cek apakah ini request untuk gambar dari minigalnano
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($request_uri, 'lib/minigalnano/') !== false) {
    // Biarkan request gambar berjalan normal
    return;
}

// ==================== CEK JIKA BUKAN REQUEST API ====================
// Jika tidak ada action, biarkan request berjalan normal
if (!isset($_POST['action']) && !isset($_GET['action'])) {
    return;
}

use SLiMS\DB;

// MATIKAN ERROR REPORTING untuk menghindari output warning
error_reporting(0);
ini_set('display_errors', 0);

// Bersihkan buffer output sebelumnya
while (ob_get_level()) {
    ob_end_clean();
}

// ==================== FUNGSI UNTUK MENJALANKAN HOOK TANPA OUTPUT ====================
/**
 * Menjalankan hook tanpa menghasilkan output apapun
 * Output dari plugin lain (toastr, dll) akan ditangkap dan dibuang
 * Tapi plugin tetap berjalan di background
 * 
 * @param string $hookName Nama hook yang akan dijalankan
 * @param array $payload Data yang akan dikirim ke hook
 * @return void
 */
// ==================== FUNGSI UNTUK MENJALANKAN HOOK TANPA OUTPUT ====================
/**
 * Menjalankan hook tanpa menghasilkan output apapun
 * Output dari plugin lain (toastr, dll) akan ditangkap dan dibuang
 * Tapi plugin tetap berjalan di background
 * 
 * @param string $hookName Nama hook yang akan dijalankan
 * @param array $payload Data yang akan dikirim ke hook
 * @return void
 */
function runHookSilently($hookName, $payload) {
    // Simpan semua level buffer yang ada
    $levels = [];
    while (ob_get_level()) {
        array_unshift($levels, ob_get_level());
        ob_end_clean();
    }
    
    // Mulai buffer baru dengan level 0
    ob_start();
    
    try {
        // Jalankan hook seperti biasa
        if (class_exists('\SLiMS\Plugins')) {
            $plugins = \SLiMS\Plugins::getInstance();
            $plugins->execute($hookName, [$payload]);
        }
    } catch (Exception $e) {
        error_log("Self Circulation: Hook silent error - " . $e->getMessage());
    }
    
    // Bersihkan SEMUA buffer sampai level 0
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Kembalikan buffer ke keadaan semula
    foreach ($levels as $level) {
        ob_start();
    }
}

// Mulai buffer untuk JSON response
ob_start();

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// Start session - hanya untuk API
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fungsi untuk mengirim JSON response
function sendJsonResponse($data) {
    // Bersihkan buffer sebelum output
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode($data);
    exit;
}

// ==================== HANDLE LOGOUT ====================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'logout') {
    error_log("Processing logout action");
    
    $db = DB::getInstance();
    
    if (isset($_SESSION['self_circ_member'])) {
        $member = $_SESSION['self_circ_member'];
        try {
            $logSt = $db->prepare("INSERT INTO system_log (log_type, id, log_location, sub_module, action, log_msg, log_date)
                                 VALUES ('member', ?, 'opac', 'self_circulation', 'logout', ?, NOW())");
            $logSt->execute([$member['member_id'], "Member logout from self circulation (auto timeout)"]);
        } catch (Exception $e) {
            error_log('Logout logging error: ' . $e->getMessage());
        }
    }
    
    unset($_SESSION['self_circ_member']);
    session_destroy();
    
    sendJsonResponse(['ok' => true, 'msg' => 'Logout berhasil']);
}

// ==================== CEK JIKA INI API DAN MEMERLUKAN SESSION ====================
// Untuk action selain logout, cek session
if ($action !== 'logout' && !isset($_SESSION['self_circ_member'])) {
    sendJsonResponse(['ok' => false, 'msg' => 'Session expired, silakan login ulang']);
}

// Jika sampai sini dan tidak ada session untuk action selain logout, exit
if ($action !== 'logout' && !isset($_SESSION['self_circ_member'])) {
    return;
}

// Inisialisasi database dan member data
$db = DB::getInstance();
$member = $_SESSION['self_circ_member'] ?? null;
$itemCode = $_POST['item_code'] ?? $_GET['item_code'] ?? '';

// Log untuk debugging
if ($member) {
    error_log("Self Circulation API Called - Action: $action, ItemCode: $itemCode, Member: {$member['member_id']}");
}

// Validasi input
if (empty($action)) {
    sendJsonResponse(['ok' => false, 'msg' => 'Action tidak boleh kosong']);
}

if (empty($itemCode) && $action !== 'check_member' && $action !== 'logout') {
    sendJsonResponse(['ok' => false, 'msg' => 'Item code tidak boleh kosong']);
}

$today = date('Y-m-d');

try {
    // Get member info jika ada session
    if ($member) {
        $st = $db->prepare("SELECT member_id, member_name, member_type_id FROM member WHERE member_id = ?");
        $st->execute([$member['member_id']]);
        $memberData = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$memberData) {
            sendJsonResponse(['ok' => false, 'msg' => 'Data member tidak ditemukan']);
        }
        
        $memberTypeId = $memberData['member_type_id'];
        
        // Get member's active loans count
        $st = $db->prepare("
            SELECT COUNT(*) as total 
            FROM loan 
            WHERE member_id = ? AND is_lent = 1 AND is_return = 0
        ");
        $st->execute([$member['member_id']]);
        $activeLoans = (int)$st->fetchColumn();
        
        // Get member's overdue count
        $st = $db->prepare("
            SELECT COUNT(*) as total 
            FROM loan 
            WHERE member_id = ? 
                AND is_lent = 1 
                AND is_return = 0 
                AND due_date < ?
        ");
        $st->execute([$member['member_id'], $today]);
        $overdueCount = (int)$st->fetchColumn();
    }
    
    // ==================== ACTION CHECK ====================
    if ($action === 'check') {
        error_log("Processing check action for item: $itemCode");
        
        $st = $db->prepare("
            SELECT 
                i.item_code,
                i.biblio_id,
                i.coll_type_id,
                b.gmd_id,
                b.title,
                b.image,
                b.publish_year,
                b.isbn_issn,
                s.no_loan,
                s.item_status_name,
                mct.coll_type_name
            FROM item i
            LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
            LEFT JOIN mst_item_status s ON s.item_status_id = i.item_status_id
            LEFT JOIN mst_coll_type mct ON mct.coll_type_id = i.coll_type_id
            WHERE i.item_code = ?
        ");
        $st->execute([$itemCode]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            sendJsonResponse(['ok' => false, 'msg' => 'Barcode tidak ditemukan di database']);
        }
        
        if ((int)$item['no_loan'] === 1) {
            sendJsonResponse(['ok' => false, 'msg' => 'Item tidak dapat dipinjam (status: ' . ($item['item_status_name'] ?? 'Tidak diketahui') . ')']);
        }
        
        $st = $db->prepare("
            SELECT loan_id, member_id 
            FROM loan 
            WHERE item_code = ? AND is_lent = 1 AND is_return = 0
        ");
        $st->execute([$itemCode]);
        $activeLoan = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($activeLoan) {
            if ($activeLoan['member_id'] == $member['member_id']) {
                sendJsonResponse(['ok' => false, 'msg' => 'Buku ini sedang Anda pinjam']);
            } else {
                sendJsonResponse(['ok' => false, 'msg' => 'Buku sedang dipinjam oleh member lain']);
            }
        }
        
        $st = $db->prepare("
            SELECT * FROM mst_loan_rules 
            WHERE member_type_id = ? 
                AND (coll_type_id = ? OR coll_type_id = 0)
                AND (gmd_id = ? OR gmd_id = 0)
            ORDER BY 
                CASE 
                    WHEN coll_type_id = ? AND gmd_id = ? THEN 1
                    WHEN coll_type_id = ? AND gmd_id = 0 THEN 2
                    WHEN coll_type_id = 0 AND gmd_id = ? THEN 3
                    WHEN coll_type_id = 0 AND gmd_id = 0 THEN 4
                    ELSE 5
                END
            LIMIT 1
        ");
        $st->execute([
            $memberTypeId, 
            $item['coll_type_id'] ?? 0, 
            $item['gmd_id'] ?? 0,
            $item['coll_type_id'] ?? 0, 
            $item['gmd_id'] ?? 0,
            $item['coll_type_id'] ?? 0,
            $item['gmd_id'] ?? 0
        ]);
        $rules = $st->fetch(PDO::FETCH_ASSOC);
        
        $loanLimit = (int)($rules['loan_limit'] ?? 3);
        
        $st = $db->prepare("
            SELECT COALESCE(SUM(
                DATEDIFF(?, due_date) * ?
            ), 0) as total_fine
            FROM loan 
            WHERE member_id = ? 
                AND is_lent = 1 
                AND is_return = 0 
                AND due_date < ?
        ");
        
        $finePerDay = (int)($rules['fine_each_day'] ?? 1000);
        $st->execute([$today, $finePerDay, $member['member_id'], $today]);
        $totalFine = (float)$st->fetchColumn();
        
        sendJsonResponse([
            'ok' => true,
            'msg' => 'Buku dapat dipinjam',
            'data' => [
                'item_code' => $item['item_code'],
                'title' => $item['title'] ?: 'Judul tidak tersedia',
                'image' => $item['image'],
                'publish_year' => $item['publish_year'] ?: '-',
                'isbn_issn' => $item['isbn_issn'] ?: '-',
                'coll_type_name' => $item['coll_type_name'] ?: 'Umum',
                'active_loans' => $activeLoans,
                'loan_limit' => $loanLimit,
                'total_fine' => $totalFine
            ]
        ]);
    }
    
    // ==================== ACTION CHECK RETURN ====================
    if ($action === 'check_return') {
        error_log("Processing check_return action for item: $itemCode");
        
        $st = $db->prepare("
            SELECT 
                i.item_code,
                b.title,
                b.image,
                l.loan_id,
                l.loan_date,
                l.due_date
            FROM item i
            LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
            LEFT JOIN loan l ON l.item_code = i.item_code AND l.is_lent = 1 AND l.is_return = 0
            WHERE i.item_code = ?
        ");
        $st->execute([$itemCode]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            sendJsonResponse(['ok' => false, 'msg' => 'Barcode tidak ditemukan di database']);
        }
        
        if (!$item['loan_id']) {
            sendJsonResponse(['ok' => false, 'msg' => 'Buku ini tidak sedang dipinjam']);
        }
        
        sendJsonResponse([
            'ok' => true,
            'msg' => 'Buku dapat dikembalikan',
            'data' => [
                'item_code' => $item['item_code'],
                'title' => $item['title'] ?: 'Judul tidak tersedia',
                'image' => $item['image'],
                'loan_date' => $item['loan_date'],
                'due_date' => $item['due_date']
            ]
        ]);
    }
    
    // ==================== ACTION BORROW ====================
    if ($action === 'borrow') {
        error_log("Processing borrow action for item: $itemCode");
        
        $st = $db->prepare("
            SELECT b.biblio_id, b.title 
            FROM biblio b 
            LEFT JOIN item i ON i.biblio_id = b.biblio_id 
            WHERE i.item_code = ?
        ");
        $st->execute([$itemCode]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            sendJsonResponse(['ok' => false, 'msg' => 'Data buku tidak ditemukan']);
        }
        
        $st = $db->prepare("
            SELECT loan_id FROM loan 
            WHERE item_code = ? AND is_lent = 1 AND is_return = 0
        ");
        $st->execute([$itemCode]);
        if ($st->fetch()) {
            sendJsonResponse(['ok' => false, 'msg' => 'Buku sedang dipinjam oleh member lain']);
        }
        
        if ($overdueCount > 0) {
            sendJsonResponse(['ok' => false, 'msg' => 'Tidak dapat meminjam karena ada buku yang terlambat dikembalikan']);
        }
        
        $st = $db->prepare("
            SELECT * FROM mst_loan_rules 
            WHERE member_type_id = ? 
            LIMIT 1
        ");
        $st->execute([$memberTypeId]);
        $rules = $st->fetch(PDO::FETCH_ASSOC);
        
        $loanPeriod = (int)($rules['loan_periode'] ?? 7);
        $dueDate = date('Y-m-d', strtotime($today . " +{$loanPeriod} days"));
        
        $st = $db->prepare("
            INSERT INTO loan 
            (item_code, member_id, loan_date, due_date, renewed, loan_rules_id, is_lent, is_return, input_date, last_update)
            VALUES (?, ?, ?, ?, 0, ?, 1, 0, NOW(), NOW())
        ");
        
        $result = $st->execute([$itemCode, $member['member_id'], $today, $dueDate, $rules['loan_rules_id'] ?? 0]);
        
        if (!$result) {
            sendJsonResponse(['ok' => false, 'msg' => 'Gagal memproses peminjaman']);
        }
        
        $db->prepare("
            INSERT INTO system_log (log_type, id, log_location, sub_module, action, log_msg, log_date)
            VALUES ('member', ?, 'opac', 'self_circulation', 'borrow', ?, NOW())
        ")->execute([$member['member_id'], "Borrow item {$itemCode} - {$item['title']}"]);
        
        // ==================== TRIGGER HOOK SILENT ====================
        // Trigger hook tanpa menghasilkan output (toastr dari plugin lain akan dibuang)
        try {
            $payload = [
                'memberID' => $member['member_id'],
                'memberName' => $memberData['member_name'] ?? '',
                'memberType' => $memberTypeId ?? '',
                'date' => date('Y-m-d H:i:s'),
                'loan' => [
                    [
                        'title' => $item['title'],
                        'itemCode' => $itemCode,
                        'loanDate' => $today,
                        'dueDate' => $dueDate
                    ]
                ]
            ];
            
            // Panggil hook dengan mode silent (output dibuang)
            runHookSilently(\SLiMS\Plugins::CIRCULATION_AFTER_SUCCESSFUL_TRANSACTION, $payload);
            
        } catch (Exception $e) {
            error_log("Self Circulation: Hook error - " . $e->getMessage());
        }
        
        sendJsonResponse([
            'ok' => true,
            'msg' => 'Buku berhasil dipinjam',
            'data' => [
                'action' => 'borrow',
                'member_id' => $member['member_id'],
                'member_name' => $memberData['member_name'],
                'item_code' => $itemCode,
                'title' => $item['title'],
                'loan_date' => $today,
                'due_date' => $dueDate
            ]
        ]);
    }
    
    // ==================== ACTION RETURN ====================
    if ($action === 'return') {
        error_log("Processing return action for item: $itemCode");
        
        $st = $db->prepare("
            SELECT i.item_code, b.title, b.biblio_id 
            FROM item i 
            LEFT JOIN biblio b ON b.biblio_id = i.biblio_id 
            WHERE i.item_code = ?
        ");
        $st->execute([$itemCode]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            sendJsonResponse(['ok' => false, 'msg' => 'Data buku tidak ditemukan']);
        }
        
        $st = $db->prepare("
            SELECT loan_id, member_id, due_date, loan_date 
            FROM loan 
            WHERE item_code = ? AND is_lent = 1 AND is_return = 0
            ORDER BY loan_id DESC LIMIT 1
        ");
        $st->execute([$itemCode]);
        $loan = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) {
            sendJsonResponse(['ok' => false, 'msg' => 'Tidak ada transaksi pinjam aktif untuk buku ini']);
        }
        
        if ($loan['member_id'] != $member['member_id']) {
            sendJsonResponse(['ok' => false, 'msg' => 'Buku ini dipinjam oleh member lain']);
        }
        
        $st = $db->prepare("SELECT fine_each_day FROM mst_loan_rules WHERE member_type_id = ? LIMIT 1");
        $st->execute([$memberTypeId]);
        $finePerDay = (int)($st->fetchColumn() ?: 1000);
        
        $dueDate = new DateTime($loan['due_date']);
        $returnDate = new DateTime($today);
        
        if ($returnDate > $dueDate) {
            $daysLate = $returnDate->diff($dueDate)->days;
            $fine = $daysLate * $finePerDay;
            
            sendJsonResponse([
                'ok' => false, 
                'msg' => 'Buku terlambat ' . $daysLate . ' hari dengan denda Rp ' . number_format($fine, 0, ',', '.') . '. Silakan hubungi petugas untuk pengembalian.'
            ]);
        }
        
        $st = $db->prepare("
            UPDATE loan 
            SET is_return = 1, is_lent = 0, return_date = ?, last_update = NOW()
            WHERE loan_id = ?
        ");
        $result = $st->execute([$today, $loan['loan_id']]);
        
        if (!$result) {
            sendJsonResponse(['ok' => false, 'msg' => 'Gagal memproses pengembalian']);
        }
        
        $db->prepare("
            INSERT INTO system_log (log_type, id, log_location, sub_module, action, log_msg, log_date)
            VALUES ('member', ?, 'opac', 'self_circulation', 'return', ?, NOW())
        ")->execute([$member['member_id'], "Return item {$itemCode} - {$item['title']}"]);
        
        // ==================== TRIGGER HOOK SILENT ====================
        // Trigger hook tanpa menghasilkan output (toastr dari plugin lain akan dibuang)
        try {
            $payload = [
                'memberID' => $member['member_id'],
                'memberName' => $memberData['member_name'] ?? '',
                'memberType' => $memberTypeId ?? '',
                'date' => date('Y-m-d H:i:s'),
                'return' => [
                    [
                        'title' => $item['title'],
                        'itemCode' => $itemCode,
                        'returnDate' => $today,
                        'loanDate' => $loan['loan_date'],
                        'dueDate' => $loan['due_date']
                    ]
                ]
            ];
            
            // Panggil hook dengan mode silent (output dibuang)
            runHookSilently(\SLiMS\Plugins::CIRCULATION_AFTER_SUCCESSFUL_TRANSACTION, $payload);
            
        } catch (Exception $e) {
            error_log("Self Circulation: Hook error - " . $e->getMessage());
        }
        
        sendJsonResponse([
            'ok' => true,
            'msg' => 'Buku berhasil dikembalikan',
            'data' => [
                'action' => 'return',
                'member_id' => $member['member_id'],
                'member_name' => $memberData['member_name'],
                'item_code' => $itemCode,
                'title' => $item['title'],
                'loan_date' => $loan['loan_date'],
                'due_date' => $loan['due_date'],
                'return_date' => $today
            ]
        ]);
    }
    
// ==================== ACTION EXTEND ====================
if ($action === 'extend') {
    error_log("Processing extend action for item: $itemCode");
    
    $st = $db->prepare("
        SELECT i.item_code, b.title, b.biblio_id 
        FROM item i 
        LEFT JOIN biblio b ON b.biblio_id = i.biblio_id 
        WHERE i.item_code = ?
    ");
    $st->execute([$itemCode]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        sendJsonResponse(['ok' => false, 'msg' => 'Data buku tidak ditemukan']);
    }
    
    $st = $db->prepare("
        SELECT loan_id, due_date, renewed, loan_date 
        FROM loan 
        WHERE item_code = ? AND is_lent = 1 AND is_return = 0
        ORDER BY loan_id DESC LIMIT 1
    ");
    $st->execute([$itemCode]);
    $loan = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$loan) {
        sendJsonResponse(['ok' => false, 'msg' => 'Tidak ada transaksi pinjam aktif']);
    }
    
    // CEK APAKAH BUKU TERLAMBAT
    $dueDate = new DateTime($loan['due_date']);
    $todayDate = new DateTime($today);
    
    if ($todayDate > $dueDate) {
        sendJsonResponse(['ok' => false, 'msg' => 'Tidak dapat memperpanjang karena sudah melewati jatuh tempo']);
    }
    
    // AMBIL BATAS PERPANJANGAN
    $st = $db->prepare("SELECT reborrow_limit FROM mst_loan_rules WHERE member_type_id = ? LIMIT 1");
    $st->execute([$memberTypeId]);
    $renewalLimit = (int)($st->fetchColumn() ?: 1);
    
    if ((int)$loan['renewed'] >= $renewalLimit) {
        sendJsonResponse(['ok' => false, 'msg' => 'Tidak dapat memperpanjang (maksimal ' . $renewalLimit . ' kali perpanjangan)']);
    }
    
    // HITUNG TANGGAL JATUH TEMPO BARU (7 HARI DARI TANGGAL JATUH TEMPO LAMA)
    $newDueDate = date('Y-m-d', strtotime($loan['due_date'] . " +7 days"));
    
    // UPDATE DATABASE
    $st = $db->prepare("
        UPDATE loan 
        SET due_date = ?, renewed = renewed + 1, last_update = NOW()
        WHERE loan_id = ?
    ");
    $result = $st->execute([$newDueDate, $loan['loan_id']]);
    
    if (!$result) {
        sendJsonResponse(['ok' => false, 'msg' => 'Gagal memperpanjang masa pinjam']);
    }
    
    // ==================== TRIGGER HOOK SILENT ====================
    try {
        $payload = [
            'memberID' => $member['member_id'],
            'memberName' => $memberData['member_name'] ?? '',
            'memberType' => $memberTypeId ?? '',
            'date' => date('Y-m-d H:i:s'),
            'extend' => [
                [
                    'title' => $item['title'],
                    'itemCode' => $itemCode,
                    'loanDate' => $loan['loan_date'],
                    'dueDate' => $newDueDate
                ]
            ]
        ];
        
        // Panggil hook dengan mode silent
        runHookSilently(\SLiMS\Plugins::CIRCULATION_AFTER_SUCCESSFUL_TRANSACTION, $payload);
        
    } catch (Exception $e) {
        error_log("Self Circulation: Hook error - " . $e->getMessage());
    }
    
    // KIRIM RESPONSE SUKSES
    sendJsonResponse([
        'ok' => true,
        'msg' => 'Masa pinjam berhasil diperpanjang hingga ' . date('d/m/Y', strtotime($newDueDate)),
        'data' => [
            'action' => 'extend',
            'item_code' => $itemCode,
            'title' => $item['title'],
            'new_due_date' => date('d/m/Y', strtotime($newDueDate))
        ]
    ]);
}
    
    error_log("Unknown action: $action");
    sendJsonResponse(['ok' => false, 'msg' => 'Action tidak dikenal']);
    
} catch (Exception $e) {
    error_log('Self Circulation API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['ok' => false, 'msg' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}
?>