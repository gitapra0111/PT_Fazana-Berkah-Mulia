<?php
// FILE: pegawai/presensi/rekap_presensi.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

// Harus login sebagai pegawai
if (
    !isset($_SESSION['user']['login']) ||
    ($_SESSION['user']['role'] ?? '') !== 'pegawai'
) {
    header("Location: ../../auth/login.php?pesan=tolak_akses");
    exit;
}

require_once __DIR__ . '/../../config.php';

/* -------------------------------------------------------
   1. PASTIKAN KONEKSI
------------------------------------------------------- */
if (!isset($connection) || !($connection instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $connection = $conn;
    } elseif (isset($koneksi) && $koneksi instanceof mysqli) {
        $connection = $koneksi;
    } else {
        $connection = new mysqli('localhost', 'root', '', 'presensi');
        if ($connection->connect_error) {
            die("Database connection not available. Error: " . $connection->connect_error);
        }
    }
}

/* -------------------------------------------------------
   2. DATA SESSION
------------------------------------------------------- */
$id_pegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
if ($id_pegawai <= 0) {
    die("ID Pegawai tidak valid");
}

/* -------------------------------------------------------
   3. FILTER BULAN/TAHUN
------------------------------------------------------- */
$bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

if ($bulan < 1 || $bulan > 12)   $bulan = (int)date('m');
if ($tahun < 2020 || $tahun > 2100) $tahun = (int)date('Y');

$awalBulan  = sprintf('%04d-%02d-01', $tahun, $bulan);
$akhirBulan = date('Y-m-t', strtotime($awalBulan));

/* -------------------------------------------------------
   4. AMBIL PRESENSI
------------------------------------------------------- */
/* -------------------------------------------------------
   4. AMBIL PRESENSI (Updated: Tambah jam_pulang_rule)
------------------------------------------------------- */
$sqlPres = "
    SELECT
        p.id AS id_presensi,
        p.tanggal_masuk,
        p.jam_masuk,
        p.jam_keluar,
        p.foto_masuk,
        p.foto_keluar,
        lp.nama_lokasi,
        lp.jam_masuk AS jam_masuk_rule,
        lp.jam_pulang AS jam_pulang_rule
    FROM presensi p
    INNER JOIN pegawai pg ON pg.id = p.id_pegawai
    LEFT JOIN lokasi_presensi lp ON lp.nama_lokasi = pg.lokasi_presensi
    WHERE p.id_pegawai = ?
      AND p.tanggal_masuk BETWEEN ? AND ?
    ORDER BY p.tanggal_masuk DESC, p.id DESC
";
$stmt = $connection->prepare($sqlPres);
if (!$stmt) {
    die("Query presensi error: " . $connection->error);
}
$stmt->bind_param("iss", $id_pegawai, $awalBulan, $akhirBulan);
$stmt->execute();
$resPres = $stmt->get_result();

