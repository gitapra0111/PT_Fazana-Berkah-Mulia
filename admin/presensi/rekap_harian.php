<?php
// =====================================
// admin/presensi/rekap_harian.php
// =====================================
declare(strict_types=1);

// session admin
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

require_once __DIR__ . '/../../config.php';

// --- Map koneksi mysqli ---
$mysqli = null;
if (isset($connection) && $connection instanceof mysqli) $mysqli = $connection;
elseif (isset($conn) && $conn instanceof mysqli)         $mysqli = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli)   $mysqli = $koneksi;

if (!$mysqli) {
  http_response_code(500);
  die("Koneksi database tidak ditemukan. Pastikan config.php mengisi \$connection / \$conn / \$koneksi.");
}

// --- Parameter filter ---
$tanggal = (isset($_GET['tanggal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tanggal']))
  ? $_GET['tanggal'] : date('Y-m-d');
$lokasi  = isset($_GET['lokasi']) ? trim((string)$_GET['lokasi']) : '';

// --- Ambil daftar lokasi (untuk dropdown) ---
$daftarLokasi = [];
$resLok = $mysqli->query("SELECT nama_lokasi FROM lokasi_presensi ORDER BY nama_lokasi ASC");
if ($resLok) {
  while ($r = $resLok->fetch_assoc()) $daftarLokasi[] = $r['nama_lokasi'];
  $resLok->free();
}

/*
 * SQL utama
 * - join presensi di tanggal tsb
 * - join ketidakhadiran di tanggal tsb
 *   (kalau ada, kita pakai ini sebagai status utama)
 */
$sql = "
  SELECT
    pg.id           AS id_pegawai,
    pg.nip,
    pg.nama,
    pg.jabatan,
    pg.lokasi_presensi,
    p.foto_masuk,   -- TAMBAHAN: Ambil foto masuk
    p.foto_keluar,  -- TAMBAHAN: Ambil foto keluar
    p.tanggal_masuk,
    p.jam_masuk,
    p.tanggal_keluar,
    p.jam_keluar,
    lp.jam_masuk    AS jam_masuk_rule,
    lp.jam_pulang   AS jam_pulang_rule,
    k.keterangan    AS ket_keterangan,
    k.status_pengajuan AS ket_status,
    k.deskripsi     AS ket_deskripsi
  FROM pegawai pg
  LEFT JOIN presensi p
    ON p.id_pegawai = pg.id AND p.tanggal_masuk = ?
  LEFT JOIN ketidakhadiran k
    ON k.id_pegawai = pg.id AND k.tanggal = ?
  LEFT JOIN lokasi_presensi lp
    ON lp.nama_lokasi = pg.lokasi_presensi
";
$params = [$tanggal, $tanggal];
$types  = "ss";

if ($lokasi !== '') {
  $sql .= " WHERE pg.lokasi_presensi = ? ";
  $params[] = $lokasi;
  $types   .= "s";
}

$sql .= " ORDER BY pg.nama ASC ";

