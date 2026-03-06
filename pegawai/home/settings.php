<?php
// FILE: pegawai/home/settings.php
declare(strict_types=1);

// 1. Session & Auth
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses");
    exit;
}

require_once __DIR__ . '/../../config.php';

// 2. Koneksi Database
$mysqli = null;
if (isset($conn) && $conn instanceof mysqli)        $mysqli = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli)          $mysqli = $koneksi;

if (!$mysqli) die("Koneksi database gagal.");

// 3. Ambil ID Pegawai
$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
if ($idPegawai <= 0) die("ID pegawai tidak valid.");

// 4. Ambil Data Awal
$stmt = $mysqli->prepare("
    SELECT p.*, u.id AS id_user, u.username, u.password 
    FROM pegawai p
    JOIN users u ON u.id_pegawai = p.id
    WHERE p.id = ?
");
$stmt->bind_param('i', $idPegawai);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) die("Data pegawai tidak ditemukan.");

$pesanError   = '';
$pesanSukses  = '';

// 5. Proses Simpan (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Tangkap Input
    $username     = trim($_POST['username'] ?? '');
    $alamat       = trim($_POST['alamat'] ?? '');
    $no_hp        = trim($_POST['no_handphone'] ?? '');
    $oldPassword  = trim($_POST['old_password'] ?? '');
    $newPassword  = trim($_POST['new_password'] ?? ''); // Ubah 'password' jadi 'new_password' biar jelas
    
    // Validasi Wajib
    if (empty($username)) {
        $pesanError = "Username tidak boleh kosong.";
    }

    // Cek Duplikasi Username (jika ganti username)
    if ($username !== $data['username']) {
        $cekStmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $cekStmt->bind_param("si", $username, $data['id_user']);
        $cekStmt->execute();
        if ($cekStmt->get_result()->num_rows > 0) {
            $pesanError = "Username '$username' sudah digunakan orang lain.";
        }
        $cekStmt->close();
    }

    // PROSES FILE FOTO (Validasi Aman)
    $foto_final = $data['foto'];
    $upload_sukses = false;
    
    if (empty($pesanError) && !empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['foto']['tmp_name'];
        $fName   = $_FILES['foto']['name'];
        $fSize   = $_FILES['foto']['size'];
        
        $ext     = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (!in_array($ext, $allowed)) {
            $pesanError = "Format foto harus JPG, PNG, atau WEBP.";
        } elseif ($fSize > 2 * 1024 * 1024) {
            $pesanError = "Ukuran foto maksimal 2MB.";
        } else {
            // Nama Unik
            $newName = $data['nip'] . '_' . time() . '_' . rand(100,999) . '.' . $ext;
            $targetDir = __DIR__ . '/../../assets/images/foto_pegawai/';
            
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            
            if (move_uploaded_file($tmpName, $targetDir . $newName)) {
                $foto_final = $newName;
                $upload_sukses = true;
            } else {
                $pesanError = "Gagal mengupload foto ke server.";
            }
        }
    }

    // PROSES GANTI PASSWORD (Validasi Ketat)
    $hashPasswordBaru = null;
    if (empty($pesanError) && !empty($newPassword)) {
        if (empty($oldPassword)) {
            $pesanError = "Masukkan password lama untuk mengganti password baru.";
        } else {
            // Cek Password Lama
            if (!password_verify($oldPassword, $data['password'])) {
                $pesanError = "Password lama yang Anda masukkan salah.";
            } else {
                // Hash Password Baru
                $hashPasswordBaru = password_hash($newPassword, PASSWORD_DEFAULT);
            }
        }
    }

    // EKSEKUSI UPDATE DATABASE
    if (empty($pesanError)) {
        $mysqli->begin_transaction();
        try {
            // Update Pegawai
            $stmt1 = $mysqli->prepare("UPDATE pegawai SET alamat=?, no_handphone=?, foto=? WHERE id=?");
            $stmt1->bind_param("sssi", $alamat, $no_hp, $foto_final, $idPegawai);
            $stmt1->execute();

            // Update Users
            if ($hashPasswordBaru) {
                // Jika Ganti Password
                $stmt2 = $mysqli->prepare("UPDATE users SET username=?, password=? WHERE id=?");
                $stmt2->bind_param("ssi", $username, $hashPasswordBaru, $data['id_user']);
            } else {
                // Jika Cuma Ganti Username
                $stmt2 = $mysqli->prepare("UPDATE users SET username=? WHERE id=?");
                $stmt2->bind_param("si", $username, $data['id_user']);
            }
            $stmt2->execute();

            $mysqli->commit();

            // Hapus Foto Lama (Cleanup)
            if ($upload_sukses && !empty($data['foto'])) {
                $oldFile = __DIR__ . '/../../assets/images/foto_pegawai/' . $data['foto'];
                if (file_exists($oldFile)) unlink($oldFile);
            }
            
            // Update Session Data (Supaya header langsung berubah tanpa logout)
            $_SESSION['user']['username'] = $username;
            if ($upload_sukses) $_SESSION['user']['foto'] = $foto_final;

            $pesanSukses = "Profil berhasil diperbarui.";
            
            // Refresh Data Variabel agar form menampilkan data terbaru
            $data['username'] = $username;
            $data['alamat']   = $alamat;
            $data['no_handphone'] = $no_hp;
            $data['foto']     = $foto_final;

        } catch (Exception $e) {
            $mysqli->rollback();
            if ($upload_sukses && file_exists(__DIR__ . '/../../assets/images/foto_pegawai/' . $foto_final)) {
                unlink(__DIR__ . '/../../assets/images/foto_pegawai/' . $foto_final);
            }
            $pesanError = "Gagal menyimpan: " . $e->getMessage();
        }
    }
}

