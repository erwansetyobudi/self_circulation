<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 19/02/2026
 * @File name           : dashboard.inc.php
 * @Description         : Dashboard utama setelah login
 */

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
    die('can not access this file directly');
}

use SLiMS\DB;

// Cek session login
if (!isset($_SESSION['self_circ_member'])) {
    header('Location: ' . SWB . 'index.php?p=self_circulation&page=login');
    exit;
}

$db = DB::getInstance();
$member = $_SESSION['self_circ_member'];

// ==================== AMBIL DATA LIBRARY ====================
// Ambil setting library dari database
$stLib = $db->prepare("SELECT setting_value FROM setting WHERE setting_name = 'library_name'");
$stLib->execute();
$libraryNameRaw = $stLib->fetchColumn();

// Unserialize jika perlu
if (!empty($libraryNameRaw) && @unserialize($libraryNameRaw) !== false) {
    $libraryName = unserialize($libraryNameRaw);
} else {
    $libraryName = $libraryNameRaw;
}

// Ambil logo library
$stLogo = $db->prepare("SELECT setting_value FROM setting WHERE setting_name = 'logo_image'");
$stLogo->execute();
$libraryLogoRaw = $stLogo->fetchColumn();

// Unserialize jika perlu
if (!empty($libraryLogoRaw) && @unserialize($libraryLogoRaw) !== false) {
    $libraryLogo = unserialize($libraryLogoRaw);
} else {
    $libraryLogo = $libraryLogoRaw;
}

// Jika tidak ada di database, gunakan default
if (empty($libraryName)) {
    $libraryName = 'Perpustakaan';
}

// Query biasa tanpa GROUP BY
// Setelah query, sebelum hitung denda
$st = $db->prepare("
    SELECT 
        l.loan_id,
        l.item_code,
        l.loan_date,
        l.due_date,
        l.is_lent,
        l.is_return,
        l.renewed,
        b.title,
        b.image AS cover_image,
        i.call_number,
        b.classification,
        m.member_id,
        m.member_name
    FROM loan AS l
    JOIN member AS m ON l.member_id = m.member_id
    JOIN item AS i ON l.item_code = i.item_code
    JOIN biblio AS b ON i.biblio_id = b.biblio_id
    WHERE l.member_id = ? 
        AND l.is_lent = 1 
        AND l.is_return = 0
    ORDER BY l.due_date ASC
");
$st->execute([$member['member_id']]);
$activeLoans = $st->fetchAll(PDO::FETCH_ASSOC);


// Hitung denda - CARA 1: Unset reference
$today = new DateTime();
$totalFine = 0;
foreach ($activeLoans as &$loan) {
    $dueDate = new DateTime($loan['due_date']);
    if ($today > $dueDate) {
        $daysLate = $today->diff($dueDate)->days;
        $loan['days_late'] = $daysLate;
        
        $stFine = $db->prepare("SELECT fine_each_day FROM mst_loan_rules 
                                WHERE member_type_id = (SELECT member_type_id FROM member WHERE member_id = ?)
                                LIMIT 1");
        $stFine->execute([$member['member_id']]);
        $finePerDay = $stFine->fetchColumn() ?: 1000;
        
        $loan['fine'] = $daysLate * $finePerDay;
        $totalFine += $loan['fine'];
    } else {
        $loan['days_late'] = 0;
        $loan['fine'] = 0;
    }
}
// PENTING: Unset reference setelah loop
unset($loan);

ob_start();
?>

<style>

/* ==================== RESET TOTAL ==================== */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

/* ==================== LAYOUT SEDERHANA ==================== */
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: transparent;
}

/* ==================== HEADER ==================== */
.kiosk-header {
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    padding: 15px 30px;
    width: 100%;
}

.header-container {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.header-logo {
    width: 50px;
    height: 50px;
    object-fit: contain;
}

.header-title h1 {
    font-size: 20px;
    font-weight: 700;
    color: #333;
    margin: 0 0 5px;
}

.header-title p {
    font-size: 13px;
    color: #666;
    margin: 0;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 30px;
}

.datetime {
    text-align: right;
}

.date {
    font-size: 14px;
    color: #666;
    margin-bottom: 5px;
}

.time {
    font-size: 20px;
    font-weight: 600;
    color: #333;
    font-family: 'Courier New', monospace;
}

.member-profile {
    display: flex;
    align-items: center;
    gap: 15px;
}

.member-info {
    text-align: right;
}

.member-name {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
}

.member-id {
    font-size: 13px;
    color: #666;
}

.member-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 20px;
    font-weight: 600;
    text-transform: uppercase;
}

.btn-logout {
    background: none;
    border: 2px solid #fee;
    color: #c33;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-logout:hover {
    background: #fee;
}

/* ==================== MAIN CONTAINER ==================== */
.main-container {
    max-width: 1400px;
    margin: 30px auto;
    padding: 0 30px 30px 30px;
    width: 100%;
}

/* ==================== WELCOME BANNER ==================== */
.welcome-banner {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    padding: 30px;
    color: white;
    margin-bottom: 30px;
    position: relative;
    overflow: hidden;
}

.welcome-banner::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
}

.welcome-banner h2 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 10px;
    position: relative;
}

.welcome-banner p {
    font-size: 16px;
    opacity: 0.9;
    position: relative;
}

/* ==================== ALERT ==================== */
.alert {
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-warning {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

/* ==================== BORROW SECTION ==================== */
.borrow-section {
    background: white;
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.02);
}

.section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.section-title i {
    font-size: 24px;
    color: #667eea;
    background: #eef2ff;
    padding: 12px;
    border-radius: 16px;
}

.section-title h3 {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.borrow-form {
    display: flex;
    gap: 20px;
    align-items: flex-end;
}

.form-group {
    flex: 1;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #666;
    margin-bottom: 8px;
}

.input-wrapper {
    position: relative;
}

.input-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    z-index: 1;
}

.input-wrapper input {
    width: 100%;
    padding: 15px 15px 15px 45px;
    border: 2px solid #eef2f6;
    border-radius: 12px;
    font-size: 16px;
    transition: all 0.3s ease;
}

.input-wrapper input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
}

.btn-borrow {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
}

.btn-borrow:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
}

/* ==================== BOOKS GRID ==================== */
.books-section {
    background: white;
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.02);
}

