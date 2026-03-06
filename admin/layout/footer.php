<footer class="footer footer-transparent mt-auto py-4">
  <div class="container-xl">
    
    <div class="row g-4 text-center text-lg-start">

      <div class="col-12 col-md-6 col-lg-3">
        <h4 class="mb-3 fw-bold text-primary">Admin Panel</h4>
        <p class="text-muted small leading-relaxed">
          Sistem Absensi Digital berbasis GPS dan Foto Selfie
          untuk monitoring dan pengelolaan kehadiran karyawan
          <strong>PT. Fazana Berkah Mulia</strong>.
        </p>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <h4 class="mb-3 fw-bold">Menu Utama</h4>
        <ul class="list-unstyled mb-0">
          <li class="mb-2">
            <a href="<?= base_url('admin/home/home.php') ?>" class="text-decoration-none link-secondary">
              <i class="fa fa-dashboard me-1"></i> Dashboard
            </a>
          </li>
          <li class="mb-2">
            <a href="<?= base_url('admin/data_pegawai/pegawai.php') ?>" class="text-decoration-none link-secondary">
              <i class="fa fa-users me-1"></i> Data Pegawai
            </a>
          </li>
          <li class="mb-2">
            <a href="<?= base_url('admin/data_lokasi/lokasi_presensi.php') ?>" class="text-decoration-none link-secondary">
              <i class="fa fa-map-marker me-1"></i> Lokasi & Radius
            </a>
          </li>
        </ul>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <h4 class="mb-3 fw-bold">Laporan</h4>
        <ul class="list-unstyled mb-0">
          <li class="mb-2">
            <a href="<?= base_url('admin/presensi/rekap_harian.php') ?>" class="text-decoration-none link-secondary">
              <i class="fa fa-list-alt me-1"></i> Rekap Harian
            </a>
          </li>
          <li class="mb-2">
            <a href="<?= base_url('admin/presensi/rekap_bulanan.php') ?>" class="text-decoration-none link-secondary">
              <i class="fa fa-calendar-check-o me-1"></i> Rekap Bulanan
            </a>
          </li>
          <li class="mb-2 text-muted fst-italic">
            <small><i class="fa fa-check-circle me-1"></i> Validasi Otomatis</small>
          </li>
        </ul>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <h4 class="mb-3 fw-bold">Specs Sistem</h4>
        <ul class="list-unstyled mb-0 text-muted small">
          <li class="mb-2"><span class="badge bg-blue-lt me-1">GPS</span> Geofencing Radius</li>
          <li class="mb-2"><span class="badge bg-green-lt me-1">ACC</span> Akurasi ≤ 50m</li>
          <li class="mb-2"><span class="badge bg-orange-lt me-1">CAM</span> Anti Fake GPS</li>
          <li class="mb-2"><span class="badge bg-purple-lt me-1">WIB</span> Timezone Auto</li>
        </ul>
      </div>

    </div>

    <hr class="my-4 border-secondary opacity-25">

    <div class="row align-items-center gy-2">
      <div class="col-12 col-lg-6 text-center text-lg-start">
        <div class="text-muted small">
          &copy; <?= date('Y') ?> <strong>PT. Fazana Berkah Mulia</strong>. All rights reserved.
        </div>
      </div>

      <div class="col-12 col-lg-6 text-center text-lg-end">
        <div class="text-muted small">
          Developed by <a href="#" class="fw-bold text-dark text-decoration-none">Sagita Pra Kosa</a>
          <span class="mx-1">&middot;</span>
          <span class="text-secondary">Skripsi Teknik Informatika</span>
        </div>
      </div>
    </div>

  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (isset($_SESSION['validasi'])): ?>
  <script>
    const Toast = Swal.mixin({
      toast: true,
      position: "top-end",
      showConfirmButton: false,
      timer: 3000,
      timerProgressBar: true,
      didOpen: (toast) => {
        toast.onmouseenter = Swal.stopTimer;
        toast.onmouseleave = Swal.resumeTimer;
      }
    });

    Toast.fire({
      icon: "success",
      title: "<?= $_SESSION['validasi']?>"
    });
  </script>
  <?php unset($_SESSION['validasi']); ?>
<?php endif; ?>

</body>
</html>