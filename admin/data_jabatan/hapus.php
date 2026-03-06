<?php
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

require_once '../../config.php';

// Validasi id
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);

    // Cek apakah data dengan ID tersebut ada
    $cek = mysqli_query($conn, "SELECT * FROM jabatan WHERE id = $id");
    if (mysqli_num_rows($cek) > 0) {
        // Hapus data
        $hapus = mysqli_query($conn, "DELETE FROM jabatan WHERE id = $id");
        if ($hapus) {
            $_SESSION['validasi'] = "Data berhasil dihapus.";
        } else {
            $_SESSION['validasi'] = "Gagal menghapus data.";
        }
    } else {
        $_SESSION['validasi'] = "Data tidak ditemukan.";
    }
} else {
    $_SESSION['validasi'] = "ID tidak valid.";
}

// Redirect kembali ke halaman utama
header("Location: " . base_url('admin/data_jabatan/jabatan.php'));
exit();