.books-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.books-header h3 {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.books-count {
    background: #eef2ff;
    color: #667eea;
    padding: 6px 12px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 600;
}

.books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

.book-card {
    background: white;
    border: 2px solid #eef2f6;
    border-radius: 16px;
    padding: 20px;
    display: flex;
    gap: 15px;
}

.book-cover {
    width: 90px;
    height: 120px;
    background: linear-gradient(135deg, #f0f2f5 0%, #e6e9f0 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
    font-size: 30px;
    flex-shrink: 0;
    overflow: hidden;
}

.book-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.book-info {
    flex: 1;
}

.book-title {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    margin-bottom: 8px;
    line-height: 1.4;
}

.book-code {
    font-size: 13px;
    color: #667eea;
    background: #eef2ff;
    display: inline-block;
    padding: 4px 8px;
    border-radius: 8px;
    margin-bottom: 12px;
}

.loan-dates {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 15px;
}

.date-item {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    margin-bottom: 6px;
}

.date-item:last-child {
    margin-bottom: 0;
}

.date-label {
    color: #666;
}

.date-value {
    font-weight: 600;
    color: #333;
}

.date-value.urgent {
    color: #dc3545;
}

.fine-badge {
    background: #dc3545;
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

.book-actions {
    display: flex;
    gap: 10px;
}

.btn-action {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.btn-return {
    background: #28a745;
    color: white;
}

.btn-extend {
    background: #ffc107;
    color: #333;
}

.btn-extend:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ==================== CART STYLES ==================== */
.cart-item {
    background: #f8f9fa;
    border-left: 4px solid #007bff;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cart-item.return-item {
    border-left-color: #ffc107;
}

.cart-item-info {
    flex: 1;
}

.cart-item-title {
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
    font-size: 14px;
}

.cart-item-code {
    font-size: 12px;
    color: #666;
}

.cart-item-remove {
    color: #dc3545;
    cursor: pointer;
    padding: 5px 10px;
    border-radius: 5px;
}

.cart-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
}

.badge-borrow {
    background: #007bff;
    color: white;
}

.badge-return {
    background: #ffc107;
    color: #333;
}

/* ==================== HISTORY SECTION ==================== */
.history-section {
    margin-top: 30px;
    margin-bottom: 30px;
}

.history-section > div:last-child {
    background: white;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.02);
    overflow-x: auto;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.history-table th {
    background: #f8f9fa;
    padding: 12px 10px;
    text-align: left;
    color: #666;
    font-weight: 600;
    border-bottom: 2px solid #eef2f6;
}

.history-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #eef2f6;
}

.history-cover {
    width: 40px;
    height: 55px;
    background: linear-gradient(135deg, #f0f2f5 0%, #e6e9f0 100%);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.history-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}

.status-late {
    background: #f8d7da;
    color: #721c24;
}

.status-ontime {
    background: #d4edda;
    color: #155724;
}

/* ==================== FOOTER ==================== */
.kiosk-footer {
    background: white;
    padding: 20px 30px;
    border-top: 1px solid #eef2f6;
    width: 100%;
}

.footer-content {
    max-width: 1400px;
    margin: 0 auto;
    text-align: center;
    color: #666;
    font-size: 14px;
}

/* ==================== LOADING OVERLAY ==================== */
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255,255,255,0.9);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.loading-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #f0f2f5;
    border-top-color: #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ==================== RESPONSIVE ==================== */
@media (max-width: 768px) {
    .kiosk-header {
        padding: 12px 15px;
    }
    
    .header-container {
        flex-direction: column;
        gap: 15px;
    }
    
    .header-right {
        width: 100%;
        justify-content: space-between;
    }
    
    .datetime {
        display: none;
    }
    
    .main-container {
        padding: 0 15px 20px 15px;
    }
    
    .borrow-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .books-grid {
        grid-template-columns: 1fr;
    }
}

/* ==================== SPACER ==================== */
.spacer {
    height: 30px;
    width: 100%;
}

/* Perbaikan tambahan untuk touch scroll */
html, body {
    height: 100%;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
}

.main-container {
    height: auto;
    min-height: 100vh;
    overflow-y: visible !important;
}

/* Pastikan container dengan overflow bisa di-scroll */
.books-section, 
.history-section,
.borrow-section {
    overflow-y: visible !important;
}

/* Pastikan semua container bisa di-scroll dengan touch */
.main-container,
.books-section,
.history-section,
.borrow-section,
.kiosk-footer {
    touch-action: pan-y;
    -webkit-overflow-scrolling: touch;
}

/* Perbaikan untuk elemen dengan overflow */
.history-section > div:last-child {
    -webkit-overflow-scrolling: touch;
    touch-action: pan-x pan-y; /* Izinkan scroll horizontal dan vertikal */
}

/* Hapus event JavaScript yang memblokir touch */
</style>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
</div>

<!-- Header -->
<header class="kiosk-header no-print">
    <div class="header-container">
        <div class="header-left">
            <?php if (!empty($libraryLogo) && file_exists('images/default/' . $libraryLogo)): ?>
                <img src="<?= SWB ?>images/default/<?= rawurlencode($libraryLogo) ?>" alt="Logo" class="header-logo">
            <?php else: ?>
                <i class="fas fa-book-open" style="font-size: 40px; color: #667eea;"></i>
            <?php endif; ?>
            <div class="header-title">
                <h1><?= sc_h($libraryName) ?></h1>
                <p>Self Circulation System</p>
            </div>
        </div>

        <div class="header-right">
            <div class="datetime">
                <div class="date" id="currentDate"></div>
                <div class="time" id="currentTime"></div>
            </div>

            <div class="member-profile">
                <div class="member-info">
                    <div class="member-name">Hi, <?= sc_h($member['member_name']) ?></div>
                    <div class="member-id"><?= sc_h($member['member_id']) ?></div>
                </div>
                <div class="member-avatar">
                    <?= strtoupper(substr($member['member_name'], 0, 1)) ?>
                </div>
            </div>

            <button class="btn-logout" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </button>
        </div>
    </div>
</header>

<!-- Main Container -->
<main class="main-container">
<div class="welcome-banner no-print">
        <h2>Selamat Datang, <?= sc_h($member['member_name']) ?>! 👋</h2>
        <p>Silakan scan barcode buku untuk melakukan peminjaman atau pengembalian mandiri.</p>
</div>

<?php if (!empty($timeoutMessage)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-clock"></i>
        <?= sc_h($timeoutMessage) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?= sc_h($error) ?>
    </div>
<?php endif; ?>

    <!-- Alert Container -->
    <div id="alertContainer" class="no-print"></div>

    <!-- Form Peminjaman -->
    <div class="borrow-section no-print">
        <div class="section-title">
            <i class="fas fa-qrcode"></i>
            <h3>Formulir Peminjaman / Pengembalian</h3>
        </div>

        <div class="borrow-form">
            <div class="form-group">
                <label>Scan Barcode Buku</label>
                <div class="input-wrapper">
                    <i class="fas fa-barcode input-icon"></i>
                    <input type="text" 
                           id="itemCode" 
                           placeholder="Scan barcode di sini..." 
                           autocomplete="off"
                           autofocus>
                </div>
            </div>
            <button class="btn-borrow" onclick="processBorrow()">
                <i class="fas fa-arrow-right"></i>
                Proses Peminjaman
            </button>
        </div>
        <div style="margin-top: 10px; font-size: 12px; color: #666;">
            <i class="fas fa-info-circle"></i> Tekan Enter setelah scan barcode
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-shopping-cart"></i> Keranjang Peminjaman</h5>
                </div>
                <div class="card-body">
                    <div id="borrowCart" class="mb-3">
                        <!-- Items will be added here dynamically -->
                        <p class="text-muted text-center mb-0" id="emptyBorrowCart">Belum ada buku</p>
                    </div>
                    <button class="btn btn-success w-100" id="processBorrowBtn" onclick="processBorrowCart()" disabled>
                        <i class="fas fa-check-circle"></i> Proses Semua Peminjaman
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="fas fa-undo-alt"></i> Keranjang Pengembalian</h5>
                </div>
                <div class="card-body">
                    <div id="returnCart" class="mb-3">
                        <!-- Items will be added here dynamically -->
                        <p class="text-muted text-center mb-0" id="emptyReturnCart">Belum ada buku</p>
                    </div>
                    <button class="btn btn-warning w-100" id="processReturnBtn" onclick="processReturnCart()" disabled>
                        <i class="fas fa-check-circle"></i> Proses Semua Pengembalian
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tombol Reset Keranjang -->
    <div class="row mt-3">
        <div class="col-12 text-center">
            <button class="btn btn-secondary" onclick="resetCarts()">
                <i class="fas fa-redo-alt"></i> Reset Semua Keranjang
            </button>
            <br></br>
        </div>
    </div>

<!-- Buku yang Dipinjam -->
<div class="books-section">
    <div class="books-header">
        <h3>
            <i class="fas fa-book" style="margin-right: 8px; color: #667eea;"></i>
            Buku yang Sedang Dipinjam
        </h3>
        <span class="books-count"><?= count($activeLoans) ?> Buku</span>
    </div>

    <?php if (empty($activeLoans)): ?>
        <div style="text-align: center; padding: 60px 20px; color: #999;">
            <i class="fas fa-books" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
            <p>Anda belum meminjam buku apapun saat ini.</p>
        </div>
    <?php else: ?>
        <div class="books-grid">
            <?php 
            // Loop dengan for untuk kontrol lebih baik
            for ($i = 0; $i < count($activeLoans); $i++):
                $loan = $activeLoans[$i];
                
                // Debug untuk memastikan index dan data
                echo "<!-- LOOP INDEX: $i, Loan ID: {$loan['loan_id']}, Title: {$loan['title']} -->\n";
            ?>
                <div class="book-card" 
                     id="book_<?= $loan['loan_id'] ?>_<?= $i ?>"
                     data-item="<?= sc_h($loan['item_code']) ?>"
                     data-loan-id="<?= $loan['loan_id'] ?>"
                     data-index="<?= $i ?>">
                    
                    <div class="book-cover">
                        <?php if (!empty($loan['cover_image'])): ?>
                            <img src="<?= SWB ?>lib/minigalnano/createthumb.php?filename=images/docs/<?= rawurlencode($loan['cover_image']) ?>&width=90" 
                                 alt="Cover">
                        <?php else: ?>
                            <i class="fas fa-book"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-info">
                        <div class="book-title"><?= sc_h($loan['title']) ?></div>
                        <div class="book-code"><?= sc_h($loan['item_code']) ?></div>
                        
                                               
                        <div class="loan-dates">
                            <div class="date-item">
                                <span class="date-label">Tanggal Pinjam:</span>
                                <span class="date-value"><?= date('d/m/Y', strtotime($loan['loan_date'])) ?></span>
                            </div>
                            <div class="date-item">
                                <span class="date-label">Jatuh Tempo:</span>
                                <span class="date-value <?= ($loan['days_late'] > 0) ? 'urgent' : '' ?>">
                                    <?= date('d/m/Y', strtotime($loan['due_date'])) ?>
                                    <?php if ($loan['days_late'] > 0): ?>
                                        (Terlambat <?= $loan['days_late'] ?> hari)
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($loan['days_late'] > 0): ?>
                                <div class="date-item">
                                    <span class="date-label">Denda:</span>
                                    <span class="fine-badge">Rp <?= number_format($loan['fine'], 0, ',', '.') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="book-actions">
                            <button class="btn-action btn-return" 
                                    onclick="processReturn('<?= sc_h($loan['item_code']) ?>')">
                                <i class="fas fa-undo-alt"></i> Kembalikan
                            </button>
                            <button class="btn-action btn-extend" 
                                    onclick="extendLoan('<?= sc_h($loan['item_code']) ?>')"
                                    <?= ($loan['days_late'] > 0 || (isset($loan['renewed']) && $loan['renewed'] >= 1)) ? 'disabled' : '' ?>>
                                <i class="fas fa-clock"></i> Perpanjang
                            </button>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        
        
        <script>
        // Hitung jumlah card yang tampil
        setTimeout(function() {
            const cards = document.querySelectorAll('.book-card');
            console.log('Jumlah card di DOM:', cards.length);
            
            cards.forEach((card, i) => {
                console.log(`Card ${i}:`, {
                    id: card.id,
                    loanId: card.dataset.loanId,
                    itemCode: card.dataset.item,
                    index: card.dataset.index,
                    title: card.querySelector('.book-title')?.textContent
                });
            });
            
            if (cards.length === 2) {
                console.log('✅ SUKSES: 2 buku tampil');
            } else {
                console.error('❌ GAGAL: Hanya', cards.length, 'buku yang tampil');
            }
        }, 100);
        </script>
    <?php endif; ?>
</div>
<!-- ==================== HISTORY PEMINJAMAN ==================== -->
<?php
// Ambil history peminjaman (10 terakhir)
$stHistory = $db->prepare("
    SELECT 
        l.loan_id,
        l.item_code,
        l.loan_date,
        l.due_date,
        l.return_date,
        l.renewed,
        b.title,
        b.image AS cover_image,
        i.call_number
    FROM loan AS l
    JOIN item AS i ON l.item_code = i.item_code
    JOIN biblio AS b ON i.biblio_id = b.biblio_id
    WHERE l.member_id = ? 
        AND l.is_return = 1
    ORDER BY l.return_date DESC, l.loan_date DESC
    LIMIT 10
");
$stHistory->execute([$member['member_id']]);
$historyLoans = $stHistory->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ==================== HISTORY PEMINJAMAN (RESPONSIF) ==================== -->
<div class="history-section" style="margin-top: 30px;">
    <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h3 style="display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 600; color: #333; margin: 0;">
            <i class="fas fa-history" style="color: #667eea; background: #eef2ff; padding: 10px; border-radius: 12px;"></i>
            Riwayat Peminjaman (10 Terakhir)
        </h3>
        <span class="history-count" style="background: #eef2ff; color: #667eea; padding: 6px 12px; border-radius: 30px; font-size: 14px; font-weight: 600;">
            <?= count($historyLoans) ?> Transaksi
        </span>
    </div>

    <?php if (empty($historyLoans)): ?>
        <div style="background: white; border-radius: 20px; padding: 40px 20px; text-align: center; color: #999; box-shadow: 0 5px 20px rgba(0,0,0,0.02);">
            <i class="fas fa-book-open" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
            <p>Belum ada riwayat peminjaman.</p>
        </div>
    <?php else: ?>
        <!-- VERSION 1: CARD VIEW UNTUK MOBILE (Horizontal Scroll) -->
        <div style="display: none;" class="mobile-view">
            <!-- Ini akan ditampilkan di mobile via CSS media query -->
        </div>
        
        <!-- VERSION 2: HORIZONTAL SCROLL TABLE (Untuk semua device) -->
        <div style="background: white; border-radius: 20px; padding: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.02); overflow-x: auto; overflow-y: visible; max-height: none;">

            
            <table class="history-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Cover</th>
                        <th>Judul Buku</th>
                        <th>Kode</th>
                        <th>Tgl Pinjam</th>
                        <th>Tgl Kembali</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historyLoans as $index => $history): 
                        $loanDate = new DateTime($history['loan_date']);
                        $returnDate = new DateTime($history['return_date']);
                        $dueDate = new DateTime($history['due_date']);
                        
                        // Status pengembalian
                        if ($returnDate > $dueDate) {
                            $daysLate = $dueDate->diff($returnDate)->days;
                            $statusClass = 'status-late';
                            $statusText = 'Terlambat ' . $daysLate . ' hari';
                        } else {
                            $statusClass = 'status-ontime';
                            $statusText = 'Tepat waktu';
                        }
                        
                        // Hitung denda jika ada
                        $fineText = '';
                        if ($returnDate > $dueDate) {
                            $stFineRule = $db->prepare("SELECT fine_each_day FROM mst_loan_rules 
                                                        WHERE member_type_id = (SELECT member_type_id FROM member WHERE member_id = ?)
                                                        LIMIT 1");
                            $stFineRule->execute([$member['member_id']]);
                            $finePerDay = $stFineRule->fetchColumn() ?: 1000;
                            $fineAmount = $daysLate * $finePerDay;
                            $fineText = ' <span class="fine-badge">Rp ' . number_format($fineAmount, 0, ',', '.') . '</span>';
                        }
                    ?>
                    <tr>
                        <td style="color: #666;"><?= $index + 1 ?></td>
                        <td>
                            <div class="history-cover">
                                <?php if (!empty($history['cover_image'])): ?>
                                    <img src="<?= SWB ?>lib/minigalnano/createthumb.php?filename=images/docs/<?= rawurlencode($history['cover_image']) ?>&width=50" 
                                         alt="Cover">
                                <?php else: ?>
                                    <i class="fas fa-book"></i>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="font-weight: 500; color: #333; max-width: 200px;">
                            <div style="word-wrap: break-word; word-break: break-word;">
                                <?= sc_h($history['title']) ?>
                            </div>
                        </td>
                        <td style="color: #667eea; font-size: 12px;"><?= sc_h($history['item_code']) ?></td>
                        <td style="color: #666; font-size: 12px;"><?= date('d/m/Y', strtotime($history['loan_date'])) ?></td>
                        <td style="color: #666; font-size: 12px;"><?= date('d/m/Y', strtotime($history['return_date'])) ?></td>
                        <td>
                            <span class="status-badge <?= $statusClass ?>">
                                <?= $statusText ?>
                            </span>
                            <?= $fineText ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Info tambahan -->
            <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 10px; font-size: 11px; color: #666; display: flex; gap: 15px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-circle" style="color: #28a745; font-size: 8px;"></i>
                    <span>Tepat waktu</span>
                </div>
                <div style="display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-circle" style="color: #dc3545; font-size: 8px;"></i>
                    <span>Terlambat</span>
                </div>
                <?php if (!empty($historyLoans)): ?>
                <div style="display: flex; align-items: center; gap: 5px;">
                    <i class="fas fa-info-circle" style="color: #667eea;"></i>
                    <span>10 riwayat terakhir</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        

        
        <!-- Jika ingin menggunakan card view, aktifkan kode ini -->
        <?php if (false): // Ubah ke true untuk menggunakan card view ?>
        <div class="mobile-cards">
            <?php foreach ($historyLoans as $index => $history): 
                $loanDate = new DateTime($history['loan_date']);
                $returnDate = new DateTime($history['return_date']);
                $dueDate = new DateTime($history['due_date']);
                
                // Status pengembalian
                if ($returnDate > $dueDate) {
                    $daysLate = $dueDate->diff($returnDate)->days;
                    $statusClass = 'status-late';
                    $statusText = 'Terlambat ' . $daysLate . ' hari';
                } else {
                    $statusClass = 'status-ontime';
                    $statusText = 'Tepat waktu';
                }
                
                // Hitung denda
                $fineAmount = 0;
                if ($returnDate > $dueDate) {
                    $stFineRule = $db->prepare("SELECT fine_each_day FROM mst_loan_rules 
                                                WHERE member_type_id = (SELECT member_type_id FROM member WHERE member_id = ?)
                                                LIMIT 1");
                    $stFineRule->execute([$member['member_id']]);
                    $finePerDay = $stFineRule->fetchColumn() ?: 1000;
                    $fineAmount = $daysLate * $finePerDay;
                }
            ?>
            <div class="history-card">
                <div class="history-card-header">
                    <div class="history-card-cover">
                        <?php if (!empty($history['cover_image'])): ?>
                            <img src="<?= SWB ?>lib/minigalnano/createthumb.php?filename=images/docs/<?= rawurlencode($history['cover_image']) ?>&width=50" alt="Cover">
                        <?php else: ?>
                            <i class="fas fa-book" style="font-size: 24px; color: #999; display: flex; height: 100%; align-items: center; justify-content: center;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="history-card-title">
                        <h4><?= sc_h($history['title']) ?></h4>
                        <span class="history-card-code"><?= sc_h($history['item_code']) ?></span>
                    </div>
                </div>
                
                <div class="history-card-dates">
                    <div>Pinjam: <span><?= date('d/m/Y', strtotime($history['loan_date'])) ?></span></div>
                    <div>Kembali: <span><?= date('d/m/Y', strtotime($history['return_date'])) ?></span></div>
                </div>
                
                <div class="history-card-status">
                    <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                    <?php if ($fineAmount > 0): ?>
                        <span class="fine-badge">Rp <?= number_format($fineAmount, 0, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('=== DEBUG LENGKAP ===');
    
    // Data dari PHP
    const phpData = <?= json_encode(array_map(function($loan) {
        return [
            'item_code' => $loan['item_code'],
            'loan_id' => $loan['loan_id'],
            'title' => $loan['title']
        ];
    }, $activeLoans)) ?>;
    
    console.log('Data dari PHP:', phpData);
    console.log('Jumlah data PHP:', phpData.length);
    
    // Cek DOM
    const cards = document.querySelectorAll('.book-card');
    console.log('Jumlah book card di DOM:', cards.length);
    
    // Detail setiap card
    const domItems = {};
    cards.forEach((card, index) => {
        const itemCode = card.dataset.item;
        const loanId = card.dataset.loanId;
        const title = card.querySelector('.book-title')?.textContent.trim();
        
        console.log(`Card[${index}]: item=${itemCode}, loanId=${loanId}, title=${title}`);
        
        if (domItems[itemCode]) {
            console.error(`🚨 DUPLIKAT DITEMUKAN: ${itemCode} muncul lagi!`);
            card.style.border = '5px solid red';
        } else {
            domItems[itemCode] = loanId;
        }
    });
    
    // Kesimpulan
    if (cards.length === phpData.length) {
        console.log('✅ JUMLAH SESUAI: DOM:', cards.length, 'PHP:', phpData.length);
    } else {
        console.error('❌ JUMLAH TIDAK SESUAI: DOM:', cards.length, 'PHP:', phpData.length);
    }
    
    if (Object.keys(domItems).length === phpData.length) {
        console.log('✅ SEMUA BUKU UNIQUE');
    } else {
        console.error('❌ ADA DUPLIKAT: Unique items:', Object.keys(domItems).length, 'PHP items:', phpData.length);
    }
});
</script>
</main>

<!-- Footer -->
<footer class="kiosk-footer no-print">
    <div class="footer-content">
        <p>&copy; <?= date('Y') ?> <?= sc_h($libraryName) ?>. All rights reserved.</p>
        <p>Self Circulation System v1.0</p>
    </div>
</footer>

<!-- Receipt Template (hidden) -->
<div id="receiptTemplate" style="display: none;"></div>
<!-- Modal Konfirmasi Buku -->
<div class="modal fade" id="confirmBorrowModal" tabindex="-1" aria-labelledby="confirmBorrowModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
            <div class="modal-header" style="border-bottom: 2px solid #eef2f6; padding: 20px 25px;">
                <h5 class="modal-title" id="confirmBorrowModalLabel" style="font-weight: 700; color: #333;">
                    <i class="fas fa-book-open" style="color: #667eea; margin-right: 10px;"></i>
                    Konfirmasi Peminjaman
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 25px;">
                <div id="bookInfo" style="display: flex; gap: 20px; margin-bottom: 20px;">
                    <div id="bookCover" style="width: 100px; height: 140px; background: linear-gradient(135deg, #f0f2f5 0%, #e6e9f0 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 30px; overflow: hidden;">
                        <i class="fas fa-book"></i>
                    </div>
                    <div style="flex: 1;">
                        <div id="bookTitle" style="font-size: 18px; font-weight: 700; color: #333; margin-bottom: 10px; line-height: 1.4;"></div>
                        <div id="bookCode" style="font-size: 14px; color: #667eea; background: #eef2ff; display: inline-block; padding: 5px 10px; border-radius: 8px; margin-bottom: 15px;"></div>
                        
                        <div style="background: #f8f9fa; border-radius: 12px; padding: 15px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">Tahun Terbit:</span>
                                <span id="bookYear" style="font-weight: 600; color: #333;"></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span style="color: #666;">ISBN/ISSN:</span>
                                <span id="bookIsbn" style="font-weight: 600; color: #333;"></span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #666;">Jenis Koleksi:</span>
                                <span id="bookType" style="font-weight: 600; color: #333;"></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="loanInfo" style="background: #eef2ff; border-radius: 12px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <i class="fas fa-info-circle" style="color: #667eea;"></i>
                        <span style="font-weight: 600; color: #333;">Informasi Peminjaman</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="color: #666;">Buku yang dipinjam:</span>
                        <span id="activeLoans" style="font-weight: 600; color: #333;"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="color: #666;">Sisa kuota:</span>
                        <span id="remainingQuota" style="font-weight: 600; color: #28a745;"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #666;">Jatuh tempo:</span>
                        <span id="dueDateInfo" style="font-weight: 600; color: #333;"></span>
                    </div>
                </div>
                
                <div id="fineWarning" style="background: #fff3cd; border-radius: 12px; padding: 15px; display: none;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                        <span style="color: #856404; font-weight: 600;">Perhatian!</span>
                    </div>
                    <p id="fineMessage" style="color: #856404; margin: 10px 0 0 30px; font-size: 14px;"></p>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 2px solid #eef2f6; padding: 20px 25px;">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="padding: 12px 25px; border-radius: 12px; font-weight: 600;">
                    <i class="fas fa-times"></i> Batalkan
                </button>
                <button type="button" class="btn" id="confirmBorrowBtn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; border-radius: 12px; font-weight: 600; border: none;">
                    <i class="fas fa-check"></i> Pinjam Buku
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Di bagian bawah, sebelum </body> -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== DEBUG LENGKAP ===');
        
        // 1. Cek data dari PHP
        console.log('Data dari PHP:', <?= json_encode(array_map(function($loan) {
            return [
                'item_code' => $loan['item_code'],
                'loan_id' => $loan['loan_id'],
                'title' => $loan['title']
            ];
        }, $activeLoans)) ?>);
        
        // 2. Cek semua book card di DOM
        const cards = document.querySelectorAll('.book-card');
        console.log('Jumlah book card di DOM:', cards.length);
        
        // 3. Detail setiap card
        cards.forEach((card, index) => {
            const id = card.id;
            const itemCode = card.dataset.item;
            const loanId = card.dataset.loanId;
            const title = card.querySelector('.book-title')?.textContent.trim();
            
            console.log(`Card[${index}]: id=${id}, item=${itemCode}, loanId=${loanId}, title=${title}`);
        });
        
        // 4. Cek duplikat berdasarkan item_code
        const items = {};
        let hasDuplicate = false;
        
        cards.forEach(card => {
            const itemCode = card.dataset.item;
            const loanId = card.dataset.loanId;
            
            if (items[itemCode]) {
                console.error(`🚨 DUPLIKAT: ${itemCode} muncul dengan loanId ${items[itemCode]} dan ${loanId}`);
                card.style.border = '5px solid red';
                hasDuplicate = true;
            } else {
                items[itemCode] = loanId;
            }
        });
        
        if (!hasDuplicate) {
            console.log('✅ TIDAK ADA DUPLIKAT - Semua buku unique');
        }
        
        // 5. Ringkasan
        console.log('Total buku di DOM:', cards.length);
        console.log('Buku unik (berdasarkan item_code):', Object.keys(items).length);
        console.log('Detail unik:', items);
    });
</script>
<!-- Di bagian paling bawah, sebelum </body> -->
<script>
    // Debug DOM structure
    setTimeout(function() {
        console.log('=== FINAL DOM CHECK ===');
        const booksGrid = document.querySelector('.books-grid');
        if (booksGrid) {
            const cards = booksGrid.querySelectorAll('.book-card');
            console.log('Final book cards count:', cards.length);
            
            // Cek apakah ada duplikasi elemen
            const seen = new Set();
            cards.forEach((card, i) => {
                const loanId = card.dataset.loanId;
                const itemCode = card.dataset.item;
                const key = loanId + '-' + itemCode;
                
                console.log(`Card ${i}: loanId=${loanId}, item=${itemCode}, title=${card.querySelector('.book-title')?.textContent}`);
                
                if (seen.has(key)) {
                    console.error(`🚨 DUPLIKAT ELEMEN DITEMUKAN: ${key}`);
                    // Hapus duplikat
                    card.remove();
                } else {
                    seen.add(key);
                }
            });
            
            // Update count after cleanup
            const finalCount = booksGrid.querySelectorAll('.book-card').length;
            console.log('After cleanup - book cards count:', finalCount);
            
            // Update debug info
            const debugDiv = document.querySelector('.books-section > div:last-child');
            if (debugDiv) {
                debugDiv.innerHTML = `<strong>DEBUG INFO:</strong> Total buku di database: <?= count($activeLoans) ?> | Buku ditampilkan: ${finalCount}`;
            }
        }
    }, 500); // Delay 500ms to ensure everything is loaded
</script>

<!-- JavaScript untuk fungsionalitas (sama seperti sebelumnya) -->
<script>
    // ==================== GLOBAL VARIABLES ====================
    let lastTransaction = null;
    let resetTimer = null;
    let confirmModal = null;
    let currentItemCode = '';
    let borrowCart = [];
    let returnCart = [];
    const RESET_DELAY = 30000; // 30 detik

    // ==================== INITIALIZATION ====================
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap modal
        if (typeof bootstrap !== 'undefined') {
            const modalElement = document.getElementById('confirmBorrowModal');
            if (modalElement) {
                confirmModal = new bootstrap.Modal(modalElement);
            }
        }
        
        // Set focus to barcode input
        focusBarcode();
        
        // Update datetime immediately
        updateDateTime();
        
        // Initialize carts from localStorage
        loadCarts();
    });

    // ==================== DATE & TIME ====================
    function updateDateTime() {
        const currentDate = document.getElementById('currentDate');
        const currentTime = document.getElementById('currentTime');
        
        if (!currentDate || !currentTime) return;
        
        const now = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        };
        
        currentDate.textContent = now.toLocaleDateString('id-ID', options);
        currentTime.textContent = now.toLocaleTimeString('id-ID');
    }
    
    setInterval(updateDateTime, 1000);

    // ==================== UTILITY FUNCTIONS ====================
    function showLoading(show = true) {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = show ? 'flex' : 'none';
        }
    }

    function showAlert(type, message) {
        const alertContainer = document.getElementById('alertContainer');
        if (!alertContainer) return;
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        
        const html = `
            <div class="alert alert-${type}">
                <i class="fas ${icons[type] || 'fa-info-circle'}"></i>
                ${message}
            </div>
        `;
        
        alertContainer.innerHTML = html;
        
        setTimeout(() => {
            if (alertContainer) alertContainer.innerHTML = '';
        }, 5000);
    }

    function focusBarcode() {
        setTimeout(() => {
            const itemCode = document.getElementById('itemCode');
            if (itemCode) {
                itemCode.focus();
                itemCode.select();
            }
        }, 100);
    }

    function resetScreen() {
        const itemCode = document.getElementById('itemCode');
        if (itemCode) itemCode.value = '';
        focusBarcode();
    }

    function scheduleReset() {
        if (resetTimer) clearTimeout(resetTimer);
        resetTimer = setTimeout(resetScreen, RESET_DELAY);
    }

    function saveCarts() {
        try {
            localStorage.setItem('borrowCart', JSON.stringify(borrowCart));
            localStorage.setItem('returnCart', JSON.stringify(returnCart));
        } catch (e) {
            console.error('Failed to save carts:', e);
        }
    }

    function loadCarts() {
        try {
            const savedBorrow = localStorage.getItem('borrowCart');
            const savedReturn = localStorage.getItem('returnCart');
            
            if (savedBorrow) {
                borrowCart = JSON.parse(savedBorrow);
                updateBorrowCartDisplay();
            }
            
            if (savedReturn) {
                returnCart = JSON.parse(savedReturn);
                updateReturnCartDisplay();
            }
        } catch (e) {
            console.error('Failed to load carts:', e);
        }
    }

    function clearCarts() {
        try {
            localStorage.removeItem('borrowCart');
            localStorage.removeItem('returnCart');
        } catch (e) {
            console.error('Failed to clear carts:', e);
        }
    }

