<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// butuh base_url()
require_once __DIR__ . '/../../config.php';

/* =========================================================
   KONEKSI DB
   ========================================================= */
$db = null;
if (isset($connection) && $connection instanceof mysqli)        $db = $connection;
elseif (isset($conn) && $conn instanceof mysqli)                $db = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli)          $db = $koneksi;

// Helper untuk deteksi menu aktif (Active State)
if (!function_exists('is_active')) {
    function is_active($uri_keyword) {
        // Cek apakah URL di browser mengandung kata kunci tertentu
        return (strpos($_SERVER['REQUEST_URI'], $uri_keyword) !== false) ? 'active' : '';
    }
}

// guard akses admin
if (
    !(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true) ||
    ( $_SESSION['user']['role'] ?? '' ) !== 'admin'
) {
    header('Location: ' . base_url('auth/login.php?pesan=tolak_akses'));
    exit;
}

// supaya $judul dari halaman bisa dipakai
global $judul;

// helper kecil
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* =========================================================
   AMBIL INFO USER DARI SESSION (ADMIN YG LOGIN)
   ========================================================= */
$nama_user    = $_SESSION['user']['nama']     ?? 'Admin User';
$username     = $_SESSION['user']['username'] ?? 'admin';
$role_user    = 'Administrator';
$foto_session = $_SESSION['user']['foto']     ?? '';

// Ambil ID User dari session
$id_user_admin = (int)($_SESSION['user']['id'] ?? 0);

if ($db && $id_user_admin > 0) {
    // 1. Siapkan Query
    $sql_live = "SELECT u.username, p.nama, p.foto 
                 FROM users u 
                 LEFT JOIN pegawai p ON u.id_pegawai = p.id 
                 WHERE u.id = ? LIMIT 1";
                 
    $stmt_live = $db->prepare($sql_live);
    $stmt_live->bind_param('i', $id_user_admin);
    $stmt_live->execute();
    $res_live = $stmt_live->get_result()->fetch_assoc();
    
    if ($res_live) {
        // 2. Timpa variabel session dengan data asli DB
        // Gunakan nama pegawai jika ada, jika tidak pakai username
        $nama_user    = !empty($res_live['nama']) ? $res_live['nama'] : $res_live['username'];
        $foto_session = (string)$res_live['foto'];
        
        // 3. Update Session agar sinkron di semua halaman
        $_SESSION['user']['nama'] = $nama_user;
        $_SESSION['user']['foto'] = $foto_session;
    }
    $stmt_live->close();
} 

// 1. Set default avatar (Inisial)
$defaultAvatarAdmin = "https://ui-avatars.com/api/?name=" . urlencode($nama_user) . "&background=0d6efd&color=fff";
$foto_url = $defaultAvatarAdmin;

// 2. Bersihkan nama file (basename) untuk keamanan
$cleanFotoFn = basename((string)$foto_session);
$foto_abs = __DIR__ . '/../../assets/images/foto_pegawai/' . $cleanFotoFn;

// 3. Cek apakah file fisik benar-benar ada di folder
if ($foto_session !== '' && is_file($foto_abs)) {
    // Tambahkan '?t=' . time() agar browser dipaksa mengunduh ulang foto terbaru
    $foto_url = base_url('assets/images/foto_pegawai/' . $cleanFotoFn) . '?t=' . time();
}

$notifs       = [];
$totalPending = 0;

