<?php
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

$judul = "Detail Lokasi Presensi";

require_once '../../config.php';
include('../layout/header.php');

// Ambil data berdasarkan ID
$id = $_GET['id'];
$data = mysqli_query($conn, "SELECT * FROM lokasi_presensi WHERE id = '$id'");
$lokasi = mysqli_fetch_assoc($data);

if (!$lokasi) {
    echo "<script>alert('Data tidak ditemukan');window.location='lokasi_presensi.php';</script>";
    exit();
}

// Ambil latitude dan longitude
$lat = $lokasi['latitude'];
$lng = $lokasi['longitude'];
?>

<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center mt-4">
            <div class="col-md-8">
                <div class="card shadow border-0">
                    <div class="card-header bg-info text-white fw-bold">
                        <i class="fas fa-info-circle me-2"></i> Detail Lokasi Presensi
                    </div>
                    <div class="card-body">
                        <table class="table table-striped">
                            <tr>
                                <th>Nama Lokasi</th>
                                <td><?= $lokasi['nama_lokasi'] ?></td>
                            </tr>
                            <tr>
                                <th>Alamat Lokasi</th>
                                <td><?= $lokasi['alamat_lokasi'] ?></td>
                            </tr>
                            <tr>
                                <th>Tipe Lokasi</th>
                                <td><?= ucfirst($lokasi['tipe_lokasi']) ?></td>
                            </tr>
                            <tr>
                                <th>Latitude / Longitude</th>
                                <td><?= $lat . ' / ' . $lng ?></td>
                            </tr>
                            <tr>
                                <th>Radius</th>
                                <td><?= $lokasi['radius'] ?> meter</td>
                            </tr>
                            <tr>
                                <th>Zona Waktu</th>
                                <td><?= $lokasi['zona_waktu'] ?></td>
                            </tr>
                            <tr>
                                <th>Jam Masuk</th>
                                <td><?= date('H:i', strtotime($lokasi['jam_masuk'])) ?></td>
                            </tr>
                            <tr>
                                <th>Jam Pulang</th>
                                <td><?= date('H:i', strtotime($lokasi['jam_pulang'])) ?></td>
                            </tr>
                        </table>

                        <!-- Peta Lokasi -->
                        <div class="mt-4">
                            <label class="fw-bold mb-2">Peta Lokasi:</label>
                            <div class="ratio ratio-16x9 rounded shadow-sm border">
                                <iframe 
                                    src="https://www.google.com/maps?q=<?= $lat ?>,<?= $lng ?>&hl=id&z=16&output=embed"
                                    width="100%" 
                                    height="450" 
                                    style="border:0;" 
                                    allowfullscreen 
                                    loading="lazy" 
                                    referrerpolicy="no-referrer-when-downgrade">
                                </iframe>
                            </div>
                        </div>

                        <div class="mt-4 text-end">
                            <a href="lokasi_presensi.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Kembali
                            </a>
                            <a href="edit.php?id=<?= $lokasi['id'] ?>" class="btn btn-warning text-white">
                                <i class="fas fa-edit me-1"></i> Edit Data
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('../layout/footer.php'); ?>
