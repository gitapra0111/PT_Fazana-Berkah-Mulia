<?php 
// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

$judul = "Data Lokasi Presensi";

require_once '../../config.php'; // koneksi database
include('../layout/header.php'); // pastikan base_url() didefinisikan di sini

// Ambil data jabatan dari DB
$result = mysqli_query($conn, "SELECT * FROM lokasi_presensi ORDER BY id DESC");

if (!$result) {
    die("Query Error: " . mysqli_error($conn));
}
?>

<style>
  /* Container utama agar scroll smooth */
  .table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  /* Standar sel tabel */
  .table th, .table td {
    white-space: nowrap; /* Mencegah teks turun ke bawah agar bisa di-scroll */
    vertical-align: middle;
    background-color: #fff;
  }

  /* LOGIKA STICKY KHUSUS KOLOM NAMA LOKASI (KOLOM KE-2) */
  @media (max-width: 992px) {
    /* Target kolom ke-2: Nama Lokasi */
    .table th:nth-child(2), 
    .table td:nth-child(2) {
      position: sticky;
      left: 0; /* Menempel di paling kiri */
      z-index: 10;
      background-color: #fff;
      border-right: 1px solid #dee2e6; /* Garis pemisah */
    }

    /* Header harus lebih tinggi z-index-nya */
    .table thead th:nth-child(2) {
      z-index: 11;
      background-color: #f8f9fa !important;
    }
  }
</style>

<div class="page-body">
    <div class="container-xl">
        <a href="<?= base_url('admin/data_lokasi_presensi/tambah.php')?>" class="btn btn-primary"><span class="text"><i class="fa-solid fa-circle-plus"></i> Tambah Data</span></a>
<div class="table-responsive">
    <table class="table table-bordered mt-3">
        <tr class="text-center">
            <th>No</th>
            <th>Nama Lokasi</th>
            <th>Tipe Lokasi</th>
            <th>Latitude/Longitude</th>
            <th>Radius</th>
            <th>Aksi</th>
        </tr>
        <?php if (mysqli_num_rows($result) == 0): ?>
        <tr>
            <td colspan="6" class="text-center">Tidak ada data lokasi presensi</td>
        </tr>
    <?php else: ?>
        <?php $no = 1; ?>
<?php while ($lokasi = mysqli_fetch_array($result)) : ?>
<tr>
    <td><?= $no++ ?></td>
    <td><?= $lokasi['nama_lokasi'] ?></td>
    <td><?= $lokasi['alamat_lokasi'] ?></td>
    <td><?= $lokasi['latitude'] . ' / ' . $lokasi['longitude'] ?></td>
    <td><?= $lokasi['radius'] ?></td>
    <td class="text-center">
   <a href="<?= base_url('admin/data_lokasi_presensi/detail.php?id='.$lokasi['id']) ?>" 
      class="badge badge-pill bg-primary me-1">Detail</a>
   
   <a href="<?= base_url('admin/data_lokasi_presensi/edit.php?id='.$lokasi['id']) ?>" 
      class="badge badge-pill bg-primary me-1">Edit</a>
   
   <a href="<?= base_url('admin/data_lokasi_presensi/hapus.php?id='.$lokasi['id']) ?>" 
      class="badge badge-pill bg-danger" 
      onclick="return confirm('Apakah Anda yakin?')">Hapus</a>
</td>
</tr>
<?php endwhile; ?>
        <!-- Tempatkan kode untuk menampilkan data jika ada -->
    <?php endif; ?>
    </table>

    </div>

    </div>
</div>



<?php if (isset($_GET['pesan'])): ?>
    <script>
        <?php if ($_GET['pesan'] == 'hapus_sukses'): ?>
            Swal.fire({
                icon: 'success',
                title: 'Data Dihapus!',
                html: '🗑️ Data lokasi presensi telah berhasil dihapus.',
                showConfirmButton: false,
                timer: 2200,
                timerProgressBar: true
            });
            <?php elseif ($_GET['pesan'] == 'hapus_gagal'): ?>
                Swal.fire({
                icon: 'error',
                title: 'Penghapusan Gagal!',
                html: '❌ Data tidak berhasil dihapus.<br>Silakan coba lagi.',
                confirmButtonText: 'Coba Lagi',
                confirmButtonColor: '#d33'
            });
            <?php endif; ?>
            </script>
<?php endif; ?>



<?php if (isset($_SESSION['berhasil'])): ?>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: '<?= $_SESSION['berhasil']; ?>',
            showConfirmButton: false,
            timer: 2000
        });
        </script>
    <?php unset($_SESSION['berhasil']); ?>
    <?php endif; ?>
    
    
    <?php if (isset($_GET['pesan']) && $_GET['pesan'] == 'edit_sukses'): ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'Perubahan Disimpan!',
                text: 'Data lokasi presensi berhasil diperbarui.',
                showConfirmButton: false,
                timer: 2000
            });
            </script>
<?php endif; ?>

<?php include('../layout/footer.php'); ?>