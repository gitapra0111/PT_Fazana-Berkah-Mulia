<?php 
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

$judul = "Data Jabatan";

require_once '../../config.php'; // koneksi database
include('../layout/header.php'); // pastikan base_url() didefinisikan di sini

// Ambil data jabatan dari DB
$result = mysqli_query($conn, "SELECT * FROM jabatan ORDER BY id DESC");

if (!$result) {
    die("Query Error: " . mysqli_error($conn));
}
?>

<!-- Konten Halaman -->
<div class="page-body">
    <div class="container-xl">
      <a href="<?= base_url('admin/data_jabatan/tambah.php')?>" class="btn btn-primary"><span class="text"><i class="fa-solid fa-circle-plus"></i> Tambah Data</span></a>
        <div class="row row-deck row-cards mt-2">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Daftar Jabatan</h3>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <thead class="thead-light">
                            <tr>
                                <th>No.</th>
                                <th class="text-center">Nama Jabatan</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($result) == 0): ?>
                                <tr>
                                    <td colspan="3" class="text-center">Tidak ada data jabatan</td>
                                </tr>
                            <?php else: ?>
                                <?php $no = 1; ?>
                                <?php while ($jabatan = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td class="text-center"><?= htmlspecialchars($jabatan['jabatan']); ?></td>
                                        <td class="text-center">
                                            <a href="<?= base_url('admin/data_jabatan/edit.php?id=' . $jabatan['id']) ?>" class="badge bg-primary badge-pill">Edit</a>
                                            <a href="<?= base_url('admin/data_jabatan/hapus.php?id=' . $jabatan['id']) ?>" class="badge bg-danger badge-pill" onclick="return confirm('Yakin ingin menghapus data ini?');">Hapus</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>



<?php include('../layout/footer.php'); ?>
