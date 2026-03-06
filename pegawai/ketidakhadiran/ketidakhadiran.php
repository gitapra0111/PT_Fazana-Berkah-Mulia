<?php
// FILE: pegawai/ketidakhadiran/ketidakhadiran.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); exit;
}

require_once __DIR__ . '/../../config.php';

// mapping koneksi
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

$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);

// ambil riwayat
$riwayat = [];
$stmt = $connection->prepare("SELECT * FROM ketidakhadiran WHERE id_pegawai = ? ORDER BY tanggal DESC, id DESC");
$stmt->bind_param("i", $idPegawai);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $riwayat[] = $row;
}
$stmt->close();

$BASE_URL = function_exists('base_url') ? rtrim(base_url(), '/') . '/' : '/';
$uploadUrl = $BASE_URL . 'assets/uploads/ketidakhadiran/';

$judul = "Ketidakhadiran Saya";
require_once __DIR__ . '/../layout/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <div class="row row-cards">
            <div class="col-12 d-flex justify-content-between align-items-center mb-3">
                <h2 class="mb-0">Ketidakhadiran</h2>
                <a href="pengajuan_ketidakhadiran.php" class="btn btn-primary">
                    + Ajukan Ketidakhadiran
                </a>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title">Riwayat Pengajuan</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-vcenter table-nowrap mb-0">
                                <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Jenis</th>
                                    <th>Deskripsi</th>
                                    <th>Lampiran</th>
                                    <th>Status</th>
                                    <th style="width: 140px;">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($riwayat)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">Belum ada pengajuan.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($riwayat as $row): ?>
                                        <?php
                                        $status = $row['status_pengajuan'];
                                        $badge  = 'secondary';
                                        if ($status === 'PENDING')  $badge = 'warning';
                                        if ($status === 'DISETUJUI') $badge = 'success';
                                        if ($status === 'DITOLAK')   $badge = 'danger';
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['tanggal']) ?></td>
                                            <td><?= htmlspecialchars($row['keterangan']) ?></td>
                                            <td><?= htmlspecialchars($row['deskripsi'] ?? '-') ?></td>
                                            <td>
                                                <?php if (!empty($row['file'])): ?>
                                                    <a href="<?= $uploadUrl . urlencode($row['file']); ?>" target="_blank" class="badge bg-secondary">
                                                        Lihat
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-<?= $badge; ?>"><?= htmlspecialchars($status); ?></span></td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="detail_ketidakhadiran.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-info">Detail</a>
                                                    <?php if ($status === 'PENDING'): ?>
                                                        <a href="edit_ketidakhadiran.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                        <button type="button"
                                                                class="btn btn-sm btn-outline-danger"
                                                                onclick="hapusKetidakhadiran(<?= (int)$row['id'] ?>)">
                                                            Hapus
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer small text-muted">
                        Data diurutkan dari tanggal terbaru.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function hapusKetidakhadiran(id) {
    if (!confirm('Yakin menghapus pengajuan ini? Pengajuan yang sudah dihapus tidak bisa dikembalikan.')) return;
    window.location.href = 'hapus_ketidakhadiran.php?id=' + id;
}
</script>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