// ==================== LOGOUT ====================
function logout() {
    if (confirm('Apakah Anda yakin ingin logout?')) {
        showLoading(true);
        clearCarts();
        
        // Gunakan AJAX untuk logout
        fetch('<?= SWB ?>index.php?p=self_circulation&page=api', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'action=logout'
        })
        .then(response => {
            // Cek apakah response JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return response.json();
            } else {
                // Jika bukan JSON, anggap error
                throw new Error('Response bukan JSON');
            }
        })
        .then(data => {
            showLoading(false);
            if (data.ok) {
                // Redirect ke halaman login
                window.location.href = '<?= SWB ?>index.php?p=self_circulation&page=login';
            } else {
                showAlert('error', data.msg || 'Gagal logout');
                // Fallback setelah 2 detik
                setTimeout(() => {
                    window.location.href = '<?= SWB ?>index.php?p=self_circulation&page=dashboard&logout=1';
                }, 2000);
            }
        })
        .catch(error => {
            console.error('Logout error:', error);
            showLoading(false);
            showAlert('warning', 'Menggunakan metode logout alternatif...');
            // Fallback: redirect langsung
            setTimeout(() => {
                window.location.href = '<?= SWB ?>index.php?p=self_circulation&page=dashboard&logout=1';
            }, 1000);
        });
    }
}

    // ==================== PRINT RECEIPT ====================
    function printReceipt(data) {
        if (!data) return;
        
        const now = new Date();
        const dateStr = now.toLocaleDateString('id-ID');
        const timeStr = now.toLocaleTimeString('id-ID');
        
        const actionLabel = data.action === 'borrow' ? 'PEMINJAMAN' : 'PENGEMBALIAN';
        
        const escapeHtml = (text) => {
            if (!text) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };
        
        const safeTitle = escapeHtml(data.title);
        const safeMemberName = escapeHtml(data.member_name || '<?= sc_h($member['member_name']) ?>');
        const safeMemberId = escapeHtml(data.member_id || '<?= sc_h($member['member_id']) ?>');
        const safeItemCode = escapeHtml(data.item_code || '');
        
        let receiptHtml = `
            <div class="receipt-print" style="padding: 10px; font-family: 'Courier New', monospace; max-width: 300px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 16px;"><?= sc_h($libraryName) ?></h3>
                    <p style="margin: 5px 0; font-size: 11px;">Self Circulation System</p>
                    <p style="margin: 5px 0; font-size: 10px;">${dateStr} ${timeStr}</p>
                    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
                </div>
                <div style="margin-bottom: 15px;">
                    <p style="margin: 5px 0;"><strong>TRANSAKSI: ${actionLabel}</strong></p>
                    <p style="margin: 5px 0;">Member: ${safeMemberName}</p>
                    <p style="margin: 5px 0;">ID: ${safeMemberId}</p>
                    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
                    <p style="margin: 5px 0;"><strong>${safeTitle}</strong></p>
                    <p style="margin: 5px 0;">Barcode: ${safeItemCode}</p>
                    ${data.loan_date ? `<p style="margin: 5px 0;">Tgl Pinjam: ${escapeHtml(data.loan_date)}</p>` : ''}
                    ${data.due_date ? `<p style="margin: 5px 0;">Jatuh Tempo: <strong>${escapeHtml(data.due_date)}</strong></p>` : ''}
                    ${data.return_date ? `<p style="margin: 5px 0;">Tgl Kembali: ${escapeHtml(data.return_date)}</p>` : ''}
                    ${data.fine ? `<p style="margin: 5px 0;">Denda: Rp ${Number(data.fine).toLocaleString('id-ID')}</p>` : ''}
                    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
                </div>
                <div style="text-align: center; font-size: 10px;">
                    <p style="margin: 5px 0;">Terima kasih telah menggunakan layanan kami</p>
                    <p style="margin: 5px 0;">Salam Literasi 🙏</p>
                </div>
            </div>
        `;
        
        const printWindow = window.open('', '_blank', 'width=400,height=600');
        if (!printWindow) {
            showAlert('error', 'Popup diblokir. Izinkan popup untuk mencetak.');
            return;
        }
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Struk Transaksi</title>
                <style>
                    body { 
                        margin: 0; 
                        padding: 10px;
                        font-family: 'Courier New', monospace;
                        font-size: 12px;
                    }
                    @media print {
                        body { margin: 0; padding: 0; }
                    }
                </style>
            </head>
            <body>
                ${receiptHtml}
                <script>
                    window.onload = function() {
                        setTimeout(function() {
                            window.print();
                            setTimeout(window.close, 500);
                        }, 100);
                    }
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }

