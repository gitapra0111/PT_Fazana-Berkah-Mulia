<?php
// FILE: pegawai/home/profile.php
declare(strict_types=1);

// 1. Session Management
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

// 2. Cek Akses Pegawai
if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses");
    exit;
}

require_once __DIR__ . '/../../config.php';

// 3. Koneksi Database
$mysqli = null;
if (isset($connection) && $connection instanceof mysqli)        $mysqli = $connection;
elseif (isset($conn) && $conn instanceof mysqli)                $mysqli = $conn;
elseif (isset($koneksi) && $koneksi instanceof mysqli)          $mysqli = $koneksi;

if (!$mysqli) {
    die("Koneksi database tidak ditemukan.");
}

// 4. Ambil Data Pegawai
$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
if ($idPegawai <= 0) {
    die("ID pegawai tidak ditemukan di session.");
}

// Query join untuk ambil data pegawai + data user (username/role)
$sql = "
    SELECT p.*, u.username, u.status, u.role 
    FROM pegawai AS p
    LEFT JOIN users AS u ON u.id_pegawai = p.id
    WHERE p.id = ?
    LIMIT 1
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $idPegawai);
$stmt->execute();
$pegawai = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pegawai) {
    die("Data pegawai tidak ditemukan.");
}

// 5. Logika Foto Profil (Anti 404 & Selaras Admin)
$namaTampil = $pegawai['nama'] ?? 'Pegawai';
$fotoName   = $pegawai['foto'] ?? '';
$fotoDirAbs = __DIR__ . '/../../assets/images/foto_pegawai';

// Cek apakah file foto benar-benar ada di server?
if (!empty($fotoName) && is_file($fotoDirAbs . DIRECTORY_SEPARATOR . $fotoName)) {
    // Jika ada, pakai foto asli
    $img_src = base_url('assets/images/foto_pegawai/' . $fotoName);
} else {
    // Jika tidak ada / error, pakai UI Avatars (Inisial Nama)
    // Warna background biru (0D6EFD), teks putih
    $img_src = "https://ui-avatars.com/api/?name=" . urlencode($namaTampil) . "&background=0D6EFD&color=fff&size=128";
}

$judul = "Profil Pegawai";
require_once __DIR__ . '/../layout/header.php';
?>

<div class="my-3 my-md-5">
  <div class="container">
    
    <div class="row">
      <div class="col-lg-4 mb-4">
        <div class="card shadow-sm">
          <div class="card-body text-center">
            <div class="mb-3 d-inline-block p-1 border rounded-circle bg-white shadow-sm">
                <img class="rounded-circle" 
                     src="<?= $img_src; ?>" 
                     alt="Foto Pegawai" 
                     width="120" height="120" 
                     style="object-fit:cover;">
            </div>
            
            <h3 class="mb-0 fw-bold"><?= htmlspecialchars($namaTampil); ?></h3>
            <p class="text-muted small mb-2"><?= htmlspecialchars($pegawai['jabatan'] ?? '-'); ?></p>
            
            <div class="text-muted small mb-4">
                <i class="fa fa-user-circle me-1"></i> <?= htmlspecialchars($pegawai['username'] ?? '-'); ?>
            </div>

            <div class="d-grid">
                <a href="<?= base_url('pegawai/home/settings.php'); ?>" class="btn btn-primary btn-sm">
                  <i class="fa fa-edit me-2"></i> Edit Profil
                </a>
            </div>
          </div>
          
          <div class="card-footer bg-light py-2">
            <div class="d-flex justify-content-between small">
                <span class="text-muted">Status Akun:</span>
                <?php if (($pegawai['status'] ?? '') === 'Aktif'): ?>
                    <span class="badge bg-success">Aktif</span>
                <?php else: ?>
                    <span class="badge bg-danger">Non-Aktif</span>
                <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-header bg-white">
            <h3 class="card-title fw-bold"><i class="fa fa-id-card-o me-2"></i> Informasi Pribadi</h3>
          </div>
          <div class="table-responsive">
            <table class="table card-table table-vcenter table-striped">
              <tr>
                <td class="text-muted w-25"><i class="fa fa-hashtag me-2"></i> NIP</td>
                <td class="fw-bold"><?= htmlspecialchars($pegawai['nip'] ?? '-'); ?></td>
              </tr>
              <tr>
                <td class="text-muted"><i class="fa fa-user me-2"></i> Nama Lengkap</td>
                <td><?= htmlspecialchars($namaTampil); ?></td>
              </tr>
              <tr>
                <td class="text-muted"><i class="fa fa-venus-mars me-2"></i> Jenis Kelamin</td>
                <td><?= htmlspecialchars($pegawai['jenis_kelamin'] ?? '-'); ?></td>
              </tr>
              <tr>
                <td class="text-muted"><i class="fa fa-phone me-2"></i> No. Handphone</td>
                <td><?= htmlspecialchars($pegawai['no_handphone'] ?? '-'); ?></td>
              </tr>
              <tr>
                <td class="text-muted"><i class="fa fa-map-marker me-2"></i> Lokasi Kantor</td>
                <td>
                    <span class="text-danger"><i class="fa fa-map-pin me-1"></i></span>
                    <?= htmlspecialchars($pegawai['lokasi_presensi'] ?? '-'); ?>
                </td>
              </tr>
              <tr>
                <td class="text-muted"><i class="fa fa-home me-2"></i> Alamat</td>
                <td class="text-wrap"><?= htmlspecialchars($pegawai['alamat'] ?? '-'); ?></td>
              </tr>
            </table>
          </div>
        </div>

        <div class="alert alert-info mt-3 shadow-sm border-0">
          <i class="fa fa-info-circle me-2"></i>
          Jika terdapat kesalahan data NIP, Jabatan, atau Lokasi, silakan hubungi <strong>Admin / HRD</strong>.
          Anda hanya dapat mengubah Foto, Password, dan Username melalui menu Settings.
        </div>
      </div>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../layout/footer.php';
?>


<!-- check ulang semua lagi nanti -->