<?php 
// pastikan tidak ada output sebelum redirect
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// guard lokal (cek login admin) - lakukan sebelum proses POST dan sebelum include header
if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses');
    exit;
}

$judul = "Tambah Data Jabatan";

require_once '../../config.php'; // koneksi database

// ---------------------------
// PROSES POST / VALIDASI
// ---------------------------
$suksesMsg = '';
$errorMsg  = '';

if (isset($_POST['submit'])) {
    $jabatan = trim($_POST['jabatan'] ?? '');

    if ($jabatan === '') {
        $errorMsg = "Nama jabatan wajib diisi.";
    } else {
        // 1. CEK DUPLIKASI (Logic Tambahan)
        // Cek apakah jabatan sudah ada sebelumnya (Case insensitive)
        $checkStmt = $conn->prepare("SELECT id FROM jabatan WHERE jabatan = ? LIMIT 1");
        $checkStmt->bind_param("s", $jabatan);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            $errorMsg = "Jabatan <strong>" . htmlspecialchars($jabatan) . "</strong> sudah ada di database.";
        } else {
            // 2. INSERT DENGAN PREPARED STATEMENT (Lebih Aman)
            $stmt = $conn->prepare("INSERT INTO jabatan (jabatan) VALUES (?)");
            $stmt->bind_param("s", $jabatan);

            if ($stmt->execute()) {
                $suksesMsg = "Jabatan <strong>" . htmlspecialchars($jabatan) . "</strong> berhasil ditambahkan.";
                
                // Set session flash agar di halaman list nanti muncul notif juga (opsional)
                $_SESSION['berhasil'] = "Data jabatan baru berhasil disimpan."; 
                
                // Kosongkan POST agar form bersih
                $_POST = [];
            } else {
                $errorMsg = "Gagal menyimpan data: " . $stmt->error;
            }
            $stmt->close();
        }
        $checkStmt->close();
    }
}

// sekarang aman untuk menampilkan header / output
include('../layout/header.php');
?>

<!-- Konten Halaman -->
<div class="page-body">
    <div class="container-xl">
        <div class="card col-md-6">
            <div class="card-header"><strong><?= htmlspecialchars($judul); ?></strong></div>
            <div class="card-body">

                <!-- Form Tambah -->
                <form action="" method="post">
                    <div class="mb-3">
                        <label for="jabatan" class="form-label">Nama Jabatan</label>
                        <input type="text" class="form-control" id="jabatan" name="jabatan" required value="<?= htmlspecialchars($_POST['jabatan'] ?? '') ?>">
                    </div>
                    <button type="submit" name="submit" class="btn btn-primary">Simpan</button>
                    <a href="<?= htmlspecialchars(base_url('admin/data_jabatan/jabatan.php')); ?>" class="btn btn-secondary">Batal</a>
                </form>

            </div>
        </div>
    </div>
</div>

<!-- SweetAlert handling -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
<?php if ($suksesMsg !== ''): ?>
  Swal.fire({
    icon: 'success',
    title: 'Berhasil',
    html: <?= json_encode($suksesMsg) ?>,
    showCancelButton: false,
    confirmButtonText: 'Lihat Daftar',
    allowOutsideClick: false,
    timer: 3000,
    timerProgressBar: true
  }).then(function() {
    window.location.href = <?= json_encode(base_url('admin/data_jabatan/jabatan.php')) ?>;
  });
<?php endif; ?>

<?php if ($errorMsg !== ''): ?>
  Swal.fire({
    icon: 'error',
    title: 'Gagal',
    html: <?= json_encode($errorMsg) ?>,
    confirmButtonText: 'OK'
  });
<?php endif; ?>
</script>

<?php include('../layout/footer.php'); ?>
