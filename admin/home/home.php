<?php
declare(strict_types=1);

// =============================
// SESSION ADMIN
// =============================
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (
  !isset($_SESSION['user']['login']) ||
  $_SESSION['user']['login'] !== true ||
  ($_SESSION['user']['role'] ?? '') !== 'admin'
) {
  header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

$admin = $_SESSION['user'];
$judul = "Home Admin";
require_once __DIR__ . '/../layout/header.php';

// =============================
// KONEKSI DB (fallback aman)
// =============================
require_once __DIR__ . '/../../config.php';

$mysqli = null;
if (isset($connection) && $connection instanceof mysqli) $mysqli = $connection;
elseif (isset($conn) && $conn instanceof mysqli)        $mysqli = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli)  $mysqli = $koneksi;

if (!$mysqli) {
  http_response_code(500);
  die("Koneksi database tidak ditemukan. Pastikan config.php mengisi \$connection / \$conn / \$koneksi.");
}

// timezone biar tanggal “hari ini” konsisten
date_default_timezone_set('Asia/Jakarta');
$today = date('Y-m-d');

// =============================
// 1) TOTAL PEGAWAI AKTIF
// =============================
$total_pegawai_aktif = 0;
$stmt = $mysqli->prepare("
  SELECT COUNT(*) AS total
  FROM pegawai p
  JOIN users u ON u.id_pegawai = p.id
  WHERE u.status = 'Aktif'
");
if ($stmt) {
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $total_pegawai_aktif = (int)($res['total'] ?? 0);
  $stmt->close();
}

// =============================
// 2) JUMLAH HADIR (punya presensi masuk)
// =============================
$jumlah_hadir = 0;
$stmt = $mysqli->prepare("
  SELECT COUNT(DISTINCT p.id_pegawai) AS hadir
  FROM presensi p
  JOIN users u ON u.id_pegawai = p.id_pegawai
  WHERE u.status = 'Aktif'
    AND DATE(p.tanggal_masuk) = ?
");
if ($stmt) {
  $stmt->bind_param('s', $today);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $jumlah_hadir = (int)($res['hadir'] ?? 0);
  $stmt->close();
}

// =============================
// 3) JUMLAH SAKIT/IZIN/CUTI (DISETUJUI hari ini)
// =============================
$jumlah_sic_disetujui = 0;
$stmt = $mysqli->prepare("
  SELECT COUNT(DISTINCT k.id_pegawai) AS sic
  FROM ketidakhadiran k
  JOIN users u ON u.id_pegawai = k.id_pegawai
  WHERE u.status = 'Aktif'
    AND k.tanggal = ?
    AND k.status_pengajuan = 'DISETUJUI'
");
if ($stmt) {
  $stmt->bind_param('s', $today);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $jumlah_sic_disetujui = (int)($res['sic'] ?? 0);
  $stmt->close();
}

// =============================
// 4) MENUNGGU (PENDING hari ini) - opsional
// =============================
$jumlah_pending = 0;
$stmt = $mysqli->prepare("
  SELECT COUNT(*) AS pending
  FROM ketidakhadiran k
  JOIN users u ON u.id_pegawai = k.id_pegawai
  WHERE u.status = 'Aktif'
    AND k.tanggal = ?
    AND k.status_pengajuan = 'PENDING'
");
if ($stmt) {
  $stmt->bind_param('s', $today);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $jumlah_pending = (int)($res['pending'] ?? 0);
  $stmt->close();
}

// =============================
// 5) ALPA = aktif - hadir - (SIC disetujui)
// =============================
$jumlah_alpa = max(0, $total_pegawai_aktif - $jumlah_hadir - $jumlah_sic_disetujui);

// =============================
// (opsional) teks kecil biar enak dibaca
// =============================
$label_hadir = $jumlah_hadir . " pegawai";
$label_alpa  = $jumlah_alpa . " pegawai";
$label_sic   = $jumlah_sic_disetujui . " pegawai";
$label_pending = $jumlah_pending . " menunggu";
?>


    <!-- NEW ABSENSI ADMIN -->
<?php 
    // 1. SIAPKAN DATA
    $id_p = $_SESSION['user']['id_pegawai'] ?? 0;
    $tgl_hari_ini = date('Y-m-d');
    
    // Default State (Belum Absen)
    $pesan_sapaan = "Jangan lupa untuk melakukan presensi masuk hari ini.";
    $warna_alert  = "alert-info"; // Biru
    $tombol_text  = "Absen Masuk Sekarang";
    $tombol_url   = base_url('admin/absensi/presensi.php');
    $tombol_class = "btn-primary";
    $tombol_icon  = "fe-camera";
    $status_absen = "belum";

    if ($id_p > 0) {
        // 2. CEK DATABASE HARI INI
        // Kita cek apakah admin ini sudah ada data di tabel presensi hari ini?
        $cek_absen = mysqli_query($mysqli, "SELECT jam_masuk, jam_keluar FROM presensi WHERE id_pegawai = '$id_p' AND tanggal_masuk = '$tgl_hari_ini'");
        $data_absen = mysqli_fetch_assoc($cek_absen);

        if ($data_absen) {
            if ($data_absen['jam_keluar'] == NULL || $data_absen['jam_keluar'] == '00:00:00') {
                // KONDISI: SUDAH MASUK, BELUM PULANG
                $pesan_sapaan = "Selamat bekerja! Anda tercatat masuk pukul <strong>" . $data_absen['jam_masuk'] . "</strong>.";
                $warna_alert  = "alert-warning"; // Kuning/Oranye
                $tombol_text  = "Absen Pulang";
                $tombol_class = "btn-danger"; // Merah
                $tombol_icon  = "fe-log-out";
                $status_absen = "kerja";
            } else {
                // KONDISI: SUDAH SELESAI (MASUK & PULANG LENGKAP)
                $pesan_sapaan = "Terima kasih, presensi Anda hari ini sudah tuntas (Pulang: " . $data_absen['jam_keluar'] . ").";
                $warna_alert  = "alert-success"; // Hijau
                $tombol_text  = "Lihat Riwayat";
                $tombol_url   = base_url('admin/absensi/presensi.php'); // Arahkan ke rekap
                $tombol_class = "btn-success";
                $tombol_icon  = "fe-check-circle";
                $status_absen = "selesai";
            }
        }
    }
?>

<div class="alert <?= $warna_alert ?> d-flex align-items-center justify-content-between shadow-sm">
    <div>
        <h4 class="alert-heading mb-1">Halo, <?= $admin['nama']; ?>!</h4>
        <p class="mb-0"><?= $pesan_sapaan ?></p>
    </div>
    
    <div>
        <?php if ($id_p > 0) : ?>
            <a href="<?= $tombol_url ?>" class="btn <?= $tombol_class ?>">
                <i class="fe <?= $tombol_icon ?> me-2"></i><?= $tombol_text ?>
            </a>
        <?php else : ?>
            <button class="btn btn-secondary" disabled title="ID Pegawai tidak ditemukan">
                <i class="fe fe-alert-circle me-2"></i>ID Bermasalah
            </button>
        <?php endif; ?>
    </div>
</div>

    <!-- NEW ABSENSI ADMIN END -->

    
<div class="row row-cards">
  <div class="col-sm-6 col-lg-3">
    <div class="card p-3">
      <div class="d-flex align-items-center">
        <span class="stamp stamp-md bg-blue mr-3">
          <i class="fe fe-users"></i>
        </span>
        <div>
          <h4 class="m-0"><a href="javascript:void(0)">Total Pegawai <small>Aktif</small></a></h4>
          <small class="text-muted"><?= htmlspecialchars((string)$total_pegawai_aktif) ?> Pegawai</small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card p-3">
      <div class="d-flex align-items-center">
        <span class="stamp stamp-md bg-green mr-3">
          <i class="fe fe-check-circle"></i>
        </span>
        <div>
          <h4 class="m-0"><a href="javascript:void(0)">Jumlah <small>Hadir</small></a></h4>
          <small class="text-muted"><?= htmlspecialchars($label_hadir) ?></small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card p-3">
      <div class="d-flex align-items-center">
        <span class="stamp stamp-md bg-red mr-3">
          <i class="fe fe-x-circle"></i>
        </span>
        <div>
          <h4 class="m-0"><a href="javascript:void(0)">Jumlah <small>Alpa</small></a></h4>
          <small class="text-muted"><?= htmlspecialchars($label_alpa) ?></small>
        </div>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card p-3">
      <div class="d-flex align-items-center">
        <span class="stamp stamp-md bg-yellow mr-3">
          <i class="fe fe-alert-circle"></i>
        </span>
        <div>
          <h4 class="m-0">
            <a href="javascript:void(0)">Sakit, Izin, Cuti <small>Disetujui</small></a>
          </h4>
          <small class="text-muted">
            <?= htmlspecialchars($label_sic) ?>
            <?php if ($jumlah_pending > 0): ?>
              • <span class="text-warning"><?= htmlspecialchars($label_pending) ?></span>
            <?php endif; ?>
          </small>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (isset($_SESSION['berhasil'])) : ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: 'Berhasil!',
                text: '<?= $_SESSION['berhasil'] ?>',
                icon: 'success',
                confirmButtonColor: '#3085d6'
            });
        });
    </script>
    <?php unset($_SESSION['berhasil']); ?> 
<?php endif; ?>

<?php include __DIR__ . '/../layout/footer.php'; ?>


<!-- mengaktifkan semua logic sistem -->