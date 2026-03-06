<!-- ===== FOOTER PEGAWAI (REVISI) ===== -->
<div class="footer">
  <div class="container">
    <div class="row">

      <!-- Kiri: Info & link -->
      <div class="col-lg-8">
        <div class="row">

          <div class="col-6 col-md-3 mb-4 mb-md-0">
            <h6 class="text-uppercase text-muted mb-2">Perusahaan</h6>
            <ul class="list-unstyled mb-0">
              <li><a href="#" class="text-reset">PT. Fazana Berkah Mulia</a></li>
              <li><a href="#" class="text-reset">Sistem Presensi Digital</a></li>
            </ul>
          </div>

          <div class="col-6 col-md-3 mb-4 mb-md-0">
            <h6 class="text-uppercase text-muted mb-2">Menu Pegawai</h6>
            <ul class="list-unstyled mb-0">
              <li><a href="<?= base_url('pegawai/home/home.php'); ?>" class="text-reset">Home</a></li>
              <li><a href="<?= base_url('pegawai/presensi/rekap_presensi.php'); ?>" class="text-reset">Rekap Presensi</a></li>
            </ul>
          </div>

          <div class="col-6 col-md-3 mb-4 mb-md-0">
            <h6 class="text-uppercase text-muted mb-2">Pengajuan</h6>
            <ul class="list-unstyled mb-0">
              <li><a href="<?= base_url('pegawai/ketidakhadiran/ketidakhadiran.php'); ?>" class="text-reset">Ketidakhadiran</a></li>
              <li><a href="<?= base_url('pegawai/ketidakhadiran/pengajuan_ketidakhadiran.php'); ?>" class="text-reset">Ajukan Izin/Cuti</a></li>
            </ul>
          </div>

          <div class="col-6 col-md-3 mb-4 mb-md-0">
            <h6 class="text-uppercase text-muted mb-2">Bantuan</h6>
            <ul class="list-unstyled mb-0">
              <li><a href="<?= base_url('pegawai/home/profile.php'); ?>" class="text-reset">Profil</a></li>
              <li><a href="<?= base_url('pegawai/home/settings.php'); ?>" class="text-reset">Pengaturan</a></li>
            </ul>
          </div>

        </div>
      </div>

      <!-- Kanan: Catatan kecil -->
      <div class="col-lg-4 mt-0 mt-lg-0">
        <div class="card border-0 bg-light">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <span class="avatar bg-primary-lt mr-3">
                <i class="fe fe-map-pin"></i>
              </span>
              <div>
                <div class="font-weight-medium">Presensi berbasis lokasi & foto</div>
                <div class="text-muted small">
                  Pastikan izin lokasi aktif dan akses via HTTPS.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<footer class="footer">
  <div class="container">
    <div class="row align-items-center">

      <div class="col-12 col-md">
        <div class="text-muted text-center text-md-left">
          © <?= date('Y'); ?> <b>PT. Fazana Berkah Mulia</b> — Sistem Presensi Karyawan
        </div>
      </div>

      <div class="col-12 col-md-auto mt-3 mt-md-0">
        <ul class="list-inline list-inline-dots mb-0 text-center text-md-right">
          <li class="list-inline-item">
            <a href="<?= base_url('pegawai/home/home.php'); ?>">Dashboard</a>
          </li>
          <li class="list-inline-item">
            <a href="<?= base_url('pegawai/ketidakhadiran/ketidakhadiran.php'); ?>">Ketidakhadiran</a>
          </li>
          <li class="list-inline-item">
            <a href="<?= base_url('auth/logout.php'); ?>" class="text-danger">Logout</a>
          </li>
        </ul>
      </div>

    </div>
  </div>
</footer>
</div>
</body>
</html>

<!-- jQuery (CDN tanpa integrity untuk development) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Local JS assets via base_url -->
<!-- <script src="<?= base_url('assets/js/tabler.min.js') ?>"></script> -->
<!-- <script src="<?= base_url('assets/js/demo.min.js') ?>"></script> -->

<!-- plugin JS -->
<!-- <script src="<?= base_url('assets/libs/apexcharts/dist/apexcharts.min.js') ?>"></script> -->
<!-- <script src="<?= base_url('assets/libs/jsvectormap/dist/jsvectormap.min.js') ?>"></script> -->
<!-- <script src="<?= base_url('assets/libs/jsvectormap/dist/maps/world.js') ?>"></script> -->
<!-- <script src="<?= base_url('assets/libs/jsvectormap/dist/maps/world-merc.js') ?>"></script> -->

<!-- gambar default -->
<!-- pastikan path image pakai base_url -->
<!-- <img src="<?= base_url('assets/images/avatar-default.png') ?>" alt="" style="display:none;" /> -->
