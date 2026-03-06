<?php 
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

$judul = "Tambah Pegawai";
require_once '../../config.php';

// proses POST/validasi/insert SEBELUM mengeluarkan output (include header)
$pesan_kesalahan = [];

if (isset($_POST['submit'])) {

    $ambil_nip = mysqli_query($conn, "SELECT nip FROM pegawai ORDER BY nip DESC LIMIT 1");
    $nip_baru = "PEG-0001";
    if (mysqli_num_rows($ambil_nip) > 0) {
        $row = mysqli_fetch_assoc($ambil_nip);
        $nip_parts = explode('-', $row['nip']);
        $no_baru = (int)$nip_parts[1] + 1;
        $nip_baru = "PEG-" . str_pad($no_baru, 4, '0', STR_PAD_LEFT);
    } else{
        $nip_baru = "PEG-0001";
    }

    $nip = $nip_baru; 
    $nama = htmlspecialchars($_POST['nama'] ?? '');
    $jenis_kelamin = htmlspecialchars($_POST['jenis_kelamin'] ?? '');
    $alamat = htmlspecialchars($_POST['alamat'] ?? '');
    $no_handphone = htmlspecialchars($_POST['no_handphone'] ?? '');
    $jabatan = htmlspecialchars($_POST['jabatan'] ?? '');
    $username = htmlspecialchars($_POST['username'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    $ulangi_password = $_POST['ulangi_password'] ?? '';
    $role = htmlspecialchars($_POST['role'] ?? '');
    $status = htmlspecialchars($_POST['status'] ?? '');
    $lokasi_presensi = htmlspecialchars($_POST['lokasi_presensi'] ?? '');

    if (empty($nama)) {
        $pesan_kesalahan[] = "<i class='fa-solid fa-triangle-exclamation'></i> Nama wajib diisi";
    }
    if (empty($jenis_kelamin)) {
        $pesan_kesalahan[] = "<i class='fa-solid fa-triangle-exclamation'></i> Jenis kelamin wajib diisi";
    }
    if (empty($username)) {
        $pesan_kesalahan[] = "<i class='fa-solid fa-triangle-exclamation'></i> Username wajib diisi";
    } else {
        // --- LOGIKA BARU: CEK DUPLIKAT USERNAME ---
        $cek_username = mysqli_query($conn, "SELECT username FROM users WHERE username = '$username'");
        if (mysqli_num_rows($cek_username) > 0) {
            $pesan_kesalahan[] = "<i class='fa-solid fa-triangle-exclamation'></i> Username <b>'$username'</b> sudah terdaftar! Silakan gunakan nama lain.";
        }
    }
    if (strlen($password_raw) < 6) {
        $pesan_kesalahan[] = "<i class='fa-solid fa-triangle-exclamation'></i> Password minimal 6 karakter";
    }
    if ($password_raw !== $ulangi_password) {
        $pesan_kesalahan[] = "<i class='fa-solid fa-triangle-exclamation'></i> Password tidak cocok";
    }

    // Upload Foto
    $nama_file = '';
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $file = $_FILES['foto'];
        $nama_file = time() . '-' . basename($file['name']);
        $file_tmp = $file['tmp_name'];
        $file_direktori = __DIR__ . "/../../assets/images/foto_pegawai/" . $nama_file;
        // pastikan direktori ada
        @mkdir(dirname($file_direktori), 0755, true);
        move_uploaded_file($file_tmp, $file_direktori);
    }

    if (!empty($pesan_kesalahan)) {
        $_SESSION['validasi'] = implode("<br>", $pesan_kesalahan);
        // jangan redirect; biarkan tampil form dengan pesan
    } else {
        $password = password_hash($password_raw, PASSWORD_DEFAULT);

        // Simpan data pegawai
        $pegawai = mysqli_query($conn, "INSERT INTO pegawai(nip, nama, jenis_kelamin, alamat, no_handphone, jabatan, lokasi_presensi, foto) 
            VALUES ('$nip', '$nama', '$jenis_kelamin', '$alamat', '$no_handphone', '$jabatan', '$lokasi_presensi', '$nama_file')");

        $id_pegawai = mysqli_insert_id($conn);

        // Simpan data user
        $user = mysqli_query($conn, "INSERT INTO users(id_pegawai, username, password, status, role) 
            VALUES ('$id_pegawai', '$username', '$password', '$status', '$role')");

        $_SESSION['berhasil'] = "Data berhasil disimpan";
        // redirect setelah semua DB operation selesai (SEBELUM output)
        header("Location: " . base_url('admin/data_pegawai/pegawai.php'));
        exit;
    }
}

// sekarang aman untuk menampilkan header (output)
include('../layout/header.php');
?>

<div class="page-body">
    <div class="container-xl mt-4">
        <?php if (isset($_SESSION['validasi'])): ?>
            <div class="alert alert-danger">
                <?= $_SESSION['validasi']; unset($_SESSION['validasi']); ?>
            </div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars(base_url('admin/data_pegawai/tambah.php')) ?>" method="POST" enctype="multipart/form-data">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Tambah Data Pegawai</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        
                        
                        <div class="col-md-6">
                            <label>Nama</label>
                            <input type="text" class="form-control" name="nama" value="<?= $_POST['nama'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label>Jenis Kelamin</label>
                            <select name="jenis_kelamin" class="form-control">
                                <option value="">-- Pilih --</option>
                                <option value="Laki-laki" <?= isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] == 'Laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                                <option value="Perempuan" <?= isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] == 'Perempuan' ? 'selected' : '' ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Alamat</label>
                            <input type="text" class="form-control" name="alamat" value="<?= $_POST['alamat'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label>No. Handphone</label>
                            <input type="text" class="form-control" name="no_handphone" value="<?= $_POST['no_handphone'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label>Jabatan</label>
                            <select name="jabatan" class="form-control">
                                <option value="">-- Pilih Jabatan --</option>
                                <?php
                                $ambil_jabatan = mysqli_query($conn, "SELECT * FROM jabatan ORDER BY jabatan ASC");
                                while ($jab = mysqli_fetch_assoc($ambil_jabatan)) {
                                    $selected = (isset($_POST['jabatan']) && $_POST['jabatan'] == $jab['jabatan']) ? 'selected' : '';
                                    echo "<option value='{$jab['jabatan']}' {$selected}>{$jab['jabatan']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Lokasi Presensi</label>
                            <select name="lokasi_presensi" class="form-control">
                                <option value="">-- Pilih Lokasi --</option>
                                <?php
                                $lokasi = mysqli_query($conn, "SELECT * FROM lokasi_presensi ORDER BY nama_lokasi ASC");
                                while ($row = mysqli_fetch_assoc($lokasi)) {
                                    $selected = (isset($_POST['lokasi_presensi']) && $_POST['lokasi_presensi'] == $row['nama_lokasi']) ? 'selected' : '';
                                    echo "<option value='{$row['nama_lokasi']}' {$selected}>{$row['nama_lokasi']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Username</label>
                            <input type="text" class="form-control" name="username" value="<?= $_POST['username'] ?? '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label>Password</label>
                            <input type="password" class="form-control" name="password">
                        </div>
                        <div class="col-md-6">
                            <label>Ulangi Password</label>
                            <input type="password" class="form-control" name="ulangi_password">
                        </div>
                        <div class="col-md-6">
                            <label>Role</label>
                            <select name="role" class="form-control">
                                <option value="">-- Pilih Role --</option>
                                <option value="admin" <?= isset($_POST['role']) && $_POST['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="pegawai" <?= isset($_POST['role']) && $_POST['role'] == 'pegawai' ? 'selected' : '' ?>>Pegawai</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="">-- Pilih Status --</option>
                                <option value="Aktif" <?= isset($_POST['status']) && $_POST['status'] == 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Tidak Aktif" <?= isset($_POST['status']) && $_POST['status'] == 'Tidak Aktif' ? 'selected' : '' ?>>Tidak Aktif</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Foto</label>
                            <input type="file" class="form-control" name="foto">
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <a href="pegawai.php" class="btn btn-secondary me-2">Kembali</a>
                    <button type="submit" name="submit" class="btn btn-primary">Simpan Data</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include('../layout/footer.php'); ?>