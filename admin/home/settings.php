<?php
declare(strict_types=1);

/* ================= CONFIG ================= */
require_once __DIR__ . '/../../config.php';

/* ================= SESSION ADMIN ================= */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_name(SESS_ADMIN);
session_start();

if (
    empty($_SESSION['user']['login']) ||
    $_SESSION['user']['login'] !== true ||
    ($_SESSION['user']['role'] ?? '') !== 'admin'
) {
    header("Location: ../../auth/login.php?pesan=tolak_akses");
    exit;
}

/* ================= KONEKSI ================= */
$mysqli = $conn ?? null;
if (!$mysqli instanceof mysqli) {
    die('Koneksi database tidak ditemukan');
}

/* ================= AMBIL USER DARI SESSION ================= */
$idUser = (int)($_SESSION['user']['id'] ?? 0);
if ($idUser <= 0) {
    die('Session user tidak valid');
}

$stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $idUser);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die('Data admin tidak ditemukan');
}

/* ================= AMBIL DATA PEGAWAI (OPSIONAL) ================= */
$pegawai = null;
if (!empty($user['id_pegawai'])) {
    $stmt = $mysqli->prepare("SELECT * FROM pegawai WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user['id_pegawai']);
    $stmt->execute();
    $pegawai = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pesanError = '';

/* ================= PROSES UPDATE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username    = trim($_POST['username'] ?? '');
    $oldPassword = trim($_POST['old_password'] ?? '');
    $newPassword = trim($_POST['password'] ?? '');
    $alamat      = trim($_POST['alamat'] ?? '');
    $no_hp       = trim($_POST['no_handphone'] ?? '');
    $fotoBaru    = $_FILES['foto'] ?? null;

    /* ---- UPDATE USERNAME ---- */
    if ($username === '') {
        $pesanError .= "Username tidak boleh kosong.<br>";
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->bind_param('si', $username, $idUser);
        $stmt->execute();
        $stmt->close();
        $_SESSION['user']['username'] = $username;
    }

    /* ---- UPDATE PEGAWAI ---- */
    if ($pegawai) {
        $stmt = $mysqli->prepare(
            "UPDATE pegawai SET alamat = ?, no_handphone = ? WHERE id = ?"
        );
        $stmt->bind_param('ssi', $alamat, $no_hp, $pegawai['id']);
        $stmt->execute();
        $stmt->close();
    }

    /* ---- UPDATE PASSWORD ---- */
    if ($newPassword !== '') {
        if (!password_verify($oldPassword, $user['password'])) {
            $pesanError .= "Password lama tidak sesuai.<br>";
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $idUser);
            $stmt->execute();
            $stmt->close();
        }
    }

    /* ---- UPLOAD FOTO (DIPERBAIKI & DISINKRONKAN) ---- */
    $upload_sukses = false;
    // Gunakan $pegawai['foto'] karena data awal disimpan di sana
    $foto_final = $pegawai['foto'] ?? ''; 

    if ($fotoBaru && $fotoBaru['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($fotoBaru['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowed)) {
            $namaFileBaru = 'admin_' . $idUser . '_' . time() . '.' . $ext;
            $dir = __DIR__ . '/../../assets/images/foto_pegawai/';

            if (!is_dir($dir)) mkdir($dir, 0755, true);

            if (move_uploaded_file($fotoBaru['tmp_name'], $dir . $namaFileBaru)) {
                $upload_sukses = true;
                $foto_final = $namaFileBaru;

                // 2. Update Database Pegawai (Gunakan id_pegawai dari tabel user)
                if (!empty($user['id_pegawai'])) {
                    $stmtF = $mysqli->prepare("UPDATE pegawai SET foto = ? WHERE id = ?");
                    $stmtF->bind_param('si', $foto_final, $user['id_pegawai']);
                    $stmtF->execute();
                    $stmtF->close();
                }

                // 3. Hapus Foto Lama (Pembersihan sampah)
                // Pastikan merujuk ke foto lama yang ada di database awal
                if (!empty($pegawai['foto'])) {
                    $path_lama = $dir . $pegawai['foto'];
                    if (file_exists($path_lama)) {
                        unlink($path_lama); 
                    }
                }

                // 4. Update Session
                $_SESSION['user']['foto'] = $foto_final;
            }
        }
    }

    if ($pesanError === '') {
        header("Location: " . base_url('admin/home/settings.php?updated=1'));
        exit;
    }
}

/* ================= VIEW ================= */
$judul = "Settings Admin";
require_once __DIR__ . '/../layout/header.php';
?>

