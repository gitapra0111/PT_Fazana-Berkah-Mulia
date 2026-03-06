<?php 
require_once '../../config.php';

// 1. Cek Sesi Admin
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); 
    exit;
}

// 2. Koneksi Database
$mysqli = null;
if (isset($conn) && $conn instanceof mysqli) $mysqli = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli) $mysqli = $koneksi;

if (!$mysqli) die("Koneksi database gagal.");

// 3. Ambil Data Pegawai Berdasarkan NIP
if (!isset($_GET['nip'])) {
    header("Location: pegawai.php");
    exit();
}
$nipAsli = $_GET['nip'];

// Prepared Statement (Ambil Data Lama)
$stmt = $mysqli->prepare("
    SELECT pegawai.*, users.username, users.role, users.status, users.id AS id_user
    FROM pegawai 
    JOIN users ON pegawai.id = users.id_pegawai 
    WHERE pegawai.nip = ?
");
$stmt->bind_param("s", $nipAsli);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: pegawai.php");
    exit();
}
$data = $result->fetch_assoc();
$stmt->close();

$pesan_kesalahan = [];

// 4. Proses Update Data
if (isset($_POST['update'])) {
    $nama            = trim($_POST['nama']);
    $jenis_kelamin   = trim($_POST['jenis_kelamin']);
    $alamat          = trim($_POST['alamat']);
    $no_handphone    = trim($_POST['no_handphone']);
    $jabatan         = trim($_POST['jabatan']);
    $lokasi_presensi = trim($_POST['lokasi_presensi']);
    $username        = trim($_POST['username']);
    $role            = trim($_POST['role']);
    $status          = trim($_POST['status']);
    $password_baru   = trim($_POST['password_baru'] ?? '');

    // Validasi Wajib
    if (empty($nama) || empty($username)) {
        $pesan_kesalahan[] = "Nama dan Username wajib diisi.";
    } else {
        // --- LOGIKA CEK DUPLIKAT USERNAME (KECUALIKAN DIRI SENDIRI) ---
        // Ambil ID User dari data lama yang sedang diedit
        $id_user_sekarang = $data['id_user']; 
        
        // Query: Cari username yang sama, TAPI yang ID User-nya BUKAN ID user ini
        $stmtCek = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmtCek->bind_param("si", $username, $id_user_sekarang);
        $stmtCek->execute();
        $stmtCek->store_result();
        
        if ($stmtCek->num_rows > 0) {
            $pesan_kesalahan[] = "Username <b>'$username'</b> sudah dipakai oleh pegawai lain. Silakan cari nama lain.";
        }
        $stmtCek->close();
    }

    // --- LOGIKA UPLOAD FOTO YANG DIPERBAIKI ---
    $foto_final = $data['foto']; // Default pakai foto lama
    $upload_sukses = false;
    $path_folder = "../../assets/images/foto_pegawai/";

    if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $fileTmp   = $_FILES['foto']['tmp_name'];
        $fileName  = $_FILES['foto']['name'];
        
        // Ambil ekstensi
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowed)) {
            // Beri nama unik (mencegah cache browser & file bentrok)
            // Format: NIP_TIMESTAMP_ACAK.ext
            $foto_baru_nama = $nipAsli . '_' . time() . '_' . rand(100,999) . '.' . $ext;
            
            // Pastikan folder ada
            if (!is_dir($path_folder)) mkdir($path_folder, 0755, true);

            // Pindahkan file baru
            if (move_uploaded_file($fileTmp, $path_folder . $foto_baru_nama)) {
                $foto_final = $foto_baru_nama; // Siap simpan ke DB
                $upload_sukses = true;
            } else {
                $pesan_kesalahan[] = "Gagal memindahkan file foto.";
            }
        } else {
            $pesan_kesalahan[] = "Format foto harus JPG, PNG, atau WEBP.";
        }
    }

    // Eksekusi Update ke Database
    if (empty($pesan_kesalahan)) {
        $mysqli->begin_transaction(); 

        try {
            // 1. Update Tabel Pegawai
            $stmt1 = $mysqli->prepare("UPDATE pegawai SET nama=?, jenis_kelamin=?, alamat=?, no_handphone=?, jabatan=?, lokasi_presensi=?, foto=? WHERE nip=?");
            $stmt1->bind_param("ssssssss", $nama, $jenis_kelamin, $alamat, $no_handphone, $jabatan, $lokasi_presensi, $foto_final, $nipAsli);
            $stmt1->execute();

            // 2. Update Tabel Users
            if (!empty($password_baru)) {
                $hash = password_hash($password_baru, PASSWORD_DEFAULT);
                $stmt2 = $mysqli->prepare("UPDATE users SET username=?, role=?, status=?, password=? WHERE id=?");
                $stmt2->bind_param("ssssi", $username, $role, $status, $hash, $data['id_user']);
            } else {
                $stmt2 = $mysqli->prepare("UPDATE users SET username=?, role=?, status=? WHERE id=?");
                $stmt2->bind_param("sssi", $username, $role, $status, $data['id_user']);
            }
            $stmt2->execute();

            // 3. Commit Transaksi
            $mysqli->commit();

            // --- HAPUS FOTO LAMA (Hanya jika DB sukses update & ada upload baru) ---
            if ($upload_sukses && !empty($data['foto'])) {
                $file_lama = $path_folder . $data['foto'];
                if (file_exists($file_lama)) {
                    unlink($file_lama); // Hapus file lama agar tidak menumpuk
                }
            }

            $_SESSION['berhasil'] = "Data pegawai berhasil diperbarui.";
            header("Location: pegawai.php?pesan=edit_sukses");
            exit;

        } catch (Exception $e) {
            $mysqli->rollback(); 
            // Jika gagal DB, hapus foto baru yang terlanjur diupload (Cleanup)
            if ($upload_sukses && file_exists($path_folder . $foto_final)) {
                unlink($path_folder . $foto_final);
            }
            $pesan_kesalahan[] = "Error Sistem: " . $e->getMessage();
        }
    }
}