$stmt = $mysqli->prepare($sql);
if (!$stmt) { http_response_code(500); die("DB Error: ".$mysqli->error); }
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
  // data dari presensi
  $jm_db   = trim((string)($row['jam_masuk'] ?? ''));
  $jk_db   = trim((string)($row['jam_keluar'] ?? ''));
  $jm_rule = trim((string)($row['jam_masuk_rule'] ?? ''));
  $jp_rule = trim((string)($row['jam_pulang_rule'] ?? ''));

  // cek apakah ada ketidakhadiran
  $adaKet = !empty($row['ket_keterangan']); // cuti/izin/sakit/dinas luar

  if ($adaKet) {
    // kalau ada ketidakhadiran, status presensi kita override
    $statusText = $row['ket_keterangan'] . ' (' . ($row['ket_status'] ?? 'PENDING') . ')';
    $rows[] = [
      'nip'     => $row['nip'],
      'nama'    => $row['nama'],
      'jab'     => $row['jabatan'],
      'lokasi'  => $row['lokasi_presensi'],
      'status'  => $statusText,
      'tgl'     => $tanggal,
      'jam_in'  => '',
      'jam_out' => '',
      // --- TAMBAHAN 1: Set kosong karena sedang Cuti/Sakit ---
      'foto_masuk'  => '', 
      'foto_keluar' => '',
      'telat'   => 0,
      'durasi'  => '',
      'is_ket'  => true,
      'ket_desc'=> $row['ket_deskripsi'] ?? '',
    ];
    continue;
  }

  // kalau nggak ada ketidakhadiran → pakai logika presensi biasa
  $hadir = ($row['tanggal_masuk'] !== null);

  // telat (menit)
  $telat_menit = 0;
  if ($hadir && $jm_rule !== '' && $jm_rule !== '00:00:00' && $jm_db !== '' && $jm_db !== '00:00:00') {
    $t_db   = strtotime("1970-01-01 {$jm_db} UTC");
    $t_rule = strtotime("1970-01-01 {$jm_rule} UTC");
    if ($t_db > $t_rule) {
      $telat_menit = (int) round(($t_db - $t_rule) / 60);
    }
  }

  // --- [BARU] FORMAT TEXT TERLAMBAT ---
  // Ubah angka menit menjadi "X Jam Y Menit"
  $telat_str = '0 Menit'; 
  if ($telat_menit > 0) {
      $jam_t   = floor($telat_menit / 60);
      $menit_t = $telat_menit % 60;
      
      $telat_str = '';
      if ($jam_t > 0) {
          $telat_str .= $jam_t . ' Jam ';
      }
      $telat_str .= $menit_t . ' Menit';
  }
  // ------------------------------------

  // durasi kerja
  $durasi = '';
  if ($hadir && $jm_db !== '' && $jm_db !== '00:00:00' && $jk_db !== '' && $jk_db !== '00:00:00') {
    $t_in  = strtotime("1970-01-01 {$jm_db} UTC");
    $t_out = strtotime("1970-01-01 {$jk_db} UTC");
    
    // --- LOGIKA BARU: Cek Batas Jam Pulang (Capping) ---
    // Variabel $jp_rule sudah Anda definisikan di atas (baris 94 kode Anda)
    if ($jp_rule !== '' && $jp_rule !== '00:00:00') {
        $t_max_pulang = strtotime("1970-01-01 {$jp_rule} UTC");
        
        // Jika pegawai pulang LEBIH DARI jam aturan admin
        // Maka hitungan jam keluarnya dipotong mentok ke jam aturan
        if ($t_out > $t_max_pulang) {
            $t_out = $t_max_pulang;
        }
    }
    // ----------------------------------------------------

    if ($t_out >= $t_in) {
      $sec = $t_out - $t_in;
      $h = floor($sec / 3600);
      $m = floor(($sec % 3600) / 60);
      
      // --- LOGIKA TAMPILAN BARU ---
      // Format Lama: $durasi = sprintf('%02d:%02d', $h, $m);
      
      // Format Baru (Dynamic):
      $durasi = '';
      if ($h > 0) {
          $durasi .= (int)$h . ' Jam '; // Tampilkan Jam hanya jika > 0
      }
      $durasi .= (int)$m . ' Menit';   // Selalu tampilkan Menit
    }
  }

  $rows[] = [
    'nip'     => $row['nip'],
    'nama'    => $row['nama'],
    'jab'     => $row['jabatan'],
    'lokasi'  => $row['lokasi_presensi'],
    'status'  => $hadir ? 'Hadir' : 'Tidak Hadir',
    'tgl'     => $tanggal,
    'jam_in'  => $jm_db ?: '',
    'jam_out' => $jk_db ?: '',
    // --- TAMBAHAN 2: Masukkan Data Foto dari Database ---
    'foto_masuk'  => $row['foto_masuk'] ?? '',
    'foto_keluar' => $row['foto_keluar'] ?? '',
    'telat' => $telat_str,   // <--- Ganti jadi variabel teks
    'durasi'  => $durasi,
    'is_ket'  => false,
    'ket_desc'=> '',
  ];
}
$stmt->close();

