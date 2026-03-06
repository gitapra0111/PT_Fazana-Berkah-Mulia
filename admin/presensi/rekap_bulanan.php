<?php
// =====================================
// admin/presensi/rekap_bulanan.php
// =====================================
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

require_once __DIR__ . '/../../config.php';

/* -------------------------------------------------
   1. MAP KONEKSI
------------------------------------------------- */
$mysqli = null;
if (isset($connection) && $connection instanceof mysqli)       $mysqli = $connection;
elseif (isset($conn) && $conn instanceof mysqli)              $mysqli = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli)        $mysqli = $koneksi;

if (!$mysqli) {
  http_response_code(500);
  die("Koneksi database tidak ditemukan. Pastikan config.php mengisi \$connection / \$conn / \$koneksi.");
}

/* -------------------------------------------------
   2. PARAMETER
------------------------------------------------- */
$bulan  = (isset($_GET['bulan']) && preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'])) ? $_GET['bulan'] : date('Y-m');
$lokasi = isset($_GET['lokasi']) ? trim((string)$_GET['lokasi']) : '';

[$tahun, $bln] = array_map('intval', explode('-', $bulan));
$awal  = sprintf('%04d-%02d-01', $tahun, $bln);
$akhir = date('Y-m-t', strtotime($awal));

/* -------------------------------------------------
   3. FUNGSI BANTU
------------------------------------------------- */
function countWorkdays(string $start, string $end): int {
  $s = new DateTime($start);
  $e = new DateTime($end);
  $e->setTime(23, 59, 59);
  $days = 0;
  while ($s <= $e) {
    $w = (int)$s->format('N'); // 1=Mon..7=Sun
    if ($w <= 5) $days++;
    $s->modify('+1 day');
  }
  return $days;
}
function isWeekday(string $date): bool {
  return ((int)date('N', strtotime($date))) <= 5;
}

function formatDurasi(int $menit): string {
    if ($menit <= 0) return '0 Menit';
    
    $jam = floor($menit / 60);
    $sisamenit = $menit % 60;
    
    $out = [];
    if ($jam > 0) $out[] = $jam . ' Jam';
    if ($sisamenit > 0) $out[] = $sisamenit . ' Menit';
    
    return implode(' ', $out);
}

$workdays = countWorkdays($awal, $akhir);

/* -------------------------------------------------
   4. DAFTAR LOKASI (UNTUK FILTER)
------------------------------------------------- */
$daftarLokasi = [];
$resLok = $mysqli->query("SELECT nama_lokasi FROM lokasi_presensi ORDER BY nama_lokasi ASC");
if ($resLok) {
  while ($r = $resLok->fetch_assoc()) {
    if (!empty($r['nama_lokasi'])) $daftarLokasi[] = $r['nama_lokasi'];
  }
  $resLok->free();
}

/* -------------------------------------------------
   5. AMBIL PEGAWAI + RULE JAM
------------------------------------------------- */
$cond   = "";
$params = [];
$types  = "";

if ($lokasi !== '') {
  $cond     = " WHERE pg.lokasi_presensi = ? ";
  $params[] = $lokasi;
  $types   .= "s";
}

$sqlPeg = "
  SELECT pg.id, pg.nip, pg.nama, pg.jabatan, pg.lokasi_presensi,
         lp.jam_masuk AS jam_masuk_rule,
         lp.jam_pulang AS jam_pulang_rule
  FROM pegawai pg
  LEFT JOIN lokasi_presensi lp ON lp.nama_lokasi = pg.lokasi_presensi
  {$cond}
  ORDER BY pg.nama ASC
";
$stmtPeg = $mysqli->prepare($sqlPeg);
if (!$stmtPeg) {
  http_response_code(500);
  die("DB Error (pegawai): " . $mysqli->error);
}
if ($types !== "") {
  $stmtPeg->bind_param($types, ...$params);
}
$stmtPeg->execute();
$resPeg = $stmtPeg->get_result();

$pegawai = [];
while ($r = $resPeg->fetch_assoc()) {
  $pegawai[(int)$r['id']] = [
    'id'          => (int)$r['id'],
    'nip'         => $r['nip'],
    'nama'        => $r['nama'],
    'jab'         => $r['jabatan'],
    'lokasi'      => $r['lokasi_presensi'],
    'rule_in'     => trim((string)($r['jam_masuk_rule'] ?? '')),
    'rule_out'    => trim((string)($r['jam_pulang_rule'] ?? '')),
    // agregat
    'hadir'       => 0,
    'telat_h'     => 0,
    'telat_m'     => 0,
    'kerja_s'     => 0,
    'ket_approve' => 0,
    'ket_semua'   => 0,
    'ket_jenis'   => [],
  ];
}
$stmtPeg->close();

/* -------------------------------------------------
   6. PRESENSI & KETIDAKHADIRAN
------------------------------------------------- */
if (!empty($pegawai)) {
  $ids = array_keys($pegawai);
  $in  = implode(',', array_fill(0, count($ids), '?'));

  /* ---------- PRESENSI ---------- */
  $typesPres = str_repeat('i', count($ids)) . 'ss';
  $sqlPres = "
    SELECT id_pegawai, tanggal_masuk, jam_masuk, jam_keluar
    FROM presensi
    WHERE id_pegawai IN ($in)
      AND tanggal_masuk BETWEEN ? AND ?
    ORDER BY id_pegawai ASC, tanggal_masuk ASC
  ";
  $stmtPres = $mysqli->prepare($sqlPres);
  if (!$stmtPres) { http_response_code(500); die("DB Error (presensi): ".$mysqli->error); }
  $bindPres = array_merge($ids, [$awal, $akhir]);
  $stmtPres->bind_param($typesPres, ...$bindPres);
  $stmtPres->execute();
  $resPres = $stmtPres->get_result();

  while ($p = $resPres->fetch_assoc()) {
    $idp = (int)$p['id_pegawai'];
    if (!isset($pegawai[$idp])) continue;

    $tgl      = $p['tanggal_masuk'];
    $jm_db    = trim((string)($p['jam_masuk'] ?? ''));
    $jk_db    = trim((string)($p['jam_keluar'] ?? ''));
    $rule_in  = $pegawai[$idp]['rule_in'];

    // hitung hadir hanya hari kerja
    if (isWeekday($tgl)) {
      $pegawai[$idp]['hadir']++;

      // cek telat
      if ($rule_in !== '' && $rule_in !== '00:00:00' && $jm_db !== '' && $jm_db !== '00:00:00') {
        $t_db   = strtotime("1970-01-01 {$jm_db} UTC");
        $t_rule = strtotime("1970-01-01 {$rule_in} UTC");
        if ($t_db > $t_rule) {
          $diffMenit = (int)round(($t_db - $t_rule) / 60);
          // ATURAN BARU: Hanya hitung jika telat LEBIH DARI 60 menit (1 Jam)
          if ($diffMenit > 60) {
              $pegawai[$idp]['telat_h']++;      // Tambah frekuensi telat
              $pegawai[$idp]['telat_m'] += $diffMenit; // Tambah total menit
          }
        }
      }
    }

    // akumulasi jam kerja (boleh di luar hari kerja)
    if ($jm_db !== '' && $jm_db !== '00:00:00' && $jk_db !== '' && $jk_db !== '00:00:00') {
      $inS  = strtotime("1970-01-01 {$jm_db} UTC");
      $outS = strtotime("1970-01-01 {$jk_db} UTC");
      
      // LOGIKA CAPPING (Batas Jam Pulang)
      $rule_out = $pegawai[$idp]['rule_out']; // Ambil rule jam pulang
      if ($rule_out !== '' && $rule_out !== '00:00:00') {
          $maxPulang = strtotime("1970-01-01 {$rule_out} UTC");
          if ($outS > $maxPulang) {
              $outS = $maxPulang; // Potong kelebihan jam
          }
      }

      if ($outS >= $inS) {
        $pegawai[$idp]['kerja_s'] += ($outS - $inS);
      }
    }
  }
  $stmtPres->close();

  /* ---------- KETIDAKHADIRAN ---------- */
  $typesKet = str_repeat('i', count($ids)) . 'ss';
  $sqlKet = "
    SELECT id_pegawai, tanggal, keterangan, status_pengajuan
    FROM ketidakhadiran
    WHERE id_pegawai IN ($in)
      AND tanggal BETWEEN ? AND ?
  ";
  $stmtKet = $mysqli->prepare($sqlKet);
  if (!$stmtKet) { http_response_code(500); die("DB Error (ketidakhadiran): ".$mysqli->error); }
  $bindKet = array_merge($ids, [$awal, $akhir]);
  $stmtKet->bind_param($typesKet, ...$bindKet);
  $stmtKet->execute();
  $resKet = $stmtKet->get_result();

  while ($k = $resKet->fetch_assoc()) {
    $idp = (int)$k['id_pegawai'];
    if (!isset($pegawai[$idp])) continue;

    $pegawai[$idp]['ket_semua']++;

    $jenis = strtolower(trim((string)($k['keterangan'] ?? 'lainnya')));
    if ($jenis === '') $jenis = 'lainnya';
    if (!isset($pegawai[$idp]['ket_jenis'][$jenis])) {
      $pegawai[$idp]['ket_jenis'][$jenis] = 0;
    }
    $pegawai[$idp]['ket_jenis'][$jenis]++;

    if (strtoupper($k['status_pengajuan'] ?? '') === 'DISETUJUI' && isWeekday($k['tanggal'])) {
      $pegawai[$idp]['ket_approve']++;
    }
  }
  $stmtKet->close();
}

/* -------------------------------------------------
   7. SUSUN DATA TABEL
------------------------------------------------- */
$rows = [];
foreach ($pegawai as $g) {
  // Hitung Total Jam Kerja (dari detik ke menit dulu)
  $total_menit_kerja = (int)floor($g['kerja_s'] / 60);
  $tampilan_jam_kerja = formatDurasi($total_menit_kerja);

  // Hitung Rata-rata Jam Kerja
  $avg_menit_kerja = $g['hadir'] > 0 ? (int)round($total_menit_kerja / $g['hadir']) : 0;
  $tampilan_avg_kerja = formatDurasi($avg_menit_kerja);

  // Format Total Telat
  $tampilan_total_telat = formatDurasi($g['telat_m']);

  // Format Rata-rata Telat
  $avg_telat = $g['hadir'] > 0 ? (int)round($g['telat_m'] / $g['hadir']) : 0;
  $tampilan_avg_telat = formatDurasi($avg_telat);

  // alpha = hari kerja - hadir - ketidakhadiran disetujui
  $alpha = max(0, $workdays - $g['hadir'] - $g['ket_approve']);

  // teks ketidakhadiran
  $ketText = $g['ket_semua'] . 'x';
  if (!empty($g['ket_jenis'])) {
    $parts = [];
    foreach ($g['ket_jenis'] as $jn => $cnt) {
      $parts[] = ucfirst($jn) . ':' . $cnt;
    }
    $ketText .= ' (' . implode(', ', $parts) . ')';
  }

  $rows[] = [
    'id'       => $g['id'],
    'nip'      => $g['nip'],
    'nama'     => $g['nama'],
    'jab'      => $g['jab'],
    'lokasi'   => $g['lokasi'],
    'hadir'    => $g['hadir'],
    'ket_text' => $ketText,
    'alpha'    => $alpha,
    'telatH'   => $g['telat_h'],
    'telatM'   => $tampilan_total_telat, // Sudah diformat
    'avgTel'   => $tampilan_avg_telat,   // Sudah diformat
    'totKer'   => $tampilan_jam_kerja,   // Sudah diformat
    'avgKer'   => $tampilan_avg_kerja,   // Sudah diformat
  ];
}

/* -------------------------------------------------
   8b. EXPORT PDF (VERSI FINAL - HEADER BERSIH & TTD DINAMIS)
------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once __DIR__ . '/../../vendor/autoload.php';

    // 1. Helper Tanggal Indo
    $namaBulanIndo = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    // Format Periode
    $thn = substr($bulan, 0, 4);
    $bln = (int)substr($bulan, 5, 2);
    $periodeIndo = $namaBulanIndo[$bln] . ' ' . $thn;
    $tglIndo = date('d') . ' ' . $namaBulanIndo[(int)date('m')] . ' ' . date('Y');

    // 2. LOGIKA LOKASI & TANDA TANGAN
    $lokasiOutput = "Semua Lokasi"; // Default Header
    $prefixTandaTangan = "";        // Default Tanda Tangan (Kosong = Cuma Tanggal)

    if (isset($mysqli) && !empty($lokasi)) {
        // KASUS: Admin memilih SATU lokasi spesifik
        $stmt = $mysqli->prepare("SELECT nama_lokasi, alamat_lokasi FROM lokasi_presensi WHERE nama_lokasi = ?");
        $stmt->bind_param("s", $lokasi);
        $stmt->execute();
        $dataLokasi = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($dataLokasi) {
            // A. HEADER: Nama Lokasi Saja (Tanpa Alamat Panjang)
            $lokasiOutput = htmlspecialchars($dataLokasi['nama_lokasi']);

            // B. FOOTER: Ambil Nama Kota dari Alamat untuk Tanda Tangan
            // Asumsi format alamat: "Jl. Raya No 1, Kecamatan, Kota"
            if (!empty($dataLokasi['alamat_lokasi'])) {
                $parts = explode(',', $dataLokasi['alamat_lokasi']);
                $kota = trim(end($parts)); // Ambil kata terakhir
                
                // Set Prefix jadi: "Surabaya, "
                $prefixTandaTangan = ucwords(strtolower($kota)) . ', '; 
            }
        }
    }
    // Jika "Semua Lokasi", $prefixTandaTangan tetap "" (kosong), jadi nanti cuma muncul Tanggal.

    // 3. Setup mPDF (Landscape)
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 
        'format' => 'A4-L',
        'margin_top' => 10, 'margin_bottom' => 15, 'margin_left' => 10, 'margin_right' => 10
    ]);
    
    $mpdf->SetTitle("Rekap Bulanan - " . $periodeIndo);
    $mpdf->SetFooter('Dicetak pada: ' . date('d-m-Y H:i:s') . '|PT. FAZANA BERKAH MULIA|Halaman {PAGENO} dari {nbpg}');

    // 4. CSS PREMIUM
    $css = '
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #333; }
        
        /* KOP SURAT */
        .header-container { border-bottom: 2px solid #2c3e50; padding-bottom: 10px; margin-bottom: 20px; }
        .company-name { font-size: 20pt; font-weight: bold; color: #2c3e50; text-transform: uppercase; margin: 0; }
        .report-title { font-size: 12pt; font-weight: bold; color: #555; text-transform: uppercase; margin-top: 5px; letter-spacing: 1px; }

        /* TABEL INFO */
        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { padding: 4px; vertical-align: top; font-size: 10pt; }
        .label { font-weight: bold; width: 130px; color: #444; }

        /* TABEL DATA */
        .data-table { width: 100%; border-collapse: collapse; box-shadow: 0 0 20px rgba(0, 0, 0, 0.15); }
        .data-table th { 
            background-color: #2c3e50; color: #ffffff; 
            text-align: center; padding: 12px; font-size: 9pt;
            text-transform: uppercase; font-weight: bold;
            border: 1px solid #2c3e50;
        }
        .data-table td { 
            padding: 10px; border-bottom: 1px solid #dddddd; 
            vertical-align: middle; font-size: 10pt;
        }
        .data-table tr:nth-of-type(even) { background-color: #f8f9fa; }
        .data-table tr:last-of-type { border-bottom: 2px solid #2c3e50; }

        /* Helper Classes */
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .text-success { color: #27ae60; font-weight: bold; }
        .text-danger { color: #c0392b; font-weight: bold; }
        .text-warning { color: #d35400; font-weight: bold; }
        .col-nama { font-weight: bold; color: #2c3e50; font-size: 10.5pt; }
        .col-nip { font-size: 8.5pt; color: #7f8c8d; margin-top: 2px; }
    </style>
    ';

    // 5. Susun HTML
    $html = $css . '
    <div class="header-container center">
        <h1 class="company-name">PT. FAZANA BERKAH MULIA</h1>
        <div class="report-title">Laporan Rekapitulasi Presensi Bulanan</div>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Periode</td>
            <td>: <strong>' . $periodeIndo . '</strong></td>
            <td class="label" style="text-align:right">Total Hari Kerja</td>
            <td style="width:100px">: ' . (int)$workdays . ' Hari</td>
        </tr>
        <tr>
            <td class="label">Lokasi Kantor</td>
            <td>: ' . $lokasiOutput . '</td>
            <td class="label" style="text-align:right">Total Pegawai</td>
            <td>: ' . count($rows) . ' Orang</td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="25%" style="text-align:left; padding-left:15px;">Pegawai</th>
                <th width="15%">Jabatan</th>
                <th width="8%">Hadir</th>
                <th width="10%">Cuti/Izin</th>
                <th width="8%">Alpha</th>
                <th width="15%">Keterlambatan</th>
                <th width="14%">Total Jam Kerja</th>
            </tr>
        </thead>
        <tbody>';

    $no = 1;
    if (empty($rows)) {
        $html .= '<tr><td colspan="8" class="center" style="padding: 20px;">Tidak ada data pegawai pada periode ini.</td></tr>';
    } else {
        foreach ($rows as $r) {
            $alphaClass = ($r['alpha'] > 0) ? 'text-danger' : 'text-muted';
            $hadirClass = ($r['hadir'] > 0) ? 'text-success' : '';
            $telatClass = ($r['telatH'] > 0) ? 'text-warning' : '';
            
            $telatDisplay = '-';
            if ($r['telatH'] > 0) {
                $telatDisplay = (int)$r['telatH'] . 'x <br><small style="color:#555">(' . htmlspecialchars((string)$r['telatM']) . ')</small>';
            }
            $ketDisplay = empty($r['ket_text']) ? '-' : htmlspecialchars($r['ket_text']);

            $html .= '<tr>
                <td class="center">' . $no++ . '</td>
                <td style="padding-left:15px;">
                    <div class="col-nama">' . htmlspecialchars($r['nama']) . '</div>
                    <div class="col-nip">NIP: ' . htmlspecialchars((string)$r['nip']) . '</div>
                </td>
                <td class="center">' . htmlspecialchars($r['jab']) . '</td>
                <td class="center ' . $hadirClass . '">' . (int)$r['hadir'] . '</td>
                <td class="center">' . $ketDisplay . '</td>
                <td class="center ' . $alphaClass . '">' . (int)$r['alpha'] . '</td>
                <td class="center ' . $telatClass . '">' . $telatDisplay . '</td>
                <td class="center bold">' . htmlspecialchars($r['totKer']) . '</td>
            </tr>';
        }
    }

    $html .= '</tbody></table>

    <div style="margin-top: 40px; page-break-inside: avoid;">
        <table style="width: 100%; border: none;">
            <tr>
                <td style="width: 60%; border: none;"></td>
                <td style="width: 40%; border: none; text-align: center;">
                    <p style="margin-bottom: 5px;">' . $prefixTandaTangan . $tglIndo . '</p>
                    
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
    $mpdf->Output('Rekap_Bulanan_' . $bulan . '.pdf', 'I');
    exit;
}

// menambah untuk check di bagian jika ada 3 lokasi yang berbeda 
// mendaftarkan 3 pegawai yang berbeda lokasi  juga jadi bagaimana
// lalu uji coba NEXT LANJUT BESOK BYE


/* -------------------------------------------------
   9. RENDER HALAMAN
------------------------------------------------- */
$judul = "Rekap Presensi Bulanan";
include __DIR__ . '/../layout/header.php';
?>
<style>
  /* 1. CONTAINER TABEL */
  .table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  /* Agar border antar sel menyatu rapi */
  .table {
    border-collapse: collapse; 
    width: 100%;
  }

  .table th, .table td {
    vertical-align: middle;
    white-space: nowrap; /* Mencegah teks turun ke bawah */
    background-color: #fff; /* Wajib ada warna agar tidak tembus pandang */
    padding: 12px 15px;
    border: 1px solid #dee2e6; /* Border standar di semua sisi */
  }

  /* 2. LOGIKA STICKY KHUSUS KOLOM NAMA (KOLOM KE-3) */
  
  /* Target kolom ke-3: NAMA */
  .table th:nth-child(3), 
  .table td:nth-child(3) {
    position: sticky;
    left: 0; /* Menempel di sisi kiri saat di-scroll */
    z-index: 10;
    background-color: #fff;
    min-width: 180px; /* Kunci lebar kolom nama */
    
    /* --- PERUBAHAN DI SINI --- */
    /* border-right: 2px solid #2c3e50;  <-- INI DIHAPUS */
    /* box-shadow: ...;                  <-- INI DIHAPUS */
    /* Kita ganti dengan border standar kanan agar menyatu */
    border-right: 1px solid #dee2e6; 
  }

  /* Z-index Header Nama harus lebih tinggi */
  .table thead th:nth-child(3) {
    z-index: 11;
    background-color: #f8f9fa !important;
  }
  
  /* Header standar */
  .table thead th {
      background-color: #f8f9fa;
      font-weight: bold;
  }

  /* 3. EFEK VISUAL HOVER */
  .table tbody tr:hover td {
    background-color: #f1f5f9;
  }
  
  /* Agar kolom sticky juga ikut berubah warna saat di-hover */
  .table tbody tr:hover td:nth-child(3) {
      background-color: #f1f5f9;
  }

  /* Styling teks helper */
  .col-nama { font-weight: bold; color: #2c3e50; font-size: 10pt; }
  .col-nip { font-size: 8.5pt; color: #7f8c8d; }
  .center { text-align: center; }
</style>

<!-- tampilan sudah -->
<!-- logika sudah nanti check lagi -->
<!-- rekap_presensi.php -->
<!-- rekap_harian.php -->
<!-- rekap_bulanan.php -->
<!-- detail.php -->
<!-- diatas tersebut telah di sesuaikan dan logika sudah lumayan pas lah yak -->

<div class="page-body">
  <div class="container-xl">

    <div class="card mb-3">
      <div class="card-header"><strong>Filter Rekap Bulanan</strong></div>
      <div class="card-body">
        <form class="row g-2" method="get" action="">
          <div class="col-md-3 col-sm-6">
            <label class="form-label">Bulan</label>
            <input type="month" name="bulan" value="<?= htmlspecialchars($bulan) ?>" class="form-control" required>
          </div>
          <div class="col-md-4 col-sm-6">
            <label class="form-label">Lokasi (opsional)</label>
            <select name="lokasi" class="form-select">
              <option value="">— Semua Lokasi —</option>
              <?php foreach ($daftarLokasi as $L): ?>
                <option value="<?= htmlspecialchars($L) ?>" <?= $lokasi===$L?'selected':''; ?>>
                  <?= htmlspecialchars($L) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5 d-flex align-items-end gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="fa fa-search me-1"></i> Tampilkan
            </button>
            <a class="btn btn-danger" href="?bulan=<?= urlencode($bulan) ?>&lokasi=<?= urlencode($lokasi) ?>&export=pdf" target="_blank">
                <i class="fa fa-file-pdf-o me-1"></i> PDF
            </a>
          </div>
        </form>
      </div>
    </div>

    <div class="alert alert-info">
      <strong>Info:</strong> Alpha dihitung dari Hari Kerja (Senin–Jumat) dikurangi Hadir dan Ketidakhadiran yang <b>DISETUJUI</b>.
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <strong>Hasil — <?= htmlspecialchars($bulan) ?></strong>
          <?php if ($lokasi !== ''): ?>
            <span class="badge bg-primary ms-2">Lokasi: <?= htmlspecialchars($lokasi) ?></span>
          <?php else: ?>
            <span class="badge bg-secondary ms-2">Semua Lokasi</span>
          <?php endif; ?>
        </div>
        <span class="text-muted small">Hari Kerja: <?= (int)$workdays ?> hari</span>
      </div>
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>NIP</th>
              <th>Nama</th>
              <th class="col-opsi">Jabatan</th>
              <th>Lokasi</th>
              <th>Hadir</th>
              <th>Ketidakhadiran</th>
              <th>Alpha</th>
              <th class="col-telat-hari">Telat (hari)</th>
              <th class="col-telat-total">Total Telat (m)</th>
              <th class="col-telat-avg">Rata2 Telat (m)</th>
              <th class="col-jam-total">Total Jam Kerja</th>
              <th class="col-jam-avg">Rata2 Jam Kerja</th>
              <th class="col-opsi">Detail</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="14" class="text-center text-muted">Tidak ada data.</td></tr>
            <?php else: $i=1; foreach ($rows as $r): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($r['nip']) ?></td>
                <td><strong><?= htmlspecialchars($r['nama']) ?></strong></td>
                <td><?= htmlspecialchars($r['jab']) ?></td>
                <td><?= htmlspecialchars($r['lokasi']) ?></td>
                
                <td>
                  <span class="badge bg-success text-white">
                    <?= (int)$r['hadir'] ?>
                  </span>
                </td>
                
                <td><?= htmlspecialchars($r['ket_text']) ?></td>
                
                <td>
                  <?php if ((int)$r['alpha'] > 0): ?>
                    <span class="badge bg-danger text-white"><?= (int)$r['alpha'] ?></span>
                  <?php else: ?>
                    <span class="badge bg-secondary">0</span>
                  <?php endif; ?>
                </td>
                
                <td><?= (int)$r['telatH'] ?></td>
                <td><?= htmlspecialchars($r['telatM']) ?></td>
                <td><?= htmlspecialchars($r['avgTel']) ?></td>
                
                <td><?= htmlspecialchars($r['totKer']) ?></td>
                <td><?= htmlspecialchars($r['avgKer']) ?></td>
                
                <td class="col-aksi">
                  <a href="detail.php?id=<?= (int)$r['id'] ?>&bulan=<?= urlencode($bulan) ?>" class="btn btn-primary btn-sm">
                    Detail
                  </a>
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



<!-- next check in rekap_bulanan -->
<!-- next check in rekap_harian -->
<!-- next check in rekap_presensi -->