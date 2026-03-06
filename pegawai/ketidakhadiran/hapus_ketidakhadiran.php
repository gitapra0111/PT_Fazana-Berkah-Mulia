<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); exit;
}

require_once __DIR__ . '/../../config.php';

// koneksi
if (!isset($connection) || !($connection instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $connection = $conn;
    } elseif (isset($koneksi) && $koneksi instanceof mysqli) {
        $connection = $koneksi;
    } else {
        $connection = new mysqli('localhost', 'root', '', 'presensi');
    }
}

$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
$id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: ketidakhadiran.php"); exit;
}

// ambil dulu datanya buat hapus file
$stmt = $connection->prepare("SELECT file, status_pengajuan FROM ketidakhadiran WHERE id = ? AND id_pegawai = ? LIMIT 1");
$stmt->bind_param("ii", $id, $idPegawai);
$stmt->execute();
$res  = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    header("Location: ketidakhadiran.php?err=notfound"); exit;
}

if ($data['status_pengajuan'] !== 'PENDING') {
    header("Location: ketidakhadiran.php?err=processed"); exit;
}

// hapus dari db
$stmtDel = $connection->prepare("DELETE FROM ketidakhadiran WHERE id = ? AND id_pegawai = ? AND status_pengajuan = 'PENDING'");
$stmtDel->bind_param("ii", $id, $idPegawai);
if ($stmtDel->execute()) {
    // hapus file
    if (!empty($data['file'])) {
        $filePath = __DIR__ . '/../../assets/uploads/ketidakhadiran/' . $data['file'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    header("Location: ketidakhadiran.php?msg=deleted"); exit;
} else {
    header("Location: ketidakhadiran.php?err=deletefail"); exit;
}
