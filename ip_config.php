<?php
/**
 * Konfigurasi IP untuk Self Circulation
 * Hardcode IP address yang diizinkan
 */

// Daftar IP yang diizinkan (tambahkan sesuai kebutuhan)
$allowed_ips = [
    '127.0.0.1',        // localhost
    '::1',              // localhost IPv6
    '10.10.0.247',      // IP ANDA - Wireless LAN adapter Wi-Fi 2
    '192.168.40.6',     // IP Ethernet adapter Local Area Connection
    '103.171.162.122',    // contoh IP lain (opsional)
    // Tambahkan IP lain di sini jika perlu
];

// Aktifkan pembatasan IP? (true/false)
$enable_ip_restriction = false;  // SEKARANG AKTIFKAN!

// Fungsi untuk mendapatkan IP client (sama seperti sebelumnya)
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

// Fungsi untuk cek akses
function checkIPAccess() {
    global $allowed_ips, $enable_ip_restriction;
    
    if (!$enable_ip_restriction) {
        return true;
    }
    
    $clientIP = getClientIP();
    return in_array($clientIP, $allowed_ips);
}

// Contoh penggunaan
if (checkIPAccess()) {
    echo "Akses diizinkan!";
} else {
    echo "Akses ditolak!";
}
?>