// ==================== API CALLS with DEBUG ====================
// ==================== API CALLS with JSON EXTRACTION ====================
async function callApi(action, itemCode) {
    console.log('🚀 callApi started:', {action, itemCode});
    showLoading(true);
    
    try {
        const formData = new FormData();
        formData.append('action', action);
        if (itemCode) {
            formData.append('item_code', itemCode);
        }
        
        const url = '<?= SWB ?>index.php?p=self_circulation&page=api';
        console.log('📡 Fetching URL:', url);
        
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        console.log('📥 Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        console.log('📄 Raw response (first 200 chars):', text.substring(0, 200));
        
        // ==================== EKSTRAK JSON DARI RESPONSE CAMPURAN ====================
        // Cari JSON object di dalam response (mulai dengan { dan diakhiri dengan })
        let jsonText = null;
        
        // Method 1: Cari pattern JSON terakhir dalam response
        const jsonMatches = text.match(/\{(?:[^{}]|(?:\{[^{}]*\}))*\}/g);
        if (jsonMatches && jsonMatches.length > 0) {
            // Ambil JSON yang paling akhir (kemungkinan besar response kita)
            jsonText = jsonMatches[jsonMatches.length - 1];
            console.log('📄 Extracted JSON:', jsonText.substring(0, 200));
        } else {
            // Method 2: Coba cari dengan regex yang lebih sederhana
            const simpleMatch = text.match(/\{.*"ok".*:.*(true|false).*\}.*\}/);
            if (simpleMatch) {
                jsonText = simpleMatch[0];
                console.log('📄 Extracted JSON (simple):', jsonText.substring(0, 200));
            }
        }
        
        // Jika tidak menemukan JSON, kembalikan error
        if (!jsonText) {
            console.error('❌ No JSON found in response');
            console.error('Full response:', text);
            showAlert('error', 'Response tidak valid dari server');
            return { ok: false, msg: 'Invalid server response' };
        }
        
        // Parse JSON
        try {
            const result = JSON.parse(jsonText);
            console.log('✅ Parsed JSON result:', result);
            
            // Jika result.ok true, berarti transaksi berhasil
            // Keranjang akan di-update di function pemanggil (processBorrowCart/processReturnCart)
            
            return result;
        } catch (e) {
            console.error('❌ JSON Parse Error:', e);
            console.error('Failed to parse:', jsonText);
            showAlert('error', 'Gagal parsing response server');
            return { ok: false, msg: 'Parse error' };
        }
        
    } catch (error) {
        console.error('❌ API Error:', error);
        showAlert('error', 'Terjadi kesalahan koneksi: ' + error.message);
        return { ok: false, msg: error.message };
    } finally {
        showLoading(false);
    }
}

    // ==================== CART MANAGEMENT ====================
    async function addToBorrowCart(itemCode) {
        if (borrowCart.some(item => item.item_code === itemCode)) {
            showAlert('warning', 'Buku sudah ada di keranjang peminjaman');
            document.getElementById('itemCode').value = '';
            focusBarcode();
            return false;
        }
        
        if (returnCart.some(item => item.item_code === itemCode)) {
            showAlert('warning', 'Buku sedang dalam keranjang pengembalian');
            document.getElementById('itemCode').value = '';
            focusBarcode();
            return false;
        }
        
        showLoading(true);
        
        try {
            const result = await callApi('check', itemCode);
            
            if (result.ok) {
                borrowCart.push({
                    item_code: itemCode,
                    title: result.data.title,
                    image: result.data.image,
                    due_date: result.data.due_date
                });
                
                updateBorrowCartDisplay();
                saveCarts();
                showAlert('success', `"${result.data.title}" ditambahkan ke keranjang peminjaman`);
                document.getElementById('itemCode').value = '';
                focusBarcode();
                return true;
            } else {
                showAlert('error', result.msg || 'Buku tidak dapat dipinjam');
                document.getElementById('itemCode').value = '';
                focusBarcode();
                return false;
            }
        } catch (error) {
            console.error('Add to cart error:', error);
            showAlert('error', 'Gagal menambahkan ke keranjang');
            return false;
        } finally {
            showLoading(false);
        }
    }

    async function addToReturnCart(itemCode) {
        if (returnCart.some(item => item.item_code === itemCode)) {
            showAlert('warning', 'Buku sudah ada di keranjang pengembalian');
            document.getElementById('itemCode').value = '';
            focusBarcode();
            return false;
        }
        
        if (borrowCart.some(item => item.item_code === itemCode)) {
            showAlert('warning', 'Buku sedang dalam keranjang peminjaman');
            document.getElementById('itemCode').value = '';
            focusBarcode();
            return false;
        }
        
        showLoading(true);
        
        try {
            const result = await callApi('check_return', itemCode);
            
            if (result.ok) {
                returnCart.push({
                    item_code: itemCode,
                    title: result.data.title,
                    image: result.data.image,
                    loan_date: result.data.loan_date,
                    due_date: result.data.due_date,
                    return_date: new Date().toLocaleDateString('id-ID')
                });
                
                updateReturnCartDisplay();
                saveCarts();
                showAlert('success', `"${result.data.title}" ditambahkan ke keranjang pengembalian`);
                document.getElementById('itemCode').value = '';
                focusBarcode();
                return true;
            } else {
                showAlert('error', result.msg || 'Buku tidak dapat dikembalikan');
                document.getElementById('itemCode').value = '';
                focusBarcode();
                return false;
            }
        } catch (error) {
            console.error('Add to return cart error:', error);
            showAlert('error', 'Gagal menambahkan ke keranjang');
            return false;
        } finally {
            showLoading(false);
        }
    }

    function updateBorrowCartDisplay() {
        const cartDiv = document.getElementById('borrowCart');
        const processBtn = document.getElementById('processBorrowBtn');
        
        if (!cartDiv) return;
        
        if (borrowCart.length === 0) {
            cartDiv.innerHTML = '<p class="text-muted text-center mb-0" id="emptyBorrowCart">Belum ada buku</p>';
            if (processBtn) processBtn.disabled = true;
            return;
        }
        
        let html = '';
        borrowCart.forEach((item, index) => {
            html += `
                <div class="cart-item" data-index="${index}">
                    <div class="cart-item-info">
                        <div class="cart-item-title">
                            ${escapeHtml(item.title)}
                            <span class="cart-badge badge-borrow">Pinjam</span>
                        </div>
                        <div class="cart-item-code">${escapeHtml(item.item_code)}</div>
                    </div>
                    <div class="cart-item-remove" onclick="removeFromBorrowCart(${index})">
                        <i class="fas fa-times"></i>
                    </div>
                </div>
            `;
        });
        
        cartDiv.innerHTML = html;
        if (processBtn) processBtn.disabled = false;
    }

    function updateReturnCartDisplay() {
        const cartDiv = document.getElementById('returnCart');
        const processBtn = document.getElementById('processReturnBtn');
        
        if (!cartDiv) return;
        
        if (returnCart.length === 0) {
            cartDiv.innerHTML = '<p class="text-muted text-center mb-0" id="emptyReturnCart">Belum ada buku</p>';
            if (processBtn) processBtn.disabled = true;
            return;
        }
        
        let html = '';
        returnCart.forEach((item, index) => {
            html += `
                <div class="cart-item return-item" data-index="${index}">
                    <div class="cart-item-info">
                        <div class="cart-item-title">
                            ${escapeHtml(item.title)}
                            <span class="cart-badge badge-return">Kembali</span>
                        </div>
                        <div class="cart-item-code">${escapeHtml(item.item_code)}</div>
                        <div class="small text-muted">Jatuh tempo: ${escapeHtml(item.due_date)}</div>
                    </div>
                    <div class="cart-item-remove" onclick="removeFromReturnCart(${index})">
                        <i class="fas fa-times"></i>
                    </div>
                </div>
            `;
        });
        
        cartDiv.innerHTML = html;
        if (processBtn) processBtn.disabled = false;
    }

    function removeFromBorrowCart(index) {
        const removed = borrowCart.splice(index, 1)[0];
        updateBorrowCartDisplay();
        saveCarts();
        showAlert('info', `"${removed.title}" dihapus dari keranjang peminjaman`);
    }

    function removeFromReturnCart(index) {
        const removed = returnCart.splice(index, 1)[0];
        updateReturnCartDisplay();
        saveCarts();
        showAlert('info', `"${removed.title}" dihapus dari keranjang pengembalian`);
    }

    function resetCarts() {
        if (borrowCart.length === 0 && returnCart.length === 0) return;
        
        if (confirm('Hapus semua buku dari keranjang?')) {
            borrowCart = [];
            returnCart = [];
            updateBorrowCartDisplay();
            updateReturnCartDisplay();
            clearCarts();
            showAlert('info', 'Semua keranjang telah dikosongkan');
            focusBarcode();
        }
    }

    async function processBorrowCart() {
        if (borrowCart.length === 0) {
            showAlert('warning', 'Tidak ada buku dalam keranjang peminjaman');
            return;
        }
        
        if (!confirm(`Proses ${borrowCart.length} buku untuk dipinjam?`)) {
            return;
        }
        
        showLoading(true);
        
        try {
            const successes = [];
            const failures = [];
            
            for (const item of borrowCart) {
                const result = await callApi('borrow', item.item_code);
                if (result.ok) {
                    successes.push(item);
                } else {
                    failures.push({ item: item, msg: result.msg });
                }
            }
            
            if (successes.length > 0) {
                showAlert('success', `${successes.length} buku berhasil dipinjam`);
                printBulkReceipt('borrow', successes);
                borrowCart = borrowCart.filter(item => 
                    !successes.some(success => success.item_code === item.item_code)
                );
            }
            
            if (failures.length > 0) {
                let msg = `${failures.length} buku gagal dipinjam:\n`;
                failures.forEach(f => {
                    msg += `- ${f.item.title}: ${f.msg}\n`;
                });
                showAlert('error', msg);
            }
            
            updateBorrowCartDisplay();
            saveCarts();
            
            if (failures.length === 0 && successes.length > 0) {
                setTimeout(() => {
                    location.reload();
                }, 3000);
            }
            
        } catch (error) {
            console.error('Process borrow error:', error);
            showAlert('error', 'Terjadi kesalahan saat memproses peminjaman');
        } finally {
            showLoading(false);
        }
    }

    async function processReturnCart() {
        if (returnCart.length === 0) {
            showAlert('warning', 'Tidak ada buku dalam keranjang pengembalian');
            return;
        }
        
        if (!confirm(`Proses ${returnCart.length} buku untuk dikembalikan?`)) {
            return;
        }
        
        showLoading(true);
        
        try {
            const successes = [];
            const failures = [];
            
            for (const item of returnCart) {
                const result = await callApi('return', item.item_code);
                if (result.ok) {
                    successes.push(item);
                } else {
                    failures.push({ item: item, msg: result.msg });
                }
            }
        
            if (successes.length > 0) {
                showAlert('success', `${successes.length} buku berhasil dikembalikan`);
                printBulkReceipt('return', successes);
                returnCart = returnCart.filter(item => 
                    !successes.some(success => success.item_code === item.item_code)
                );
            }
            
            if (failures.length > 0) {
                let msg = `${failures.length} buku gagal dikembalikan:\n`;
                failures.forEach(f => {
                    msg += `- ${f.item.title}: ${f.msg}\n`;
                });
                showAlert('error', msg);
            }
            
            updateReturnCartDisplay();
            saveCarts();
            
            if (failures.length === 0 && successes.length > 0) {
                setTimeout(() => {
                    location.reload();
                }, 3000);
            }
            
        } catch (error) {
            console.error('Process return error:', error);
            showAlert('error', 'Terjadi kesalahan saat memproses pengembalian');
        } finally {
            showLoading(false);
        }
    }

    function printBulkReceipt(action, items) {
        if (!items || items.length === 0) return;
        
        const now = new Date();
        const dateStr = now.toLocaleDateString('id-ID');
        const timeStr = now.toLocaleTimeString('id-ID');
        
        const actionLabel = action === 'borrow' ? 'PEMINJAMAN' : 'PENGEMBALIAN';
        const actionIcon = action === 'borrow' ? '📤' : '📥';
        
        const dueDate = new Date();
        dueDate.setDate(dueDate.getDate() + 7);
        const dueDateStr = dueDate.toLocaleDateString('id-ID');
        
        let itemsHtml = '';
        items.forEach((item, index) => {
            const loanDate = item.loan_date ? new Date(item.loan_date).toLocaleDateString('id-ID') : '-';
            const itemDueDate = item.due_date ? new Date(item.due_date).toLocaleDateString('id-ID') : (action === 'borrow' ? dueDateStr : '-');
            const returnDate = item.return_date ? new Date(item.return_date).toLocaleDateString('id-ID') : '-';
            
            itemsHtml += `
                <tr>
                    <td style="padding: 5px; border-bottom: 1px dashed #ccc; text-align: center;">${index + 1}</td>
                    <td style="padding: 5px; border-bottom: 1px dashed #ccc;">${escapeHtml(item.title)}</td>
                    <td style="padding: 5px; border-bottom: 1px dashed #ccc; text-align: center;">${escapeHtml(item.item_code)}</td>
                    ${action === 'borrow' ? 
                        `<td style="padding: 5px; border-bottom: 1px dashed #ccc; text-align: center;">${dateStr}</td>
                         <td style="padding: 5px; border-bottom: 1px dashed #ccc; text-align: center;">${dueDateStr}</td>` : 
                        `<td style="padding: 5px; border-bottom: 1px dashed #ccc; text-align: center;">${loanDate}</td>
                         <td style="padding: 5px; border-bottom: 1px dashed #ccc; text-align: center;">${itemDueDate}</td>
                         <td style="padding: 5px; border-bottom: 1px dashed #ccc; text-align: center;">${dateStr}</td>`
                    }
                </tr>
            `;
        });
        
        const tableHeader = action === 'borrow' ? `
            <tr>
                <th style="text-align: center; border-bottom: 1px solid #000;">No</th>
                <th style="text-align: left; border-bottom: 1px solid #000;">Judul</th>
                <th style="text-align: center; border-bottom: 1px solid #000;">Barcode</th>
                <th style="text-align: center; border-bottom: 1px solid #000;">Tgl Pinjam</th>
                <th style="text-align: center; border-bottom: 1px solid #000;">Jatuh Tempo</th>
            </tr>
        ` : `
            <tr>
                <th style="text-align: center; border-bottom: 1px solid #000;">No</th>
                <th style="text-align: left; border-bottom: 1px solid #000;">Judul</th>
                <th style="text-align: center; border-bottom: 1px solid #000;">Barcode</th>
                <th style="text-align: center; border-bottom: 1px solid #000;">Tgl Pinjam</th>
                <th style="text-align: center; border-bottom: 1px solid #000;">Jatuh Tempo</th>
                <th style="text-align: center; border-bottom: 1px solid #000;">Tgl Kembali</th>
            </tr>
        `;
        
        let receiptHtml = `
            <div class="receipt-print" style="padding: 10px; font-family: 'Courier New', monospace; max-width: 380px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 16px;"><?= sc_h($libraryName) ?></h3>
                    <p style="margin: 5px 0; font-size: 11px;">Self Circulation System</p>
                    <p style="margin: 5px 0; font-size: 10px;">${dateStr} ${timeStr}</p>
                    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
                </div>
                <div style="margin-bottom: 15px;">
                    <p style="margin: 5px 0; text-align: center;"><strong>${actionIcon} TRANSAKSI ${actionLabel} ${actionIcon}</strong></p>
                    <p style="margin: 5px 0;">Member: <?= sc_h($member['member_name']) ?></p>
                    <p style="margin: 5px 0;">ID: <?= sc_h($member['member_id']) ?></p>
                    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
                    <p><strong>Daftar Buku (${items.length} item):</strong></p>
                    <table style="width: 100%; font-size: 10px; border-collapse: collapse;">
                        <thead>
                            ${tableHeader}
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                    <hr style="border-top: 1px dashed #000; margin: 10px 0;">
                    <p style="text-align: right;"><strong>Total: ${items.length} buku</strong></p>
                    ${action === 'borrow' ? '<p style="font-size: 10px; font-style: italic;">Jatuh tempo: 7 hari dari tanggal pinjam</p>' : ''}
                </div>
                <div style="text-align: center; font-size: 10px;">
                    <p style="margin: 5px 0;">Terima kasih telah menggunakan layanan kami</p>
                    <p style="margin: 5px 0;">Salam Literasi 🙏</p>
                </div>
            </div>
        `;
        
        const printWindow = window.open('', '_blank', 'width=450,height=600');
        if (!printWindow) {
            showAlert('error', 'Popup diblokir. Izinkan popup untuk mencetak.');
            return;
        }
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Struk Transaksi - ${items.length} Buku</title>
                <style>
                    body { 
                        margin: 0; 
                        padding: 10px;
                        font-family: 'Courier New', monospace;
                        font-size: 12px;
                    }
                    @media print {
                        body { margin: 0; padding: 0; }
                    }
                </style>
            </head>
            <body>
                ${receiptHtml}
                <script>
                    window.onload = function() {
                        setTimeout(function() {
                            window.print();
                            setTimeout(window.close, 500);
                        }, 100);
                    }
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
    
    // ==================== PRINT RECEIPT UNTUK PERPANJANGAN ====================
function printSingleExtendReceipt(data) {
    if (!data) return;
    
    const now = new Date();
    const dateStr = now.toLocaleDateString('id-ID');
    const timeStr = now.toLocaleTimeString('id-ID');
    
    const escapeHtml = (text) => {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };
    
    const safeTitle = escapeHtml(data.title);
    const safeMemberName = escapeHtml(data.member_name);
    const safeMemberId = escapeHtml(data.member_id);
    const safeItemCode = escapeHtml(data.item_code);
    const safeNewDueDate = escapeHtml(data.new_due_date);
    
    let receiptHtml = `
        <div class="receipt-print" style="padding: 10px; font-family: 'Courier New', monospace; max-width: 300px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 15px;">
                <h3 style="margin: 0; font-size: 16px;"><?= sc_h($libraryName) ?></h3>
                <p style="margin: 5px 0; font-size: 11px;">Self Circulation System</p>
                <p style="margin: 5px 0; font-size: 10px;">${dateStr} ${timeStr}</p>
                <hr style="border-top: 1px dashed #000; margin: 10px 0;">
            </div>
            <div style="margin-bottom: 15px;">
                <p style="margin: 5px 0; text-align: center;"><strong>📋 PERPANJANGAN MASA PINJAM 📋</strong></p>
                <p style="margin: 5px 0;">Member: ${safeMemberName}</p>
                <p style="margin: 5px 0;">ID: ${safeMemberId}</p>
                <hr style="border-top: 1px dashed #000; margin: 10px 0;">
                <p style="margin: 5px 0;"><strong>${safeTitle}</strong></p>
                <p style="margin: 5px 0;">Barcode: ${safeItemCode}</p>
                <p style="margin: 5px 0;">Tanggal Perpanjangan: ${dateStr}</p>
                <p style="margin: 5px 0;">Jatuh Tempo Baru: <strong>${safeNewDueDate}</strong></p>
                <hr style="border-top: 1px dashed #000; margin: 10px 0;">
            </div>
            <div style="text-align: center; font-size: 10px;">
                <p style="margin: 5px 0;">Terima kasih telah menggunakan layanan kami</p>
                <p style="margin: 5px 0;">Salam Literasi 🙏</p>
            </div>
        </div>
    `;
    
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    if (!printWindow) {
        showAlert('error', 'Popup diblokir. Izinkan popup untuk mencetak.');
        return;
    }
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Struk Perpanjangan</title>
            <style>
                body { 
                    margin: 0; 
                    padding: 10px;
                    font-family: 'Courier New', monospace;
                    font-size: 12px;
                }
                @media print {
                    body { margin: 0; padding: 0; }
                }
            </style>
        </head>
        <body>
            ${receiptHtml}
            <script>
                window.onload = function() {
                    setTimeout(function() {
                        window.print();
                        setTimeout(window.close, 500);
                    }, 100);
                }
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

    async function handleScan() {
        const itemCodeInput = document.getElementById('itemCode');
        if (!itemCodeInput) return;
        
        const itemCode = itemCodeInput.value.trim();
        
        if (!itemCode) {
            showAlert('error', 'Silakan scan barcode buku terlebih dahulu');
            focusBarcode();
            return;
        }
        
        const choice = confirm('Tambahkan ke keranjang PEMINJAMAN?\nKlik OK untuk Pinjam, Cancel untuk Kembali');
        
        if (choice) {
            await addToBorrowCart(itemCode);
        } else {
            await addToReturnCart(itemCode);
        }
    }

    // ==================== FUNGSI PROSES BORROW ====================
    function processBorrow() {
        const itemCodeInput = document.getElementById('itemCode');
        if (!itemCodeInput) return;
        
        const itemCode = itemCodeInput.value.trim();
        
        if (!itemCode) {
            showAlert('error', 'Silakan scan barcode buku terlebih dahulu');
            focusBarcode();
            return;
        }
        
        // Langsung tambahkan ke keranjang peminjaman tanpa konfirmasi
        addToBorrowCart(itemCode);
    }

    // ==================== EVENT LISTENERS ====================
    const itemCodeInput = document.getElementById('itemCode');
    if (itemCodeInput) {
        itemCodeInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleScan();
            }
        });
    }

    const processBorrowBtn = document.getElementById('processBorrowBtn');
    if (processBorrowBtn) {
        processBorrowBtn.addEventListener('click', processBorrowCart);
    }

    const processReturnBtn = document.getElementById('processReturnBtn');
    if (processReturnBtn) {
        processReturnBtn.addEventListener('click', processReturnCart);
    }

    const resetBtn = document.getElementById('resetCartsBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', resetCarts);
    }

    window.processReturn = function(itemCode) {
        addToReturnCart(itemCode);
    };

    window.extendLoan = extendLoan;

    // ==================== SECURITY ====================
    // document.addEventListener('contextmenu', e => e.preventDefault());

    // document.addEventListener('keydown', (e) => {
    //     const blocked = [
    //         e.key === 'F12',
    //         (e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key)),
    //         (e.ctrlKey && ['u', 's', 'p'].includes(e.key.toLowerCase()))
    //     ];
        
    //     if (blocked.includes(true)) {
    //         e.preventDefault();
    //         return false;
    //     }
    // });



    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Fungsi extendLoan
