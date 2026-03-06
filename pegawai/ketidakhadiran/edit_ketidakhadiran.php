<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); exit;
}

require_once __DIR__ . '/../../config.php';

// koneksi
if (!isset($connection) || !($connection instanceof mysqli)) {
    if (isset($conn) && $conn instanceof mysqli) {
        $connection = $conn;
    } elseif (isset($koneksi) && $koneksi instanceof mysqli) {
        $connection = $koneksi;
    } else {
        $connection = new mysqli('localhost', 'root', '', 'presensi');
    }
}

$idPegawai = (int)($_SESSION['user']['id_pegawai'] ?? 0);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ambil data
$stmt = $connection->prepare("SELECT * FROM ketidakhadiran WHERE id = ? AND id_pegawai = ? LIMIT 1");
$stmt->bind_param("ii", $id, $idPegawai);
$stmt->execute();
$res  = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    die("Data tidak ditemukan / bukan milik Anda.");
}

if ($data['status_pengajuan'] !== 'PENDING') {
    die("Data sudah diproses admin dan tidak boleh diedit.");
}

$pesanError = "";

// proses simpan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan'])) {
    $keterangan = $_POST['keterangan'] ?? '';
    $tanggal    = $_POST['tanggal'] ?? '';
    $deskripsi  = $_POST['deskripsi'] ?? '';
    $namaFile   = $data['file']; // default: file lama

    if ($keterangan === '' || $tanggal === '') {
        $pesanError = "Jenis dan tanggal wajib diisi.";
    } else {
        // cek upload baru
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
                $namaBaru = 'ketidakhadiran_' . $idPegawai . '_' . time() . '.' . $ext;
                if (move_uploaded_file($tmpName, $uploadDir . $namaBaru)) {
                    // hapus file lama (opsional)
                    if (!empty($namaFile) && file_exists($uploadDir . $namaFile)) {
                        @unlink($uploadDir . $namaFile);
                    }
                    $namaFile = $namaBaru;
                } else {
                    $pesanError = "Gagal upload file.";
                }
            }
        }

        if ($pesanError === '') {
            $stmt = $connection->prepare("
                UPDATE ketidakhadiran
                SET keterangan = ?, tanggal = ?, deskripsi = ?, file = ?
                WHERE id = ? AND id_pegawai = ? AND status_pengajuan = 'PENDING'
            ");
            $stmt->bind_param("ssssii", $keterangan, $tanggal, $deskripsi, $namaFile, $id, $idPegawai);
            if ($stmt->execute()) {
                header("Location: ketidakhadiran.php?msg=updated");
                exit;
            } else {
                $pesanError = "Gagal menyimpan perubahan: " . $connection->error;
            }
            $stmt->close();
        }
    }
}

$judul = "Edit Ketidakhadiran";
require_once __DIR__ . '/../layout/header.php';
?>
<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Edit Ketidakhadiran</h3>
                        <a href="ketidakhadiran.php" class="btn btn-sm btn-secondary">Kembali</a>
                    </div>
                    <div class="card-body">
                        <?php if ($pesanError): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($pesanError) ?></div>
                        <?php endif; ?>

                        <form action="" method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Jenis Ketidakhadiran</label>
                                <select name="keterangan" class="form-select" required>
                                    <?php
                                    $opsi = ['Cuti','Izin','Sakit','Dinas Luar'];
                                    foreach ($opsi as $o):
                                    ?>
                                        <option value="<?= $o ?>" <?= $o === $data['keterangan'] ? 'selected' : '' ?>>
                                            <?= $o ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tanggal</label>
                                <input type="date" name="tanggal" value="<?= htmlspecialchars($data['tanggal']) ?>" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Deskripsi</label>
                                <textarea name="deskripsi" class="form-control" rows="3"><?= htmlspecialchars($data['deskripsi'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Lampiran (opsional)</label><br>
                                <?php if (!empty($data['file'])): ?>
                                    <small class="text-muted">File sekarang: <?= htmlspecialchars($data['file']) ?></small><br>
                                <?php endif; ?>
                                <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="form-footer">
                                <button type="submit" name="simpan" class="btn btn-primary w-100">Simpan Perubahan</button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer small text-muted">
                        Hanya bisa diedit selama status masih <b>PENDING</b>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