if ($db) {
    // hitung pending
    $sqlCount = "SELECT COUNT(*) AS jml FROM ketidakhadiran WHERE status_pengajuan = 'PENDING'";
    $resC = $db->query($sqlCount);
    if ($resC && $rowC = $resC->fetch_assoc()) {
        $totalPending = (int)$rowC['jml'];
    }

    /* ---------------------------------------------------------
       ambil daftar pending terbaru + FOTO PEGAWAI
       catatan: aku ambil p.foto. Kalau di tabelmu namanya beda
       (misal: foto_profil / gambar / avatar) tinggal ganti sini.
       --------------------------------------------------------- */
    $sqlNotif = "
        SELECT 
            k.id,
            k.keterangan,
            k.created_at,
            p.nama,
            p.foto AS foto_pegawai
        FROM ketidakhadiran k
        JOIN pegawai p ON k.id_pegawai = p.id
        WHERE k.status_pengajuan = 'PENDING'
        ORDER BY k.created_at DESC
        LIMIT 5
    ";
    $resN = $db->query($sqlNotif);
    if ($resN) {
        while ($r = $resN->fetch_assoc()) {
            // Default: Gunakan inisial nama pegawai
            $fotoPegawaiUrl = "https://ui-avatars.com/api/?name=" . urlencode($r['nama']) . "&background=random&color=fff&size=64";
            
            if (!empty($r['foto_pegawai'])) {
                $absP = __DIR__ . '/../../assets/images/foto_pegawai/' . basename($r['foto_pegawai']);
                if (is_file($absP)) {
                    $fotoPegawaiUrl = base_url('assets/images/foto_pegawai/' . basename($r['foto_pegawai'])) . '?t=' . time();
                }
            }
            $r['foto_url'] = $fotoPegawaiUrl;
            $notifs[] = $r;
        }
    }
}


?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($judul) ? h($judul) : 'Dashboard'; ?></title>
  <link rel="icon" href="<?= base_url('favicon.ico'); ?>" type="image/x-icon"/>

  <!-- Fonts & Icons -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">

  <!-- CSS Lokal -->
  <link href="<?= base_url('assets/css/dashboard.css'); ?>" rel="stylesheet" />
  <link href="<?= base_url('assets/plugins/charts-c3/plugin.css'); ?>" rel="stylesheet" />
  <link href="<?= base_url('assets/plugins/maps-google/plugin.css'); ?>" rel="stylesheet" />

  <!-- JS eksternal (butuh buat dropdown) -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.16.1/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

  <style>
    .avatar {
      background-size: cover;
      background-position: center;
    }
  </style>

  <style>
    /* Agar menu yang sedang aktif berwarna biru */
    .header .nav-tabs .nav-link.active {
        border-bottom: 2px solid #0d6efd;
        color: #0d6efd;
        font-weight: 600;
    }
    .avatar {
        background-size: cover;
        background-position: center;
    }