// Fungsi extendLoan dengan receipt
async function extendLoan(itemCode) {
    if (!itemCode) return;
    
    if (!confirm('Apakah Anda yakin ingin memperpanjang masa pinjam?')) {
        return;
    }
    
    const result = await callApi('extend', itemCode);
    
    if (result.ok) {
        showAlert('success', '✅ Masa pinjam berhasil diperpanjang!');
        
        // Buat data untuk receipt
        const extendData = {
            action: 'extend',
            member_id: '<?= $member['member_id'] ?>',
            member_name: '<?= $member['member_name'] ?>',
            item_code: result.data.item_code,
            title: result.data.title,
            new_due_date: result.data.new_due_date,
            loan_date: new Date().toLocaleDateString('id-ID')
        };
        
        // Panggil fungsi print untuk 1 item
        printSingleExtendReceipt(extendData);
        
        // Reload halaman setelah 2 detik untuk update tampilan
        setTimeout(() => {
            location.reload();
        }, 2000);
    } else {
        showAlert('error', result.msg || 'Gagal memperpanjang masa pinjam');
    }
}

// ==================== SESSION TIMEOUT ====================
// 10 Menit = 600000 milliseconds
const SESSION_TIMEOUT = 600000; 
let sessionTimer;

// Fungsi untuk reset timer session
function resetSessionTimer() {
    if (sessionTimer) {
        clearTimeout(sessionTimer);
    }
    
    // Set timer baru untuk logout otomatis
    sessionTimer = setTimeout(function() {
        // Tampilkan peringatan
        showAlert('warning', 'Sesi akan berakhir karena tidak ada aktivitas...');
        
        // Tunda 1 detik untuk memberi kesempatan user melihat pesan
        setTimeout(function() {
            // Cek apakah masih ada aktivitas? Jika tidak, logout
            performAutoLogout();
        }, 1000);
    }, SESSION_TIMEOUT);
}