$judul = "Pengaturan Akun";
require_once __DIR__ . '/../layout/header.php';
?>

<div class="my-3 my-md-5">
  <div class="container">
    <div class="row">
      
      <div class="col-lg-8">
        <form method="post" enctype="multipart/form-data" class="card shadow-sm">
          <div class="card-header bg-white">
            <h3 class="card-title fw-bold"><i class="fa fa-cog me-2"></i> Pengaturan Akun</h3>
          </div>
          
          <div class="card-body">
            <?php if ($pesanSukses): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fa fa-check-circle me-2"></i> <?= $pesanSukses ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($pesanError): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fa fa-exclamation-triangle me-2"></i> <?= $pesanError ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-user"></i></span>
                        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($data['username']); ?>" required>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="p-3 bg-light border rounded">
                        <label class="form-label fw-bold text-dark">Ganti Password (Opsional)</label>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="password" name="old_password" class="form-control" placeholder="Password Lama">
                            </div>
                            <div class="col-md-6">
                                <input type="password" name="new_password" class="form-control" placeholder="Password Baru">
                            </div>
                        </div>
                        <small class="text-muted mt-1 d-block"><i class="fa fa-info-circle"></i> Isi kedua kolom di atas hanya jika ingin mengubah password.</small>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">No. Handphone</label>
                    <input type="text" name="no_handphone" class="form-control" value="<?= htmlspecialchars($data['no_handphone'] ?? ''); ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-label">Alamat Lengkap</label>
                    <textarea name="alamat" class="form-control" rows="2"><?= htmlspecialchars($data['alamat'] ?? ''); ?></textarea>
                </div>

                <div class="col-md-12">
                    <label class="form-label">Foto Profil</label>
                    <div class="d-flex align-items-center gap-3">
                        <?php if (!empty($data['foto'])): ?>
                            <img src="../../assets/images/foto_pegawai/<?= htmlspecialchars($data['foto']) ?>" 
                                 class="rounded-circle border" width="60" height="60" style="object-fit:cover;">
                        <?php else: ?>
                            <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center text-white" 
                                 style="width:60px; height:60px;">Foto</div>
                        <?php endif; ?>
                        
                        <div class="flex-grow-1">
                            <input type="file" name="foto" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                            <small class="text-muted">Maksimal 2MB (JPG, PNG)</small>
                        </div>
                    </div>
                </div>
            </div>
          </div>
          
          <div class="card-footer text-end bg-white">
            <a href="profile.php" class="btn btn-secondary me-2">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Simpan Perubahan</button>
          </div>
        </form>
      </div>

      <div class="col-lg-4 mt-4 mt-lg-0">
        <div class="card shadow-sm">
          <div class="card-header bg-info text-white">
            <h3 class="card-title"><i class="fa fa-info-circle me-2"></i> Informasi</h3>
          </div>
          <div class="card-body">
            <p class="mb-3">
                Data berikut <strong>tidak dapat diubah</strong> sendiri dan harus menghubungi Admin/HRD:
            </p>
            <ul class="list-group list-group-flush mb-0 small">
                <li class="list-group-item px-0"><i class="fa fa-check text-success me-2"></i> NIP (Nomor Induk Pegawai)</li>
                <li class="list-group-item px-0"><i class="fa fa-check text-success me-2"></i> Jabatan & Divisi</li>
                <li class="list-group-item px-0"><i class="fa fa-check text-success me-2"></i> Lokasi Kantor Presensi</li>
                <li class="list-group-item px-0"><i class="fa fa-check text-success me-2"></i> Status Kepegawaian</li>
            </ul>
          </div>
        </div>
      </div>
    
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>