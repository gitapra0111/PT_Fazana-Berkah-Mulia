<?php
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

require_once '../../config.php'; // koneksi database

// Ambil ID dari parameter URL
$id = isset($_GET['id']) ? $_GET['id'] : null;

if ($id) {
    // Query untuk menghapus data
    $query = "DELETE FROM lokasi_presensi WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $result = mysqli_stmt_execute($stmt);
    
    if ($result) {
        // Jika berhasil dihapus, redirect dengan pesan sukses
        header("Location: lokasi_presensi.php?pesan=hapus_sukses");
        exit();
    } else {
        // Jika gagal, redirect dengan pesan error
        header("Location: lokasi_presensi.php?pesan=hapus_gagal");
        exit();
    }
} else {
    // Jika tidak ada ID, redirect dengan pesan error
    header("Location: lokasi_presensi.php?pesan=id_tidak_valid");
    exit();
}
?>