$presensi = [];
while ($row = $resPres->fetch_assoc()) {
    // hitung keterlambatan (menit)
    $telatMenit = 0;
    if (
        !empty($row['jam_masuk']) && $row['jam_masuk'] !== '00:00:00' &&
        !empty($row['jam_masuk_rule']) && $row['jam_masuk_rule'] !== '00:00:00'
    ) {
        $tMasuk = strtotime("1970-01-01 {$row['jam_masuk']} UTC");
        $tRule  = strtotime("1970-01-01 {$row['jam_masuk_rule']} UTC");
        if ($tMasuk > $tRule) {
            // 1. Hitung selisih murni dulu
            $selisih = (int)round(($tMasuk - $tRule) / 60);
            
            // 2. Terapkan Aturan: Hanya dihitung jika > 60 menit
            if ($selisih > 60) {
                $telatMenit = $selisih;
            } else {
                $telatMenit = 0; // Dianggap toleransi / Tepat Waktu
            }
        }
    }

    // hitung durasi kerja (menit)
    $durasiKerja = null;
    if (
        !empty($row['jam_masuk']) && $row['jam_masuk'] !== '00:00:00' &&
        !empty($row['jam_keluar']) && $row['jam_keluar'] !== '00:00:00'
    ) {
        $tIn  = strtotime("1970-01-01 {$row['jam_masuk']} UTC");
        $tOut = strtotime("1970-01-01 {$row['jam_keluar']} UTC");
        
        // LOGIKA BARU: Cek batas jam pulang admin
        if (!empty($row['jam_pulang_rule']) && $row['jam_pulang_rule'] !== '00:00:00') {
            $tMaxPulang = strtotime("1970-01-01 {$row['jam_pulang_rule']} UTC");
            
            // Jika jam keluar pegawai MELEBIHI batas admin,
            // Maka paksa jam keluar = jam batas admin.
            if ($tOut > $tMaxPulang) {
                $tOut = $tMaxPulang;
            }
        }

        if ($tOut >= $tIn) {
            $durasiKerja = (int)round(($tOut - $tIn) / 60);
        }
    }

    // Tentukan label status berdasarkan hasil hitung keterlambatan
// Tentukan label status
    $statusTampil = 'Hadir';
    
    // Jika ada nilai terlambat (berarti sudah pasti > 60 menit dari logika sebelumnya)
    if ($telatMenit > 0) {
        $statusTampil = 'Terlambat';
    }



$presensi[] = [
    'tipe'           => 'presensi',
    'tanggal_mysql'  => $row['tanggal_masuk'],
    'tanggal'        => date('d/m/Y', strtotime($row['tanggal_masuk'])),
    'nama_lokasi'    => $row['nama_lokasi'] ?: '-',
    'status'         => $statusTampil, // <--- Sekarang status berubah otomatis
    'jam_masuk'      => $row['jam_masuk'] ?: '-',
    'jam_keluar'     => $row['jam_keluar'] ?: '-',
    'keterlambatan'  => $telatMenit,
    'durasi_kerja'   => $durasiKerja,
    'foto_masuk'     => $row['foto_masuk'] ?: null,
    'foto_keluar'    => $row['foto_keluar'] ?: null,
    'deskripsi_izin' => '',
    'approval_status'=> '',
];
}
$stmt->close();

/* -------------------------------------------------------
   5. AMBIL KETIDAKHADIRAN
------------------------------------------------------- */
$sqlKet = "
    SELECT id, keterangan, tanggal, deskripsi, status_pengajuan
    FROM ketidakhadiran
    WHERE id_pegawai = ?
      AND tanggal BETWEEN ? AND ?
    ORDER BY tanggal DESC, id DESC
";
$stmtKet = $connection->prepare($sqlKet);
$stmtKet->bind_param("iss", $id_pegawai, $awalBulan, $akhirBulan);
$stmtKet->execute();
$resKet = $stmtKet->get_result();

$ket = [];
while ($row = $resKet->fetch_assoc()) {
    $ket[] = [
        'tipe'            => 'ketidakhadiran',
        'tanggal_mysql'   => $row['tanggal'],
        'tanggal'         => date('d/m/Y', strtotime($row['tanggal'])),
        'nama_lokasi'     => '-',
        'status'          => $row['keterangan'],
        'jam_masuk'       => '-',
        'jam_keluar'      => '-',
        'keterlambatan'   => 0,
        'durasi_kerja'    => null,
        'foto_masuk'      => null,
        'foto_keluar'     => null,
        'deskripsi_izin'  => $row['deskripsi'] ?? '',
        'approval_status' => $row['status_pengajuan'] ?? 'PENDING',
    ];
}
$stmtKet->close();

/* -------------------------------------------------------
   6. GABUNG
------------------------------------------------------- */
$gabung = [];
foreach ($presensi as $p) {
    $gabung[$p['tanggal_mysql']] = $p;
}
foreach ($ket as $k) {
    $gabung[$k['tanggal_mysql']] = $k; // override kalau tanggal sama
}
$rows = array_values($gabung);
usort($rows, function ($a, $b) {
    return strcmp($b['tanggal_mysql'], $a['tanggal_mysql']);
});



