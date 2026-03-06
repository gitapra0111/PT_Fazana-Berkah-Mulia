<?php
declare(strict_types=1);

/* ================= CONFIG ================= */
require_once('../config.php');

/* ================= SESSION AUTH ================= */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
// Gunakan nama session default untuk halaman login
session_name(defined('SESS_AUTH') ? SESS_AUTH : 'PHPSESSID');
session_start();

/* ================= HELPER ================= */
function flash_error(string $msg): never {
    // Tambah attempt counter saat error terjadi
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
    }
    $_SESSION['login_attempts']++;
    $_SESSION['last_attempt_time'] = time();
    
    $_SESSION['gagal'] = $msg;
    header("Location: login.php");
    exit;
}

/* ================= PROSES LOGIN ================= */
if (isset($_POST['login'])) {

    // --------------------------------------------
    // 1. RATE LIMITING (ANTI BRUTE FORCE)
    // --------------------------------------------
    $max_attempts = 5;      // Maksimal 5 kali salah
    $lockout_time = 1 * 60; // Lock 15 menit
    
    // Inisialisasi tracking
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
    }
    
    // Reset counter jika sudah lewat waktu hukuman (lockout time)
    if (time() - $_SESSION['last_attempt_time'] > $lockout_time) {
        $_SESSION['login_attempts'] = 0;
    }
    
    // Cek apakah user sedang dihukum?
    if ($_SESSION['login_attempts'] >= $max_attempts) {
        $remaining = $lockout_time - (time() - $_SESSION['last_attempt_time']);
        $minutes = ceil($remaining / 60);
        
        // Jangan panggil flash_error disini agar attempt tidak nambah terus
        $_SESSION['gagal'] = "Terlalu banyak percobaan gagal. Silakan tunggu {$minutes} menit lagi.";
        header("Location: login.php");
        exit;
    }

    // --------------------------------------------
    // 2. TANGKAP INPUT
    // --------------------------------------------
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash_error('Username dan password wajib diisi');
    }

    // --------------------------------------------
    // 3. QUERY DATABASE
    // --------------------------------------------
    // Pastikan koneksi db ($conn atau $connection) tersedia dari config.php
    $mysqli = $conn ?? $koneksi ?? $connection; 


    // CODE di bawah ini yang saya comment karena ingin mengganti ke query yang baru ke binary
    // $sql = "SELECT
    //             users.id,
    //             users.username,
    //             users.password,
    //             users.role,
    //             users.status,
    //             users.id_pegawai,
    //             pegawai.nama,
    //             pegawai.nip,
    //             pegawai.jabatan,
    //             pegawai.lokasi_presensi,
    //             pegawai.foto
    //         FROM users
    //         LEFT JOIN pegawai ON users.id_pegawai = pegawai.id
    //         WHERE users.username = ?
    //         LIMIT 1";

            // KODE BARU (Case Sensitive - Wajib Sama Persis)
    $sql = "SELECT
                users.id,
                users.username,
                users.password,
                users.role,
                users.status,
                users.id_pegawai,
                pegawai.nama,
                pegawai.nip,
                pegawai.jabatan,
                pegawai.lokasi_presensi,
                pegawai.foto
            FROM users
            LEFT JOIN pegawai ON users.id_pegawai = pegawai.id
            WHERE BINARY users.username = ?  /* <--- TAMBAHKAN BINARY DI SINI */
            LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();

    // Cek Ketersediaan User
    if ($res->num_rows !== 1) {
        // Sleep sebentar untuk mencegah Timing Attack (Enumerasi Username)
        usleep(100000); // 0.1 detik
        flash_error('Username tidak ditemukan');
    }

    $row = $res->fetch_assoc();

    // --------------------------------------------
    // 4. VERIFIKASI PASSWORD NEW
    // --------------------------------------------
    if (!password_verify($password, $row['password'])) {
        flash_error('Password yang Anda masukkan salah');
    }

    // Cek Status Aktif
    if (($row['status'] ?? '') !== 'Aktif') {
        // Reset attempt dulu karena user/pass benar, cuma status non-aktif
        // (Opsional, tergantung kebijakan, mau dihitung attempt atau tidak)
        flash_error('Akun Anda dinonaktifkan. Hubungi Admin.');
    }

    // --------------------------------------------
    // 5. LOGIN SUKSES
    // --------------------------------------------
    
    // Reset attempt counter karena berhasil login
    unset($_SESSION['login_attempts']);
    unset($_SESSION['last_attempt_time']);

    // Siapkan Data User
    $payload = [
        'login'           => true,
        'id'              => (int)$row['id'],
        'username'        => $row['username'],
        'role'            => $row['role'],
        'id_pegawai'      => (int)($row['id_pegawai'] ?? 0),
        'nama'            => $row['nama'] ?? $row['username'], // Fallback ke username jika nama null
        'nip'             => $row['nip'] ?? null,
        'jabatan'         => $row['jabatan'] ?? null,
        'lokasi_presensi' => $row['lokasi_presensi'] ?? null,
        'foto'            => $row['foto'] ?? null,
    ];

    /* --- SWITCH SESSION NAME (Opsional tapi Bagus) --- */
    // Kita tutup sesi login saat ini, dan mulai sesi baru sesuai role
    $_SESSION = [];
    session_write_close();

    $isAdmin = (strtolower($row['role']) === 'admin');
    
    // Tentukan nama session baru berdasarkan role
    // Pastikan konstanta SESS_ADMIN / SESS_PEGAWAI ada di config.php
    $newSessionName = $isAdmin 
        ? (defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID') 
        : (defined('SESS_PEGAWAI') ? SESS_PEGAWAI : 'PEGAWAISESSID');
    
    session_name($newSessionName);
    session_start();
    session_regenerate_id(true); // Ganti ID Session (PENTING!)

    // Simpan payload ke sesi baru
    $_SESSION['user'] = $payload;
    $_SESSION['sukses'] = "Selamat datang, " . $payload['nama'];

    // Hapus cookie sesi login lama (SESS_AUTH) agar bersih
    if (defined('SESS_AUTH')) {
        setcookie(SESS_AUTH, '', time() - 3600, '/');
    }

    // Redirect
    $redirectURL = $isAdmin 
        ? base_url('admin/home/home.php') 
        : base_url('pegawai/home/home.php');
        
    header("Location: " . $redirectURL);
    exit;
}
?>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Presensi - PT. Fazana</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            /* Warna Brand Utama - Ubah disini jika ingin ganti warna */
            --brand-color: #0d6efd; 
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #fff;
            height: 100vh;
            overflow: hidden; /* Mencegah scrollbar */
        }

        /* Layout Split Screen */
        .login-wrapper {
            height: 100vh;
            width: 100%;
            display: flex;
        }

        /* KIRI: Gambar / Branding */
        .login-side-img {
            flex: 1; /* Mengambil sisa ruang */
            background: url('https://source.unsplash.com/random/1200x900/?office,modern') no-repeat center center;
            background-size: cover;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Overlay Gelap di Gambar */
        .login-side-img::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(13, 110, 253, 0.8), rgba(0, 0, 0, 0.6));
            z-index: 1;
        }
        .login-brand-text {
            position: relative;
            z-index: 2;
            color: white;
            text-align: center;
            padding: 2rem;
        }

        /* KANAN: Form Login */
        .login-side-form {
            width: 100%;
            max-width: 500px; /* Batas lebar form */
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            background: #fff;
        }
        
        .form-content {
            width: 100%;
        }

        /* Styling Input Modern (Floating Label) */
        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            color: var(--brand-color);
            opacity: 0.8;
        }
        .form-control:focus {
            border-color: var(--brand-color);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
        }

        .btn-brand {
            background-color: var(--brand-color);
            border-color: var(--brand-color);
            color: white;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-brand:hover {
            background-color: #0b5ed7;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }

        /* Icon Mata Password */
        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 18px;
            color: #6c757d;
            z-index: 10;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .login-side-img { display: none; } /* Sembunyikan gambar di HP */
            .login-side-form { max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    
    <div class="login-side-img d-none d-md-flex">
        <div class="login-brand-text">
            <img src="../assets/images/logoFix.png" alt="Logo" class="mb-4 bg-white p-2 rounded shadow" style="height: 80px;">
            <h1 class="fw-bold">E-Presensi</h1>
            <p class="lead opacity-75">PT. Fazana Berkah Mulia</p>
            <div class="mt-5 small text-white-50">
                &copy; <?= date('Y') ?> All Rights Reserved.
            </div>
        </div>
    </div>

    <div class="login-side-form">
        <div class="form-content">
            <div class="text-center d-md-none mb-4">
                <img src="../assets/images/logoFix.png" alt="Logo" style="height: 60px;">
            </div>

            <div class="mb-5">
                <h2 class="fw-bold text-dark">Selamat Datang 👋</h2>
                <p class="text-muted">Silakan masukkan akun Anda untuk masuk.</p>
            </div>

            <form action="" method="POST" autocomplete="off">
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="username" name="username" placeholder="Username" required autofocus>
                    <label for="username"><i class="bi bi-person me-2"></i>Username</label>
                </div>

                <div class="form-floating mb-4 position-relative">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password"><i class="bi bi-lock me-2"></i>Password</label>
                    <i class="bi bi-eye-slash password-toggle" id="togglePassword"></i>
                </div>

                <button type="submit" name="login" class="btn btn-brand w-100 rounded-3">
                    Masuk Sekarang <i class="bi bi-arrow-right ms-2"></i>
                </button>

            </form>

            <div class="mt-4 text-center">
                <small class="text-muted">Lupa password? Hubungi <a href="#" class="text-decoration-none fw-bold">Administrator</a></small>
            </div>
        </div>
    </div>
</div>

<script>
    const togglePassword = document.querySelector('#togglePassword');
    const password = document.querySelector('#password');

    togglePassword.addEventListener('click', function (e) {
        // Toggle tipe input
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        
        // Toggle icon mata
        this.classList.toggle('bi-eye');
        this.classList.toggle('bi-eye-slash');
    });
</script>

<?php if (!empty($_SESSION['gagal'])): ?>
    <script>
        Swal.fire({
            icon: "error",
            title: "Login Gagal",
            text: <?= json_encode($_SESSION['gagal']) ?>,
            confirmButtonColor: '#0d6efd'
        });
    </script>
    <?php unset($_SESSION['gagal']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['sukses'])): ?>
    <script>
        Swal.fire({
            icon: "success",
            title: "Berhasil",
            text: <?= json_encode($_SESSION['sukses']) ?>,
            showConfirmButton: false,
            timer: 1500
        });
    </script>
    <?php unset($_SESSION['sukses']); ?>
<?php endif; ?>

</body>
</html>


<!-- yang sudah di cek debugging -->
 <!-- presensi_masuk.php -->
 <!-- presensi_masuk_aksi.php -->
 <!-- presensi_keluar.php -->
 <!-- presensi_keluar_aksi.php -->
  <!-- home.php -->
  <!-- config.php -->

  <!-- yang lain di perbaiki dan di cek lagi -->

  <!-- UJI COBA SEMUA NANTI -->
  <!-- POP UP CANTIK NANTI-->
<!-- Face API Partial content -->