/* =========================================================
   LOGIKA EXPORT PDF (TANDA TANGAN DINAMIS)
   ========================================================= */
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once __DIR__ . '/../../vendor/autoload.php';

    // 1. Helper Tanggal Indo
    $namaBulanIndo = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    function tglIndo($tgl, $bulanArr) {
        $split = explode('-', $tgl);
        return $split[2] . ' ' . $bulanArr[(int)$split[1]] . ' ' . $split[0];
    }
    
    $tglCetak = tglIndo($tanggal, $namaBulanIndo);
    $tglTandaTangan = date('d') . ' ' . $namaBulanIndo[(int)date('m')] . ' ' . date('Y');

    // 2. LOGIKA LOKASI & TANDA TANGAN
    $lokasiOutput = "Semua Lokasi"; 
    
    // PERUBAHAN DI SINI: Default Kosong (Agar jika "Semua Lokasi", kotanya hilang)
    $prefixTandaTangan = ""; 

    if (isset($mysqli) && !empty($lokasi)) {
        // Jika Admin memilih lokasi tertentu
        $stmt = $mysqli->prepare("SELECT nama_lokasi, alamat_lokasi FROM lokasi_presensi WHERE nama_lokasi = ?");
        $stmt->bind_param("s", $lokasi);
        $stmt->execute();
        $dataLokasi = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($dataLokasi) {
            // Header: Nama Lokasi
            $lokasiOutput = htmlspecialchars($dataLokasi['nama_lokasi']);
            
            // Footer: Ambil Kota dari Alamat
            if (!empty($dataLokasi['alamat_lokasi'])) {
                $parts = explode(',', $dataLokasi['alamat_lokasi']);
                $kota = trim(end($parts));
                // Set Prefix: "Surabaya, "
                $prefixTandaTangan = ucwords(strtolower($kota)) . ', ';
            }
        }
    }

    // 3. Setup mPDF
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4-L',
        'margin_top' => 10, 'margin_bottom' => 15, 'margin_left' => 10, 'margin_right' => 10
    ]);
    
    $mpdf->SetTitle("Rekap Harian - " . $tglCetak);
    $mpdf->SetFooter('Dicetak pada: ' . date('d-m-Y H:i:s') . '|PT. FAZANA BERKAH MULIA|Halaman {PAGENO} dari {nbpg}');

    // 4. CSS (Tetap Sama)
    $css = '
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #333; }
        .header-container { border-bottom: 2px solid #2c3e50; padding-bottom: 10px; margin-bottom: 20px; }
        .company-name { font-size: 20pt; font-weight: bold; color: #2c3e50; text-transform: uppercase; margin: 0; }
        .report-title { font-size: 12pt; font-weight: bold; color: #555; text-transform: uppercase; margin-top: 5px; letter-spacing: 1px; }
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { padding: 4px; vertical-align: top; font-size: 10pt; }
        .label { font-weight: bold; width: 100px; color: #444; }
        .data-table { width: 100%; border-collapse: collapse; box-shadow: 0 0 20px rgba(0, 0, 0, 0.15); }
        .data-table th { background-color: #2c3e50; color: #ffffff; text-align: center; padding: 10px; font-size: 9pt; text-transform: uppercase; font-weight: bold; border: 1px solid #2c3e50; }
        .data-table td { padding: 8px; border-bottom: 1px solid #dddddd; vertical-align: middle; font-size: 9pt; }
        .data-table tr:nth-of-type(even) { background-color: #f8f9fa; }
        .data-table tr:last-of-type { border-bottom: 2px solid #2c3e50; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .text-success { color: #27ae60; font-weight: bold; }
        .text-danger { color: #c0392b; font-weight: bold; }
        .text-warning { color: #d35400; font-weight: bold; }
        .photo-img { border-radius: 4px; border: 1px solid #ddd; padding: 2px; height: 35px; width: auto; }
        .col-nama { font-weight: bold; color: #2c3e50; font-size: 10pt; }
        .col-nip { font-size: 8pt; color: #7f8c8d; }
    </style>
    ';

    // Hitung Ringkasan
    $hadirCount = 0; $alphaCount = 0;
    foreach ($rows as $r) {
        if ($r['status'] == 'Hadir' || $r['status'] == 'Terlambat') $hadirCount++;
        else $alphaCount++;
    }

    // 5. Susun HTML
    $html = $css . '
    <div class="header-container center">
        <h1 class="company-name">PT. FAZANA BERKAH MULIA</h1>
        <div class="report-title">Laporan Rekap Presensi Harian</div>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Tanggal</td><td>: <strong>' . $tglCetak . '</strong></td>
            <td class="label" style="text-align:right">Total Hadir</td><td style="width:50px">: ' . $hadirCount . '</td>
        </tr>
        <tr>
            <td class="label">Lokasi</td><td>: ' . $lokasiOutput . '</td>
            <td class="label" style="text-align:right">Tidak Hadir</td><td>: ' . $alphaCount . '</td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="20%" style="text-align:left; padding-left:10px;">Pegawai</th>
                <th width="10%">Jam Masuk</th>
                <th width="8%">Foto</th>
                <th width="10%">Jam Keluar</th>
                <th width="8%">Foto</th>
                <th width="12%">Status</th>
                <th width="12%">Telat</th>
                <th width="15%">Durasi</th>
            </tr>
        </thead>
        <tbody>';

    $no = 1;
    if (empty($rows)) {
        $html .= '<tr><td colspan="9" class="center" style="padding:20px;">Tidak ada data presensi pada tanggal ini.</td></tr>';
    } else {
        foreach ($rows as $r) {
            $st = $r['status'];
            $stClass = 'text-warning'; 
            if ($st == 'Hadir') $stClass = 'text-success';
            elseif ($st == 'Tidak Hadir' || $st == 'Alpha') $stClass = 'text-danger';
            elseif ($st == 'Terlambat') $stClass = 'text-danger';

            $folderFoto = __DIR__ . '/../../assets/uploads/presensi/';
            $imgMasuk = (!empty($r['foto_masuk']) && file_exists($folderFoto . $r['foto_masuk'])) 
                ? '<img src="' . $folderFoto . $r['foto_masuk'] . '" class="photo-img">' : '-';
            $imgKeluar = (!empty($r['foto_keluar']) && file_exists($folderFoto . $r['foto_keluar'])) 
                ? '<img src="' . $folderFoto . $r['foto_keluar'] . '" class="photo-img">' : '-';

            $telatTeks = ((string)$r['telat'] !== '0' && (string)$r['telat'] !== '0 Menit') ? $r['telat'] : '-';

            $html .= '<tr>
                <td class="center">' . $no++ . '</td>
                <td style="padding-left:10px;">
                    <div class="col-nama">' . htmlspecialchars($r['nama']) . '</div>
                    <div class="col-nip">' . htmlspecialchars((string)$r['nip']) . '</div>
                </td>
                <td class="center">' . htmlspecialchars($r['jam_in']) . '</td>
                <td class="center">' . $imgMasuk . '</td>
                <td class="center">' . htmlspecialchars($r['jam_out']) . '</td>
                <td class="center">' . $imgKeluar . '</td>
                <td class="center bold ' . $stClass . '">' . htmlspecialchars($st) . '</td>
                <td class="center">' . htmlspecialchars($telatTeks) . '</td>
                <td class="center bold">' . htmlspecialchars($r['durasi']) . '</td>
            </tr>';
        }
    }

    $html .= '</tbody></table>

    <div style="margin-top: 30px; page-break-inside: avoid;">
        <table style="width: 100%; border: none;">
            <tr>
                <td style="width: 60%; border: none;"></td>
                <td style="width: 40%; border: none; text-align: center;">
                    <p style="margin-bottom: 5px;">' . $prefixTandaTangan . $tglTandaTangan . '</p>
                    <p>Mengetahui,</p>
                    <p class="bold" style="color: #2c3e50;">Kepala HRD</p>
                    <br><br><br><br>
                    <p style="border-bottom: 1px solid #333; display: inline-block; width: 200px;"></p>
                    <p style="margin-top: 5px;">( .......................................... )</p>
                </td>
            </tr>
        </table>
    </div>';

    $mpdf->WriteHTML($html);
    $mpdf->Output('Rekap_Harian_' . $tanggal . '.pdf', 'I');
    exit;
}

// new

// ============= RENDER HALAMAN =============
$judul = "Rekap Presensi Harian";
include __DIR__ . '/../layout/header.php';
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
    white-space: nowrap; /* Mencegah teks turun ke bawah agar bisa di-scroll samping */
    background-color: #fff;
    border: 1px solid #dee2e6;
    padding: 10px 15px;
  }

  /* 3. LOGIKA STICKY KOLOM NAMA (KOLOM KE-3) */
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

  /* Efek Hover agar baris tetap terlihat jelas */
  .table tbody tr:hover td,
  .table tbody tr:hover td:nth-child(3) {
    background-color: #f1f5f9;
  }
</style>

<div class="page-body">
  <div class="container-xl">

    <div class="card mb-3">
      <div class="card-header"><strong>Filter Rekap Harian</strong></div>
      <div class="card-body">
        <form class="row g-2" method="get" action="">
          <div class="col-md-3">
            <label class="form-label">Tanggal</label>
            <input type="date" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Lokasi (opsional)</label>
            <select name="lokasi" class="form-control">
              <option value="">— Semua Lokasi —</option>
              <?php foreach ($daftarLokasi as $L): ?>
                <option value="<?= htmlspecialchars($L) ?>" <?= $lokasi===$L?'selected':''; ?>>
                  <?= htmlspecialchars($L) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5 d-flex align-items-end gap-2">
            <button class="btn btn-primary me-2" type="submit"><i class="fa fa-search"></i> Tampilkan</button>
            <a class="btn btn-outline-danger me-2" href="?tanggal=<?= urlencode($tanggal) ?>&lokasi=<?= urlencode($lokasi) ?>&export=pdf" target="_blank">
                <i class="fa fa-file-pdf-o"></i> Export PDF
            </a>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Hasil — <?= htmlspecialchars($tanggal) ?> <?= $lokasi!=='' ? " • Lokasi: ".htmlspecialchars($lokasi) : "" ?></strong>
        <span class="text-muted small">Total Pegawai: <?= count($rows) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0">
          <thead class="thead-light">
            <tr>
              <th>#</th>
              <th>NIP</th>
              <th>Nama</th>
              <th>Jabatan</th>
              <th>Lokasi</th>
              <th>Status</th>
              <th>Jam Masuk</th>
              <th>Jam Keluar</th>
              <th>Terlambat</th>
              <th>Durasi Kerja</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="10" class="text-center text-muted">Tidak ada data.</td></tr>
            <?php else: $i=1; foreach ($rows as $r): ?>
              <tr class="<?= $r['is_ket'] ? 'table-warning' : '' ?>">
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($r['nip']) ?></td>
                <td><?= htmlspecialchars($r['nama']) ?></td>
                <td><?= htmlspecialchars($r['jab']) ?></td>
                <td><?= htmlspecialchars($r['lokasi']) ?></td>
                <td>
                  <?php if ($r['is_ket']): ?>
                    <span class="badge bg-warning text-dark"><?= htmlspecialchars($r['status']) ?></span>
                  <?php else: ?>
                    <span class="badge <?= $r['status']==='Hadir'?'bg-success':'bg-danger' ?>">
                      <?= htmlspecialchars($r['status']) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['jam_in']) ?></td>
                <td><?= htmlspecialchars($r['jam_out']) ?></td>
                <td><?= htmlspecialchars((string)$r['telat']) ?></td>
                <td>
                  <?= htmlspecialchars($r['durasi']) ?>
                  <?php if ($r['is_ket'] && $r['ket_desc']): ?>
                    <div class="text-muted small"><?= htmlspecialchars($r['ket_desc']) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
<?php include __DIR__ . '/../layout/footer.php'; ?>



<!-- excel to pdf -->