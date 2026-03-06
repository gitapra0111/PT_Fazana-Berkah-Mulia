<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
  header("Location: ../../auth/login.php?pesan=tolak_akses"); exit;
}

$judul = "Home Pegawai";
require_once __DIR__ . '/../layout/header.php';

/* =========================================================
   KONEKSI DB (fallback)
========================================================= */
if (!isset($connection) || !($connection instanceof mysqli)) {
  if (isset($conn) && $conn instanceof mysqli) $connection = $conn;
  elseif (isset($koneksi) && $koneksi instanceof mysqli) $connection = $koneksi;
}

if (!isset($connection) || !($connection instanceof mysqli)) {
  http_response_code(500);
  die("Koneksi database belum terinisialisasi. Pastikan config.php membuat \$connection = new mysqli(...);");
}

/* =========================================================
   AMBIL LOKASI PRESENSI PEGAWAI DARI SESSION
========================================================= */
$lokasi_presensi = isset($_SESSION['user']['lokasi_presensi']) ? trim((string)$_SESSION['user']['lokasi_presensi']) : "";

$latitude_kantor  = "";
$longitude_kantor = "";
$radius           = "";
$zona_waktu       = "";
$jam_masuk_master = "";
$jam_pulang       = "";

if ($lokasi_presensi !== "") {
  $stmt = $connection->prepare(
    "SELECT latitude, longitude, radius, zona_waktu, jam_masuk, jam_pulang
     FROM lokasi_presensi
     WHERE nama_lokasi = ? LIMIT 1"
  );
  if ($stmt) {
    $stmt->bind_param("s", $lokasi_presensi);
    $stmt->execute();
    $stmt->bind_result($latitude_kantor, $longitude_kantor, $radius, $zona_waktu, $jam_masuk_master, $jam_pulang);
    $stmt->fetch();
    $stmt->close();
  } else {
    die("Gagal menyiapkan query lokasi_presensi: " . $connection->error);
  }
}

/* =========================================================
   SET ZONA WAKTU
========================================================= */
if ($zona_waktu === 'WIB') date_default_timezone_set('Asia/Jakarta');
elseif ($zona_waktu === 'WITA') date_default_timezone_set('Asia/Makassar');
elseif ($zona_waktu === 'WIT') date_default_timezone_set('Asia/Jayapura');

/* =========================================================
   CEK KETIDAKHADIRAN HARI INI
========================================================= */
$id_pegawai       = isset($_SESSION['user']['id_pegawai']) ? (int)$_SESSION['user']['id_pegawai'] : 0;
$tanggal_hari_ini = date('Y-m-d');

$punya_ketidakhadiran = false;
$data_ketidakhadiran  = null;

if ($id_pegawai > 0) {
  $stmt = $connection->prepare("
    SELECT id, keterangan, tanggal, deskripsi, status_pengajuan, file
    FROM ketidakhadiran
    WHERE id_pegawai = ? AND tanggal = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param("is", $id_pegawai, $tanggal_hari_ini);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      $punya_ketidakhadiran = true;
      $data_ketidakhadiran  = $row;
    }
    $stmt->close();
  }
}

/* =========================================================
   CEK PRESENSI MASUK HARI INI
========================================================= */
$belum_masuk = true;
if ($id_pegawai > 0) {
  $stmt = $connection->prepare("SELECT 1 FROM presensi WHERE id_pegawai = ? AND DATE(tanggal_masuk) = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('is', $id_pegawai, $tanggal_hari_ini);
    $stmt->execute();
    $stmt->store_result();
    $belum_masuk = ($stmt->num_rows === 0);
    $stmt->close();
  }
}

/* =========================================================
   AMBIL DATA PRESENSI HARI INI UNTUK STATUS KELUAR
========================================================= */
$is_empty_time = function(?string $t): bool {
  $t = trim((string)($t ?? ''));
  return $t === '' || $t === '00:00:00' || $t === '0';
};
$is_empty_date = function(?string $d): bool {
  $d = trim((string)($d ?? ''));
  return $d === '' || $d === '0000-00-00';
};

$jam_keluar_db = '';
$tanggal_keluar_db = '';
if ($id_pegawai > 0) {
  $stmt = $connection->prepare("
    SELECT IFNULL(tanggal_keluar, ''), IFNULL(jam_keluar, '')
    FROM presensi
    WHERE id_pegawai = ? AND DATE(tanggal_masuk) = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param('is', $id_pegawai, $tanggal_hari_ini);
    $stmt->execute();
    $stmt->bind_result($tanggal_keluar_db, $jam_keluar_db);
    $stmt->fetch();
    $stmt->close();
  }
}
$sudah_keluar = (!$is_empty_time($jam_keluar_db) || !$is_empty_date($tanggal_keluar_db));


/* =========================================================
   VALIDASI JAM MASUK MASTER
========================================================= */
$waktu_sekarang = time();
$jamMasukToday  = null;

