<?php 

// KODE BARU - PERBAIKAN
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

if (!(isset($_SESSION['user']['login']) && $_SESSION['user']['login'] === true && ($_SESSION['user']['role'] ?? '') === 'admin')) {
    header('Location: ../../auth/login.php?pesan=tolak_akses'); exit;
}

$judul = "Data Pegawai";

require_once '../../config.php'; // koneksi database
include('../layout/header.php'); // pastikan base_url() didefinisikan di sini

// Ambil data jabatan dari DB
$result = mysqli_query($conn, "SELECT users.id_pegawai, users.username, users.password, users.status,
 users.role, pegawai.* FROM users JOIN pegawai ON users.id_pegawai = pegawai.id");

if (!$result) {
    die("Query Error: " . mysqli_error($conn));
}
?>

<style>
  /* 1. CONTAINER TABEL RESPONSIVE */
  .table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  /* 2. STYLE DASAR TABEL */
  .table {
    border-collapse: collapse; 
    width: 100%;
  }

  .table th, .table td {
    vertical-align: middle;
    white-space: nowrap; /* Mencegah teks turun ke bawah agar bisa di-scroll */
    background-color: #fff;
    border: 1px solid #dee2e6;
    padding: 10px 15px;
  }

  /* 3. LOGIKA STICKY KOLOM NAMA (KOLOM KE-3) */
  /* Kita pertahankan Nama di posisi kiri saat di-scroll */
  .table th:nth-child(3), 
  .table td:nth-child(3) {
    position: sticky;
    left: 0; /* Menempel di paling kiri */
    z-index: 10;
    background-color: #fff;
    /* Memberikan sedikit border kanan agar terlihat batasnya saat digeser */
    border-right: 1px solid #dee2e6; 
  }

  /* Z-index Header Nama harus lebih tinggi */
  .table thead th:nth-child(3) {
    z-index: 11;
    background-color: #f8f9fa !important;
  }

  /* Efek hover agar baris yang disorot tetap terlihat jelas */
  .table tbody tr:hover td {
    background-color: #f1f5f9;
  }
  .table tbody tr:hover td:nth-child(3) {
    background-color: #f1f5f9;
  }
</style>

<div class="page-body">
    <div class="container-xl">
        <a href="<?= base_url('admin/data_pegawai/tambah.php')?>" class="btn btn-primary"><span class="text"><i class="fa-solid fa-circle-plus"></i> Tambah Data</span></a>
<div class="table-responsive">
    <table class="table table-bordered mt-3">
        <tr class="text-center">
            <th>No</th>
            <th>NIP</th>
            <th>Nama</th>
            <th>Username</th>
            <th>Jabatan</th>
            <th>Role</th>
            <th>Aksi</th>
        </tr>
        <?php if (mysqli_num_rows($result) == 0): ?>
        <tr>
            <td colspan="7" class="text-center">Tidak ada data</td>
        </tr>
    <?php else: ?>
        <?php $no = 1; ?>
<?php while ($pegawai = mysqli_fetch_array($result)) : ?>
<tr>
    <td><?= $no++ ?></td>
    <td><?= $pegawai['nip'] ?></td>
    <td><?= $pegawai['nama'] ?></td>
    <td><?= $pegawai['username'] ?></td>
    <td><?= $pegawai['jabatan'] ?></td>
    <td><?= $pegawai['role'] ?></td>
    <td class="text-center">
       

    <a href="<?= base_url('admin/data_pegawai/detail.php?nip=' . $pegawai['nip']) ?>" class="badge badge-pill bg-info text-white">Detail</a>

    <a href="<?= base_url('admin/data_pegawai/edit.php?nip=' . $pegawai['nip']) ?>" class="badge badge-pill bg-warning">Edit</a>

    <a href="<?= base_url('admin/data_pegawai/hapus.php?nip=' . $pegawai['nip']) ?>" 
   class="badge badge-pill bg-danger" 
   onclick="return confirm('Yakin ingin menghapus pegawai ini?')">🗑️ Hapus</a>

    </td>
</tr>
<?php endwhile; ?>
        <!-- Tempatkan kode untuk menampilkan data jika ada -->
    <?php endif; ?>
    </table>
    </div>

    </div>
</div>

<?php include('../layout/footer.php'); ?>


<?php if (isset($_GET['pesan'])): ?>
    <script>
        <?php if ($_GET['pesan'] == 'hapus_sukses'): ?>
            Swal.fire({
                icon: 'success',
                title: 'Data Dihapus!',
                html: '🗑️ Data pegawai telah berhasil dihapus.',
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
            text: 'Data pegawai berhasil diperbarui.',
            showConfirmButton: false,
            timer: 2000
        });
    </script>
<?php endif; ?>



