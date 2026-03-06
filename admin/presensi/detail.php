<?php
// =====================================
// admin/presensi/detail.php
// =====================================
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

require_once __DIR__ . '/../../config.php';

/* ---- koneksi ---- */
$mysqli = null;
if (isset($connection) && $connection instanceof mysqli)       $mysqli = $connection;
elseif (isset($conn) && $conn instanceof mysqli)              $mysqli = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli)        $mysqli = $koneksi;
if (!$mysqli) { http_response_code(500); die("Koneksi DB tidak ditemukan."); }

/* ---- param ---- */
$idPeg = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$bulan = (isset($_GET['bulan']) && preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'])) ? $_GET['bulan'] : date('Y-m');

if ($idPeg <= 0) {
  die("Pegawai tidak valid.");
}
[$tahun, $bln] = array_map('intval', explode('-', $bulan));
$awal  = sprintf('%04d-%02d-01', $tahun, $bln);
$akhir = date('Y-m-t', strtotime($awal));

function isWeekday(string $date): bool {
  return ((int)date('N', strtotime($date))) <= 5;
}

// --- TAMBAHAN BARU: Helper Format Durasi ---
function formatDurasi(int $menit): string {
    if ($menit <= 0) return '-';
    
    $jam = floor($menit / 60);
    $sisamenit = $menit % 60;
    
    $out = [];
    if ($jam > 0) $out[] = $jam . ' Jam';
    if ($sisamenit > 0) $out[] = $sisamenit . ' Menit';
    
    return implode(' ', $out);
}
function countWorkdays(string $start, string $end): int {
  $s = new DateTime($start);
  $e = new DateTime($end);
  $e->setTime(23,59,59);
  $days = 0;
  while ($s <= $e) {
    if ((int)$s->format('N') <= 5) $days++;
    $s->modify('+1 day');
  }
  return $days;
}
$workdays = countWorkdays($awal, $akhir);

/* ---- ambil pegawai ---- */
$stmt = $mysqli->prepare("
  SELECT pg.id, pg.nip, pg.nama, pg.jabatan, pg.lokasi_presensi,
         lp.jam_masuk AS jam_masuk_rule,
         lp.jam_pulang AS jam_pulang_rule
  FROM pegawai pg
  LEFT JOIN lokasi_presensi lp ON lp.nama_lokasi = pg.lokasi_presensi
  WHERE pg.id = ?
");
$stmt->bind_param('i', $idPeg);
$stmt->execute();
$resPeg = $stmt->get_result();
$peg = $resPeg->fetch_assoc();
$stmt->close();

if (!$peg) {
  die("Pegawai tidak ditemukan.");
}

/* -------------------------------------------------
   3. PROSES PRESENSI (LOGIC CAPPING & TELAT)
------------------------------------------------- */
$stmt = $mysqli->prepare("
  SELECT 
    p.tanggal_masuk, p.jam_masuk, p.jam_keluar, p.foto_masuk, p.foto_keluar,
    p.latitude_masuk, p.longitude_masuk,   -- TAMBAHAN
    p.latitude_keluar, p.longitude_keluar, -- TAMBAHAN
    lp.latitude AS lat_kantor,             -- TAMBAHAN
    lp.longitude AS long_kantor,           -- TAMBAHAN
    lp.radius                              -- TAMBAHAN
  FROM presensi p
  JOIN pegawai pg ON p.id_pegawai = pg.id
  LEFT JOIN lokasi_presensi lp ON pg.lokasi_presensi = lp.nama_lokasi
  WHERE p.id_pegawai = ? AND p.tanggal_masuk BETWEEN ? AND ?
  ORDER BY p.tanggal_masuk ASC
");
$stmt->bind_param('iss', $idPeg, $awal, $akhir);
$stmt->execute();
$resPres = $stmt->get_result();

$presensi = [];
$hadir = 0;
$telatHari = 0;
$telatMenitTotal = 0; // Total menit telat sebulan
$totKerjaDetik = 0;   // Total detik kerja sebulan

while ($p = $resPres->fetch_assoc()) {
  // Default values per baris
  $telatMenitHari = 0;
  $statusTelat = 'Tepat Waktu';
  $durasiDetikHari = 0;

  // 1. LOGIKA HADIR & TELAT
  if (isWeekday($p['tanggal_masuk'])) {
    $hadir++;
    
    // Cek Rule Jam Masuk
    if (!empty($peg['jam_masuk_rule']) && $peg['jam_masuk_rule'] !== '00:00:00' &&
        !empty($p['jam_masuk']) && $p['jam_masuk'] !== '00:00:00') {
        
        $t_db   = strtotime("1970-01-01 {$p['jam_masuk']} UTC");
        $t_rule = strtotime("1970-01-01 {$peg['jam_masuk_rule']} UTC");
        
        if ($t_db > $t_rule) {
            $diff = (int)round(($t_db - $t_rule) / 60);
            
            // ATURAN BARU: Telat hanya dihitung jika > 60 menit
            if ($diff > 60) {
                $telatMenitHari = $diff;
                $telatHari++;
                $telatMenitTotal += $diff;
                $statusTelat = 'Terlambat';
            }
        }
    }
  }

  // 2. LOGIKA DURASI KERJA (CAPPING)
  if (!empty($p['jam_masuk']) && $p['jam_masuk'] !== '00:00:00' &&
      !empty($p['jam_keluar']) && $p['jam_keluar'] !== '00:00:00') {
      
      $inS  = strtotime("1970-01-01 {$p['jam_masuk']} UTC");
      $outS = strtotime("1970-01-01 {$p['jam_keluar']} UTC");
      
      // Cek Batas Jam Pulang (Capping)
      if (!empty($peg['jam_pulang_rule']) && $peg['jam_pulang_rule'] !== '00:00:00') {
          $maxPulang = strtotime("1970-01-01 {$peg['jam_pulang_rule']} UTC");
          // Jika pulang lebih dari aturan, potong
          if ($outS > $maxPulang) {
              $outS = $maxPulang;
          }
      }
      
      if ($outS >= $inS) {
          $durasiDetikHari = $outS - $inS;
          $totKerjaDetik += $durasiDetikHari;
      }
  }

  // Simpan hasil hitungan ke array untuk dipakai di Tabel HTML nanti
  $p['telat_menit_display'] = $telatMenitHari; // Berapa menit telat hari itu
  $p['status_telat']        = $statusTelat;    // String 'Terlambat' / 'Tepat Waktu'
  $p['durasi_detik']        = $durasiDetikHari;// Detik kerja hari itu
  
  $presensi[] = $p;
}
$stmt->close();

/* ---- ketidakhadiran pegawai ---- */
$stmt = $mysqli->prepare("
  SELECT tanggal, keterangan, status_pengajuan
  FROM ketidakhadiran
  WHERE id_pegawai = ?
    AND tanggal BETWEEN ? AND ?
  ORDER BY tanggal ASC
");
$stmt->bind_param('iss', $idPeg, $awal, $akhir);
$stmt->execute();
$resKet = $stmt->get_result();

$ketidakhadiran = [];
$ketApprove = 0;
while ($k = $resKet->fetch_assoc()) {
  $ketidakhadiran[] = $k;
  if (strtoupper($k['status_pengajuan']) === 'DISETUJUI' && isWeekday($k['tanggal'])) {
    $ketApprove++;
  }
}
$stmt->close();

/* ---- hitung summary ---- */
/* ---- hitung summary ---- */
// 1. Hitung Alpha
$alpha = max(0, $workdays - $hadir - $ketApprove);

// 2. Format Total Telat
// Mengubah angka (misal: 90) menjadi teks "1 Jam 30 Menit"
$totalTelatStr = formatDurasi($telatMenitTotal);

// 3. Format Total Jam Kerja
// Konversi dulu dari Detik ke Menit
$totalMenitKerja = (int)floor($totKerjaDetik / 60);
$totalKerjaStr   = formatDurasi($totalMenitKerja);

// 4. Hitung Rata-rata (Opsional, untuk info tambahan)
$avgTelatMenit = $hadir > 0 ? (int)round($telatMenitTotal / $hadir) : 0;
$rataTelatStr  = formatDurasi($avgTelatMenit);

$avgKerjaDetik = $hadir > 0 ? (int)round($totKerjaDetik / $hadir) : 0;
$avgKerjaMenit = (int)floor($avgKerjaDetik / 60);
$rataKerjaStr  = formatDurasi($avgKerjaMenit);

/* -------------------------------------------------
   EXPORT PDF LOGIC
------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once __DIR__ . '/../../vendor/autoload.php';
    
    // Inisialisasi mPDF (Portrait)
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_top' => 10, 'margin_bottom' => 10]);
    $mpdf->SetTitle("Detail Presensi - " . $peg['nama']);

    // CSS untuk PDF
    $css = '
        <style>
            body { font-family: sans-serif; font-size: 10pt; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .info-table { width: 100%; margin-bottom: 20px; }
            .info-table td { padding: 4px; vertical-align: top; }
            .presensi-table { width: 100%; border-collapse: collapse; }
            .presensi-table th, .presensi-table td { border: 1px solid #444; padding: 6px; text-align: center; vertical-align: middle; font-size: 9pt; }
            .presensi-table th { background-color: #f0f0f0; font-weight: bold; }
            .badge-success { color: #006400; font-weight: bold; }
            .badge-danger { color: #8B0000; font-weight: bold; }
            .text-left { text-align: left !important; }
            .summary-box { border: 1px solid #ccc; padding: 10px; background-color: #f9f9f9; margin-bottom: 20px; }
        </style>
    ';

    // Header HTML
    $html = $css . '
    <div class="header">
        <h2 style="margin:0">DETAIL PRESENSI PEGAWAI</h2>
        <small>PT. FAZANA BERKAH MULIA</small>
    </div>

    <table class="info-table">
        <tr>
            <td width="15%"><strong>Nama</strong></td>
            <td width="35%">: ' . htmlspecialchars($peg['nama']) . '</td>
            <td width="15%"><strong>Periode</strong></td>
            <td width="35%">: ' . date('d F Y', strtotime($awal)) . ' - ' . date('d F Y', strtotime($akhir)) . '</td>
        </tr>
        <tr>
            <td><strong>NIP</strong></td>
            <td>: ' . htmlspecialchars($peg['nip']) . '</td>
            <td><strong>Jabatan</strong></td>
            <td>: ' . htmlspecialchars($peg['jabatan']) . '</td>
        </tr>
        <tr>
            <td><strong>Lokasi</strong></td>
            <td>: ' . htmlspecialchars($peg['lokasi_presensi']) . '</td>
            <td></td><td></td>
        </tr>
    </table>

    <div class="summary-box">
        <table width="100%">
            <tr>
                <td align="center"><strong>Hadir</strong><br>' . (int)$hadir . ' Hari</td>
                <td align="center"><strong>Alpha</strong><br>' . (int)$alpha . ' Hari</td>
                <td align="center"><strong>Total Telat</strong><br>' . $totalTelatStr . '</td>
                <td align="center"><strong>Total Jam Kerja</strong><br>' . $totalKerjaStr . '</td>
            </tr>
        </table>
    </div>

    <h3>Riwayat Harian</h3>
    <table class="presensi-table">
        <thead>
            <tr>
                <th width="15%">Tanggal</th>
                <th width="10%">Masuk</th>
                <th width="10%">Foto</th>
                <th width="10%">Keluar</th>
                <th width="10%">Foto</th>
                <th>Status</th>
                <th>Durasi</th>
            </tr>
        </thead>
        <tbody>';

    if (empty($presensi)) {
        $html .= '<tr><td colspan="7">Tidak ada data presensi.</td></tr>';
    } else {
        foreach ($presensi as $p) {
            // Logic Foto
            $imgMasuk = '-';
            if (!empty($p['foto_masuk'])) {
                $path = __DIR__ . '/../../assets/uploads/presensi/' . $p['foto_masuk'];
                if (file_exists($path)) {
                    $imgMasuk = '<img src="' . $path . '" height="40" style="border-radius:4px;">';
                }
            }

            $imgKeluar = '-';
            if (!empty($p['foto_keluar'])) {
                $path = __DIR__ . '/../../assets/uploads/presensi/' . $p['foto_keluar'];
                if (file_exists($path)) {
                    $imgKeluar = '<img src="' . $path . '" height="40" style="border-radius:4px;">';
                }
            }

            // Status Badge Text
            $statusText = 'Tepat Waktu';
            $statusClass = 'badge-success';
            if ($p['status_telat'] === 'Terlambat') {
                $statusText = 'Terlambat (' . formatDurasi($p['telat_menit_display']) . ')';
                $statusClass = 'badge-danger';
            }

            $html .= '<tr>
                <td>' . date('d/m/Y', strtotime($p['tanggal_masuk'])) . '<br><small>' . date('l', strtotime($p['tanggal_masuk'])) . '</small></td>
                <td>' . substr($p['jam_masuk'], 0, 5) . '</td>
                <td>' . $imgMasuk . '</td>
                <td>' . substr($p['jam_keluar'], 0, 5) . '</td>
                <td>' . $imgKeluar . '</td>
                <td class="' . $statusClass . '">' . $statusText . '</td>
                <td>' . formatDurasi((int)floor($p['durasi_detik'] / 60)) . '</td>
            </tr>';
        }
    }
    $html .= '</tbody></table>';

    // Tabel Ketidakhadiran (Jika ada)
    if (!empty($ketidakhadiran)) {
        $html .= '<h3>Riwayat Cuti / Izin / Sakit</h3>
        <table class="presensi-table">
            <thead>
                <tr>
                    <th width="20%">Tanggal</th>
                    <th>Keterangan</th>
                    <th width="20%">Status</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($ketidakhadiran as $k) {
            $html .= '<tr>
                <td>' . date('d F Y', strtotime($k['tanggal'])) . '</td>
                <td class="text-left">' . htmlspecialchars($k['keterangan']) . '</td>
                <td>' . strtoupper($k['status_pengajuan']) . '</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    }

    $mpdf->WriteHTML($html);
    $mpdf->Output('Detail_Presensi_' . $peg['nip'] . '_' . $bulan . '.pdf', 'I');
    exit;
}

// new pdf di detail.php

$judul = "Detail Rekap Pegawai";
include __DIR__ . '/../layout/header.php';
// --- TAMBAHAN WAJIB: Path Foto ---
$baseFoto = '../../assets/uploads/presensi/';

?>
<style>
    .avatar-detail { 
        width: 40px; height: 40px; 
        object-fit: cover; border-radius: 4px; 
        cursor: pointer; border: 1px solid #ddd; 
        transition: transform 0.2s;
    }
    .avatar-detail:hover { transform: scale(1.5); z-index: 10; }
</style>

<style>
    .avatar-detail { 
        width: 40px; height: 40px; 
        object-fit: cover; border-radius: 4px; 
        cursor: pointer; border: 1px solid #ddd; 
        transition: transform 0.2s;
    }
    .avatar-detail:hover { transform: scale(1.5); z-index: 10; }
</style>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />

<style>
    /* Agar peta muncul dengan benar di dalam modal */
    #map-container { height: 400px; width: 100%; border-radius: 8px; }
</style>

<div class="page-body">
  <div class="container-xl">
    
    <div>
           <a href="detail.php?id=<?= $idPeg ?>&bulan=<?= $bulan ?>&export=pdf" class="btn btn-danger" target="_blank">
             <i class="fa fa-file-pdf-o me-1"></i> Export PDF
           </a>
           <a href="rekap_bulanan.php?bulan=<?= urlencode($bulan) ?>&lokasi=<?= urlencode($peg['lokasi_presensi'] ?? '') ?>" class="btn btn-outline-secondary ms-2">
             <i class="fa fa-arrow-left me-1"></i> Kembali
           </a>
       </div>
    </div>

    <div class="row row-cards mb-4">
      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-success text-white avatar"><i class="fa fa-check"></i></span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Total Hadir</div>
                <div class="text-muted"><?= (int)$hadir ?> Hari</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-danger text-white avatar"><i class="fa fa-times"></i></span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Total Alpha</div>
                <div class="text-muted"><?= (int)$alpha ?> Hari</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-warning text-white avatar"><i class="fa fa-exclamation-triangle"></i></span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Total Telat</div>
                <div class="text-muted"><?= $totalTelatStr ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-primary text-white avatar"><i class="fa fa-clock-o"></i></span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Total Jam Kerja</div>
                <div class="text-muted"><?= $totalKerjaStr ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">Riwayat Presensi Harian</h3>
        <span class="badge bg-azure-lt">Rata-rata: <?= $rataKerjaStr ?> / hari</span>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table table-striped">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th class="text-center">Jam Masuk</th>
              <th class="text-center">Foto</th>
              <th class="text-center">Jam Keluar</th>
              <th class="text-center">Foto</th>
              <th>Status</th>
              <th>Durasi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($presensi)): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">Belum ada data presensi.</td></tr>
            <?php else: ?>
              <?php foreach ($presensi as $p): ?>
              <tr>
                <td>
                    <div class="font-weight-medium"><?= date('d/m/Y', strtotime($p['tanggal_masuk'])) ?></div>
                    <div class="small text-muted"><?= date('l', strtotime($p['tanggal_masuk'])) ?></div>
                </td>
                
                <td class="text-center">
                    <div><?= htmlspecialchars($p['jam_masuk'] ?? '-') ?></div>
                    
                    <?php if (!empty($p['latitude_masuk']) && !empty($p['long_kantor'])): ?>
                        <button type="button" class="btn btn-icon btn-sm btn-outline-primary mt-1" 
                                title="Lihat Lokasi Masuk"
                                onclick="showMap(
                                    <?= $p['lat_kantor'] ?>, <?= $p['long_kantor'] ?>, <?= $p['radius'] ?>, 
                                    <?= $p['latitude_masuk'] ?>, <?= $p['longitude_masuk'] ?>, 
                                    'Masuk', '<?= $p['tanggal_masuk'] ?>'
                                )">
                            <i class="fa fa-map-marker"></i>
                        </button>
                    <?php endif; ?>
                </td>
                
                <td class="text-center">
                    <?php if(!empty($p['foto_masuk']) && file_exists(__DIR__ . '/' . $baseFoto . $p['foto_masuk'])): ?>
                        <img src="<?= $baseFoto.$p['foto_masuk'] ?>" class="avatar-detail" title="Masuk" onclick="window.open(this.src, '_blank')">
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>

                <td class="text-center">
                    <div><?= htmlspecialchars($p['jam_keluar'] ?? '-') ?></div>
                    
                    <?php if (!empty($p['latitude_keluar']) && !empty($p['long_kantor'])): ?>
                        <button type="button" class="btn btn-icon btn-sm btn-outline-danger mt-1" 
                                title="Lihat Lokasi Keluar"
                                onclick="showMap(
                                    <?= $p['lat_kantor'] ?>, <?= $p['long_kantor'] ?>, <?= $p['radius'] ?>, 
                                    <?= $p['latitude_keluar'] ?>, <?= $p['longitude_keluar'] ?>, 
                                    'Keluar', '<?= $p['tanggal_masuk'] ?>'
                                )">
                            <i class="fa fa-map-marker"></i>
                        </button>
                    <?php endif; ?>
                </td>

                <td class="text-center">
                    <?php if(!empty($p['foto_keluar']) && file_exists(__DIR__ . '/' . $baseFoto . $p['foto_keluar'])): ?>
                        <img src="<?= $baseFoto.$p['foto_keluar'] ?>" class="avatar-detail" title="Keluar" onclick="window.open(this.src, '_blank')">
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>

                <td>
                    <?php if ($p['status_telat'] === 'Terlambat'): ?>
                        <span class="badge bg-danger text-white mb-1">Terlambat</span>
                        <div class="small text-danger">
                            (<?= formatDurasi($p['telat_menit_display']) ?>)
                        </div>
                    <?php else: ?>
                        <span class="badge bg-success text-white">Tepat Waktu</span>
                    <?php endif; ?>
                </td>

                <td>
                    <strong><?= formatDurasi((int)floor($p['durasi_detik']/60)) ?></strong>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if (!empty($ketidakhadiran)): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Riwayat Cuti / Izin / Sakit</h3>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Keterangan</th>
              <th>Status Approval</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ketidakhadiran as $k): ?>
              <tr>
                <td><?= date('d F Y', strtotime($k['tanggal'])) ?></td>
                <td><span class="fw-bold"><?= htmlspecialchars($k['keterangan']) ?></span></td>
                <td>
                  <?php 
                    $st = strtoupper($k['status_pengajuan']);
                    if($st==='DISETUJUI') echo '<span class="badge bg-success">DISETUJUI</span>';
                    elseif($st==='DITOLAK') echo '<span class="badge bg-danger">DITOLAK</span>';
                    else echo '<span class="badge bg-warning text-dark">PENDING</span>';
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<div class="modal modal-blur fade" id="modal-map" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Lokasi Presensi (<span id="judul-lokasi"></span>)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="tutupModal()"></button>
      </div>
      <div class="modal-body">
        <div id="map-container" style="width: 100%; height: 400px; background-color: #f0f0f0;"></div>
        
        <div class="mt-2 text-center small text-muted">
            <span class="badge bg-green me-2"></span> Area Kantor
            &nbsp;|&nbsp;
            <span class="badge bg-red"></span> Posisi Pegawai
        </div>
      </div>
      <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="tutupModal()">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
    var map = null;

    function showMap(latKantor, longKantor, radius, latPegawai, longPegawai, tipe, tanggal) {
        document.getElementById('judul-lokasi').innerText = tipe + " - " + tanggal;

        // Buka Modal dengan jQuery (Standar AdminLTE/Tabler)
        $('#modal-map').modal('show');

        // Tunggu animasi selesai baru render peta
        setTimeout(function() {
            if (map !== null) { map.remove(); map = null; }

            // Init Peta
            map = L.map('map-container').setView([latKantor, longKantor], 15);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            // Icon Custom
            var iconBase = 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/';
            var redIcon = new L.Icon({
                iconUrl: iconBase + 'marker-icon-2x-red.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
            });
            var greenIcon = new L.Icon({
                iconUrl: iconBase + 'marker-icon-2x-green.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
            });

            // Kantor
            L.circle([latKantor, longKantor], { color: 'green', fillColor: '#2eb82e', fillOpacity: 0.2, radius: radius }).addTo(map);
            L.marker([latKantor, longKantor], {icon: greenIcon}).addTo(map).bindPopup("Lokasi Kantor");

            // Pegawai
            L.marker([latPegawai, longPegawai], {icon: redIcon}).addTo(map).bindPopup("Posisi Pegawai").openPopup();

            // Fit Bounds
            var group = new L.featureGroup([L.marker([latKantor, longKantor]), L.marker([latPegawai, longPegawai])]);
            map.fitBounds(group.getBounds().pad(0.2));
            
            map.invalidateSize();
        }, 500);
    }

    // FUNGSI KHUSUS UNTUK MENUTUP MODAL & MEMBERSIHKAN LAYAR
    function tutupModal() {
        // 1. Sembunyikan modal secara manual
        $('#modal-map').modal('hide');
        
        // 2. Hapus peta dari memori
        if (map !== null) {
            map.remove();
            map = null;
        }

        // 3. (PENTING) Paksa hapus layar abu-abu jika tertinggal
        // Kadang backdrop tidak hilang otomatis, jadi kita hapus manual
        setTimeout(function(){
            $('.modal-backdrop').remove();       // Hapus elemen backdrop
            $('body').removeClass('modal-open'); // Hapus class di body agar bisa scroll lagi
            $('body').css('padding-right', '');  // Reset padding
        }, 300);
    }
</script>
<?php include __DIR__ . '/../layout/footer.php'; ?>



<!-- maps sudah ada tinggal check ulang nanti -->