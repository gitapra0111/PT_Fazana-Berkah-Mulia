<?php
declare(strict_types=1);

// 1. Session Management
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// 2. Admin Access Check
if (!isset($_SESSION['user']['login']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../../auth/login.php?pesan=tolak_akses");
    exit;
}

require_once __DIR__ . '/../../config.php';

// 3. Database Connection Mapping
$mysqli = $connection ?? $conn ?? $koneksi ?? null;
if (!$mysqli) die("Koneksi database tidak ditemukan.");

// 4. Data Retrieval Logic
$sessionId    = (int)($_SESSION['user']['id'] ?? 0);
$sessionUname = $_SESSION['user']['username'] ?? 'admin';

// Ambil data User & Pegawai sekaligus via JOIN
$sql = "SELECT u.username, u.status, u.role, p.* FROM users u 
        LEFT JOIN pegawai p ON u.id_pegawai = p.id 
        WHERE u.id = ? LIMIT 1";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $sessionId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?? [];
$stmt->close();

// 5. Data Tampilan (Fallback aman agar tidak kosong)
$namaTampil    = $user['nama'] ?? 'Administrator';
$jabatanTampil = $user['jabatan'] ?? 'Super Admin';
$nipTampil     = $user['nip'] ?? '-';
$fotoPegawai   = $user['foto'] ?? '';

// 6. Logika Foto Profil (Anti 404)
$fotoDirAbs = __DIR__ . '/../../assets/images/foto_pegawai';
if (!empty($fotoPegawai) && is_file($fotoDirAbs . DIRECTORY_SEPARATOR . $fotoPegawai)) {
    $img_src = base_url('assets/images/foto_pegawai/' . $fotoPegawai);
} else {
    // Inisial otomatis jika foto tidak ada
    $img_src = "https://ui-avatars.com/api/?name=" . urlencode($namaTampil) . "&background=0D6EFD&color=fff&size=128";
}

$judul = "Profil Admin";
require_once __DIR__ . '/../layout/header.php';
?>

<div class="my-3 my-md-5">
    <div class="container">
        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <div class="mb-3 d-inline-block p-1 border rounded-circle bg-white shadow-sm">
                            <img src="<?= $img_src; ?>" class="rounded-circle" width="120" height="120" style="object-fit:cover;">
                        </div>
                        <h3 class="mb-0 fw-bold"><?= htmlspecialchars($namaTampil); ?></h3>
                        <p class="text-muted small mb-3"><?= htmlspecialchars($jabatanTampil); ?></p>
                        
                        <div class="d-grid">
                            <a href="<?= base_url('admin/home/settings.php'); ?>" class="btn btn-primary btn-sm">
                                <i class="fa fa-edit me-2"></i> Edit Profil
                            </a>
                        </div>
                    </div>
                    <div class="card-footer bg-light py-2">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Status Akun:</span>
                            <span class="badge bg-success"><?= htmlspecialchars($user['status'] ?? 'Aktif'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h3 class="card-title fw-bold">Informasi Akun</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter table-striped">
                            <tr>
                                <td class="text-muted w-25">Username</td>
                                <td class="fw-bold"><?= htmlspecialchars($user['username'] ?? 'admin'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">NIP</td>
                                <td><?= htmlspecialchars($nipTampil); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Nama Lengkap</td>
                                <td><?= htmlspecialchars($namaTampil); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Jenis Kelamin</td>
                                <td><?= htmlspecialchars($user['jenis_kelamin'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">No. Handphone</td>
                                <td><?= htmlspecialchars($user['no_handphone'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Lokasi Kantor</td>
                                <td><i class="fa fa-map-marker text-danger me-1"></i> <?= htmlspecialchars($user['lokasi_presensi'] ?? 'Kantor Pusat'); ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Alamat</td>
                                <td class="text-wrap"><?= htmlspecialchars($user['alamat'] ?? '-'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="alert alert-info mt-3 shadow-sm border-0">
                    <i class="fa fa-info-circle me-2"></i>
                    Gunakan menu <strong>Settings</strong> untuk memperbarui kredensial login atau mengganti foto profil Anda.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>