// Fungsi untuk melakukan logout otomatis
function performAutoLogout() {
    // Cek apakah user masih aktif? 
    // Jika masih ada mouse movement atau keyboard, cancel logout
    // Tapi untuk keamanan, kita tetap logout
    
    showLoading(true);
    
    // Gunakan AJAX untuk logout
    fetch('<?= SWB ?>index.php?p=self_circulation&page=api', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=logout'
    })
    .then(response => {
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            throw new Error('Response bukan JSON');
        }
    })
    .then(data => {
        showLoading(false);
        // Redirect ke halaman login dengan parameter timeout
        window.location.href = '<?= SWB ?>index.php?p=self_circulation&page=login&timeout=1';
    })
    .catch(error => {
        console.error('Auto logout error:', error);
        showLoading(false);
        // Fallback: redirect langsung
        window.location.href = '<?= SWB ?>index.php?p=self_circulation&page=login&timeout=1';
    });
}

// Event listeners untuk mendeteksi aktivitas user
function initSessionTracking() {
    // Daftar event yang dianggap sebagai aktivitas
    const activityEvents = [
        'mousedown', 'mousemove', 'keydown', 
        'scroll', 'touchstart', 'click', 'keypress',
        'focus', 'input', 'change'
    ];
    
    // Reset timer setiap ada aktivitas
    activityEvents.forEach(eventType => {
        document.addEventListener(eventType, function(e) {
            // Abaikan event dari input itemCode untuk menghindari reset terlalu sering
            // Tapi tetap reset karena ini aktivitas user
            resetSessionTimer();
        }, { passive: true });
    });
    
    // Mulai timer pertama kali
    resetSessionTimer();
}

