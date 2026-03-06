<?php
declare(strict_types=1);

// ====== INCLUDE GUARD: cegah header.php dieksekusi dua kali ======
if (defined('PEGAWAI_LAYOUT_HEADER_INCLUDED')) {
    return;
}
define('PEGAWAI_LAYOUT_HEADER_INCLUDED', true);

// Start session hanya jika belum aktif
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
    session_start();
}

// Pastikan base_url() tersedia
require_once __DIR__ . '/../../config.php';


// Helper untuk deteksi menu aktif
if (!function_exists('is_active')) {
    function is_active($uri_keyword) {
        // Cek apakah URL di browser mengandung kata kunci tertentu
        return (strpos($_SERVER['REQUEST_URI'], $uri_keyword) !== false) ? 'active' : '';
    }
}

// --- 1. INISIALISASI VARIABEL AWAL ---
$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
$nama      = $_SESSION['user']['nama'] ?? 'Pengguna';
$role      = $_SESSION['user']['role'] ?? 'User';
$fotoFn    = $_SESSION['user']['foto'] ?? '';

$mysqli = $connection ?? $conn ?? $koneksi ?? null;

$notifications = []; // Inisialisasi di ATAS sebelum query
$notifPending  = 0;

// --- 2. LIVE UPDATE & NOTIFIKASI (Satu Blok Database) ---
if ($mysqli && $idPegawai > 0) {
    
    // A. Ambil Data Pegawai Terbaru (Kunci agar foto langsung berubah)
    $stmtUser = $mysqli->prepare("SELECT nama, foto FROM pegawai WHERE id = ? LIMIT 1");
    $stmtUser->bind_param('i', $idPegawai);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    if ($rowUser = $resUser->fetch_assoc()) {
        $nama   = $rowUser['nama'];
        $fotoFn = $rowUser['foto'];
        // Update session agar sinkron ke halaman lain
        $_SESSION['user']['nama'] = $nama;
        $_SESSION['user']['foto'] = $fotoFn;
    }
    $stmtUser->close();

    // B. Ambil Notifikasi
    $sqlNotif = "SELECT id, keterangan, tanggal, status_pengajuan, created_at
                 FROM ketidakhadiran
                 WHERE id_pegawai = ?
                 ORDER BY created_at DESC
                 LIMIT 5";
    $stmtN = $mysqli->prepare($sqlNotif);
    $stmtN->bind_param('i', $idPegawai);
    $stmtN->execute();
    $resN = $stmtN->get_result();
    while ($rowN = $resN->fetch_assoc()) {
        $notifications[] = $rowN;
        if ($rowN['status_pengajuan'] === 'PENDING') {
            $notifPending++;
        }
    }
    $stmtN->close();
}

// --- 3. SIAPKAN AVATAR URL ---
$avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($nama) . "&background=0d6efd&color=fff";
$pathFoto  = __DIR__ . '/../../assets/images/foto_pegawai/' . basename((string)$fotoFn);

if (!empty($fotoFn) && is_file($pathFoto)) {
    // Tambahkan '?t=time()' agar browser tidak menyimpan cache foto lama
    $avatarUrl = base_url('assets/images/foto_pegawai/' . basename($fotoFn)) . '?t=' . time();
}

?>
<!doctype html>
<html lang="en" dir="ltr">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta http-equiv="Content-Language" content="en" />
    <meta name="msapplication-TileColor" content="#2d89ef">
    <meta name="theme-color" content="#4188c9">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="HandheldFriendly" content="True">
    <meta name="MobileOptimized" content="320">
    <link rel="icon" href="<?= base_url('favicon.ico'); ?>" type="image/x-icon"/>
    <link rel="shortcut icon" type="image/x-icon" href="<?= base_url('favicon.ico'); ?>" />

    <title><?= isset($judul) ? htmlspecialchars($judul) : 'Presensi' ?></title>

    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,300i,400,400i,500,500i,600,600i,700,700i&amp;subset=latin-ext">

    <link href="<?= base_url('assets/css/dashboard.css'); ?>" rel="stylesheet" />
    <link href="<?= base_url('assets/plugins/charts-c3/plugin.css'); ?>" rel="stylesheet" />
    <link href="<?= base_url('assets/plugins/maps-google/plugin.css'); ?>" rel="stylesheet" />

    <script src="<?= base_url('assets/js/require.min.js'); ?>"></script>
    <script>
      if (typeof requirejs !== 'undefined') {
        requirejs.config({ baseUrl: "<?= base_url(); ?>" });
      } else {
        var s = document.createElement('script');
        s.src = "https://cdnjs.cloudflare.com/ajax/libs/require.js/2.3.6/require.min.js";
        document.head.appendChild(s);
      }
    </script>

    <style>
    /* Style tambahan agar menu aktif terlihat beda */
    .header .nav-tabs .nav-link.active {
        border-bottom: 2px solid #0d6efd; /* Garis biru di bawah */
        color: #0d6efd; /* Teks jadi biru */
        font-weight: 600;
    }
