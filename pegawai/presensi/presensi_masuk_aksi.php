<?php
declare(strict_types=1);

/* --- 1. SESSION & AUTH --- */
if (session_status() === PHP_SESSION_ACTIVE) { 
    session_write_close(); 
}
session_name('PEGAWAISESSID');
session_start();

// Pastikan hanya pegawai yang bisa mengakses
if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); 
    exit;
}

require_once __DIR__ . '/../../config.php';

/* --- 2. URL & REDIRECT HELPER --- */
$BASE_URL = rtrim(base_url(), '/') . '/';
$HOME_URL = $BASE_URL . 'pegawai/home/home.php';

function swal_and_redirect(string $icon, string $title, string $text, string $redirectUrl): void {
    ?>
    <!doctype html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <body style="font-family: 'Inter', sans-serif;">
    <script>
      Swal.fire({
        icon: <?= json_encode($icon) ?>,
        title: <?= json_encode($title) ?>,
        text: <?= json_encode($text) ?>,
        showConfirmButton: true,
        confirmButtonColor: '#206bc4',
      }).then(()=>{ window.location.href = <?= json_encode($redirectUrl) ?>; });
    </script>
    </body>
    </html>
    <?php
    exit;
}

/* --- 3. KONEKSI DATABASE --- */
$mysqli = $conn ?? $koneksi ?? $connection;
if (!$mysqli) {
    swal_and_redirect('error', 'Koneksi Gagal', 'Sistem gagal terhubung ke database.', $HOME_URL);
}

/* --- 4. TANGKAP DATA (SINKRONISASI VARIABLE) NEW--- */
$id_pegawai   = (int)$_SESSION['user']['id_pegawai'];
$rawDataUri   = $_POST['foto_base64'] ?? '';
$latitude     = $_POST['latitude_masuk'] ?? '0';
$longitude    = $_POST['longitude_masuk'] ?? '0';
// --- TAMBAHAN: TANGKAP AKURASI DAN BLOKIR FAKE GPS ---
$accuracy     = (float)($_POST['accuracy'] ?? 0);

if ($accuracy <= 1.0 && $accuracy > 0) {
    swal_and_redirect('error', 'Terdeteksi Lokasi Palsu!', 'Sistem menolak presensi karena terindikasi menggunakan Fake GPS.', $HOME_URL);
}

// ==========================================================
// PENGAMANAN MUTLAK: WAKTU DITENTUKAN OLEH SERVER (KASIR)
// ==========================================================
$lokasi_presensi = $_SESSION['user']['lokasi_presensi'] ?? '';

// PERBAIKAN: Tambahkan jam_masuk, jam_pulang, latitude, longitude, dan radius ke dalam SELECT
$query_lokasi = mysqli_query($mysqli, "SELECT zona_waktu, jam_masuk, jam_pulang, latitude, longitude, radius FROM lokasi_presensi WHERE nama_lokasi = '$lokasi_presensi'");
$data_lokasi = mysqli_fetch_assoc($query_lokasi);

$zona = $data_lokasi['zona_waktu'] ?? 'WIB';

if ($zona === 'WIB') date_default_timezone_set('Asia/Jakarta');
elseif ($zona === 'WITA') date_default_timezone_set('Asia/Makassar');
elseif ($zona === 'WIT') date_default_timezone_set('Asia/Jayapura');

// Mengambil jam & tanggal detik ini juga
$tanggalMasuk = date('Y-m-d'); 
$jamMasuk     = date('H:i:s'); 

// --- SATPAM WAKTU ---
$jam_masuk_master = $data_lokasi['jam_masuk'];
$jamMasukToday = strtotime($tanggalMasuk . ' ' . $jam_masuk_master);
$waktu_buka_absen = $jamMasukToday - (30 * 60); 
$waktu_sekarang = time();

if ($waktu_sekarang < $waktu_buka_absen) {
    swal_and_redirect('error', 'Akses Ditolak!', 'Belum waktunya melakukan presensi masuk.', $HOME_URL);
}
// ============================new==============================

