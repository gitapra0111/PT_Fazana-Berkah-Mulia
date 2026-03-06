<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); exit;
}

require_once __DIR__ . '/../../config.php';

// koneksi
if (!isset($connection) || !($connection instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $connection = $conn;
    } elseif (isset($koneksi) && $koneksi instanceof mysqli) {
        $connection = $koneksi;
    } else {
        $connection = new mysqli('localhost', 'root', '', 'presensi');
    }
}

$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $connection->prepare("SELECT * FROM ketidakhadiran WHERE id = ? AND id_pegawai = ? LIMIT 1");
$stmt->bind_param("ii", $id, $idPegawai);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    die("Data ketidakhadiran tidak ditemukan / bukan milik Anda.");
}

$BASE_URL = function_exists('base_url') ? rtrim(base_url(), '/') . '/' : '/';
$uploadUrl = $BASE_URL . 'assets/uploads/ketidakhadiran/';

$judul = "Detail Ketidakhadiran";
require_once __DIR__ . '/../layout/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Detail Ketidakhadiran</h3>
                        <a href="ketidakhadiran.php" class="btn btn-sm btn-secondary">Kembali</a>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">Tanggal</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($data['tanggal']) ?></dd>

                            <dt class="col-sm-4">Jenis</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($data['keterangan']) ?></dd>

                            <dt class="col-sm-4">Deskripsi</dt>
                            <dd class="col-sm-8"><?= nl2br(htmlspecialchars($data['deskripsi'] ?? '-')) ?></dd>

                            <dt class="col-sm-4">Lampiran</dt>
                            <dd class="col-sm-8">
                                <?php if (!empty($data['file'])): ?>
                                    <a href="<?= $uploadUrl . urlencode($data['file']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        Lihat Lampiran
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Tidak ada</span>
                                <?php endif; ?>
                            </dd>

                            <dt class="col-sm-4">Status</dt>
                            <dd class="col-sm-8">
                                <?php
                                $badge = 'secondary';
                                if ($data['status_pengajuan'] === 'PENDING') $badge = 'warning';
                                if ($data['status_pengajuan'] === 'DISETUJUI') $badge = 'success';
                                if ($data['status_pengajuan'] === 'DITOLAK') $badge = 'danger';
                                ?>
                                <span class="badge bg-<?= $badge; ?>"><?= htmlspecialchars($data['status_pengajuan']); ?></span>
                            </dd>
                        </dl>
                    </div>
                    <div class="card-footer small text-muted">
                        ID: <?= (int)$data['id'] ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