</style>

    <script defer src="<?= base_url('assets/js/dashboard.js'); ?>"></script>
    <script defer src="<?= base_url('assets/plugins/maps-google/plugin.js'); ?>"></script>
    <script defer src="<?= base_url('assets/plugins/charts-c3/plugin.js'); ?>"></script>
    <script defer src="<?= base_url('assets/plugins/input-mask/plugin.js'); ?>"></script>
  </head>

  <body class="">
    <div class="page">
      <div class="page-main">

        <div class="header py-4">
          <div class="container">
            <div class="d-flex">
              <a class="header-brand" href="<?= base_url('pegawai/home/home.php'); ?>">
                <img src="<?= base_url('assets/images/logoFix.svg'); ?>" class="header-brand-img" alt="Logo">
              </a>

              <div class="d-flex order-lg-2 ml-auto">

                <!-- NOTIFIKASI -->
                <div class="dropdown d-none d-md-flex">
                  <a class="nav-link icon" data-toggle="dropdown" href="#" aria-expanded="false">
                    <i class="fe fe-bell"></i>
                    <?php if ($notifPending > 0): ?>
                      <span class="nav-unread"></span>
                    <?php endif; ?>
                  </a>
                  <div class="dropdown-menu dropdown-menu-right dropdown-menu-arrow">
                    <?php if (count($notifications) === 0): ?>
                      <span class="dropdown-item text-muted">Belum ada notifikasi.</span>
                    <?php else: ?>
                      <?php foreach ($notifications as $notif): ?>
                        <?php
                          // warna kecil di teks
                          $statusLabel = $notif['status_pengajuan'];
                          $statusColor = 'text-muted';
                          if ($statusLabel === 'PENDING')   $statusColor = 'text-warning';
                          if ($statusLabel === 'DISETUJUI') $statusColor = 'text-success';
                          if ($statusLabel === 'DITOLAK')   $statusColor = 'text-danger';
                        ?>
                        <a href="<?= base_url('pegawai/ketidakhadiran/ketidakhadiran.php'); ?>" class="dropdown-item d-flex">
                          <span class="avatar mr-3" style="background-image: url('<?= h($avatarUrl); ?>')"></span>
                          <div>
                            <strong><?= h($notif['keterangan']); ?></strong>
                            <div class="<?= $statusColor ?> small">
                              <?= h($statusLabel); ?> • <?= h($notif['tanggal']); ?>
                            </div>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- PROFIL -->
                <div class="dropdown">
                  <a href="#" class="nav-link pr-0 leading-none" data-toggle="dropdown">
                    <span class="avatar" style="background-image:url('<?= h($avatarUrl); ?>')"></span>
                    <span class="ml-2 d-none d-lg-block">
                      <span class="text-default"><?= h($nama); ?></span>
                      <small class="text-muted d-block mt-1"><?= h($role); ?></small>
                    </span>
                  </a>
                  <div class="dropdown-menu dropdown-menu-right dropdown-menu-arrow">
                    <a class="dropdown-item" href="<?= base_url('pegawai/home/profile.php'); ?>">
                      <i class="dropdown-icon fe fe-user"></i> Profile
                    </a>
                    <a class="dropdown-item" href="<?= base_url('pegawai/home/settings.php'); ?>">
                      <i class="dropdown-icon fe fe-settings"></i> Settings
                    </a>
                    <a class="dropdown-item" href="<?= base_url('auth/logout.php'); ?>">
                      <i class="dropdown-icon fe fe-log-out"></i> Sign out
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

        <div class="header collapse d-lg-flex p-0" id="headerMenuCollapse">
          <div class="container">
            <div class="row align-items-center">
              <div class="col-lg order-lg-first">
                <ul class="nav nav-tabs border-0 flex-column flex-lg-row">
                  <li class="nav-item">
                      <a href="<?= base_url('pegawai/home/home.php'); ?>" class="nav-link <?= is_active('home.php') ?>">
                          <i class="fe fe-home"></i> Home
                      </a>
                  </li>
                  <li class="nav-item">
                      <a href="<?= base_url('pegawai/presensi/rekap_presensi.php'); ?>" class="nav-link <?= is_active('rekap_presensi') ?>">
                          <i class="fe fe-calendar"></i> Rekap Presensi
                      </a>
                  </li>
                  <li class="nav-item">
                      <a href="<?= base_url('pegawai/ketidakhadiran/ketidakhadiran.php'); ?>" class="nav-link <?= is_active('ketidakhadiran') ?>">
                          <i class="fe fe-check-square"></i> Ketidakhadiran
                      </a>
                  </li>
                  <li class="nav-item">
                      <a href="<?= base_url('pegawai/face-recg/registrasi_wajah.php'); ?>" class="nav-link <?= is_active('face-recg') ?>">
                          <i class="fe fe-user"></i> Registrasi Wajah
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
