<?php
/**
 * Plugin Name: Self Circulation
 * Plugin URI: https://github.com/erwansetyobudi/self_circulation
 * Description: Self Circulation System untuk anggota dengan notifikasi email
 * Version: 2.0.0
 * Author: Erwan Setyo Budi
 * Author URI: https://github.com/erwansetyobudi/
 */

use SLiMS\Plugins;
use SLiMS\Url;
use SLiMS\DB;

// ==================== CEK JIKA INI REQUEST GAMBAR ====================
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($request_uri, 'lib/minigalnano/') !== false) {
    // JANGAN LAKUKAN APAPUN, biarkan gambar berjalan normal
    return;
}

// ==================== CEK JIKA INI BUKAN HALAMAN PLUGIN ====================
$current_page = $_GET['p'] ?? '';
$current_module = $_GET['mod'] ?? '';
$is_plugin_page = ($current_page === 'self_circulation' || $current_module === 'self_circulation');

// Jika bukan halaman plugin, JANGAN load kode plugin
if (!$is_plugin_page) {
    return;
}

$plugins = Plugins::getInstance();

// ==================== FUNGSI CEK IP ====================
function getClientIP() {
    $ipaddress = '';
    
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if(isset($_SERVER['REMOTE_ADDR']))
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = 'UNKNOWN';
    
    return $ipaddress;
}

