<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 19/02/2026
 * @File name           : admin_logs.inc.php
 * @Description         : Halaman log untuk admin
 */

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
    die('can not access this file directly');
}

use SLiMS\DB;

// Cek session admin
if (!isset($_SESSION['uid'])) {
    header('Location: ' . SWB . 'admin/login.php');
    exit;
}

$db = DB::getInstance();

// Ambil log self circulation
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$st = $db->prepare("
    SELECT SQL_CALC_FOUND_ROWS * 
    FROM system_log 
    WHERE sub_module = 'self_circulation' 
    ORDER BY log_date DESC 
    LIMIT ? OFFSET ?
");
$st->execute([$limit, $offset]);
$logs = $st->fetchAll(PDO::FETCH_ASSOC);

$total = $db->query("SELECT FOUND_ROWS()")->fetchColumn();
$totalPages = ceil($total / $limit);

ob_start();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Log Self Circulation</h3>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Member ID</th>
                                <th>Aksi</th>
                                <th>Pesan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars($log['log_date']) ?></td>
                                <td><?= htmlspecialchars($log['id']) ?></td>
                                <td><?= htmlspecialchars($log['action']) ?></td>
                                <td><?= htmlspecialchars($log['log_msg']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link" href="?p=self_circulation_logs&page=<?= $i ?>"><?= $i ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$main_content = ob_get_clean();
$page_title = 'Self Circulation Logs';
require SB . 'admin/inc/header.inc.php';
require SB . 'admin/inc/footer.inc.php';
?>