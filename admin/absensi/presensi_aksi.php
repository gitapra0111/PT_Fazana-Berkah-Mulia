<?php
// FILE: admin/absensi/presensi_aksi.php

require_once '../../config.php'; 


if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// 2. CEK LOGIN
if (!isset($_SESSION['user']['login']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../../auth/login.php");
    exit;
}

// 3. KONEKSI DB
$db = null;
if (isset($conn)) { $db = $conn; } 
elseif (isset($koneksi)) { $db = $koneksi; }

if (!$db) {
    die("Koneksi database gagal.");
}

// 4. TANGKAP DATA DASAR USER
$id_pegawai = $_SESSION['user']['id_pegawai'];

// --- UPDATE QUERY: Ambil zona_waktu DAN lokasi_presensi agar bisa cek jam master ---
$sql_user = "SELECT p.lokasi_presensi, l.zona_waktu 
             FROM pegawai p 
             JOIN lokasi_presensi l ON p.lokasi_presensi = l.nama_lokasi 
             WHERE p.id = ?";
$stmt_u = $db->prepare($sql_user);
$stmt_u->bind_param('i', $id_pegawai);
$stmt_u->execute();
$res_user = $stmt_u->get_result()->fetch_assoc();
$zona = $res_user['zona_waktu'] ?? 'WIB';
$lokasi_admin = $res_user['lokasi_presensi'];

// SET ZONA WAKTU SECARA DINAMIS
if ($zona === 'WIB') date_default_timezone_set('Asia/Jakarta');
elseif ($zona === 'WITA') date_default_timezone_set('Asia/Makassar');
elseif ($zona === 'WIT') date_default_timezone_set('Asia/Jayapura');

// AMBIL WAKTU SERVER SEKARANG
$tanggal_hari_ini = date('Y-m-d');
$jam_sekarang     = date('H:i:s');

// 5. TANGKAP DATA DARI POST (Pindahkan ke sini agar variabelnya siap dipakai)
$latitude     = $_POST['latitude'] ?? null;
$longitude    = $_POST['longitude'] ?? null;
$tipe_absen   = $_POST['tipe_absen'] ?? 'masuk'; 
$foto_base64  = $_POST['foto_base64'] ?? null;
$accuracy     = $_POST['accuracy'] ?? null;

/// --- PENGAMANAN TAMBAHAN: VALIDASI MODE BERDASARKAN JAM MASTER ---
$sql_master = "SELECT jam_masuk, jam_pulang, latitude, longitude, radius FROM lokasi_presensi WHERE nama_lokasi = ?";
$stmt_m = $db->prepare($sql_master);
// UBAH $res_zona MENJADI $res_user
$stmt_m->bind_param('s', $res_user['lokasi_presensi']); 
$stmt_m->execute();
$master = $stmt_m->get_result()->fetch_assoc();

if ($tipe_absen === 'masuk') {
    $waktu_buka = strtotime($master['jam_masuk']) - (30 * 60);
    if (strtotime($jam_sekarang) < $waktu_buka) {
        $_SESSION['gagal'] = "Akses Ilegal: Belum waktunya absen masuk!";
        header("Location: presensi.php"); exit;
    }
} elseif ($tipe_absen === 'keluar') {
    if (strtotime($jam_sekarang) < strtotime($master['jam_pulang'])) {
        $_SESSION['gagal'] = "Akses Ilegal: Belum waktunya absen pulang!";
        header("Location: presensi.php"); exit;
    }
}
// ============batas================

// Tambahan Proteksi Anti-Fake GPS di Server (Opsional tapi Bagus)
if ($accuracy !== null && (float)$accuracy <= 1.0 && (float)$accuracy > 0) {
    $_SESSION['gagal'] = "Terdeteksi Lokasi Palsu (Akurasi Terlalu Sempurna).";
    header("Location: presensi.php");
    exit;
}

// --- PENGAMANAN TAMBAHAN: VALIDASI RADIUS (GEOFENCING PHP) ---
function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000.0;
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1); $dlmb = deg2rad($lon2 - $lon1);
    $a = sin($dphi/2) ** 2 + cos($phi1) * cos($phi2) * sin($dlmb/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

$jarakMeter = haversine_m((float)$latitude, (float)$longitude, (float)$master['latitude'], (float)$master['longitude']);

if ($jarakMeter > (float)$master['radius']) {
    $_SESSION['gagal'] = "Akses Ditolak: Jarak Anda (" . round($jarakMeter) . "m) berada di luar radius kantor!";
    header("Location: presensi.php");
    exit;
}
// -------------------------------------------------------------

// Validasi Data Kosong
if (empty($latitude) || empty($longitude) || empty($foto_base64)) {
    $_SESSION['gagal'] = "Gagal: Lokasi atau Foto tidak terdeteksi.";
    header("Location: presensi.php");
    exit;
}

// 5. PROSES SIMPAN FOTO KE FOLDER
$folder_tujuan = "../../assets/uploads/presensi/";

if (!file_exists($folder_tujuan)) {
    mkdir($folder_tujuan, 0755, true);
}

$foto_parts = explode(";base64,", $foto_base64);
if (count($foto_parts) < 2) {
    $_SESSION['gagal'] = "Format foto tidak valid.";
    header("Location: presensi.php");
    exit;
}
$foto_base64_decode = base64_decode($foto_parts[1]);

// Nama File: masuk_16_20231105.jpg
$nama_file = $tipe_absen . "_" . $id_pegawai . "_" . date('Ymd_His') . ".jpg";
$path_lengkap = $folder_tujuan . $nama_file;

if (file_put_contents($path_lengkap, $foto_base64_decode) === false) {
    $_SESSION['gagal'] = "Gagal menyimpan file foto.";
    header("Location: presensi.php");
    exit;
}

// =================================================================================
// 6. LOGIKA DATABASE (SESUAI STRUKTUR TABEL ANDA)
// =================================================================================

if ($tipe_absen == 'masuk') {
    
    // --- CEK DUPLIKASI MASUK ---
    // Cek apakah hari ini sudah ada data di kolom 'tanggal_masuk'
    $cek_sql = "SELECT id FROM presensi WHERE id_pegawai = ? AND tanggal_masuk = ?";
    $stmt_cek = $db->prepare($cek_sql);
    $stmt_cek->bind_param('is', $id_pegawai, $tanggal_hari_ini);
    $stmt_cek->execute();
    
    if ($stmt_cek->get_result()->num_rows > 0) {
        $_SESSION['gagal'] = "Anda sudah absen MASUK hari ini!";
        header("Location: ../home/home.php");
        exit;
    }

    // --- QUERY INSERT (MASUK) ---
    // Perhatikan nama kolom sesuai gambar database Anda
    $sql = "INSERT INTO presensi (id_pegawai, tanggal_masuk, jam_masuk, foto_masuk, latitude_masuk, longitude_masuk) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param('isssss', $id_pegawai, $tanggal_hari_ini, $jam_sekarang, $nama_file, $latitude, $longitude);

} elseif ($tipe_absen == 'keluar') {

    // --- CEK APAKAH SUDAH ABSEN MASUK DULUAN? ---
    // Absen keluar hanya bisa dilakukan jika sudah absen masuk hari ini
    $cek_sql = "SELECT id, tanggal_keluar FROM presensi WHERE id_pegawai = ? AND tanggal_masuk = ?";
    $stmt_cek = $db->prepare($cek_sql);
    $stmt_cek->bind_param('is', $id_pegawai, $tanggal_hari_ini);
    $stmt_cek->execute();
    $result = $stmt_cek->get_result();
    $data_absen = $result->fetch_assoc();

    if ($result->num_rows == 0) {
        $_SESSION['gagal'] = "Anda belum absen MASUK hari ini!";
        header("Location: presensi.php");
        exit;
    }

    if (!empty($data_absen['tanggal_keluar'])) {
        $_SESSION['gagal'] = "Anda sudah absen KELUAR hari ini!";
        header("Location: ../home/home.php");
        exit;
    }

    // --- QUERY UPDATE (KELUAR) ---
    $sql = "UPDATE presensi SET 
            tanggal_keluar = ?, 
            jam_keluar = ?, 
            foto_keluar = ?, 
            latitude_keluar = ?, 
            longitude_keluar = ? 
            WHERE id = ?";
            
    $stmt = $db->prepare($sql);
    // Menggunakan data_absen['id'] dari hasil query cek sebelumnya
    $stmt->bind_param('sssssi', $tanggal_hari_ini, $jam_sekarang, $nama_file, $latitude, $longitude, $data_absen['id']);
}

// EKSEKUSI QUERY
if ($stmt->execute()) {
    $_SESSION['berhasil'] = "Presensi " . strtoupper($tipe_absen) . " Berhasil!";
    header("Location: ../home/home.php");
} else {
    $_SESSION['gagal'] = "Database Error: " . $stmt->error;
    header("Location: presensi.php");
}

exit;