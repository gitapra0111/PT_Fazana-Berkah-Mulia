<?php 
require_once '../../config.php';
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

if (!isset($_GET['nip'])) {
    header("Location: pegawai.php");
    exit();
}

$nip = $_GET['nip'];

$result = mysqli_query($conn, "SELECT p.*, u.username, u.status, u.role 
    FROM pegawai p 
    JOIN users u ON p.id = u.id_pegawai 
    WHERE p.nip = '$nip'");

if (mysqli_num_rows($result) == 0) {
    header("Location: pegawai.php");
    exit();
}

$data = mysqli_fetch_assoc($result);


$judul = "detail pegawai";
include('../layout/header.php');
?>

<div class="container-xl mt-4">
    <div class="card shadow-lg">
        <div class="card-header bg-info text-white">
            <h4 class="mb-0">📋 Detail Pegawai - <?= $data['nama']; ?></h4>
        </div>
        <div class="card-body row">
            <div class="col-md-4 text-center mb-3">
    <?php 
    // Tentukan path foto
    $foto_path = "../../assets/images/foto_pegawai/" . $data['foto'];
    $foto_url = base_url('assets/images/foto_pegawai/' . $data['foto']);
    $foto_default = "https://ui-avatars.com/api/?name=" . urlencode($data['nama']) . "&background=random&size=200";

    // Jika file ada dan nama file tidak kosong
    if (!empty($data['foto']) && file_exists($foto_path)) {
        $img_src = $foto_url;
    } else {
        // Gunakan UI Avatars jika foto tidak ada (lebih keren daripada gambar 'not found')
        $img_src = $foto_default;
    }
    ?>
    <div class="img-wrapper p-2 shadow-sm bg-white rounded-circle d-inline-block">
        <img src="<?= $img_src ?>" class="rounded-circle object-cover" width="200" height="200" style="object-fit: cover; border: 5px solid #f1f5f9;">
    </div>
</div>

<!-- cukup -->
            <div class="col-md-8">
                <table class="table table-borderless">
                    <tr>
                        <th style="width: 30%;">NIP</th>
                        <td>: <?= $data['nip']; ?></td>
                    </tr>
                    <tr>
                        <th>Nama</th>
                        <td>: <?= $data['nama']; ?></td>
                    </tr>
                    <tr>
                        <th>Jenis Kelamin</th>
                        <td>: <?= $data['jenis_kelamin']; ?></td>
                    </tr>
                    <tr>
                        <th>Alamat</th>
                        <td>: <?= $data['alamat']; ?></td>
                    </tr>
                    <tr>
                        <th>No. Handphone</th>
                        <td>: <?= $data['no_handphone']; ?></td>
                    </tr>
                    <tr>
                        <th>Jabatan</th>
                        <td>: <?= $data['jabatan']; ?></td>
                    </tr>
                    <tr>
                        <th>Lokasi Presensi</th>
                        <td>: <?= $data['lokasi_presensi']; ?></td>
                    </tr>
                    <tr>
                        <th>Username</th>
                        <td>: <?= $data['username']; ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>: 
                            <?php if ($data['status'] == 'Aktif'): ?>
                                <span class="badge bg-success">Aktif</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Tidak Aktif</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Role</th>
                        <td>: <?= ucfirst($data['role']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <div class="card-footer text-end">
            <a href="pegawai.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Kembali</a>
        </div>
    </div>
</div>

<?php include('../layout/footer.php'); ?>
