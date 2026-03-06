<?php
declare(strict_types=1);

/* =========================================================
   PT. FAZANA BERKAH MULIA - AKSI PRESENSI KELUAR (PULANG)
========================================================= */

// 1. SESSION & AUTH
if (session_status() === PHP_SESSION_ACTIVE) { 
    session_write_close(); 
}
session_name('PEGAWAISESSID');
session_start();

// Cek apakah user sudah login sebagai pegawai
if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); 
    exit;
}

require_once __DIR__ . '/../../config.php';

// Helper URL & Redirect
$BASE_URL = function_exists('base_url') ? rtrim(base_url(), '/') . '/' : 'http://' . $_SERVER['HTTP_HOST'] . '/presensi/';
$HOME_URL = $BASE_URL . 'pegawai/home/home.php';

/**
 * Fungsi pembantu untuk menampilkan pesan SweetAlert dan redirect
 */
function swal_and_redirect(string $icon, string $title, string $text, string $redirectUrl): never {
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Sistem Presensi - PT. Fazana</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap">
        <style>body { font-family: 'Inter', sans-serif; }</style>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: <?= json_encode($icon) ?>,
                title: <?= json_encode($title) ?>,
                text: <?= json_encode($text) ?>,
                allowOutsideClick: false,
                confirmButtonColor: '#d63939'
            }).then(() => { 
                window.location.href = <?= json_encode($redirectUrl) ?>; 
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// 2. KONEKSI DATABASE
$mysqli = $conn ?? $koneksi ?? $connection;
if (!$mysqli) {
    swal_and_redirect('error', 'Koneksi Gagal', 'Sistem gagal terhubung ke database.', $HOME_URL);
}

// 3. TANGKAP DATA POST NEW
$id_pegawai = (int)$_SESSION['user']['id_pegawai'];
$fotoB64    = $_POST['foto_base64'] ?? '';

// Ambil koordinat dari form
$latitude   = $_POST['lat_keluar'] ?? '0';
$longitude  = $_POST['lng_keluar'] ?? '0';

// --- TAMBAHAN BARU: TANGKAP AKURASI ---
$accuracy     = (float)($_POST['accuracy'] ?? 0);

// Cek Anti-Fake GPS di Server (Lapis Kedua)
if ($accuracy <= 1.0 && $accuracy > 0) {
    swal_and_redirect('error', 'Terdeteksi Lokasi Palsu!', 'Sistem menolak presensi karena terindikasi menggunakan Fake GPS.', $HOME_URL);
}

// ==========================================================
// PENGAMANAN MUTLAK: WAKTU PULANG DITENTUKAN OLEH SERVER (KASIR)
// ==========================================================
$lokasi_presensi = $_SESSION['user']['lokasi_presensi'] ?? '';

// PERBAIKAN: Ambil jam_pulang juga agar variabelnya tidak kosong/undefined
$query_lokasi = mysqli_query($mysqli, "SELECT zona_waktu, jam_pulang FROM lokasi_presensi WHERE nama_lokasi = '$lokasi_presensi'");
$data_lokasi = mysqli_fetch_assoc($query_lokasi);

$zona = $data_lokasi['zona_waktu'] ?? 'WIB';

if ($zona === 'WIB') date_default_timezone_set('Asia/Jakarta');
elseif ($zona === 'WITA') date_default_timezone_set('Asia/Makassar');
elseif ($zona === 'WIT') date_default_timezone_set('Asia/Jayapura');

// Mengambil jam & tanggal detik ini juga secara mutlak
$tglKeluar  = date('Y-m-d');
$jamKeluar  = date('H:i:s');

// --- SATPAM WAKTU PULANG (DOUBLE LOCK) ---
// Sekarang $data_lokasi['jam_pulang'] sudah ada isinya karena sudah di-SELECT di atas
$jam_pulang_master = $data_lokasi['jam_pulang'] ?? '00:00:00'; 
$jamPulangToday = strtotime($tglKeluar . ' ' . $jam_pulang_master);
$waktu_sekarang = time();

if ($waktu_sekarang < $jamPulangToday) {
    swal_and_redirect('error', 'Akses Ditolak!', 'Belum waktunya melakukan presensi pulang.', $HOME_URL);
}
// ===============================new===========================

/* --- 4. VALIDASI DATA --- */
if (empty($fotoB64)) {
    swal_and_redirect('error', 'Gagal', 'Foto biometrik tidak diterima oleh server.', $HOME_URL);
}

if ($latitude === '0' || $latitude === '') {
    swal_and_redirect('warning', 'Lokasi Tidak Akurat', 'Gagal mendapatkan koordinat GPS. Pastikan GPS aktif.', $HOME_URL);
}

// Inisialisasi variabel agar bisa dipakai di bawah
$nama_file = '';
$path_abs  = '';

try {
    // 1. Validasi Header Base64 (Hanya JPG/PNG)
    if (preg_match('/^data:image\/(\w+);base64,/', $fotoB64, $type)) {
        $ext = strtolower($type[1]);
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            throw new Exception('Format file tidak didukung (Gunakan JPG/PNG).');
        }
        
        // Bersihkan data base64
        $fotoB64 = substr($fotoB64, strpos($fotoB64, ',') + 1);
        $fotoB64 = str_replace(' ', '+', $fotoB64);
        $binData = base64_decode($fotoB64);
        
        if ($binData === false) throw new Exception("Gagal decode foto.");
    } else {
        throw new Exception("Format data gambar rusak/kosong.");
    }

    // 2. Siapkan Folder
    $uploadDir = __DIR__ . '/../../assets/uploads/presensi/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true); // Izin folder yang lebih aman
    }

    // 3. Simpan File
    $nama_file = 'keluar_' . date('Ymd_His') . '_' . $id_pegawai . '.jpg';
    $path_abs  = $uploadDir . '/' . $nama_file;
    
    if (file_put_contents($path_abs, $binData) === false) {
        throw new Exception("Gagal menyimpan file ke server.");
    }

} catch (Exception $e) {
    swal_and_redirect('error', 'Gagal Upload', $e->getMessage(), $HOME_URL);
}