/* -------------------------------------------------------
   7. EXPORT PDF (VERSI FINAL - SUMMARY BOX FIX TURUN KE BAWAH)
------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../../config.php';

    // 1. HELPER & ARRAY INDONESIA
    $namaBulanIndo = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $namaHariIndo = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];

    // 2. KONEKSI & AMBIL DATA PEGAWAI + LOKASI
    $mysqli = null;
    if (isset($connection) && $connection instanceof mysqli) $mysqli = $connection;
    elseif (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
    elseif (isset($koneksi) && $koneksi instanceof mysqli) $mysqli = $koneksi;

    $idUser = $id_pegawai;
    
    // Default Data
    $namaPegawai = 'Pegawai';
    $nipPegawai  = '-';
    $jabatanPegawai = '-';
    $lokasiPegawai = '-';
    $kotaTandaTangan = 'Pacitan'; // Default

    if ($idUser > 0 && $mysqli) {
        // QUERY JOIN: Ambil data pegawai, jabatan, dan alamat lokasi
        $sqlUser = "
            SELECT p.nama, p.nip, p.jabatan, p.lokasi_presensi, l.alamat_lokasi 
            FROM pegawai p
            LEFT JOIN lokasi_presensi l ON p.lokasi_presensi = l.nama_lokasi
            WHERE p.id = ?
        ";
        $stmt = $mysqli->prepare($sqlUser);
        $stmt->bind_param("i", $idUser);
        $stmt->execute();
        $resUser = $stmt->get_result()->fetch_assoc();
        
        if ($resUser) {
            $namaPegawai = $resUser['nama'];
            $nipPegawai  = $resUser['nip'];
            $jabatanPegawai = $resUser['jabatan'];
            $lokasiPegawai = $resUser['lokasi_presensi'];

            // Logika Kota Dinamis
            if (!empty($resUser['alamat_lokasi'])) {
                $parts = explode(',', $resUser['alamat_lokasi']);
                $kota = trim(end($parts));
                if (!empty($kota)) {
                    $kotaTandaTangan = ucwords(strtolower($kota));
                }
            }
        }
        $stmt->close();
    }

    // 3. HITUNG RINGKASAN DATA
    $totalHadir = 0;
    $totalAlpha = 0;
    $totalTelatMenit = 0;
    $totalDurasiMenit = 0;

    foreach ($rows as $r) {
        if ($r['status'] == 'Hadir' || $r['status'] == 'Terlambat') {
            $totalHadir++;
        } elseif ($r['status'] == 'Alpha' || $r['status'] == 'Tidak Hadir' || $r['status'] == 'Sakit' || $r['status'] == 'Izin') {
            $totalAlpha++;
        }
        // Hitung Menit Keterlambatan
        if (isset($r['keterlambatan']) && is_numeric($r['keterlambatan'])) {
            // Pastikan hanya menghitung jika statusnya 'Terlambat'
            // (Karena yg 'Hadir' nilai keterlambatannya sudah kita set 0 di langkah 1)
            if ($r['status'] === 'Terlambat') {
                $totalTelatMenit += (int)$r['keterlambatan'];
            }
        }
        if (isset($r['durasi_kerja']) && is_numeric($r['durasi_kerja'])) {
            $totalDurasiMenit += (int)$r['durasi_kerja'];
        }
    }

    // Format Durasi ke String
    function formatJamMenit($totalMenit) {
        $jam = floor($totalMenit / 60);
        $menit = $totalMenit % 60;
        $hasil = '';
        if ($jam > 0) $hasil .= $jam . ' Jam ';
        $hasil .= $menit . ' Menit';
        return $hasil;
    }

    $strTotalTelat = formatJamMenit($totalTelatMenit);
    $strTotalKerja = formatJamMenit($totalDurasiMenit);

    // 4. SETUP MPDF
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4-L', 
        'margin_top' => 10, 'margin_bottom' => 15, 'margin_left' => 10, 'margin_right' => 10
    ]);
    
    $periodeIndo = $namaBulanIndo[(int)$bulan] . ' ' . $tahun;
    $tglCetak = date('d') . ' ' . $namaBulanIndo[(int)date('m')] . ' ' . date('Y');

    $mpdf->SetTitle("Rekap Presensi - " . $namaPegawai);
    $mpdf->SetFooter('Dicetak pada: ' . date('d-m-Y H:i:s') . '|PT. FAZANA BERKAH MULIA|Halaman {PAGENO} dari {nbpg}');

    // 5. CSS (STRUKTUR BARU AGAR DIJAMIN TURUN KE BAWAH)
    $css = '
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #333; }
        
        .header-container { border-bottom: 2px solid #2c3e50; padding-bottom: 10px; margin-bottom: 20px; }
        .company-name { font-size: 20pt; font-weight: bold; color: #2c3e50; text-transform: uppercase; margin: 0; }
        .report-title { font-size: 12pt; font-weight: bold; color: #555; text-transform: uppercase; margin-top: 5px; letter-spacing: 1px; }

        .info-table { width: 100%; margin-bottom: 20px; }
        .info-table td { padding: 4px; vertical-align: top; font-size: 10pt; }
        .label { font-weight: bold; width: 130px; color: #444; }

        /* SUMMARY BOX - MENGGUNAKAN DIV AGAR PASTI BLOCK */
        .summary-box { 
            width: 100%; 
            border: 1px solid #ddd; 
            background-color: #fcfcfc;
            margin-bottom: 25px; 
            border-collapse: collapse; 
        }
        .summary-box td { 
            width: 25%; 
            text-align: center; 
            padding: 15px 5px; 
            border-right: 1px solid #eee; 
            vertical-align: middle;
        }
        .summary-box td:last-child { border-right: none; }
        
        /* Div Pembungkus agar rapi */
        .sum-label-div {
            font-size: 9pt; 
            font-weight: bold; 
            color: #555; 
            text-transform: uppercase;
            margin-bottom: 5px; /* Jarak ke bawah */
        }
        
        .sum-val-div {
            font-size: 13pt; 
            font-weight: bold;
            margin-top: 5px; /* Jarak dari atas */
        }

        /* Warna */
        .txt-green { color: #27ae60; }
        .txt-red { color: #c0392b; }
        .txt-orange { color: #d35400; }
        .txt-dark { color: #2c3e50; }

        /* DATA TABLE */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background-color: #2c3e50; color: #ffffff; padding: 10px; font-size: 9pt; text-transform: uppercase; font-weight: bold; border: 1px solid #2c3e50; }
        .data-table td { padding: 8px; border-bottom: 1px solid #dddddd; vertical-align: middle; font-size: 9pt; text-align: center; }
        .data-table tr:nth-of-type(even) { background-color: #f8f9fa; }
        
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .text-success { color: #27ae60; font-weight: bold; }
        .text-danger { color: #c0392b; font-weight: bold; }
        .text-warning { color: #d35400; font-weight: bold; }
        .photo-img { border-radius: 4px; border: 1px solid #ddd; padding: 2px; }
    </style>';

    // 6. HTML STRUCTURE
    $html = $css . '
    <div class="header-container center">
        <h1 class="company-name">PT. FAZANA BERKAH MULIA</h1>
        <div class="report-title">Laporan Detail Presensi Pegawai</div>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Nama</td>
            <td>: <strong>' . htmlspecialchars($namaPegawai) . '</strong></td>
            <td class="label" style="text-align:right">Periode</td>
            <td style="width:200px">: ' . $periodeIndo . '</td>
        </tr>
        <tr>
            <td class="label">NIP</td>
            <td>: ' . htmlspecialchars($nipPegawai) . '</td>
            <td class="label" style="text-align:right">Jabatan</td>
            <td>: ' . htmlspecialchars($jabatanPegawai) . '</td>
        </tr>
        <tr>
            <td class="label">Lokasi</td>
            <td>: ' . htmlspecialchars($lokasiPegawai) . '</td>
            <td class="label"></td>
            <td></td>
        </tr>
    </table>

    <table class="summary-box">
        <tr>
            <td>
                <div class="sum-label-div">HADIR</div>
                <div class="sum-val-div txt-green">' . $totalHadir . ' Hari</div>
            </td>
            <td>
                <div class="sum-label-div">ALPHA / IZIN</div>
                <div class="sum-val-div txt-red">' . $totalAlpha . ' Hari</div>
            </td>
            <td>
                <div class="sum-label-div">TOTAL TELAT</div>
                <div class="sum-val-div txt-orange">' . $strTotalTelat . '</div>
            </td>
            <td>
                <div class="sum-label-div">TOTAL JAM KERJA</div>
                <div class="sum-val-div txt-dark">' . $strTotalKerja . '</div>
            </td>
        </tr>
    </table>

    <h4 style="margin-bottom: 10px; color: #444; border-bottom: 1px solid #ccc; padding-bottom: 5px;">Riwayat Harian</h4>

    <table class="data-table">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="15%">Tanggal</th>
                <th width="10%">Jam Masuk</th>
                <th width="10%">Foto Masuk</th>
                <th width="10%">Jam Keluar</th>
                <th width="10%">Foto Keluar</th>
                <th width="20%">Status</th>
                <th width="20%">Durasi</th>
            </tr>
        </thead>
        <tbody>';

    $no = 1;
    if (empty($rows)) {
        $html .= '<tr><td colspan="8" class="center" style="padding:20px;">Tidak ada data presensi bulan ini.</td></tr>';
    } else {
        foreach ($rows as $r) {
            $statusClass = 'text-success';
            if ($r['status'] === 'Terlambat') $statusClass = 'text-danger';
            elseif ($r['tipe'] === 'ketidakhadiran') $statusClass = 'text-warning';

            $pathFoto = __DIR__ . '/../../assets/uploads/presensi/';

            
            $imgMasuk = (!empty($r['foto_masuk']) && file_exists($pathFoto.$r['foto_masuk'])) 
                ? '<img src="'.$pathFoto.$r['foto_masuk'].'" height="35" class="photo-img">' : '-';
            $imgKeluar = (!empty($r['foto_keluar']) && file_exists($pathFoto.$r['foto_keluar'])) 
                ? '<img src="'.$pathFoto.$r['foto_keluar'].'" height="35" class="photo-img">' : '-';

            $durasiTeks = (!is_null($r['durasi_kerja'])) ? floor($r['durasi_kerja']/60).' Jam '.($r['durasi_kerja']%60).' Menit' : '-';
            
            $timestamp = strtotime($r['tanggal_mysql']);
            $tglAngka = date('d/m/Y', $timestamp);
            $hariInggris = date('l', $timestamp);
            $hariIndo = $namaHariIndo[$hariInggris] ?? $hariInggris;

            $displayStatus = $r['status'];
            if($r['status'] === 'Terlambat') {
                 $menitTelat = (int)$r['keterlambatan'];
                 $displayStatus .= '<br><span style="font-weight:normal; font-size:8pt; color:#c0392b;">(' . $menitTelat . ' Menit)</span>';
            }
            if($r['tipe'] === 'ketidakhadiran') {
                $displayStatus .= '<br><span style="font-weight:normal; font-size:8pt; color:#555">('.($r['approval_status'] ?? '-').')</span>';
            }

            $html .= '<tr>
                <td class="center">'.$no++.'</td>
                <td><strong>'.$tglAngka.'</strong><br><small style="color:#666">'.$hariIndo.'</small></td>
                <td class="center">'.$r['jam_masuk'].'</td>
                <td class="center">'.$imgMasuk.'</td>
                <td class="center">'.$r['jam_keluar'].'</td>
                <td class="center">'.$imgKeluar.'</td>
                <td class="center bold '.$statusClass.'">'.$displayStatus.'</td>
                <td class="center bold">'.$durasiTeks.'</td>
            </tr>';
        }
    }

    $html .= '</tbody></table>
    
    <div style="margin-top: 30px; page-break-inside: avoid;">
        <table style="width: 100%; border: none;">
            <tr>
                <td style="width: 60%; border: none;"></td>
                <td style="width: 40%; border: none; text-align: center;">
                    <p style="margin-bottom: 5px;">' . $kotaTandaTangan . ', ' . $tglCetak . '</p>
                    <p>Pegawai Yang Bersangkutan,</p>
                    <br><br><br><br>
                    <p style="border-bottom: 1px solid #333; display: inline-block; min-width: 150px;"><b>'.htmlspecialchars($namaPegawai).'</b></p>
                </td>
            </tr>
        </table>
    </div>';

    $mpdf->WriteHTML($html);
    $mpdf->Output('Rekap_Presensi_'.$namaPegawai.'_'.$bulan.'.pdf', 'I');
    exit;
}

/* -------------------------------------------------------
   8. VIEW
------------------------------------------------------- */
$judul = "Rekap Presensi";
require_once __DIR__ . '/../layout/header.php';

// siapkan base url foto presensi utk dipakai di tombol
$baseFotoPresensi = rtrim(base_url('assets/uploads/presensi/'), '/') . '/';
?>
<div class="container-xl">
    <!-- Filter Form -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row align-items-end g-2">
                <div class="col-md-3 col-6">
                    <label class="form-label">Bulan</label>
                    <select name="bulan" class="form-control">
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                            <option value="<?= $i ?>" <?= $i === $bulan ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$i,1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label">Tahun</label>
                    <select name="tahun" class="form-control">
                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === $tahun ? 'selected' : '' ?>>
                                <?= $y ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa fa-filter"></i> Filter
                    </button>
                </div>
                <div class="col-md-3 col-6 mt-2 mt-md-0">
                    <a href="?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>&export=pdf" class="btn btn-danger w-100" target="_blank">
                        <i class="fa fa-file-pdf-o"></i> Export PDF
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Rekap Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="card-title mb-0">
                Rekap Presensi: <?= date('F Y', mktime(0,0,0,$bulan,1,$tahun)) ?>
            </h3>
            <span class="badge bg-primary mt-2 mt-md-0"><?= count($rows) ?> data</span>
        </div>
        <div class="table-responsive">
            <table class="table card-table table-vcenter mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>TANGGAL</th>
                        <th>LOKASI</th>
                        <th>STATUS</th>
                        <th>JAM MASUK</th>
                        <th>JAM KELUAR</th>
                        <th>TERLAMBAT</th>
                        <th>DURASI</th>
                        <th>FOTO / KETERANGAN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                Belum ada presensi atau pengajuan pada periode ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php if ($row['tipe'] === 'ketidakhadiran'): ?>
                                <?php
                                    $app = $row['approval_status'] ?? 'PENDING';
                                    $badgeApp = 'badge bg-warning text-dark';
                                    if ($app === 'DISETUJUI') {
                                        $badgeApp = 'badge bg-success';
                                    } elseif ($app === 'DITOLAK') {
                                        $badgeApp = 'badge bg-danger';
                                    }
                                ?>
                                <tr class="align-middle">
                                    <td><?= htmlspecialchars($row['tanggal']) ?></td>
                                    <td>-</td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($row['status']) ?></span></td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td>0</td>
                                    <td>-</td>
                                    <td>
                                        <span class="<?= $badgeApp ?>"><?= htmlspecialchars($app) ?></span>
                                        <?php if (!empty($row['deskripsi_izin'])): ?>
                                            <div class="text-muted small mt-1">
                                                <?= htmlspecialchars($row['deskripsi_izin']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr class="align-middle">
                                    <td><?= htmlspecialchars($row['tanggal']) ?></td>
                                    <td><?= htmlspecialchars($row['nama_lokasi']) ?></td>
                                    <td>
                                        <?php 
                                            $badgeColor = ($row['status'] === 'Terlambat') ? 'bg-danger' : 'bg-success';
                                        ?>
                                        <span class="badge <?= $badgeColor ?>"><?= htmlspecialchars($row['status']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['jam_masuk']) ?></td>
                                    <td><?= htmlspecialchars($row['jam_keluar']) ?></td>
                                    <td>
                                        <?php if ((int)$row['keterlambatan'] > 0): ?>
                                            <span class="text-danger"><?= (int)$row['keterlambatan'] ?> menit</span>
                                        <?php else: ?>
                                            <span class="text-success">Tepat waktu</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!is_null($row['durasi_kerja'])): ?>
                                            <?= floor($row['durasi_kerja']/60) . 'j ' . ($row['durasi_kerja']%60) . 'm' ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['foto_masuk'])): ?>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-primary mb-1"
                                                onclick="showImage('<?= $baseFotoPresensi . rawurlencode($row['foto_masuk']); ?>','Foto Masuk (<?= htmlspecialchars($row['tanggal'], ENT_QUOTES, 'UTF-8'); ?>)')">
                                                <i class="fa fa-camera"></i> Masuk
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($row['foto_keluar'])): ?>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-secondary mb-1"
                                                onclick="showImage('<?= $baseFotoPresensi . rawurlencode($row['foto_keluar']); ?>','Foto Keluar (<?= htmlspecialchars($row['tanggal'], ENT_QUOTES, 'UTF-8'); ?>)')">
                                                <i class="fa fa-camera"></i> Keluar
                                            </button>
                                        <?php endif; ?>
                                        <?php if (empty($row['foto_masuk']) && empty($row['foto_keluar'])): ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function useSwal(cb) {
  if (window.Swal) return cb(window.Swal);
  var s = document.createElement('script');
  s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
  s.onload = function(){ cb(window.Swal); };
  s.onerror = function(){ cb(null); };
  document.head.appendChild(s);
}

function showImage(fullUrl, title) {
  useSwal(function(Swal) {
    if (!Swal) {
      // cadangan kalau CDN gagal
      var w = window.open(fullUrl, '_blank');
      if (!w) alert('Buka: ' + fullUrl);
      return;
    }
    Swal.fire({
      title: title,
      imageUrl: fullUrl,
      imageWidth: 420,
      imageHeight: 420,
      imageAlt: title,
      confirmButtonText: 'Tutup'
    });
  });
}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>



<!-- last yang saya benarkan yaitu ada di presensi masuk dan presensi keluar semua dan aksi -->
 <!--  -->
 <!-- selanjutnya menyempurnakan yang lain -->
  <!-- memaksimalkan untuk face regcognetion -->