</style>
</head>
<body class="">
  <div class="page">
    <div class="page-main">
      <!-- Header atas -->
      <div class="header py-4">
        <div class="container">
          <div class="d-flex">
            <a class="header-brand" href="<?= base_url('admin/home/home.php'); ?>">
              <img src="<?= base_url('assets/images/logoFix.svg'); ?>" class="header-brand-img" alt="Logo">
            </a>

            <div class="d-flex order-lg-2 ml-auto">
              <!-- Dropdown Notifikasi -->
              <div class="dropdown d-none d-md-flex">
                <a class="nav-link icon" data-toggle="dropdown" href="#">
                  <i class="fe fe-bell"></i>
                  <?php if ($totalPending > 0): ?>
                    <span class="nav-unread"></span>
                  <?php endif; ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right dropdown-menu-arrow">
                  <?php if ($totalPending === 0): ?>
                    <span class="dropdown-item text-muted">Tidak ada notifikasi</span>
                  <?php else: ?>
                    <?php foreach ($notifs as $n): ?>
                      <a href="<?= base_url('admin/data_ketidakhadiran/ketidakhadiran.php'); ?>" class="dropdown-item d-flex">
                        <span class="avatar mr-3 align-self-center"
                              style="background-image: url('<?= h($n['foto_url']); ?>')"></span>
                        <div>
                          <strong><?= h($n['nama']); ?></strong>
                          mengajukan <?= h($n['keterangan']); ?>
                          <div class="small text-muted">
                            <?= h($n['created_at']); ?>
                          </div>
                        </div>
                      </a>
                    <?php endforeach; ?>
                    <div class="dropdown-divider"></div>
                    <a href="<?= base_url('admin/data_ketidakhadiran/ketidakhadiran.php'); ?>" class="dropdown-item text-center text-muted-dark">
                      Lihat semua (<?= $totalPending; ?>) pengajuan
                    </a>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Dropdown Profil -->
              <div class="dropdown">
                <a href="#" class="nav-link pr-0 leading-none" data-toggle="dropdown">
                  <span class="avatar" style="background-image: url('<?= h($foto_url); ?>')"></span>
                  <span class="ml-2 d-none d-lg-block">
                    <span class="text-default"><?= h($nama_user); ?></span>
                    <small class="text-muted d-block mt-1"><?= h($role_user); ?></small>
                  </span>
                </a>
                <div class="dropdown-menu dropdown-menu-right dropdown-menu-arrow">
                  <a class="dropdown-item" href="<?= base_url('admin/home/profile.php'); ?>">
                    <i class="dropdown-icon fe fe-user"></i> Profile
                  </a>
                  <a class="dropdown-item" href="<?= base_url('admin/home/settings.php'); ?>">
                    <i class="dropdown-icon fe fe-settings"></i> Settings
                  </a>
                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item" href="<?= base_url('auth/logout.php'); ?>">
                    <i class="dropdown-icon fe fe-log-out"></i> Logout
                  </a>
                </div>
              </div>
            </div>

            <a href="#" class="header-toggler d-lg-none ml-3 ml-lg-0" data-toggle="collapse" data-target="#headerMenuCollapse">
              <span class="header-toggler-icon"></span>
            </a>
          </div>
        </div>
      </div>

      <!-- Header menu -->
      <div class="header collapse d-lg-flex p-0" id="headerMenuCollapse">
        <div class="container">
          <div class="row align-items-center">
            <div class="col-lg order-lg-first">
              <ul class="nav nav-tabs border-0 flex-column flex-lg-row">
                <li class="nav-item">
                    <a href="<?= base_url('admin/home/home.php'); ?>" class="nav-link <?= is_active('home.php') ?>">
                        <i class="fe fe-home"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= base_url('admin/data_pegawai/pegawai.php'); ?>" class="nav-link <?= is_active('pegawai.php') ?>">
                        <i class="fa fa-users"></i> Pegawai
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a href="javascript:void(0)" class="nav-link <?= (is_active('jabatan') || is_active('lokasi')) ? 'active' : '' ?>" data-toggle="dropdown">
                        <i class="fe fe-box"></i> Master Data
                    </a>
                    <div class="dropdown-menu dropdown-menu-arrow">
                        <a href="<?= base_url('admin/data_jabatan/jabatan.php'); ?>" class="dropdown-item <?= is_active('jabatan') ?>">Jabatan</a>
                        <a href="<?= base_url('admin/data_lokasi_presensi/lokasi_presensi.php'); ?>" class="dropdown-item <?= is_active('lokasi') ?>">Lokasi Presensi</a>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a href="javascript:void(0)" class="nav-link <?= (is_active('rekap_harian') || is_active('rekap_bulanan')) ? 'active' : '' ?>" data-toggle="dropdown">
                        <i class="fe fe-calendar"></i> Rekap Presensi
                    </a>
                    <div class="dropdown-menu dropdown-menu-arrow">
                        <a href="<?= base_url('admin/presensi/rekap_harian.php'); ?>" class="dropdown-item <?= is_active('rekap_harian') ?>">Harian</a>
                        <a href="<?= base_url('admin/presensi/rekap_bulanan.php'); ?>" class="dropdown-item <?= is_active('rekap_bulanan') ?>">Bulanan</a>
                    </div>
                </li>
                <li class="nav-item">
                    <a href="<?= base_url('admin/data_ketidakhadiran/ketidakhadiran.php'); ?>" class="nav-link <?= is_active('ketidakhadiran') ?>">
                        <i class="fe fe-check-square"></i> Ketidakhadiran
                        <?php if ($totalPending > 0): ?>
                            <span class="badge badge-danger ml-2"><?= $totalPending ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= base_url('auth/logout.php'); ?>" class="nav-link">
                        <i class="fa fa-sign-out"></i> Logout
                    </a>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Judul halaman -->
      <div class="my-3 my-md-5">
        <div class="container">
          <div class="page-header">
            
            <h1 class="page-title"><?= $judul ?? 'Judul Halaman'; ?></h1>

          </div>


          <!-- uji coba untuk mencari bug system -->
           <!-- notifikasi done -->