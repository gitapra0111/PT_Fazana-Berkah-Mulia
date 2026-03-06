<?php
declare(strict_types=1);

// ===== 1) BOOTSTRAP & GUARD ADMIN (belum ada output sama sekali) =====
require_once __DIR__ . '/../../config.php';

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ' . base_url('auth/login.php?pesan=tolak_akses')); exit;
}

// Ambil id, validasi
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: ' . base_url('admin/data_lokasi_presensi/lokasi_presensi.php?pesan=not_found')); exit;
}

// ===== 2) HANDLE POST (Proses dulu, lalu redirect) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    // Ambil & normalisasi input
    $nama_lokasi   = trim((string)($_POST['nama_lokasi']   ?? ''));
    $alamat_lokasi = trim((string)($_POST['alamat_lokasi'] ?? ''));
    $tipe_lokasi   = trim((string)($_POST['tipe_lokasi']   ?? ''));
    $latitude      = trim((string)($_POST['latitude']      ?? ''));
    $longitude     = trim((string)($_POST['longitude']     ?? ''));
    $radius        = (int)($_POST['radius']                ?? 0);
    $zona_waktu    = trim((string)($_POST['zona_waktu']    ?? ''));
    $jam_masuk     = trim((string)($_POST['jam_masuk']     ?? ''));
    $jam_pulang    = trim((string)($_POST['jam_pulang']    ?? ''));

    // Prepared UPDATE agar aman
    $sql = "UPDATE lokasi_presensi SET
              nama_lokasi   = ?,
              alamat_lokasi = ?,
              tipe_lokasi   = ?,
              latitude      = ?,
              longitude     = ?,
              radius        = ?,
              zona_waktu    = ?,
              jam_masuk     = ?,
              jam_pulang    = ?
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    // sssss i sss i  -> total 10 parameter
    $stmt->bind_param(
        'sssssisssi',
        $nama_lokasi, $alamat_lokasi, $tipe_lokasi,
        $latitude, $longitude, $radius,
        $zona_waktu, $jam_masuk, $jam_pulang,
        $id
    );

    if ($stmt->execute()) {
        // Simpan flash di sesi admin
        $_SESSION['berhasil'] = 'Data berhasil diperbarui';
        // REDIRECT ke listing (belum ada output, jadi aman)
        header('Location: ' . base_url('admin/data_lokasi_presensi/lokasi_presensi.php?pesan=edit_sukses')); exit;
    } else {
        // Boleh arahkan balik dengan pesan gagal
        $_SESSION['gagal'] = 'Update gagal: ' . $stmt->error;
        header('Location: ' . base_url('admin/data_lokasi_presensi/edit.php?id=' . $id)); exit;
    }
}

// ===== 3) GET DATA (untuk tampilan form) =====
$stmt = $conn->prepare("SELECT * FROM lokasi_presensi WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$res    = $stmt->get_result();
$lokasi = $res->fetch_assoc();
if (!$lokasi) {
    header('Location: ' . base_url('admin/data_lokasi_presensi/lokasi_presensi.php?pesan=not_found')); exit;
}

// ===== 4) BARU MULAI OUTPUT HTML =====
$judul = "Edit Lokasi Presensi";
include __DIR__ . '/../layout/header.php';
?>
<div class="page-body">
  <div class="container-xl">
    <div class="row justify-content-center mt-4">
      <div class="col-md-8">
        <div class="card shadow border-0">
          <div class="card-header bg-warning text-dark fw-bold">
            <i class="fas fa-map-marker-alt me-1"></i> Edit Lokasi Presensi
          </div>
          <div class="card-body">
            <form method="POST">
              <div class="mb-3">
                <label class="form-label">Nama Lokasi</label>
                <input type="text" class="form-control" name="nama_lokasi" value="<?= h($lokasi['nama_lokasi']) ?>" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Alamat Lokasi</label>
                <input type="text" class="form-control" name="alamat_lokasi" value="<?= h($lokasi['alamat_lokasi']) ?>" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Tipe Lokasi</label>
                <select class="form-select" name="tipe_lokasi" required>
                  <option value="pusat"  <?= $lokasi['tipe_lokasi']==='pusat'  ? 'selected' : '' ?>>Pusat</option>
                  <option value="cabang" <?= $lokasi['tipe_lokasi']==='cabang' ? 'selected' : '' ?>>Cabang</option>
                </select>
              </div>

              <div class="row mb-3">
                <div class="col-md-4">
                  <label class="form-label">Latitude</label>
                  <input type="text" class="form-control" name="latitude" value="<?= h($lokasi['latitude']) ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Longitude</label>
                  <input type="text" class="form-control" name="longitude" value="<?= h($lokasi['longitude']) ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Radius (m)</label>
                  <input type="number" class="form-control" name="radius" value="<?= h($lokasi['radius']) ?>" required>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">Zona Waktu</label>
                <select class="form-select" name="zona_waktu" required>
                  <option value="WIB"  <?= $lokasi['zona_waktu']==='WIB'  ? 'selected' : '' ?>>WIB</option>
                  <option value="WITA" <?= $lokasi['zona_waktu']==='WITA' ? 'selected' : '' ?>>WITA</option>
                  <option value="WIT"  <?= $lokasi['zona_waktu']==='WIT'  ? 'selected' : '' ?>>WIT</option>
                </select>
              </div>

              <div class="row mb-3">
                <div class="col">
                  <label class="form-label">Jam Masuk</label>
                  <input type="time" class="form-control" name="jam_masuk" value="<?= h($lokasi['jam_masuk']) ?>" required>
                </div>
                <div class="col">
                  <label class="form-label">Jam Pulang</label>
                  <input type="time" class="form-control" name="jam_pulang" value="<?= h($lokasi['jam_pulang']) ?>" required>
                </div>
              </div>

              <div class="d-flex justify-content-between">
                <a href="<?= base_url('admin/data_lokasi_presensi/lokasi_presensi.php') ?>" class="btn btn-outline-secondary">
                  <i class="fas fa-arrow-left me-1"></i> Kembali
                </a>
                <button type="submit" name="submit" class="btn btn-warning text-white fw-bold">
                  <i class="fas fa-save me-1"></i> Simpan Perubahan
                </button>
              </div>
            </form>
          </div>
        </div>

        <?php if (!empty($_SESSION['berhasil'])): ?>
          <script>
            Swal.fire({ icon: 'success', title: 'Berhasil!', text: <?= json_encode($_SESSION['berhasil']) ?>, timer: 2000, showConfirmButton: false });
          </script>
          <?php unset($_SESSION['berhasil']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['gagal'])): ?>
          <script>
            Swal.fire({ icon: 'error', title: 'Gagal', text: <?= json_encode($_SESSION['gagal']) ?> });
          </script>
          <?php unset($_SESSION['gagal']); ?>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