function showAccessDenied($clientIP) {
    http_response_code(403);
    die("
    <!DOCTYPE html>
    <html>
    <head><title>Akses Ditolak</title>
    <style>
        body { font-family: Arial; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; max-width: 400px; }
        h1 { color: #dc3545; margin-bottom: 20px; }
        p { color: #666; line-height: 1.6; }
        .ip { background: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; margin: 20px 0; }
    </style>
    </head>
    <body>
        <div class='container'>
            <h1>⛔ Akses Ditolak</h1>
            <p>IP Address Anda tidak terdaftar untuk mengakses halaman ini.</p>
            <div class='ip'>IP Anda: $clientIP</div>
            <p>Silakan hubungi administrator perpustakaan untuk mendapatkan akses.</p>
        </div>
    </body>
    </html>
    ");
}

// ==================== CEK AKSES IP ====================
// Konfigurasi IP
$allowed_ips = [
    '127.0.0.1',
    '::1',
    '10.10.0.247',      // IP ANDA
];
$enable_ip_restriction = false;

// Cek akses IP
$clientIP = getClientIP();

if ($enable_ip_restriction && !in_array($clientIP, $allowed_ips)) {
    showAccessDenied($clientIP);
}

// ==================== FUNGSI KIRIM NOTIFIKASI ====================
function sendTransactionNotification($memberId, $transactionType, $itemData) {
    try {
        $db = DB::getInstance();
        
        // Ambil data member
        $stmt = $db->prepare("SELECT member_id, member_name, member_email FROM member WHERE member_id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$member || empty($member['member_email'])) {
            error_log("Self Circulation: Member email not found for ID: " . $memberId);
            return false;
        }
        
        // Build email content
        $libraryName = config('library_name') ?? 'Perpustakaan';
        $subject = "[$libraryName] Notifikasi Transaksi " . ucfirst($transactionType);
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                .footer { background: #f0f0f0; padding: 15px; text-align: center; font-size: 12px; border-radius: 0 0 10px 10px; }
                .book-detail { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #667eea; }
                .label { font-weight: bold; color: #555; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>$libraryName</h2>
                    <p>Self Circulation System</p>
                </div>
                <div class='content'>
                    <p>Halo <strong>{$member['member_name']}</strong>,</p>
                    <p>Berikut adalah detail transaksi {$transactionType} Anda:</p>
                    
                    <div class='book-detail'>
                        <p><span class='label'>Judul Buku:</span> {$itemData['title']}</p>
                        <p><span class='label'>Kode Buku:</span> {$itemData['item_code']}</p>
                        <p><span class='label'>Tanggal {$transactionType}:</span> {$itemData['transaction_date']}</p>
        ";
        
        if ($transactionType === 'peminjaman' && isset($itemData['due_date'])) {
            $message .= "<p><span class='label'>Jatuh Tempo:</span> {$itemData['due_date']}</p>";
        }
        
        if ($transactionType === 'pengembalian' && isset($itemData['fine'])) {
            $message .= "<p><span class='label'>Denda:</span> Rp " . number_format($itemData['fine'], 0, ',', '.') . "</p>";
        }
        
        $message .= "
                    </div>
                    <p>Terima kasih telah menggunakan layanan Self Circulation.</p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " $libraryName. All rights reserved.</p>
                    <p>Email ini dikirim otomatis, mohon tidak membalas.</p>
                </div>
            </div>
        </html>
        ";
        
        // Kirim email menggunakan SLiMS Mail
        if (class_exists('\SLiMS\Mail')) {
            \SLiMS\Mail::to($member['member_email'], $member['member_name'])
                ->subject($subject)
                ->message($message)
                ->send();
            
            error_log("Self Circulation: Email sent to {$member['member_email']} for {$transactionType}");
            return true;
        }
        
    } catch (Exception $e) {
        error_log("Self Circulation Email Error: " . $e->getMessage());
        return false;
    }
}

// ==================== REGISTER HOOK ====================
// Hook untuk mendeteksi transaksi selesai
$plugins->register(Plugins::CIRCULATION_AFTER_SUCCESSFUL_TRANSACTION, function($payload) {
    error_log("Self Circulation: Hook fired - " . json_encode($payload));
    
    // Normalisasi payload
    $data = null;
    if (is_array($payload) && isset($payload['data'])) {
        $data = $payload['data'];
    } elseif (is_array($payload) && (isset($payload['loan']) || isset($payload['return']) || isset($payload['extend']))) {
        $data = $payload;
    }
    
    if (!$data) {
        error_log("Self Circulation: No valid payload data");
        return;
    }
    
    $memberId = $data['memberID'] ?? null;
    if (!$memberId) {
        error_log("Self Circulation: No member ID in payload");
        return;
    }
    
    // Handle peminjaman
    if (!empty($data['loan'])) {
        foreach ($data['loan'] as $loan) {
            sendTransactionNotification($memberId, 'peminjaman', [
                'title' => $loan['title'] ?? 'Unknown',
                'item_code' => $loan['itemCode'] ?? $loan['item_code'] ?? 'Unknown',
                'transaction_date' => $loan['loanDate'] ?? $loan['loan_date'] ?? date('Y-m-d H:i:s'),
                'due_date' => $loan['dueDate'] ?? $loan['due_date'] ?? ''
            ]);
        }
    }
    
    // Handle pengembalian
    if (!empty($data['return'])) {
        foreach ($data['return'] as $return) {
            sendTransactionNotification($memberId, 'pengembalian', [
                'title' => $return['title'] ?? 'Unknown',
                'item_code' => $return['itemCode'] ?? $return['item_code'] ?? 'Unknown',
                'transaction_date' => $return['returnDate'] ?? $return['return_date'] ?? date('Y-m-d H:i:s'),
                'fine' => $return['overdues']['value'] ?? 0
            ]);
        }
    }
    
    // Handle perpanjangan
    if (!empty($data['extend'])) {
        foreach ($data['extend'] as $extend) {
            sendTransactionNotification($memberId, 'perpanjangan', [
                'title' => $extend['title'] ?? 'Unknown',
                'item_code' => $extend['itemCode'] ?? $extend['item_code'] ?? 'Unknown',
                'transaction_date' => date('Y-m-d H:i:s'),
                'due_date' => $extend['new_due_date'] ?? ''
            ]);
        }
    }
});

// ==================== REGISTER MENU ====================
$plugins->registerMenu('opac', 'self_circulation', __DIR__ . '/pages/kiosk.inc.php');
$plugins->registerMenu('membership', 'self_circulation', __('Self Circulation'));

// ==================== DEBUG ====================
error_log("Self Circulation Plugin v2.0.0 Loaded - Page: $current_page, Module: $current_module");
