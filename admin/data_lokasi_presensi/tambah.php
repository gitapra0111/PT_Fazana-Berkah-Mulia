<?php
// --- SESSION & AUTH ---
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

require_once '../../config.php'; // koneksi + base_url()

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ' . base_url('auth/login.php?pesan=tolak_akses')); exit;
}

// --- HANDLE POST (TANPA OUTPUT SATUPUN DI ATAS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ambil & sanitasi
    $nama_lokasi   = htmlspecialchars($_POST['nama_lokasi']   ?? '');
    $alamat_lokasi = htmlspecialchars($_POST['alamat_lokasi'] ?? '');
    $tipe_lokasi   = htmlspecialchars($_POST['tipe_lokasi']   ?? '');
    $latitude      = htmlspecialchars($_POST['latitude']      ?? '');
    $longitude     = htmlspecialchars($_POST['longitude']     ?? '');
    $radius        = htmlspecialchars($_POST['radius']        ?? '');
    $zona_waktu    = htmlspecialchars($_POST['zona_waktu']    ?? '');
    $jam_masuk     = htmlspecialchars($_POST['jam_masuk']     ?? '');
    $jam_pulang    = htmlspecialchars($_POST['jam_pulang']    ?? '');

    $sql = "INSERT INTO lokasi_presensi 
            (nama_lokasi, alamat_lokasi, tipe_lokasi, latitude, longitude, radius, zona_waktu, jam_masuk, jam_pulang) 
            VALUES 
            ('$nama_lokasi', '$alamat_lokasi', '$tipe_lokasi', '$latitude', '$longitude', '$radius', '$zona_waktu', '$jam_masuk', '$jam_pulang')";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        // opsional: bawa pesan error via session, hindari echo/die yang mengeluarkan output
        $_SESSION['gagal'] = 'Query Error: ' . mysqli_error($conn);
        header('Location: ' . base_url('admin/data_lokasi_presensi/tambah.php?pesan=gagal'));
        exit;
    }

    $_SESSION['berhasil'] = 'Data berhasil disimpan';
    header('Location: ' . base_url('admin/data_lokasi_presensi/lokasi_presensi.php?pesan=sukses_tambah'));
    exit;
}

// --- HANYA SAMPAI SINI KITA BOLEH OUTPUT (include header) ---
$judul = "Tambah Lokasi Presensi";
include('../layout/header.php');
?>

<div class="page-body">
  <div class="container-xl">
    <div class="row justify-content-center mt-4">
      <div class="col-md-8">
        <div class="card shadow-sm border-0">
          <div class="card-header bg-primary text-white">
            <h5 class="mb-0">🗺️ Tambah Lokasi Presensi</h5>
          </div>
          <div class="card-body">
            <form action="<?= base_url('admin/data_lokasi_presensi/tambah.php') ?>" method="POST">
              <div class="row mb-3">
                <div class="col">
                  <label>Nama Lokasi</label>
                  <input type="text" class="form-control" name="nama_lokasi" required>
                </div>
                <div class="col">
                  <label>Alamat Lokasi</label>
                  <input type="text" class="form-control" name="alamat_lokasi" required>
                </div>
              </div>

              <div class="mb-3">
                <label>Tipe Lokasi</label>
                <select name="tipe_lokasi" class="form-control" required>
                  <option value="">--- Pilih Tipe Lokasi ---</option>
                  <option value="pusat">Pusat</option>
                  <option value="cabang">Cabang</option>
                </select>
              </div>

              <div class="row mb-3">
                <div class="col">
                  <label>Latitude</label>
                  <input type="text" class="form-control" name="latitude" required>
                </div>
                <div class="col">
                  <label>Longitude</label>
                  <input type="text" class="form-control" name="longitude" required>
                </div>
                <div class="col">
                  <label>Radius (meter)</label>
                  <input type="number" class="form-control" name="radius" required>
                </div>
              </div>

              <div class="mb-3">
                <label>Zona Waktu</label>
                <select name="zona_waktu" class="form-control" required>
                  <option value="">--- Pilih Zona Waktu ---</option>
                  <option value="WIB">WIB</option>
                  <option value="WITA">WITA</option>
                  <option value="WIT">WIT</option>
                </select>
              </div>

              <div class="row mb-3">
                <div class="col">
                  <label>Jam Masuk</label>
                  <input type="time" class="form-control" name="jam_masuk" required>
                </div>
                <div class="col">
                  <label>Jam Pulang</label>
                  <input type="time" class="form-control" name="jam_pulang" required>
                </div>
              </div>

              <div class="d-flex justify-content-end">
                <a href="<?= base_url('admin/data_lokasi_presensi/lokasi_presensi.php') ?>" class="btn btn-secondary mr-2">Kembali</a>
                <button class="btn btn-primary" type="submit" name="submit">💾 Simpan</button>
              </div>
            </form>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<?php include('../layout/footer.php'); ?>
