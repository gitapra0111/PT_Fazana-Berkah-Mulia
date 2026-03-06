<?php
declare(strict_types=1);

/* --- 1. SESSION & AUTH --- */
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name('PEGAWAISESSID');
session_start();

// Validasi Login
if (!isset($_SESSION['user']['login']) || $_SESSION['user']['role'] !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); exit;
}

require_once '../../config.php';
require_once __DIR__ . '/../layout/header.php';

$base_url = base_url();
$id_pegawai = (int)$_SESSION['user']['id_pegawai'];

/* --- 2. CEK STATUS PENDAFTARAN --- */
// Ambil data descriptor dari database
$query = mysqli_query($conn, "SELECT face_descriptor FROM pegawai WHERE id = '$id_pegawai'");
$data = mysqli_fetch_assoc($query);

// Cek apakah descriptor berisi data (tidak NULL dan tidak kosong)
$is_registered = !empty($data['face_descriptor']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    
    <script>
        if (typeof define === 'function' && define.amd) {
            window._tempDefine = define;
            define = null;
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?= base_url('assets/js/face-api.min.js') ?>"></script>

    <script>
        if (window._tempDefine) { define = window._tempDefine; }
    </script>

    <style>
        body { background-color: #f4f6fa; font-family: 'Inter', sans-serif; }
        .face-card { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        
        /* CSS Kamera (Hanya dipakai jika belum terdaftar) */
        #video-container { 
            position: relative; width: 100%; max-width: 400px; margin: auto; 
            border-radius: 25px; overflow: hidden; background: #000; 
            aspect-ratio: 3/4; border: 6px solid #fff; box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
        
        /* Elemen Visual Scan */
        .scan-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
        .scan-frame {
            position: absolute; top: 15%; left: 10%; width: 80%; height: 70%;
            border: 2px solid rgba(255,255,255,0.3); border-radius: 50% / 40%;
            transition: border-color 0.3s ease;
        }
        .scan-line {
            position: absolute; width: 100%; height: 5px;
            background: linear-gradient(to bottom, transparent, #206bc4, transparent);
            box-shadow: 0 0 15px #206bc4;
            top: 20%; animation: moveScan 2.5s infinite ease-in-out; display: none; z-index: 10;
        }
        @keyframes moveScan { 0% { top: 20%; opacity: 0.3; } 50% { top: 80%; opacity: 1; } 100% { top: 20%; opacity: 0.3; } }

        .instruction-step { font-size: 0.85rem; color: #626976; margin-bottom: 10px; }
        .instruction-step i { color: #206bc4; margin-right: 8px; }
        .status-info { transition: all 0.3s ease; font-weight: 600; }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <div class="page-body">
        <div class="container-tight py-4">
            <div class="card face-card">
                <div class="card-body p-4 text-center">
                    
                    <h2 class="mb-3">Registrasi Wajah</h2>

                    <?php if ($is_registered): ?>
                        
                        <div class="py-5">
                            <i class="ti ti-shield-check text-success" style="font-size: 6rem;"></i>
                            <h3 class="mt-3 text-success">Wajah Sudah Terdaftar</h3>
                            <p class="text-muted">
                                Data biometrik Anda sudah tersimpan di sistem.<br>
                                Anda sudah bisa melakukan presensi.
                            </p>
                            
                            <div class="mt-4">
                                <a href="verifikasi_wajah_baru.php" class="btn btn-warning w-100 mb-2">
                                    <i class="ti ti-reload me-2"></i> Perbarui Wajah
                                </a>
                                <a href="<?= base_url('pegawai/home/home.php') ?>" class="btn btn-light w-100">
                                    Kembali ke Home
                                </a>
                            </div>
                        </div>

                    <?php else: ?>

                        <p class="text-muted small mb-4">Pola wajah Anda akan dienkripsi untuk keamanan presensi.</p>
                        
                        <div id="status" class="alert alert-info d-flex align-items-center mb-4 py-2 status-info">
                            <div class="spinner-border spinner-border-sm me-3"></div>
                            <div class="small">Menginisialisasi AI...</div>
                        </div>

                        <div id="video-container" class="mb-4">
                            <video id="video" autoplay muted playsinline></video>
                            <div class="scan-overlay">
                                <div id="visual-frame" class="scan-frame"></div>
                                <div id="scanner" class="scan-line"></div>
                            </div>
                        </div>

                        <div class="row text-start mb-4 g-2">
                            <div class="col-6 instruction-step"><i class="ti ti-brightness"></i>Cahaya Cukup</div>
                            <div class="col-6 instruction-step"><i class="ti ti-face-id"></i>Wajah Tegak</div>
                            <div class="col-6 instruction-step"><i class="ti ti-masks-off"></i>Tanpa Masker</div>
                            <div class="col-6 instruction-step"><i class="ti ti-focus-centered"></i>Fokus Tengah</div>
                        </div>

                        <div class="d-grid gap-2">
                            <button id="btn-register" class="btn btn-primary btn-lg shadow-sm" disabled>
                                <i class="ti ti-camera-selfie me-2"></i>Mulai Pindai Wajah
                            </button>
                            <a href="<?= base_url('pegawai/home/home.php') ?>" class="btn btn-link text-muted small">Batal & Kembali</a>
                        </div>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <?php if (!$is_registered): ?>
    <script>
        const video = document.getElementById('video');
        const btnRegister = document.getElementById('btn-register');
        const statusDiv = document.getElementById('status');
        const scanner = document.getElementById('scanner');
        const visualFrame = document.getElementById('visual-frame');

        // 1. Muat Model AI
        async function loadModels() {
            const MODEL_URL = '<?= base_url("models/") ?>';
            try {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                ]);
                startVideo();
            } catch (err) {
                statusDiv.innerHTML = "Gagal memuat AI. Periksa koneksi models.";
                statusDiv.className = "alert alert-danger mb-4 py-2 status-info";
            }
        }

        // 2. Aktifkan Kamera
        function startVideo() {
            navigator.mediaDevices.getUserMedia({ video: { width: 640 } })
                .then(stream => {
                    video.srcObject = stream;
                    statusDiv.innerHTML = "<i class='ti ti-circle-check-filled me-2'></i>Sistem Siap. Klik tombol untuk memindai.";
                    statusDiv.className = "alert alert-success mb-4 py-2 status-info";
                    btnRegister.disabled = false;
                })
                .catch(err => {
                    statusDiv.innerHTML = "Kamera tidak terdeteksi.";
                    statusDiv.className = "alert alert-warning mb-4 py-2 status-info";
                });
        }

        // 3. Proses Registrasi
        btnRegister.addEventListener('click', async () => {
            btnRegister.disabled = true;
            scanner.style.display = "block";
            statusDiv.innerHTML = "<div class='spinner-grow spinner-grow-sm me-2'></div>Sedang memindai wajah Anda...";
            statusDiv.className = "alert alert-warning mb-4 py-2 status-info";

            const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) {
                scanner.style.display = "none";
                Swal.fire('Gagal', 'Wajah tidak terdeteksi. Posisikan wajah di tengah.', 'warning');
                statusDiv.innerHTML = "<i class='ti ti-alert-circle me-2'></i>Gagal mendeteksi. Coba lagi.";
                statusDiv.className = "alert alert-info mb-4 py-2 status-info";
                btnRegister.disabled = false;
                return;
            }

            scanner.style.display = "none";
            visualFrame.style.borderColor = "#2fb344"; 
            statusDiv.innerHTML = "<i class='ti ti-discount-check-filled me-2'></i>Berhasil! Menyimpan data...";
            statusDiv.className = "alert alert-success mb-4 py-2 status-info";

            setTimeout(() => {
                // Ambil Foto Base64
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth; canvas.height = video.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.translate(canvas.width, 0); ctx.scale(-1, 1);
                ctx.drawImage(video, 0, 0);
                const fotoWajah = canvas.toDataURL('image/jpeg', 0.8);

                const faceDescriptorJson = JSON.stringify(Array.from(detection.descriptor));

                fetch('registrasi_wajah_aksi.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id_pegawai=<?= $id_pegawai ?>&face_descriptor=${encodeURIComponent(faceDescriptorJson)}&foto_wajah=${encodeURIComponent(fotoWajah)}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire({
                            title: 'Registrasi Berhasil!',
                            text: 'Wajah berhasil didaftarkan.',
                            icon: 'success',
                            showConfirmButton: false,
                            timer: 1500
                        }).then(() => location.reload()); // Reload agar tampilan berubah jadi "Terdaftar"
                    } else {
                        Swal.fire('Error', data.message, 'error');
                        btnRegister.disabled = false;
                        visualFrame.style.borderColor = "rgba(255,255,255,0.3)";
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Kesalahan jaringan.', 'error');
                    btnRegister.disabled = false;
                });
            }, 800);
        });

        loadModels();
    </script>
    <?php endif; ?>
</body>
</html>