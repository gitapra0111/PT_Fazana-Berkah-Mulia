<?php
// FILE: pegawai/ketidakhadiran/pengajuan_ketidakhadiran.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); exit;
}

require_once __DIR__ . '/../../config.php';

// mapping koneksi
if (!isset($connection) || !($connection instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $connection = $conn;
    } elseif (isset($koneksi) && $koneksi instanceof mysqli) {
        $connection = $koneksi;
    } else {
        die("Koneksi database tidak ditemukan.");
    }
}

$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
$pesanSukses = '';
$pesanError  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_ketidakhadiran'])) {
    $keterangan = $_POST['keterangan'] ?? '';
    $tanggal    = $_POST['tanggal'] ?? '';
    $deskripsi  = $_POST['deskripsi'] ?? '';
    $status_pengajuan = 'PENDING';
    $namaFileSimpan = null;

    if (!$keterangan || !$tanggal) {
        $pesanError = "Jenis ketidakhadiran dan tanggal wajib diisi.";
    } else {
        // upload (opsional)
        if (!empty($_FILES['file']['name'])) {
            $uploadDir = __DIR__ . '/../../assets/uploads/ketidakhadiran/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }

            $namaAsli = $_FILES['file']['name'];
            $tmpName  = $_FILES['file']['tmp_name'];
            $ext      = strtolower(pathinfo($namaAsli, PATHINFO_EXTENSION));
            $allowed  = ['pdf','jpg','jpeg','png'];

            if (!in_array($ext, $allowed, true)) {
                $pesanError = "File harus pdf/jpg/jpeg/png.";
            } else {
                $namaFileSimpan = 'ketidakhadiran_' . $idPegawai . '_' . time() . '.' . $ext;
                $targetPath = $uploadDir . $namaFileSimpan;
                if (!move_uploaded_file($tmpName, $targetPath)) {
                    $pesanError = "Gagal upload file.";
                }
            }
        }

        if ($pesanError === '') {
            $stmt = $connection->prepare(
                "INSERT INTO ketidakhadiran (id_pegawai, keterangan, tanggal, deskripsi, file, status_pengajuan)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "isssss",
                $idPegawai,
                $keterangan,
                $tanggal,
                $deskripsi,
                $namaFileSimpan,
                $status_pengajuan
            );

            if ($stmt->execute()) {
                // balik ke daftar
                header("Location: ketidakhadiran.php?status=ok");
                exit;
            } else {
                $pesanError = "Gagal menyimpan data: " . $connection->error;
            }
            $stmt->close();
        }
    }
}

$judul = "Ajukan Ketidakhadiran";
require_once __DIR__ . '/../layout/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Ajukan Ketidakhadiran</h3>
                        <a href="ketidakhadiran.php" class="btn btn-sm btn-secondary">Kembali</a>
                    </div>
                    <div class="card-body">
                        <?php if ($pesanError): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($pesanError) ?></div>
                        <?php endif; ?>

                        <form action="" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">Jenis Ketidakhadiran</label>
                                <select name="keterangan" class="form-select" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="Cuti">Cuti</option>
                                    <option value="Izin">Izin</option>
                                    <option value="Sakit">Sakit</option>
                                    <option value="Dinas Luar">Dinas Luar</option>
                                </select>
                                <div class="invalid-feedback">Harap pilih salah satu.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" name="tanggal" class="form-control" required>
                                <div class="invalid-feedback">Tanggal wajib diisi.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Deskripsi / Alasan</label>
                                <textarea name="deskripsi" class="form-control" rows="3" placeholder="contoh: Demam, tugas luar kota ..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Lampiran (opsional)</label>
                                <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="form-hint">pdf/jpg/png</small>
                            </div>
                            <div class="form-footer">
                                <button type="submit" name="simpan_ketidakhadiran" class="btn btn-primary w-100">
                                    Kirim Pengajuan
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer small text-muted">
                        Pengajuan akan berstatus <strong>PENDING</strong> sampai disetujui admin.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>

<script>
(() => {
    'use strict'
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})()
</script>