/* --- 6. LOGIKA DATABASE (SEARCH & UPDATE) --- */

/**
 * Langkah A: Cari ID presensi hari ini milik pegawai ini 
 * yang jam_keluarnya masih kosong (atau 00:00:00).
 */
$sqlSearch = "SELECT id FROM presensi 
              WHERE id_pegawai = ? 
              AND tanggal_masuk = ? 
              AND (jam_keluar IS NULL OR jam_keluar = '00:00:00' OR jam_keluar = '') 
              ORDER BY id DESC LIMIT 1";

$stmtSearch = $mysqli->prepare($sqlSearch);
$stmtSearch->bind_param('is', $id_pegawai, $tglKeluar);
$stmtSearch->execute();
$resSearch = $stmtSearch->get_result();
$row = $resSearch->fetch_assoc();
$stmtSearch->close();

if (!$row) {
    // Jika tidak ditemukan data masuk hari ini
    swal_and_redirect('warning', 'Data Tidak Ditemukan', 'Anda belum melakukan absen masuk hari ini.', $HOME_URL);
}

$id_presensi_record = (int)$row['id'];

/**
 * Langkah B: Update baris tersebut dengan data pulang
 */
$sqlUpdate = "UPDATE presensi SET 
                tanggal_keluar = ?, 
                jam_keluar = ?, 
                foto_keluar = ?, 
                latitude_keluar = ?, 
                longitude_keluar = ? 
              WHERE id = ?";

$stmtUpdate = $mysqli->prepare($sqlUpdate);

// Simpan koordinat sebagai string (s) untuk menjaga presisi angka desimal
$latStr = (string)$latitude;
$lngStr = (string)$longitude;

$stmtUpdate->bind_param('sssssi', 
    $tglKeluar, 
    $jamKeluar, 
    $nama_file, 
    $latStr, 
    $lngStr, 
    $id_presensi_record
);

if ($stmtUpdate->execute()) {
    // SUKSES
    swal_and_redirect('success', 'Berhasil Pulang', 'Hati-hati di jalan!', $HOME_URL);
} else {
    // GAGAL: Hapus foto yang terlanjur diupload (ROLLBACK)
    if (file_exists($path_abs)) {
        unlink($path_abs);
    }
    swal_and_redirect('error', 'Gagal Database', 'Terjadi kesalahan: ' . $mysqli->error, $HOME_URL);
}

$stmtUpdate->close();

// berjalan baik