<!-- HTML FORM TETAP SEPERTI MILIKMU -->
<div class="my-3 my-md-5">
  <div class="container">
    <div class="page-header mb-4">
      <h1 class="page-title fw-bold">
        <i class="fe fe-settings me-2"></i>Pengaturan Akun Admin
      </h1>
    </div>

    <div class="row row-cards">
      <div class="col-lg-8">
        <form method="post" enctype="multipart/form-data" class="card shadow-sm">
          <div class="card-header bg-primary text-white">
            <h3 class="card-title">Formulir Pembaruan Profil</h3>
          </div>
          
          <div class="card-body">
            <div class="row">
              <div class="col-md-12 mb-3">
                <label class="form-label fw-bold text-uppercase small text-muted">Informasi Login</label>
                <hr class="mt-1 mb-3">
              </div>

              <div class="col-md-12 mb-3">
                <label class="form-label">Username</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fe fe-user"></i></span>
                  <input type="text" name="username" class="form-control"
                         value="<?= htmlspecialchars($user['username'] ?? ''); ?>" required>
                </div>
              </div>

              <div class="col-md-6 mb-3">
                <label class="form-label">Password Lama</label>
                <input type="password" name="old_password" class="form-control" placeholder="Masukkan password saat ini">
              </div>

              <div class="col-md-6 mb-4">
                <label class="form-label">Password Baru</label>
                <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak diubah">
              </div>

              <div class="col-md-12 mb-3 mt-2">
                <label class="form-label fw-bold text-uppercase small text-muted">Informasi Pribadi</label>
                <hr class="mt-1 mb-3">
              </div>

              <div class="col-md-12 mb-3">
                <label class="form-label">Alamat Lengkap</label>
                <textarea name="alamat" class="form-control" rows="3" placeholder="Alamat sesuai KTP"><?= htmlspecialchars($pegawai['alamat'] ?? ''); ?></textarea>
              </div>

              <div class="col-md-12 mb-4">
                <label class="form-label">No. Handphone</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fe fe-phone"></i></span>
                  <input type="text" name="no_handphone" class="form-control"
                         value="<?= htmlspecialchars($pegawai['no_handphone'] ?? ''); ?>" placeholder="0812xxxx">
                </div>
              </div>

              <div class="col-md-12 mb-3">
                <label class="form-label">Foto Profil</label>
                <div class="d-flex align-items-center gap-3 p-3 border rounded bg-light">
                    <div class="avatar-preview">
                        <?php if (!empty($pegawai['foto'])): ?>
                            <img src="<?= base_url('assets/images/foto_pegawai/' . $pegawai['foto']); ?>?t=<?= time(); ?>" 
                                 class="rounded-circle border border-3 border-white shadow-sm" 
                                 width="80" height="80" style="object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center text-white shadow-sm" 
                                 style="width:80px; height:80px;">No Pic</div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <input type="file" name="foto" class="form-control">
                        <small class="text-muted d-block mt-1">Format: JPG, PNG, WEBP (Maks. 2MB)</small>
                    </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card-footer bg-light text-end">
            <button type="submit" class="btn btn-primary px-5 fw-bold">
               <i class="fe fe-save me-2"></i>Simpan Perubahan
            </button>
          </div>
        </form>
      </div>

      <div class="col-lg-4">
        <div class="card shadow-sm border-0">
          <div class="card-status-top bg-info"></div>
          <div class="card-header">
            <h3 class="card-title text-info"><i class="fe fe-info me-2"></i>Panduan Pengaturan</h3>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <span class="badge bg-blue-lt mb-2">Akun</span>
              <p class="text-muted small">Username digunakan untuk masuk ke dashboard. Pastikan unik dan mudah diingat.</p>
            </div>
            <div class="mb-3">
              <span class="badge bg-yellow-lt mb-2">Keamanan</span>
              <p class="text-muted small">Jika ingin mengganti password, Anda <strong>wajib</strong> memasukkan password lama demi alasan keamanan.</p>
            </div>
            <div class="mb-0">
              <span class="badge bg-green-lt mb-2">Profil</span>
              <p class="text-muted small">Foto profil akan tampil pada bilah navigasi atas dan laporan administrasi.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Notifikasi Sukses
    <?php if (isset($_GET['updated'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Berhasil!',
        text: 'Data profil Anda telah diperbarui.',
        timer: 2500,
        showConfirmButton: false
    });
    <?php endif; ?>

    // Notifikasi Error
    <?php if (!empty($pesanError)): ?>
    Swal.fire({
        icon: 'error',
        title: 'Gagal Update',
        html: '<?= trim($pesanError); ?>',
        confirmButtonColor: '#d33'
    });
    <?php endif; ?>
</script>

<?php
require_once __DIR__ . '/../layout/footer.php';

