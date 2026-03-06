<?php
// FILE: admin/data_ketidakhadiran/ketidakhadiran.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
// kalau di projectmu nama session admin beda, tinggal ganti baris ini:
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// pastikan yang login admin
if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header("Location: ../../auth/login.php?pesan=tolak_akses");
    exit;
}

require_once __DIR__ . '/../../config.php';

/* =========================================================
   KONEKSI
   ========================================================= */
if (!isset($connection) || !($connection instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $connection = $conn;
    } elseif (isset($koneksi) && $koneksi instanceof mysqli) {
        $connection = $koneksi;
    } else {
        // fallback
        $connection = new mysqli('localhost', 'root', '', 'presensi');
        if ($connection->connect_error) {
            die("Koneksi database gagal: " . $connection->connect_error);
        }
    }
}

/* =========================================================
   FILTER BULAN / TAHUN (opsional)
   ========================================================= */
$bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

if ($bulan < 1 || $bulan > 12) $bulan = (int)date('m');
if ($tahun < 2020 || $tahun > 2100) $tahun = (int)date('Y');

/* =========================================================
   AKSI: setujui / tolak / hapus
   ========================================================= */
$pesan = '';
if (isset($_GET['aksi'], $_GET['id'])) {
    $aksi = $_GET['aksi'];
    $id   = (int)$_GET['id'];

    // ambil dulu datanya (butuh file + id_pegawai)
    $stmt = $connection->prepare("SELECT id, file FROM ketidakhadiran WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resData = $stmt->get_result();
    $dataRow = $resData->fetch_assoc();
    $stmt->close();

    if ($dataRow) {
        if ($aksi === 'setujui') {
            $upd = $connection->prepare("UPDATE ketidakhadiran SET status_pengajuan = 'DISETUJUI' WHERE id = ?");
            $upd->bind_param("i", $id);
            $upd->execute();
            $upd->close();
            $pesan = "Pengajuan berhasil disetujui.";
        } elseif ($aksi === 'tolak') {
            $upd = $connection->prepare("UPDATE ketidakhadiran SET status_pengajuan = 'DITOLAK' WHERE id = ?");
            $upd->bind_param("i", $id);
            $upd->execute();
            $upd->close();
            $pesan = "Pengajuan berhasil ditolak.";
        } elseif ($aksi === 'hapus') {
            // hapus file
            $file = $dataRow['file'] ?? '';
            $del  = $connection->prepare("DELETE FROM ketidakhadiran WHERE id = ?");
            $del->bind_param("i", $id);
            if ($del->execute()) {
                if ($file) {
                    $path = __DIR__ . '/../../assets/uploads/ketidakhadiran/' . $file;
                    if (file_exists($path)) @unlink($path);
                }
                $pesan = "Data pengajuan dihapus.";
            }
            $del->close();
        }
    }

    // biar refresh nggak ngulang aksi
    header("Location: ketidakhadiran.php?bulan=$bulan&tahun=$tahun&msg=" . urlencode($pesan));
    exit;
}

/* =========================================================
   AMBIL DATA KETIDAKHADIRAN + NAMA PEGAWAI
   ========================================================= */
$sql = "
    SELECT k.*, p.nama
    FROM ketidakhadiran k
    JOIN pegawai p ON k.id_pegawai = p.id
    WHERE MONTH(k.tanggal) = ? AND YEAR(k.tanggal) = ?
    ORDER BY k.tanggal DESC, k.id DESC
";

$stmt = $connection->prepare($sql);
$stmt->bind_param("ii", $bulan, $tahun);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

$BASE_URL = function_exists('base_url') ? rtrim(base_url(), '/') . '/' : '/';
$uploadUrl = $BASE_URL . 'assets/uploads/ketidakhadiran/';

$judul = "Data Ketidakhadiran";
require_once __DIR__ . '/../layout/header.php';
?>

<style>
  /* 1. CONTAINER TABEL RESPONSIVE */
  .table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  /* 2. STYLE DASAR TABEL */
  .table {
    border-collapse: collapse; 
    width: 100%;
  }

  .table th, .table td {
    vertical-align: middle;
    white-space: nowrap; /* Mencegah teks turun ke bawah agar bisa di-scroll */
    background-color: #fff;
    border: 1px solid #dee2e6;
    padding: 10px 15px;
  }

  /* 3. LOGIKA STICKY KOLOM PEGAWAI (KOLOM KE-3) */
  @media (max-width: 992px) {
    /* Target kolom ke-3: Nama Pegawai */
    .table th:nth-child(3), 
    .table td:nth-child(3) {
      position: sticky;
      left: 0; /* Menempel di paling kiri saat digeser */
      z-index: 10;
      background-color: #fff;
      border-right: 1px solid #dee2e6; 
    }

    /* Header Nama harus lebih tinggi z-index-nya agar tidak tertimpa */
    .table thead th:nth-child(3) {
      z-index: 11;
      background-color: #f8f9fa !important;
    }
    
    /* Tombol aksi dibuat lebih ramping di mobile */
    .btn-group-sm > .btn, .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
  }

  /* Efek Hover */
  .table tbody tr:hover td,
  .table tbody tr:hover td:nth-child(3) {
    background-color: #f1f5f9;
  }
</style>

<div class="page-body">
    <div class="container-xl">
        <div class="row mb-3">
    <div class="col-12 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center">
        <!-- <h2 class="mb-2 mb-sm-0">Data Ketidakhadiran</h2> -->
        
        <div>
            <a href="ketidakhadiran_admin.php" class="btn btn-primary d-none d-sm-inline-block shadow-sm">
                <i class="fe fe-plus me-2"></i> Ajukan Izin / Cuti
            </a>
            <a href="ketidakhadiran_admin.php" class="btn btn-primary d-sm-none w-100 shadow-sm">
                <i class="fe fe-plus me-2"></i> Ajukan Izin / Cuti
            </a>
        </div>
    </div>
</div>

        <!-- Filter -->
        <div class="card mb-3 shadow-sm">
            <div class="card-body">
                <form class="row g-2 align-items-end" method="get">
                    <div class="col-md-3 col-6">
                        <label class="form-label">Bulan</label>
                        <select name="bulan" class="form-select">
                            <?php for ($i=1; $i<=12; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == $bulan ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0,0,0,$i,1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label">Tahun</label>
                        <select name="tahun" class="form-select">
                            <?php for ($y=date('Y'); $y>=2022; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == $tahun ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-6 mt-2 mt-md-0">
                        <button class="btn btn-primary w-100" type="submit">Tampilkan</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Tabel -->
        <div class="card shadow-sm">
            <div class="card-header">
                <h3 class="card-title mb-0">Daftar Pengajuan</h3>
            </div>
            <div class="table-responsive">
    <table class="table table-vcenter table-striped mb-0">
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th>Tanggal</th>
                <th>Pegawai</th> <th>Jenis</th>
                <th>Deskripsi</th>
                <th class="text-center">Lampiran</th>
                <th class="text-center">Status</th>
                <th class="text-center" style="width: 200px;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">Tidak ada pengajuan pada periode ini.</td>
                </tr>
            <?php else: ?>
                <?php $no=1; foreach ($rows as $r): ?>
                    <?php
                    $status = $r['status_pengajuan'];
                    $badge  = 'secondary';
                    if ($status === 'PENDING')  $badge = 'warning text-dark';
                    if ($status === 'DISETUJUI') $badge = 'success';
                    if ($status === 'DITOLAK')   $badge = 'danger';
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= htmlspecialchars($r['tanggal']) ?></td>
                        <td><strong><?= htmlspecialchars($r['nama']) ?></strong></td>
                        <td><?= htmlspecialchars($r['keterangan']) ?></td>
                        <td class="text-wrap" style="min-width: 150px;"><?= htmlspecialchars($r['deskripsi'] ?? '-') ?></td>
                        <td class="text-center">
                            <?php if (!empty($r['file'])): ?>
                                <a href="<?= $uploadUrl . urlencode($r['file']); ?>" 
                                   target="_blank" 
                                   class="badge bg-primary text-decoration-none py-2 px-3"
                                   title="Klik untuk melihat bukti">
                                    <i class="fa fa-eye me-1"></i> Lihat Lampiran
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($status) ?></span>
                        </td>
                        <td class="text-center">
                            <div class="btn-list justify-content-center">
                                <a href="detail.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-info text-white">Detail</a>
                                
                                <?php if ($status === 'PENDING'): ?>
                                    <a href="ketidakhadiran.php?aksi=setujui&id=<?= (int)$r['id'] ?>&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>"
                                       class="btn btn-sm btn-success"
                                       onclick="return confirm('Setujui pengajuan ini?')">Setujui</a>
                                    
                                    <a href="ketidakhadiran.php?aksi=tolak&id=<?= (int)$r['id'] ?>&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>"
                                       class="btn btn-sm btn-warning"
                                       onclick="return confirm('Tolak pengajuan ini?')">Tolak</a>
                                <?php endif; ?>
                                
                                <a href="ketidakhadiran.php?aksi=hapus&id=<?= (int)$r['id'] ?>&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Hapus permanen pengajuan ini?')">Hapus</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
            <div class="card-footer small text-muted">
                Menampilkan pengajuan bulan <?= date('F', mktime(0,0,0,$bulan,1)) ?> <?= $tahun ?>.
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>



<!-- ui ux telah di perbarui -->
 <!-- responsibilitas Mobile maupun PC -->
  <!-- check logika lain -->