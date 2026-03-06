<?php 
require_once '../../config.php';

// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

if (isset($_GET['nip'])) {
    $nip = mysqli_real_escape_string($conn, $_GET['nip']);

    // Ambil id_pegawai dan foto
    $getData = mysqli_query($conn, "SELECT id AS id_pegawai, foto FROM pegawai WHERE nip = '$nip'");
    if (mysqli_num_rows($getData) > 0) {
        $data = mysqli_fetch_assoc($getData);
        $id_pegawai = $data['id_pegawai'];
        $foto = $data['foto'];

        // Hapus dari tabel users terlebih dahulu
        mysqli_query($conn, "DELETE FROM users WHERE id_pegawai = '$id_pegawai'");

        // Hapus foto dari folder
        if (!empty($foto) && file_exists("../../assets/images/foto_pegawai/$foto")) {
            unlink("../../assets/images/foto_pegawai/$foto");
        }

        // Hapus dari tabel pegawai
        $hapusPegawai = mysqli_query($conn, "DELETE FROM pegawai WHERE nip = '$nip'");

        if ($hapusPegawai) {
            header("Location: pegawai.php?pesan=hapus_sukses");
            exit();
        } else {
            header("Location: pegawai.php?pesan=hapus_gagal");
            exit();
        }
    } else {
        header("Location: pegawai.php?pesan=hapus_gagal");
        exit();
    }
} else {
    header("Location: pegawai.php?pesan=hapus_gagal");
    exit();
}