/* --- 5. VALIDASI DATA --- */
if (empty($rawDataUri)) {
    swal_and_redirect('warning', 'Data Kosong', 'Foto biometrik gagal diambil oleh sistem.', $HOME_URL);
}

if ($latitude === '0' || $longitude === '0') {
    swal_and_redirect('warning', 'GPS Tidak Akurat', 'Gagal mendapatkan koordinat lokasi. Pastikan GPS aktif.', $HOME_URL);
}

// Tambahan baru
// [PENTING] Cek apakah sudah absen hari ini? Mencegah double insert.
$cek = $mysqli->prepare("SELECT id FROM presensi WHERE id_pegawai = ? AND tanggal_masuk = ?");
$cek->bind_param("is", $id_pegawai, $tanggalMasuk);
$cek->execute();
$cek->store_result();
if ($cek->num_rows > 0) {
    swal_and_redirect('info', 'Sudah Masuk', 'Anda sudah melakukan presensi masuk hari ini.', $HOME_URL);
}
$cek->close();

/* --- 6. PROSES SIMPAN FOTO --- */
$nama_file_rel = ''; // Inisialisasi agar bisa dipakai di SQL

try {
    // 1. Validasi & Bersihkan Base64
    if (preg_match('/^data:image\/(\w+);base64,/', $rawDataUri, $type)) {
        // Ambil tipe file (jpg/png) dari header
        $ext = strtolower($type[1]); 
        
        // Validasi ekstensi
        if (!in_array($ext, [ 'jpg', 'jpeg', 'png' ])) {
            throw new Exception('Format file gambar tidak didukung (Hanya JPG/PNG).');
        }

        // Ambil data murni setelah koma
        $rawDataUri = substr($rawDataUri, strpos($rawDataUri, ',') + 1);
        
        // Ganti spasi dengan plus (standar base64)
        $rawDataUri = str_replace(' ', '+', $rawDataUri);
        
        // Decode menjadi binary
        $fotoBinary = base64_decode($rawDataUri);
        
        if ($fotoBinary === false) {
            throw new Exception('Gagal decode base64 gambar');
        }
    } else {
        throw new Exception('Format data gambar tidak valid/kosong.');
    }

    // 2. Siapkan Folder
    $uploadDir = __DIR__ . '/../../assets/uploads/presensi/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true); 
    }

    // 3. Generate Nama File
    $nama_file_rel = 'masuk_' . date('Ymd_His') . '_' . $id_pegawai . '.jpg';
    
    // Path lengkap untuk penyimpanan (Folder + Nama File)
    $nama_file_abs = $uploadDir . '/' . $nama_file_rel;
    
    // 4. Simpan File ke Server
    // PERBAIKAN DISINI: Gunakan $nama_file_abs (bukan $file_path)
    if (file_put_contents($nama_file_abs, $fotoBinary) === false) {
        throw new Exception('Gagal menyimpan file gambar ke server');
    }

} catch (Exception $e) {
    swal_and_redirect('error', 'Gagal Upload', $e->getMessage(), $HOME_URL);
}

// ==================================================================

/* --- 7. QUERY SIMPAN KE DATABASE --- */
// Menambahkan status_kehadiran agar rekap lebih mudah (Opsional)
$sql = "INSERT INTO presensi (id_pegawai, tanggal_masuk, jam_masuk, foto_masuk, latitude_masuk, longitude_masuk) 
        VALUES (?, ?, ?, ?, ?, ?)";

$stmt = $mysqli->prepare($sql);

if ($stmt) {
    // i = integer, s = string
    $stmt->bind_param('isssss', $id_pegawai, $tanggalMasuk, $jamMasuk, $nama_file_rel, $latitude, $longitude);
    
    if ($stmt->execute()) {
        swal_and_redirect('success', 'Presensi Berhasil', 'Data kehadiran Anda telah tercatat ke sistem.', $HOME_URL);
    } else {
        swal_and_redirect('error', 'Gagal Database', $stmt->error, $HOME_URL);
    }
    $stmt->close();
} else {
    swal_and_redirect('error', 'Query Error', $mysqli->error, $HOME_URL);
}


/* --- 8. TUTUP KONEKSI --- */

// berjalan dengan baik