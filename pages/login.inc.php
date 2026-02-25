<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 19/02/2026
 * @File name           : login.inc.php
 * @Description         : Halaman login untuk self circulation
 */

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
    die('can not access this file directly');
}

use SLiMS\DB;

$db = DB::getInstance();

// Redirect ke dashboard jika sudah login
if (isset($_SESSION['self_circ_member'])) {
    header('Location: ' . SWB . 'index.php?p=self_circulation&page=dashboard');
    exit;
}

// Redirect ke dashboard jika sudah login
if (isset($_SESSION['self_circ_member'])) {
    header('Location: ' . SWB . 'index.php?p=self_circulation&page=dashboard');
    exit;
}

// Cek apakah ada parameter timeout
$timeout = isset($_GET['timeout']) && $_GET['timeout'] == 1;
$timeoutMessage = $timeout ? 'Sesi berakhir karena tidak ada aktivitas. Silakan login kembali.' : '';

// Proses login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $member_id = trim($_POST['member_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($member_id) || empty($password)) {
        $error = 'Member ID dan Password harus diisi';
    } else {
        // Validasi member
        $st = $db->prepare("SELECT member_id, member_name, member_email, member_image, pin, mpasswd, expire_date, is_pending 
                            FROM member WHERE member_id = ? LIMIT 1");
        $st->execute([$member_id]);
        $member = $st->fetch(PDO::FETCH_ASSOC);
        
        $today = date('Y-m-d');
        
        if (!$member) {
            $error = 'Member ID tidak ditemukan';
        } elseif ((int)$member['is_pending'] === 1) {
            $error = 'Keanggotaan masih pending';
        } elseif (!empty($member['expire_date']) && $member['expire_date'] < $today) {
            $error = 'Keanggotaan sudah kedaluwarsa';
        } else {
            // Cek password
            $password_ok = false;
            if (!empty($member['mpasswd'])) {
                $password_ok = password_verify($password, $member['mpasswd']);
            } else {
                $password_ok = hash_equals((string)$member['pin'], (string)$password);
            }
            
            if (!$password_ok) {
                $error = 'Password salah';
            } else {
                // Set session login
                $_SESSION['self_circ_member'] = [
                    'member_id' => $member['member_id'],
                    'member_name' => $member['member_name'],
                    'member_email' => $member['member_email'],
                    'member_image' => $member['member_image'],
                    'login_time' => time()
                ];
                
                // Catat log login
                $db->prepare("INSERT INTO system_log (log_type, id, log_location, sub_module, action, log_msg, log_date)
                             VALUES ('member', ?, 'opac', 'self_circulation', 'login', ?, NOW())")
                   ->execute([$member_id, "Member login via self circulation"]);
                
                // Redirect ke dashboard
                header('Location: ' . SWB . 'index.php?p=self_circulation&page=dashboard');
                exit;
            }
        }
    }
}

// Ambil setting library
$libraryName = $sysconf['library_name'] ?? 'Perpustakaan';
$librarySub = $sysconf['library_subname'] ?? '';
$libraryLogo = $sysconf['logo_image'] ?? '';

// Tentukan base URL untuk plugin self_circulation
$plugin_url = SWB . 'plugins/self_circulation/';

// Gambar untuk carousel - menggunakan path lokal
$carouselImages = [
    'slide1.jpg',
    'slide2.jpg',
    'slide3.jpg',
    'slide4.jpg',
];

// Array konten untuk setiap slide
$slideContents = [
    [
        'title' => 'Koleksi Lengkap',
        'description' => 'Tersedia ribuan koleksi buku, jurnal, dan multimedia untuk mendukung kebutuhan belajar dan penelitian Anda'
    ],
    [
        'title' => 'Layanan Mandiri',
        'description' => 'Lakukan peminjaman dan pengembalian buku secara mandiri dengan cepat dan mudah'
    ],
    [
        'title' => 'Ruangan Nyaman',
        'description' => 'Nikmati suasana ruang baca yang nyaman dan kondusif untuk belajar'
    ],
    [
        'title' => 'Layanan Digital',
        'description' => 'Akses koleksi digital dan database jurnal online 24/7 dari mana saja'
    ]
];

ob_start();
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        min-height: 100vh;
        margin: 0;
        padding: 0;
        background: #f5f5f5;
    }

    .login-container {
        display: flex;
        min-height: 100vh;
    }

    /* Left side - Form */
    .form-side {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        position: relative;
        overflow: hidden;
    }

    .form-side::before {
        content: '';
        position: absolute;
        width: 150%;
        height: 150%;
        background: rgba(255, 255, 255, 0.1);
        transform: rotate(45deg);
        top: -25%;
        left: -25%;
        animation: rotate 20s linear infinite;
    }

    @keyframes rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .form-wrapper {
        width: 100%;
        max-width: 450px;
        padding: 40px 30px;
        position: relative;
        z-index: 1;
    }

    .login-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        padding: 40px 30px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: fadeInLeft 0.6s ease-out;
    }

    @keyframes fadeInLeft {
        from {
            opacity: 0;
            transform: translateX(-30px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .library-brand {
        text-align: center;
        margin-bottom: 30px;
    }

    .library-logo {
        width: 100px;
        height: 100px;
        margin: 0 auto 15px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 40px;
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        overflow: hidden;
    }

    .library-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .library-name {
        font-size: 24px;
        font-weight: 700;
        color: #333;
        margin-bottom: 5px;
    }

    .library-sub {
        font-size: 14px;
        color: #666;
    }

    .welcome-text {
        text-align: center;
        margin-bottom: 30px;
    }

    .welcome-text h2 {
        font-size: 28px;
        font-weight: 700;
        color: #333;
        margin-bottom: 10px;
    }

    .welcome-text p {
        color: #666;
        font-size: 14px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: #555;
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
        font-size: 18px;
        z-index: 1;
    }

    .input-wrapper input {
        width: 100%;
        padding: 15px 15px 15px 45px;
        border: 2px solid #eef2f6;
        border-radius: 12px;
        font-size: 16px;
        transition: all 0.3s ease;
        background: white;
    }

    .input-wrapper input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
    }

    .password-toggle {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #999;
        cursor: pointer;
        font-size: 18px;
        padding: 5px;
        z-index: 1;
    }

    .password-toggle:hover {
        color: #667eea;
    }

    .btn-login {
        width: 100%;
        padding: 15px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        border-radius: 12px;
        color: white;
        font-size: 18px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 20px;
        box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
    }

    .btn-login:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 30px rgba(102, 126, 234, 0.4);
    }

    .btn-login:active {
        transform: translateY(0);
    }

    .btn-login i {
        margin-right: 8px;
    }

    .alert {
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }

    .alert-error {
        background: #fee;
        color: #c33;
        border: 1px solid #fcc;
    }

    .alert i {
        font-size: 20px;
    }

    .back-link {
        text-align: center;
        margin-top: 20px;
    }

    .back-link a {
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        font-size: 14px;
        transition: color 0.3s ease;
    }

    .back-link a:hover {
        color: white;
    }

    .back-link a i {
        margin-right: 5px;
    }

    /* Right side - Carousel */
    .carousel-side {
        flex: 1;
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    .carousel-container {
        position: relative;
        width: 100%;
        height: 100%;
    }

    .carousel-slide {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        transition: opacity 0.8s ease-in-out;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
    }

    .carousel-slide.active {
        opacity: 1;
    }

    .carousel-slide::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.7) 0%, rgba(118, 75, 162, 0.7) 100%);
        z-index: 1;
    }

    .slide-content {
        position: absolute;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%);
        text-align: center;
        color: white;
        z-index: 2;
        width: 80%;
        max-width: 500px;
        animation: fadeInUp 1s ease-out;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translate(-50%, 20px);
        }
        to {
            opacity: 1;
            transform: translate(-50%, 0);
        }
    }

    .slide-content h3 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 15px;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
    }

    .slide-content p {
        font-size: 16px;
        line-height: 1.6;
        opacity: 0.9;
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
    }

    .carousel-dots {
        position: absolute;
        bottom: 40px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 12px;
        z-index: 2;
    }

    .dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.5);
        cursor: pointer;
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }

    .dot.active {
        background: white;
        transform: scale(1.2);
        border-color: rgba(255, 255, 255, 0.3);
    }

    .dot:hover {
        background: white;
        transform: scale(1.1);
    }

    .carousel-arrow {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        width: 50px;
        height: 50px;
        background: rgba(255, 255, 255, 0.2);
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        cursor: pointer;
        transition: all 0.3s ease;
        z-index: 2;
        backdrop-filter: blur(5px);
    }

    .carousel-arrow:hover {
        background: rgba(255, 255, 255, 0.3);
        border-color: white;
    }

    .carousel-arrow.left {
        left: 30px;
    }

    .carousel-arrow.right {
        right: 30px;
    }

    .footer-info {
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        text-align: center;
        color: rgba(255, 255, 255, 0.9);
        font-size: 14px;
        z-index: 2;
        width: 100%;
    }

    .header-info {
        position: absolute;
        top: 50px;
        left: 50%;
        transform: translateX(-50%);
        text-align: center;
        color: rgba(255, 255, 255, 0.9);
        font-size: 14px;
        z-index: 2;
        width: 100%;
    }

    .datetime {
        font-family: 'Courier New', monospace;
        background: rgba(255, 255, 255, 0.1);
        padding: 8px 16px;
        border-radius: 50px;
        display: inline-block;
        margin-top: 10px;
    }

    @media (max-width: 768px) {
        .login-container {
            flex-direction: column;
        }
        
        .form-side {
            padding: 40px 20px;
        }
        
        .carousel-side {
            min-height: 300px;
        }
        
        .slide-content {
            bottom: 60px;
        }
        
        .slide-content h3 {
            font-size: 20px;
        }
        
        .slide-content p {
            font-size: 14px;
        }
        
        .carousel-arrow {
            width: 40px;
            height: 40px;
            font-size: 20px;
        }
        
        .carousel-arrow.left {
            left: 15px;
        }
        
        .carousel-arrow.right {
            right: 15px;
        }
    }
