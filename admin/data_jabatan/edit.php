<?php 
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

require_once '../../config.php';
$judul = "Edit Jabatan";

// Ambil ID dari URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['validasi'] = "ID tidak valid.";
    header("Location: " . base_url('admin/data_jabatan/jabatan.php'));
    exit();
}

$id = intval($_GET['id']);

// Ambil data jabatan berdasarkan ID
$data = mysqli_query($conn, "SELECT * FROM jabatan WHERE id = $id");

if (mysqli_num_rows($data) == 0) {
    $_SESSION['validasi'] = "Data tidak ditemukan.";
    header("Location: " . base_url('admin/data_jabatan/jabatan.php'));
    exit();
}

$jabatan = mysqli_fetch_assoc($data);

// Proses jika form disubmit
if (isset($_POST['submit'])) {
    $nama_jabatan = htmlspecialchars(trim($_POST['jabatan']));

    if (empty($nama_jabatan)) {
        $_SESSION['validasi'] = "Nama jabatan wajib diisi.";
    } else {
        $update = mysqli_query($conn, "UPDATE jabatan SET jabatan = '$nama_jabatan' WHERE id = $id");

        if ($update) {
            $_SESSION['validasi'] = "Data jabatan berhasil diperbarui.";
            header("Location: " . base_url('admin/data_jabatan/jabatan.php'));
            exit();
        } else {
            $_SESSION['validasi'] = "Gagal memperbarui data.";
        }
    }
}

include('../layout/header.php');
?>

<!-- Form Edit -->
<div class="page-body">
    <div class="container-xl">
        <div class="card col-md-6">
            <div class="card-header"><strong><?= $judul; ?></strong></div>
            <div class="card-body">

                <?php if (isset($_SESSION['validasi'])): ?>
                    <div class="alert alert-warning">
                        <?= $_SESSION['validasi']; ?>
                        <?php unset($_SESSION['validasi']); ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post">
                    <div class="mb-3">
                        <label for="jabatan">Nama Jabatan</label>
                        <input type="text" name="jabatan" id="jabatan" class="form-control" value="<?= htmlspecialchars($jabatan['jabatan']); ?>" required>
                    </div>
                    <button type="submit" name="submit" class="btn btn-success">Update</button>
                    <a href="<?= base_url('admin/data_jabatan/jabatan.php'); ?>" class="btn btn-secondary">Batal</a>
                </form>

            </div>
        </div>
    </div>
</div>

<?php include('../layout/footer.php'); ?>