$judul = "Edit Pegawai";
?>

<?php include('../layout/header.php'); ?>
<div class="container-xl mt-4">
    <h4>Edit Data Pegawai - <?= htmlspecialchars($data['nama']); ?></h4>
    <?php if (!empty($pesan_kesalahan)): ?>
        <div class="alert alert-danger"><?= implode('<br>', $pesan_kesalahan); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="card">
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label>Nama</label>
                    <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($data['nama']); ?>">
                </div>

                <div class="col-md-6">
                    <label>Jenis Kelamin</label>
                    <select name="jenis_kelamin" class="form-control">
                        <option value="Laki-laki" <?= $data['jenis_kelamin'] == 'Laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                        <option value="Perempuan" <?= $data['jenis_kelamin'] == 'Perempuan' ? 'selected' : '' ?>>Perempuan</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label>Alamat</label>
                    <input type="text" name="alamat" class="form-control" value="<?= htmlspecialchars($data['alamat']); ?>">
                </div>

                <div class="col-md-6">
                    <label>No. Handphone</label>
                    <input type="text" name="no_handphone" class="form-control" value="<?= htmlspecialchars($data['no_handphone']); ?>">
                </div>

                <div class="col-md-6">
                    <label>Jabatan</label>
                    <select name="jabatan" class="form-control">
                        <option value="">-- Pilih Jabatan --</option>
                        <?php
                        $jabRes = $mysqli->query("SELECT * FROM jabatan ORDER BY jabatan ASC");
                        while ($j = $jabRes->fetch_assoc()) {
                            $sel = ($j['jabatan'] == $data['jabatan']) ? 'selected' : '';
                            echo "<option value='{$j['jabatan']}' $sel>{$j['jabatan']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label>Lokasi Presensi</label>
                    <select name="lokasi_presensi" class="form-control">
                        <option value="">-- Pilih Lokasi --</option>
                        <?php
                        $lokRes = $mysqli->query("SELECT * FROM lokasi_presensi ORDER BY nama_lokasi ASC");
                        while ($l = $lokRes->fetch_assoc()) {
                            $sel = ($l['nama_lokasi'] == $data['lokasi_presensi']) ? 'selected' : '';
                            echo "<option value='{$l['nama_lokasi']}' $sel>{$l['nama_lokasi']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($data['username']); ?>">
                </div>

                <div class="col-md-6">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="Aktif" <?= $data['status'] == 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                        <option value="Tidak Aktif" <?= $data['status'] == 'Tidak Aktif' ? 'selected' : '' ?>>Tidak Aktif</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label>Role</label>
                    <select name="role" class="form-control">
                        <option value="admin" <?= $data['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="pegawai" <?= $data['role'] == 'pegawai' ? 'selected' : '' ?>>Pegawai</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label>Password Baru (opsional)</label>
                    <input type="password" name="password_baru" class="form-control" placeholder="Biarkan kosong jika tidak diganti">
                </div>

                <div class="col-md-6">
                    <label>Foto Profil (Opsional)</label>
                    <input type="file" name="foto" class="form-control" accept=".jpg, .jpeg, .png, .webp">
                    <div class="mt-2">
                        <?php if (!empty($data['foto'])): ?>
                            <img src="../../assets/images/foto_pegawai/<?= htmlspecialchars($data['foto']) ?>" 
                                 class="rounded-circle border" width="60" height="60" style="object-fit:cover;">
                            <small class="d-block text-muted">Foto saat ini</small>
                        <?php else: ?>
                            <small class="text-muted">Belum ada foto</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-footer text-end">
                <a href="pegawai.php" class="btn btn-secondary">Kembali</a>
                <button type="submit" name="update" class="btn btn-success">Simpan Perubahan</button>
            </div>
        </div>
    </form>
</div>
<?php include('../layout/footer.php'); ?>