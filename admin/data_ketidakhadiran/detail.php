<?php
// FILE: admin/data_ketidakhadiran/detail.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header("Location: ../../auth/login.php?pesan=tolak_akses");
    exit;
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
        if ($connection->connect_error) {
            die("Koneksi database gagal: " . $connection->connect_error);
        }
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

if ($id <= 0) {
    header("Location: ketidakhadiran.php"); exit;
}

/* =========================================================
   AMBIL DATA KETIDAKHADIRAN + NAMA PEGAWAI
   ========================================================= */
$stmt = $connection->prepare("
    SELECT k.*, p.nama, p.nip
    FROM ketidakhadiran k
    JOIN pegawai p ON k.id_pegawai = p.id
    WHERE k.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    die("Data ketidakhadiran tidak ditemukan.");
}

$BASE_URL = function_exists('base_url') ? rtrim(base_url(), '/') . '/' : '/';
$uploadUrl = $BASE_URL . 'assets/uploads/ketidakhadiran/';

/* =========================================================
   PROSES SETUJUI / TOLAK (POST)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi'])) {
    $aksi = $_POST['aksi'];
    if ($aksi === 'setujui') {
        $upd = $connection->prepare("UPDATE ketidakhadiran SET status_pengajuan = 'DISETUJUI' WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        $upd->close();
        header("Location: ketidakhadiran.php?bulan=$bulan&tahun=$tahun&msg=" . urlencode('Pengajuan disetujui.'));
        exit;
    } elseif ($aksi === 'tolak') {
        $upd = $connection->prepare("UPDATE ketidakhadiran SET status_pengajuan = 'DITOLAK' WHERE id = ?");
        $upd->bind_param("i", $id);
        $upd->execute();
        $upd->close();
        header("Location: ketidakhadiran.php?bulan=$bulan&tahun=$tahun&msg=" . urlencode('Pengajuan ditolak.'));
        exit;
    }
}

$judul = "Detail Ketidakhadiran";
require_once __DIR__ . '/../layout/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Detail Ketidakhadiran</h3>
                        <a href="ketidakhadiran.php?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="btn btn-sm btn-secondary">
                            Kembali
                        </a>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">Pegawai</dt>
                            <dd class="col-sm-8">
                                <?= htmlspecialchars($data['nama']) ?>
                                <?php if (!empty($data['nip'])): ?>
                                    <div class="text-muted small">NIP: <?= htmlspecialchars($data['nip']) ?></div>
                                <?php endif; ?>
                            </dd>

                            <dt class="col-sm-4">Tanggal</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($data['tanggal']) ?></dd>

                            <dt class="col-sm-4">Jenis</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($data['keterangan']) ?></dd>

                            <dt class="col-sm-4">Deskripsi</dt>
                            <dd class="col-sm-8"><?= $data['deskripsi'] ? nl2br(htmlspecialchars($data['deskripsi'])) : '<span class="text-muted">-</span>' ?></dd>

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

                            <dt class="col-sm-4">Status Pengajuan</dt>
                            <dd class="col-sm-8">
                                <?php
                                $status = $data['status_pengajuan'];
                                $badge  = 'secondary';
                                if ($status === 'PENDING')  $badge = 'warning';
                                if ($status === 'DISETUJUI') $badge = 'success';
                                if ($status === 'DITOLAK')   $badge = 'danger';
                                ?>
                                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($status) ?></span>
                            </dd>
                        </dl>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <?php if ($data['status_pengajuan'] === 'PENDING'): ?>
                            <form method="post" onsubmit="return confirm('Setujui pengajuan ini?')">
                                <input type="hidden" name="aksi" value="setujui">
                                <button type="submit" class="btn btn-success">Setujui</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Tolak pengajuan ini?')">
                                <input type="hidden" name="aksi" value="tolak">
                                <button type="submit" class="btn btn-warning">Tolak</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted small">Pengajuan sudah diproses.</span>
                        <?php endif; ?>
                        <form method="get" action="ketidakhadiran.php" class="ms-auto">
                            <input type="hidden" name="bulan" value="<?= $bulan ?>">
                            <input type="hidden" name="tahun" value="<?= $tahun ?>">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Kembali ke daftar</button>
                        </form>
                    </div>
                </div>

                <div class="alert alert-info mt-3">
                    Halaman ini hanya menampilkan satu pengajuan. Untuk menghapus data gunakan daftar utama.
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