if (!empty($jam_masuk_master)) {
  $jamNorm = str_replace('.', ':', trim((string)$jam_masuk_master));
  if (preg_match('/^\d{1,2}:\d{2}$/', $jamNorm)) $jamNorm .= ':00';

  if (preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d:[0-5]\d$/', $jamNorm) && $jamNorm !== '00:00:00') {
    $jamMasukToday = strtotime(date('Y-m-d') . ' ' . $jamNorm);
  }
}

// Toleransi: bisa masuk 30 menit sebelum jam masuk
$toleransi_awal = 30 * 60; // 30 menit dalam detik
$boleh_masuk_waktu = ($jamMasukToday !== null && $waktu_sekarang >= ($jamMasukToday - $toleransi_awal));

/* Validasi jam pulang (kode yang sudah ada) */
$jamPulangToday  = null;
// ... dst kode jam pulang yang sudah ada ...


/* =========================================================
   VALIDASI JAM PULANG MASTER
========================================================= */
$waktu_sekarang = time();
$jamPulangToday  = null;

if (!empty($jam_pulang)) {
  $jamNorm = str_replace('.', ':', trim((string)$jam_pulang));
  if (preg_match('/^\d{1,2}:\d{2}$/', $jamNorm)) $jamNorm .= ':00';

  if (preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d:[0-5]\d$/', $jamNorm) && $jamNorm !== '00:00:00') {
    $jamPulangToday = strtotime(date('Y-m-d') . ' ' . $jamNorm);
  }
}
$boleh_keluar_waktu = ($jamPulangToday !== null && $waktu_sekarang >= $jamPulangToday);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<style>
  /* Layout & UI */
  .hero-card {
    border-radius: 16px;
    border: 1px solid rgba(0,0,0,.06);
    box-shadow: 0 10px 30px rgba(0,0,0,.06);
  }
  .mini-muted { color:#6b7280; font-size:.85rem; }
  .pill {
    display:inline-flex; align-items:center; gap:8px;
    padding:6px 12px; border-radius:999px;
    font-size:.85rem; font-weight:600;
    border:1px solid rgba(0,0,0,.08);
    background:#fff;
  }
  .pill i { font-size: 1rem; }
  .loc-grid {
    display:grid;
    grid-template-columns: 1.2fr .8fr;
    gap:12px;
  }
  @media (max-width: 992px) {
    .loc-grid { grid-template-columns: 1fr; }
  }

  .time-display {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 2.2rem;
    font-weight: 800;
    padding: 10px 18px;
    border-radius: 14px;
    display: inline-flex;
    gap:10px;
    align-items:center;
    border: 1px solid rgba(0,0,0,.08);
    background: #0b1220;
    color: #e5f3ff;
    box-shadow: inset 0 0 18px rgba(56,189,248,.20), 0 8px 18px rgba(0,0,0,.10);
  }
  .time-sep { color:#ff5d7a; font-weight:900; }
  .time-part { min-width: 34px; text-align:center; }
  .card-header-strong {
    font-weight:800;
    letter-spacing:.2px;
    display:flex;
    align-items:center;
    gap:10px;
  }

  /* Status lokasi */
  .loc-status {
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px dashed rgba(0,0,0,.14);
    background: rgba(255,255,255,.75);
  }
  .loc-status .rowline {
    display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;
  }
  .btn-soft {
    border-radius: 999px;
    padding: 8px 14px;
    font-weight: 700;
  }
  .btn-big {
    border-radius: 999px;
    padding: 10px 20px;
    font-weight: 800;
  }
</style>

<div class="page-body">
  <div class="container-xl">

    <!-- Header kecil -->
    <div class="row row-cards mb-3">
      <div class="col-12">
        <div class="card hero-card">
          <div class="card-body">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
              <div>
                <div class="h2 mb-1">Halo, <?= h($_SESSION['user']['nama'] ?? 'Pegawai'); ?> 👋</div>
                <div class="mini-muted">
                  Lokasi presensi: <b><?= h($lokasi_presensi ?: '-'); ?></b> • Zona waktu: <b><?= h($zona_waktu ?: '-'); ?></b>
                </div>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <span class="pill"><i class="fe fe-calendar"></i> <?= date('d M Y'); ?></span>
                <span class="pill"><i class="fe fe-clock"></i> <span id="clockTop">--:--:--</span></span>
              </div>
            </div>

            <!-- Lokasi panel -->
            <div class="loc-grid mt-3">
              <div class="loc-status">
                <div class="rowline mb-1">
                  <div class="pill" id="locBadge"><i class="fe fe-navigation"></i> Lokasi: <span id="locState">mengambil...</span></div>
                  <div class="pill"><i class="fe fe-crosshair"></i> Akurasi: <span id="accText">-</span></div>
                </div>
                <div class="rowline mb-2">
                  <div class="mini-muted">
                    Koordinat: <b id="coordText">-</b>
                    <span class="mini-muted">• Sumber: <b id="srcText">-</b></span>
                  </div>
                  <div class="mini-muted">
                    Target akurasi: <b id="targetAcc">≤ 50m</b>
                  </div>
                </div>

                <div class="alert alert-warning mb-0" id="locHint" style="display:none;">
                  Lokasi belum stabil. Pastikan izin lokasi aktif, GPS nyala, dan akses via HTTPS.
                </div>

                <div class="d-flex gap-2 flex-wrap mt-3">
                  <button type="button" class="btn btn-outline-primary btn-soft" id="btnRefreshLoc">
                    <i class="fe fe-refresh-ccw mr-1"></i> Refresh Lokasi
                  </button>
                  <button type="button" class="btn btn-outline-secondary btn-soft" id="btnStopWatch" disabled>
                    <i class="fe fe-pause mr-1"></i> Stop Watch
                  </button>
                </div>
              </div>

              <div class="card hero-card mb-0">
                <div class="card-body">
                  <div class="mini-muted mb-1">Status hari ini</div>
                  <div class="d-flex gap-2 flex-wrap">
                    <?php if ($punya_ketidakhadiran): ?>
                      <span class="badge badge-warning">Ketidakhadiran: <?= h($data_ketidakhadiran['keterangan'] ?? ''); ?></span>
                      <span class="badge badge-secondary">Presensi dinonaktifkan</span>
                    <?php else: ?>
                      <?php if ($belum_masuk): ?>
                        <span class="badge badge-info">Belum presensi masuk</span>
                      <?php else: ?>
                        <span class="badge badge-success">Sudah presensi masuk</span>
                      <?php endif; ?>

                      <?php if ($sudah_keluar): ?>
                        <span class="badge badge-success">Sudah presensi keluar</span>
                      <?php else: ?>
                        <span class="badge badge-secondary">Belum presensi keluar</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                  <div class="mini-muted mt-2">
                    Tips: kalau lokasi “loncat-loncat”, tekan <b>Refresh Lokasi</b> dan tunggu akurasi membaik.
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <?php if ($punya_ketidakhadiran): ?>
      <?php
        $status = $data_ketidakhadiran['status_pengajuan'] ?? 'PENDING';
        $badge  = 'secondary';
        if ($status === 'PENDING')   $badge = 'warning';
        if ($status === 'DISETUJUI') $badge = 'success';
        if ($status === 'DITOLAK')   $badge = 'danger';
      ?>
      <div class="row row-cards">
        <div class="col-12">
          <div class="card hero-card">
            <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
              <div>
                <div class="h3 mb-1">Ketidakhadiran Hari Ini</div>
                <div class="mini-muted mb-2">
                  <?= h($data_ketidakhadiran['keterangan'] ?? ''); ?> pada <?= date('d M Y', strtotime((string)$data_ketidakhadiran['tanggal'] ?? $tanggal_hari_ini)); ?>
                </div>
                <div class="mb-2"><?= $data_ketidakhadiran['deskripsi'] ? h($data_ketidakhadiran['deskripsi']) : 'Tidak ada keterangan tambahan.'; ?></div>
                <span class="badge badge-<?= $badge ?>">Status: <?= h($status); ?></span>
              </div>
              <div class="text-muted">
                <i class="fe fe-slash" style="font-size:52px;"></i>
                <div class="mini-muted mt-2">Presensi disembunyikan.</div>
              </div>
            </div>
          </div>
        </div>
      </div>

    <?php else: ?>

      <div class="row row-cards">
        <!-- PRESENSI MASUK -->
        <div class="col-12 col-lg-6">
          <div class="card hero-card">
            <div class="card-header card-header-strong">
              <span class="avatar avatar-sm bg-blue-lt"><i class="fe fe-log-in"></i></span>
              Presensi Masuk
            </div>
            <div class="card-body text-center">

              <?php if ($belum_masuk): ?>
                
                <?php if (!$boleh_masuk_waktu): ?>
                  <!-- BELUM WAKTUNYA MASUK -->
                  <div class="h3 mb-1">Belum waktunya presensi masuk</div>
                  <?php if ($jamMasukToday): ?>
                    <?php 
                      $waktu_buka = $jamMasukToday - $toleransi_awal;
                      $sisa_detik = $waktu_buka - $waktu_sekarang; 
                      $sisa_menit = max(0, (int)ceil($sisa_detik / 60)); 
                    ?>
                    <div class="mini-muted">
                      Bisa masuk mulai pukul <b><?= date('H:i', $waktu_buka) ?></b> 
                      (± <?= $sisa_menit ?> menit lagi)
                    </div>
                    <div class="mini-muted mt-2">
                      Jam masuk kantor: <b><?= date('H:i', $jamMasukToday) ?></b>
                    </div>
                  <?php endif; ?>
                  
                  <!-- Auto reload saat waktunya tiba -->
                  <?php if ($jamMasukToday && $waktu_sekarang < ($jamMasukToday - $toleransi_awal)): ?>
                    <script>
                      setTimeout(() => location.reload(), <?= ($waktu_buka - $waktu_sekarang) * 1000 ?>);
                    </script>
                  <?php endif; ?>
                  
                <?php else: ?>
                  <!-- SUDAH WAKTUNYA MASUK -->
                  <div class="mini-muted mb-2">Waktu sekarang</div>
                  <div class="time-display mb-3">
                    <span id="jam_masuk" class="time-part"></span><span class="time-sep">:</span>
                    <span id="menit_masuk" class="time-part"></span><span class="time-sep">:</span>
                    <span id="detik_masuk" class="time-part"></span>
                  </div>

                  <form method="POST" action="<?= h(base_url('pegawai/presensi/presensi_masuk.php')) ?>" class="mt-2">
                    <!-- koordinat dari JS -->
                    <input type="hidden" name="latitude_pegawai"  id="latitude_pegawai"  value="">
                    <input type="hidden" name="longitude_pegawai" id="longitude_pegawai" value="">
                    <input type="hidden" name="accuracy" id="accuracy_masuk" value="">

                    <input type="hidden" name="latitude_kantor"   value="<?= h((string)$latitude_kantor) ?>">
                    <input type="hidden" name="longitude_kantor"  value="<?= h((string)$longitude_kantor) ?>">
                    <input type="hidden" name="radius"            value="<?= h((string)$radius) ?>">
                    <input type="hidden" name="zona_waktu"        value="<?= h((string)$zona_waktu) ?>">

                    <button type="submit" name="tombol_masuk" class="btn btn-primary btn-big mt-2" id="btnMasuk" disabled>
                      <i class="fe fe-check-circle mr-1"></i> Masuk
                    </button>

                    <div class="mini-muted mt-2" id="masukHelp">
                      Menunggu lokasi stabil…
                    </div>
                  </form>
                <?php endif; ?>

              <?php else: ?>
                <div class="h3 mb-1">Anda telah melakukan presensi masuk hari ini.</div>
                <div class="text-success"><i class="fe fe-check-circle"></i> Data masuk sudah tercatat.</div>
              <?php endif; ?>

            </div>
          </div>
        </div>

        <!-- PRESENSI KELUAR -->
        <div class="col-12 col-lg-6">
          <div class="card hero-card">
            <div class="card-header card-header-strong">
              <span class="avatar avatar-sm bg-red-lt"><i class="fe fe-log-out"></i></span>
              Presensi Keluar
            </div>

            <?php if ($belum_masuk): ?>
              <div class="card-body text-center">
                <div class="h3 mb-1">Belum Presensi Masuk</div>
                <div class="text-muted">Silakan lakukan presensi masuk terlebih dahulu.</div>
              </div>

            <?php elseif ($sudah_keluar): ?>
              <div class="card-body text-center">
                <div class="h3 mb-1">Sudah Presensi Keluar</div>
                <?php if (!$is_empty_time($jam_keluar_db)): ?>
                  <div class="mini-muted">Waktu keluar: <b><?= h($jam_keluar_db) ?></b></div>
                <?php endif; ?>
                <div class="text-success mt-2"><i class="fe fe-check-circle"></i> Data sudah tercatat 🎉</div>
              </div>

            <?php elseif (!$boleh_keluar_waktu): ?>
              <div class="card-body text-center">
                <div class="h3 mb-1">Belum waktunya keluar</div>
                <?php if ($jamPulangToday): ?>
                  <?php $sisa_detik = $jamPulangToday - $waktu_sekarang; $sisa_menit = max(0, (int)ceil($sisa_detik / 60)); ?>
                  <div class="mini-muted">
                    Bisa keluar pukul <b><?= date('H:i', $jamPulangToday) ?></b> (± <?= $sisa_menit ?> menit lagi)
                  </div>
                <?php endif; ?>
              </div>
              <?php if ($jamPulangToday && $waktu_sekarang < $jamPulangToday): ?>
                <script>setTimeout(() => location.reload(), <?= ($jamPulangToday - $waktu_sekarang) * 1000 ?>);</script>
              <?php endif; ?>

            <?php else: ?>
              <div class="card-body text-center">
                <div class="mini-muted mb-2">Waktu sekarang</div>
                <div class="time-display mb-3">
                  <span id="jam_keluar" class="time-part"></span><span class="time-sep">:</span>
                  <span id="menit_keluar" class="time-part"></span><span class="time-sep">:</span>
                  <span id="detik_keluar" class="time-part"></span>
                </div>

                <form method="POST" action="<?= h(base_url('pegawai/presensi/presensi_keluar.php')) ?>">
                  <input type="hidden" name="tombol_keluar" value="1">

                  <input type="hidden" name="latitude_pegawai"  id="lat_keluar" value="">
                  <input type="hidden" name="longitude_pegawai" id="lng_keluar" value="">
                  <input type="hidden" name="accuracy" id="accuracy_keluar" value="">

                  <input type="hidden" name="latitude_kantor"   value="<?= h((string)$latitude_kantor) ?>">
                  <input type="hidden" name="longitude_kantor"  value="<?= h((string)$longitude_kantor) ?>">
                  <input type="hidden" name="radius"            value="<?= h((string)$radius) ?>">
                  <input type="hidden" name="zona_waktu"        value="<?= h((string)$zona_waktu) ?>">

                  <button class="btn btn-danger btn-big" type="submit" id="btnKeluar" disabled>
                    <i class="fe fe-log-out mr-1"></i> Keluar
                  </button>

                  <div class="mini-muted mt-2" id="keluarHelp">
                    Menunggu lokasi stabil…
                  </div>
                </form>
              </div>
            <?php endif; ?>

          </div>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // ===== clock UI new perlindungan manipulasi waktu & Full Zonasi =====
  
  // 1. Ambil waktu awal dari Server PHP (dalam milidetik)
  const serverWaktuAwal = <?= time() * 1000 ?>;
  
  // 2. Gunakan performance.now() yang KEBAL terhadap perubahan jam laptop
  const waktuMulaiLaptop = performance.now();

  // 3. Ambil zona waktu dari PHP
  const tzString = '<?= ($zona_waktu === "WITA") ? "Asia/Makassar" : (($zona_waktu === "WIT") ? "Asia/Jayapura" : "Asia/Jakarta") ?>';

  function tickClock() {
    // 4. Hitung selisih waktu berjalan dan tambahkan ke waktu server
    const waktuBerjalan = performance.now() - waktuMulaiLaptop;
    const now = new Date(serverWaktuAwal + waktuBerjalan);
    
    // 5. Paksa format waktu sesuai zona dari database
    const options = { 
        timeZone: tzString,
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false 
    };
    
    // Hasilkan string jam, cth: "14:05:09"
    const t = new Intl.DateTimeFormat('id-ID', options).format(now).replace(/\./g, ':');
    
    // Pecah jadi jam, menit, detik untuk animasi UI
    const [hh, mm, ss] = t.split(':');

    const top = document.getElementById('clockTop');
    if (top) top.textContent = t;

    // Update panel presensi masuk
    const jm = document.getElementById('jam_masuk');
    const min_m = document.getElementById('menit_masuk');
    const dm = document.getElementById('detik_masuk');
    if (jm && min_m && dm) {
      jm.textContent = hh;
      min_m.textContent = mm;
      dm.textContent = ss;
    }

    // Update panel presensi keluar
    const jk = document.getElementById('jam_keluar');
    const min_k = document.getElementById('menit_keluar');
    const dk = document.getElementById('detik_keluar');
    if (jk && min_k && dk) {
      jk.textContent = hh;
      min_k.textContent = mm;
      dk.textContent = ss;
    }
  }
  
  tickClock();
  setInterval(tickClock, 1000);

  // ===== DOM refs (lokasi) =====
  const locState  = document.getElementById('locState');
  const accText   = document.getElementById('accText');
  const coordText = document.getElementById('coordText');
  const srcText   = document.getElementById('srcText');
  const locHint   = document.getElementById('locHint');
  const btnRefresh = document.getElementById('btnRefreshLoc');
  const btnStop    = document.getElementById('btnStopWatch');

  const inLat   = document.getElementById('latitude_pegawai');
  const inLng   = document.getElementById('longitude_pegawai');
  const outLat  = document.getElementById('lat_keluar');
  const outLng  = document.getElementById('lng_keluar');

  const accMasuk  = document.getElementById('accuracy_masuk');
  const accKeluar = document.getElementById('accuracy_keluar');

  const btnMasuk  = document.getElementById('btnMasuk');
  const btnKeluar = document.getElementById('btnKeluar');
  const masukHelp = document.getElementById('masukHelp');
  const keluarHelp= document.getElementById('keluarHelp');

  // ===== Device detection =====
  const UA = navigator.userAgent || '';
  const IS_MOBILE = /Android|iPhone|iPad|iPod/i.test(UA);
  const IS_DESKTOP = !IS_MOBILE;

  // ===== config =====
  const TARGET_ACC_MOBILE  = 50;   // ketat untuk HP (GPS)
  const TARGET_ACC_DESKTOP = 250;  // realistis untuk desktop (Wi-Fi/IP)
  const TARGET_ACC = IS_DESKTOP ? TARGET_ACC_DESKTOP : TARGET_ACC_MOBILE;

  const MAX_WATCH_MS = IS_DESKTOP ? 25000 : 20000; // desktop kasih waktu lebih
  const CACHE_TTL_MS = 2 * 60 * 1000;

  let watchId = null;
  let watchTimer = null;

  function setText(el, val) { if (el) el.textContent = val; }

  function estimateSource(pos, explicit) {
    if (explicit) return explicit;
    // heuristik sederhana: kalau mobile + high accuracy biasanya GPS
    // desktop hampir selalu Wi-Fi/IP
    if (IS_DESKTOP) return 'wifi/ip';
    const acc = pos?.coords?.accuracy ?? 9999;
    return acc <= 60 ? 'gps' : 'network';
  }

  // Tambahkan di awal script (setelah const TARGET_ACC = ...)
let coordHistory = [];
const MAX_HISTORY = 5;

function isStaticLocation(lat, lng) {
    const newCoord = `${lat.toFixed(6)}_${lng.toFixed(6)}`;
    coordHistory.push(newCoord);
    
    if (coordHistory.length > MAX_HISTORY) {
        coordHistory.shift(); // hapus yang paling lama
    }
    
    // Jika 5 koordinat terakhir PERSIS SAMA = fake GPS
    if (coordHistory.length >= MAX_HISTORY) {
        const allSame = coordHistory.every(c => c === coordHistory[0]);
        return allSame;
    }
    
    return false;
}


  // BATAS peringatan Lokasi Palsu (Fake GPS) dan logika heuristik sederhana
function setCoords(lat, lng, acc, source) {
    // 1. Format Data
    const vLat = (typeof lat === 'number') ? lat.toFixed(6) : '';
    const vLng = (typeof lng === 'number') ? lng.toFixed(6) : '';
    const vAcc = (typeof acc === 'number') ? Math.round(acc) : null;

    // 2. Update Input Hidden
    if (inLat) inLat.value = vLat;
    if (inLng) inLng.value = vLng;
    if (outLat) outLat.value = vLat;
    if (outLng) outLng.value = vLng;
    if (accMasuk) accMasuk.value = (vAcc ?? '');
    if (accKeluar) accKeluar.value = (vAcc ?? '');

    // 3. Update Teks Info Dasar
    setText(coordText, (vLat && vLng) ? `${vLat}, ${vLng}` : '-');
    setText(accText, vAcc !== null ? `${vAcc} m` : '-');
    setText(srcText, source || '-');

    // ============================================================
    // 4. LOGIKA DETEKSI KECURANGAN (ANTI-FAKE GPS)
    // ============================================================
    let isMock = false;
    let mockReason = "";
    
    // 1. HEURISTIK: Jika akurasi <= 1 meter, ini ciri khas Fake GPS.
    if (vAcc !== null && vAcc <= 1.0) {
        isMock = true;
        mockReason = "Akurasi GPS terlalu sempurna (Indikasi Fake GPS).";
    }

    // ============================================================
    // TAMBAHAN BARU UNTUK PERGERAKAN GPS

     // 2. Koordinat statis (tidak bergerak natural) - TAMBAHKAN INI!
    if (!isMock && vLat && vLng && isStaticLocation(lat, lng)) {
        isMock = true;
        mockReason = "Lokasi tidak bergerak secara natural. Indikasi Fake GPS.";
    }
    
    // 3. Akurasi = 0 atau null (tidak natural) - TAMBAHKAN INI!
    if (!isMock && (vAcc === 0 || vAcc === null)) {
        isMock = true;
        mockReason = "Akurasi GPS tidak valid. Indikasi Fake GPS.";
    }

    // Syarat Boleh Absen: Akurasi sesuai target DAN Tidak Curang
    const ok = (vAcc !== null && vAcc <= TARGET_ACC && vLat && vLng && !isMock);

    // ============================================================
    // 5. UPDATE TAMPILAN UI (STRUKTUR IF-ELSE AGAR TIDAK SALING TIMPA)
    // ============================================================
    
    if (isMock) {
        // --- KONDISI 1: TERDETEKSI CURANG (MERAH) ---
        setText(locState, 'FAKE GPS!');
        if(locState) locState.className = 'pill bg-danger text-white'; // Merah
        
        if (locHint) {
            locHint.style.display = 'block';
            locHint.className = 'alert alert-danger mb-2'; // Merah
            locHint.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="ti ti-alert-triangle me-2 fs-2"></i>
                    <div>
                        <strong>PERINGATAN KEAMANAN!</strong><br>
                        ${mockReason}<br>
                        <small>Matikan aplikasi Fake GPS Anda.</small>
                    </div>
                </div>
            `;
        }
        
        // Kunci Tombol
        if (btnMasuk) btnMasuk.disabled = true;
        if (btnKeluar) btnKeluar.disabled = true;

    } else if (!ok) {
        // --- KONDISI 2: BELUM AKURAT / MENUNGGU (KUNING) ---
        setText(locState, 'MENINGKATKAN...');
        if(locState) locState.className = 'pill'; // Default / Kuning

        if (locHint) {
            locHint.style.display = 'block';
            locHint.className = 'alert alert-warning mb-0';
            locHint.textContent = IS_DESKTOP
                ? `Mode desktop: lokasi via Wi-Fi/IP. Target ≤ ${TARGET_ACC}m.`
                : `Akurasi saat ini ${vAcc}m. Target sistem ≤ ${TARGET_ACC}m.`;
        }

        // Kunci Tombol Sementara
        if (btnMasuk) btnMasuk.disabled = true;
        if (btnKeluar) btnKeluar.disabled = true;

        if (masukHelp) masukHelp.textContent = `Menunggu akurasi membaik...`;
        if (keluarHelp) keluarHelp.textContent = `Menunggu akurasi membaik...`;

    } else {
        // --- KONDISI 3: AMAN & SIAP (HIJAU) ---
        setText(locState, 'SIAP');
        if(locState) locState.className = 'pill bg-success text-white'; // Hijau

        if (locHint) locHint.style.display = 'none'; // Hilangkan pesan error

        // Buka Kunci Tombol MASUK (cek waktu juga)
        const bolehMasukWaktu = <?= json_encode($boleh_masuk_waktu) ?>;
        if (btnMasuk) btnMasuk.disabled = !bolehMasukWaktu;
        
        // Khusus tombol keluar, cek jam pulang (mengambil variabel PHP)
        const bolehWaktu = <?= json_encode($boleh_keluar_waktu) ?>; 
        if (btnKeluar) btnKeluar.disabled = !bolehWaktu;
        
        if (masukHelp) masukHelp.textContent = `Lokasi valid (${vAcc}m). Silakan absen.`;
        if (keluarHelp) keluarHelp.textContent = `Lokasi valid (${vAcc}m). Silakan pulang.`;

        // Cache lokasi valid
        try {
            localStorage.setItem('last_geo_fix', JSON.stringify({
                lat: Number(vLat), lng: Number(vLng), acc: vAcc, ts: Date.now()
            }));
        } catch (e) {}
        
        stopWatch(); // Hemat baterai
    }
  }

  // ============================================================

 
  function setStatusLoading(msg) {
    setText(locState, msg);
    if (locHint) locHint.style.display = 'none';
  }

  function setStatusError(err) {
    setText(locState, 'GAGAL');
    if (locHint) {
      locHint.style.display = 'block';
      // pesan lebih jelas + sesuai error code
      let msg = 'Lokasi gagal diambil. Pastikan izin lokasi aktif dan akses via HTTPS.';
      if (err && typeof err.code === 'number') {
        if (err.code === 1) msg = 'Izin lokasi ditolak. Silakan Allow lokasi di browser.';
        if (err.code === 2) msg = 'Lokasi tidak tersedia. Coba nyalakan Wi-Fi/GPS atau ganti jaringan.';
        if (err.code === 3) msg = 'Request lokasi timeout. Coba Refresh Lokasi atau tunggu sebentar.';
      }
      locHint.textContent = msg;
    }
    if (btnMasuk) btnMasuk.disabled = true;
    if (btnKeluar) btnKeluar.disabled = true;
  }

  function stopWatch() {
    if (watchId !== null && 'geolocation' in navigator) {
      navigator.geolocation.clearWatch(watchId);
      watchId = null;
    }
    if (watchTimer) {
      clearTimeout(watchTimer);
      watchTimer = null;
    }
    if (btnStop) btnStop.disabled = true;
  }

  function startWatch(mode) {
    if (!('geolocation' in navigator)) {
      setStatusError({code: -1});
      return;
    }
    stopWatch();
    if (btnStop) btnStop.disabled = false;

    const isRefine = (mode === 'refine');
    const opts = IS_DESKTOP
      ? { enableHighAccuracy: false, timeout: 20000, maximumAge: 0 } // desktop: jangan maksa GPS
      : { enableHighAccuracy: true,  timeout: 15000, maximumAge: 0 }; // mobile: boleh GPS


      
      // ============================================
      // TAMBAHAN BARU - WATCH POSITION DENGAN VALIDASI ANTI-FAKE GPS
      // ============================================
    watchId = navigator.geolocation.watchPosition(
  pos => {
    const src = estimateSource(pos, IS_DESKTOP ? 'wifi/ip' : (isRefine ? 'gps' : 'watch'));
    
    // ============================================
    // VALIDASI TAMBAHAN ANTI-FAKE GPS
    // ============================================
    
    // 1. Validasi Altitude (ketinggian)
    const altitude = pos.coords.altitude;
    const altitudeAccuracy = pos.coords.altitudeAccuracy;
    
    // Jika mobile + GPS aktif tapi altitude null/0 = mencurigakan
    if (IS_MOBILE && src.includes('gps') && (altitude === null || altitude === 0)) {
      console.warn('⚠️ SUSPICIOUS: Altitude tidak valid, kemungkinan fake GPS');
    }
    
    // 2. Validasi Timestamp
    const now = Date.now();
    const posTime = pos.timestamp;
    const timeDiff = Math.abs(now - posTime);
    
    // Jika selisih waktu > 5 detik = tidak sinkron (mencurigakan)
    if (timeDiff > 5000) {
      console.warn('⚠️ SUSPICIOUS: Timestamp GPS tidak sinkron dengan waktu sistem', {
        timeDiff: timeDiff + 'ms',
        systemTime: new Date(now).toISOString(),
        gpsTime: new Date(posTime).toISOString()
      });
    }
    
    // 3. Set Koordinat (dengan validasi di dalam setCoords)
    setCoords(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy, src);
    
    // 4. Enhanced debug log
    console.log('WATCH:', {
      lat: pos.coords.latitude, 
      lng: pos.coords.longitude, 
      acc: pos.coords.accuracy,
      altitude: altitude,
      altitudeAcc: altitudeAccuracy,
      timestamp: new Date(posTime).toISOString(),
      timeDiff: timeDiff + 'ms',
      src: src
    });
  },
  err => {
    console.error('❌ WATCH ERROR', err);
    setStatusError(err);
    stopWatch();
  },
  opts
);

// ============================================

    watchTimer = setTimeout(() => {
      stopWatch();
    }, MAX_WATCH_MS);
  }

  function tryUseCacheFirst() {
    try {
      const raw = localStorage.getItem('last_geo_fix');
      if (!raw) return false;
      const data = JSON.parse(raw);
      if (!data || !data.ts) return false;
      if ((Date.now() - data.ts) > CACHE_TTL_MS) return false;
      setCoords(Number(data.lat), Number(data.lng), Number(data.acc), 'cache');
      return true;
    } catch (e) { return false; }
  }

  function getOnceQuick() {
    return new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: false,  // cepat
        timeout: IS_DESKTOP ? 15000 : 9000,
        maximumAge: 60000
      });
    });
  }

  // function getOnceRefine() {
  //   return new Promise((resolve, reject) => {
  //     navigator.geolocation.getCurrentPosition(resolve, reject, {
  //       enableHighAccuracy: IS_DESKTOP ? false : true, // desktop jangan maksa
  //       timeout: IS_DESKTOP ? 20000 : 15000,
  //       maximumAge: 0
  //     });
  //   });
  // }

  // new FUNGSI getOnceRefine dengan validasi tambahan
  function getOnceRefine() {
  return new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(
      pos => {
        // Validasi sebelum resolve
        const altitude = pos.coords.altitude;
        const now = Date.now();
        const timeDiff = Math.abs(now - pos.timestamp);
        
        if (IS_MOBILE && (altitude === null || altitude === 0)) {
          console.warn('⚠️ REFINE: Altitude mencurigakan');
        }
        
        if (timeDiff > 5000) {
          console.warn('⚠️ REFINE: Timestamp tidak sinkron');
        }
        
        resolve(pos);
      },
      reject,
      {
        enableHighAccuracy: IS_DESKTOP ? false : true,
        timeout: IS_DESKTOP ? 20000 : 15000,
        maximumAge: 0
      }
    );
  });
}

  async function requestLocationSmart() {
    if (!('geolocation' in navigator)) {
      setStatusError({code: -1});
      return;
    }

    setStatusLoading('mengambil...');
    tryUseCacheFirst();

    try {
      const posQuick = await getOnceQuick();
      setCoords(
        posQuick.coords.latitude,
        posQuick.coords.longitude,
        posQuick.coords.accuracy,
        IS_DESKTOP ? 'wifi/ip' : 'quick'
      );

      // kalau belum memenuhi target, refine / watch
      const acc = posQuick.coords.accuracy ?? 9999;
      if (acc > TARGET_ACC) {
        try {
          const posRef = await getOnceRefine();
          setCoords(
            posRef.coords.latitude,
            posRef.coords.longitude,
            posRef.coords.accuracy,
            estimateSource(posRef, IS_DESKTOP ? 'wifi/ip' : 'refine')
          );
        } catch (e) {
          // refine gagal → pakai watch untuk improve
          startWatch('refine');
        }
      }
    } catch (err) {
      console.error('❌ GEOLOCATION ERROR', err);
      setStatusError(err);
      // fallback: watch
      startWatch('quick');
      return;
    }

    // Jika masih belum OK, jalanin watch sebentar (improve)
    startWatch('quick');
  }

  // tombol refresh/stop
  if (btnRefresh) btnRefresh.addEventListener('click', () => requestLocationSmart());
  if (btnStop) btnStop.addEventListener('click', () => stopWatch());

  // start otomatis
  requestLocationSmart();
});
</script>


<?php include __DIR__ . '/../layout/footer.php'; ?>

<!-- ===== FOOTER PEGAWAI (REVISI) =====-->
 <!-- new home pegawai -->

 <!-- di bawah ini adalah javascrip untuk melihat eror titik lokasi -->

 <!-- <script>
  navigator.geolocation.getCurrentPosition(
  (pos) => {
    console.log('LOKASI BERHASIL:', pos.coords);
  },
  (err) => {
    console.error('❌ GEOLOCATION ERROR');
    console.error('Code:', err.code);
    console.error('Message:', err.message);

    switch (err.code) {
      case err.PERMISSION_DENIED:
        console.warn('User menolak izin lokasi');
        break;
      case err.POSITION_UNAVAILABLE:
        console.warn('Lokasi tidak tersedia');
        break;
      case err.TIMEOUT:
        console.warn('Request lokasi timeout');
        break;
      default:
        console.warn('Error tidak diketahui');
    }
  },
  {
    enableHighAccuracy: true,
    timeout: 15000,
    maximumAge: 0
  }
);

 </script> -->