// Tambahkan juga tracking untuk aktivitas dari input barcode
document.addEventListener('DOMContentLoaded', function() {
    
    
    // Inisialisasi session tracking
    initSessionTracking();
    
    // Override fungsi focusBarcode untuk reset timer
    const originalFocusBarcode = focusBarcode;
    window.focusBarcode = function() {
        originalFocusBarcode();
        resetSessionTimer();
    };
    
    // Override fungsi showAlert untuk reset timer
    const originalShowAlert = showAlert;
    window.showAlert = function(type, message) {
        originalShowAlert(type, message);
        resetSessionTimer();
    };
    
    // Override fungsi scheduleReset
    const originalScheduleReset = scheduleReset;
    window.scheduleReset = function() {
        originalScheduleReset();
        resetSessionTimer();
    };
    
    console.log('Session timeout initialized: 9 seconds');
});

// Reset timer ketika ada interaksi dengan modal
if (typeof bootstrap !== 'undefined') {
    // Modal show event
    document.addEventListener('shown.bs.modal', function() {
        resetSessionTimer();
    });
    
    // Modal hide event
    document.addEventListener('hidden.bs.modal', function() {
        resetSessionTimer();
    });
}

// Visual indicator untuk session timeout (opsional)
function addTimeoutIndicator() {
    const indicator = document.createElement('div');
    indicator.id = 'timeoutIndicator';
    indicator.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: rgba(0,0,0,0.7);
        color: white;
        padding: 8px 15px;
        border-radius: 30px;
        font-size: 12px;
        z-index: 9999;
        display: none;
        align-items: center;
        gap: 8px;
        backdrop-filter: blur(5px);
    `;
    indicator.innerHTML = `
        <i class="fas fa-clock"></i>
        <span>Sesi akan berakhir dalam <span id="timeoutCountdown">9</span> detik</span>
    `;
    document.body.appendChild(indicator);
    
    // Update countdown setiap detik saat timer aktif
    let countdownInterval;
    
    // Override resetSessionTimer untuk menampilkan countdown
    const originalResetTimer = resetSessionTimer;
    window.resetSessionTimer = function() {
        originalResetTimer();
        
        // Tampilkan indicator
        const indicator = document.getElementById('timeoutIndicator');
        const countdownSpan = document.getElementById('timeoutCountdown');
        
        if (indicator && countdownSpan) {
            indicator.style.display = 'flex';
            
            // Clear interval lama
            if (countdownInterval) {
                clearInterval(countdownInterval);
            }
            
            // Set countdown baru
            let timeLeft = 9;
            countdownSpan.textContent = timeLeft;
            
            countdownInterval = setInterval(function() {
                timeLeft--;
                countdownSpan.textContent = timeLeft;
                
                if (timeLeft <= 0) {
                    clearInterval(countdownInterval);
                    indicator.style.display = 'none';
                }
            }, 1000);
            
            // Sembunyikan indicator setelah timer reset (3 detik)
            setTimeout(function() {
                indicator.style.display = 'none';
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                }
            }, 3000);
        }
    };
}

// Aktifkan indicator (opsional, bisa di-comment jika tidak ingin)
// addTimeoutIndicator();
</script>

<?php
$page_title = 'Dashboard - Self Circulation';
$main_content = ob_get_clean();
require __DIR__ . '/../templates/kiosk_layout.inc.php';
exit;
?>