</style>
<link rel="stylesheet" href="<?= SWB ?>assets/css/login.css"> <!-- Ini menautkan file login.css -->
<div class="login-container">
    <!-- Left side - Form -->
    <div class="form-side">
        <div class="form-wrapper">
            <div class="login-card">
                <div class="library-brand">
                    <div class="library-logo">
                        <?php if (!empty($libraryLogo) && file_exists('images/default/' . $libraryLogo)): ?>
                            <img src="<?= SWB ?>images/default/<?= rawurlencode($libraryLogo) ?>" alt="Logo">
                        <?php else: ?>
                            <i class="fas fa-book-open"></i>
                        <?php endif; ?>
                    </div>
                    <h1 class="library-name"><?= sc_h($libraryName) ?></h1>
                    <?php if (!empty($librarySub)): ?>
                        <div class="library-sub"><?= sc_h($librarySub) ?></div>
                    <?php endif; ?>
                </div>

                <div class="welcome-text">
                    <h2>Selamat Datang</h2>
                    <h2>Di Layanan Mandiri</h2>
                    <p>Silakan login untuk mengakses layanan sirkulasi mandiri</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= sc_h($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" autocomplete="off">
                    <div class="form-group">
                        <label>Member ID</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" 
                                   name="member_id" 
                                   id="member_id"
                                   placeholder="Masukkan Member ID" 
                                   value="<?= sc_h($_POST['member_id'] ?? '') ?>"
                                   autocomplete="off"
                                   autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" 
                                   name="password" 
                                   id="password"
                                   placeholder="Masukkan Password"
                                   autocomplete="off">
                            <button type="button" class="password-toggle" id="togglePassword" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="login" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i>
                        Login
                    </button>
                </form>
            </div>

            <div class="back-link">
                <a href="<?= SWB ?>index.php"><i class="fas fa-arrow-left"></i> Kembali ke OPAC</a>
            </div>
        </div>
    </div>

    <!-- Right side - Carousel -->
    <div class="carousel-side">
        <div class="carousel-container" id="carousel">
            <?php foreach ($carouselImages as $index => $image): ?>
            <div class="carousel-slide <?= $index === 0 ? 'active' : '' ?>" 
                 style="background-image: url('<?= $plugin_url ?>assets/images/<?= $image ?>');">
                <div class="slide-content">
                    <h3><?= $slideContents[$index]['title'] ?></h3>
                    <p><?= $slideContents[$index]['description'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Navigation Arrows -->
            <div class="carousel-arrow left" id="prevSlide">
                <i class="fas fa-chevron-left"></i>
            </div>
            <div class="carousel-arrow right" id="nextSlide">
                <i class="fas fa-chevron-right"></i>
            </div>

            <!-- Dots Indicator -->
            <div class="carousel-dots" id="carouselDots">
                <?php foreach ($carouselImages as $index => $image): ?>
                <span class="dot <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="header-info">
            <div class="datetime">
                <span id="currentDate"></span> - <span id="currentTime"></span>
            </div>
        </div>
    </div>
</div>

<script>
    // Update waktu realtime
    function updateDateTime() {
        const now = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        };
        
        document.getElementById('currentDate').textContent = 
            now.toLocaleDateString('id-ID', options);
        document.getElementById('currentTime').textContent = 
            now.toLocaleTimeString('id-ID');
    }
    
    setInterval(updateDateTime, 1000);
    updateDateTime();

    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const password = document.getElementById('password');
        const icon = this.querySelector('i');
        
        if (password.type === 'password') {
            password.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            password.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });

    // Auto focus pada input member_id
    document.getElementById('member_id').focus();

    // Carousel functionality
    const slides = document.querySelectorAll('.carousel-slide');
    const dots = document.querySelectorAll('.dot');
    const prevBtn = document.getElementById('prevSlide');
    const nextBtn = document.getElementById('nextSlide');
    let currentSlide = 0;
    let autoSlideInterval;

    function showSlide(index) {
        if (index < 0) index = slides.length - 1;
        if (index >= slides.length) index = 0;
        
        slides.forEach(slide => slide.classList.remove('active'));
        dots.forEach(dot => dot.classList.remove('active'));
        
        slides[index].classList.add('active');
        dots[index].classList.add('active');
        currentSlide = index;
    }

    function nextSlide() {
        showSlide(currentSlide + 1);
    }

    function prevSlide() {
        showSlide(currentSlide - 1);
    }

    // Event listeners untuk tombol
    nextBtn.addEventListener('click', () => {
        nextSlide();
        resetAutoSlide();
    });

    prevBtn.addEventListener('click', () => {
        prevSlide();
        resetAutoSlide();
    });

    // Event listeners untuk dots
    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            showSlide(index);
            resetAutoSlide();
        });
    });

    // Auto slide setiap 5 detik
    function startAutoSlide() {
        autoSlideInterval = setInterval(nextSlide, 5000);
    }

    function resetAutoSlide() {
        clearInterval(autoSlideInterval);
        startAutoSlide();
    }

    // Mulai auto slide
    startAutoSlide();

    // Hentikan auto slide saat mouse di atas carousel
    const carousel = document.getElementById('carousel');
    carousel.addEventListener('mouseenter', () => {
        clearInterval(autoSlideInterval);
    });

    carousel.addEventListener('mouseleave', () => {
        startAutoSlide();
    });

    // Touch events untuk mobile
    let touchStartX = 0;
    let touchEndX = 0;

    carousel.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].screenX;
    });

    carousel.addEventListener('touchend', (e) => {
        touchEndX = e.changedTouches[0].screenX;
        handleSwipe();
    });

    function handleSwipe() {
        const swipeThreshold = 50;
        const diff = touchEndX - touchStartX;
        
        if (Math.abs(diff) > swipeThreshold) {
            if (diff > 0) {
                prevSlide();
            } else {
                nextSlide();
            }
            resetAutoSlide();
        }
    }

    // Prevent right click
    // document.addEventListener('contextmenu', e => e.preventDefault());

    // Block some keyboard shortcuts
    // document.addEventListener('keydown', function(e) {
    //     if (e.key === 'F12' || 
    //         (e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key)) ||
    //         (e.ctrlKey && ['u', 's', 'p'].includes(e.key.toLowerCase()))) {
    //         e.preventDefault();
    //     }
    // });

    // Keyboard navigation untuk carousel
    document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') {
            prevSlide();
            resetAutoSlide();
        } else if (e.key === 'ArrowRight') {
            nextSlide();
            resetAutoSlide();
        }
    });
</script>

<?php
$page_title = 'Login - Self Circulation';
$main_content = ob_get_clean();
require __DIR__ . '/../templates/kiosk_layout.inc.php';
exit;
?>