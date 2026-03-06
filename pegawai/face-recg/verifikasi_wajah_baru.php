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

/* --- 2. AMBIL DATA WAJAH LAMA (KUNCI) --- */
$query = mysqli_query($conn, "SELECT face_descriptor FROM pegawai WHERE id = '$id_pegawai'");
$data = mysqli_fetch_assoc($query);
$existing_descriptor = $data['face_descriptor'] ?? null;

// Jika wajah belum ada, lempar balik ke registrasi biasa
if (empty($existing_descriptor)) {
    echo "<script>window.location.href='registrasi_wajah.php';</script>";
    exit;
}
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
        
        #video-container { 
            position: relative; width: 100%; max-width: 400px; margin: auto; 
            border-radius: 25px; overflow: hidden; background: #000; 
            aspect-ratio: 3/4; border: 6px solid #fff; box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        
        /* Efek Border saat Terkunci vs Terbuka */
        .border-locked { border-color: #f59f00 !important; box-shadow: 0 0 20px rgba(245, 159, 0, 0.3) !important; }
        .border-unlocked { border-color: #2fb344 !important; box-shadow: 0 0 20px rgba(47, 179, 68, 0.3) !important; }

        video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }

        /* Overlay Status */
        .overlay-status {
            position: absolute; top: 20px; left: 0; right: 0; 
            text-align: center; z-index: 10;
        }
        .badge-mode { 
            background: rgba(0,0,0,0.6); color: white; padding: 8px 16px; 
            border-radius: 30px; backdrop-filter: blur(4px); font-weight: 600;
        }

        /* Scan Line */
        .scan-line {
            position: absolute; width: 100%; height: 4px;
            background: #f59f00; /* Orange saat Verifikasi */
            top: 20%; animation: moveScan 2s infinite ease-in-out; 
            display: none; z-index: 5; box-shadow: 0 0 15px #f59f00;
        }
        @keyframes moveScan { 0% { top: 20%; opacity: 0; } 50% { top: 80%; opacity: 1; } 100% { top: 20%; opacity: 0; } }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <div class="page-body">
        <div class="container-tight py-4">
            <div class="card face-card">
                <div class="card-header">
                    <h3 class="card-title" id="page-title">
                        <i class="ti ti-lock me-2"></i>Verifikasi Keamanan
                    </h3>
                </div>
                <div class="card-body p-4 text-center">
                    
                    <p class="text-muted small mb-4" id="instruction-text">
                        Sistem mendeteksi Anda ingin mengubah data wajah.<br>
                        <b>Scan wajah lama</b> Anda terlebih dahulu untuk membuka akses.
                    </p>

                    <div id="video-container" class="mb-4 border-locked">
                        <video id="video" autoplay muted playsinline></video>
                        
                        <div class="overlay-status">
                            <span id="badge-status" class="badge-mode">
                                <i class="ti ti-lock me-1"></i> MODE TERKUNCI
                            </span>
                        </div>

                        <div id="scanner" class="scan-line"></div>
                    </div>

                    <div id="status" class="alert alert-light border py-2 small mb-3 text-muted">
                        <span class="spinner-border spinner-border-sm me-2"></span> Menyiapkan Sistem...
                    </div>

                    <div class="d-grid gap-2">
                        <button id="btn-action" class="btn btn-warning btn-lg shadow-sm" disabled>
                            <i class="ti ti-scan me-2"></i>Verifikasi Wajah Lama
                        </button>
                        <a href="registrasi_wajah.php" class="btn btn-link text-muted small">Batal</a>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
        // --- 1. SETUP VARIABEL ---
        const existingDescriptor = new Float32Array(Object.values(<?= $existing_descriptor ?>));
        const idPegawai = <?= $id_pegawai ?>;
        
        // Elemen DOM
        const video = document.getElementById('video');
        const videoContainer = document.getElementById('video-container');
        const btnAction = document.getElementById('btn-action');
        const statusDiv = document.getElementById('status');
        const scanner = document.getElementById('scanner');
        const badgeStatus = document.getElementById('badge-status');
        const pageTitle = document.getElementById('page-title');
        const instructionText = document.getElementById('instruction-text');

        // State Machine
        let currentMode = 'LOCKED'; 
        let isModelLoaded = false;

        // --- 2. INIT SYSTEM ---
        async function loadModels() {
            const MODEL_URL = '<?= base_url("models/") ?>';
            try {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                ]);
                startCamera();
            } catch (err) {
                statusDiv.innerHTML = "<span class='text-danger'>Gagal memuat AI. Periksa folder models.</span>";
            }
        }

        function startCamera() {
            navigator.mediaDevices.getUserMedia({ video: { width: 640 } })
                .then(stream => {
                    video.srcObject = stream;
                    isModelLoaded = true;
                    // Ubah UI Tombol saat loading
                    btnAction.disabled = true;
                    btnAction.innerHTML = "<i class='ti ti-scan me-2'></i>Memindai Wajah Lama...";
                    scanner.style.display = 'block';
                })
                .catch(err => {
                    statusDiv.innerHTML = "Akses kamera ditolak.";
                });
        }

        // --- 3. PEMINDAIAN OTOMATIS (AUTO-UNLOCK) ---
        video.addEventListener('play', () => {
            async function autoScan() {
                // Hentikan pemindaian otomatis jika video mati atau sistem sudah terbuka
                if (video.paused || video.ended || currentMode === 'UNLOCKED') return;

                const detect = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceDescriptor();

                if (detect) {
                    const distance = faceapi.euclideanDistance(detect.descriptor, existingDescriptor);

                    if (distance < 0.45) { // WAJAH COCOK (Jarak diatur sedikit lebih ketat agar aman)
                        unlockSystem(); // Buka Kunci
                        return; // Hentikan loop otomatis ini
                    } else {
                        statusDiv.innerHTML = "<span class='text-danger'><i class='ti ti-alert-triangle me-1'></i> Wajah tidak cocok dengan data lama.</span>";
                    }
                } else {
                    statusDiv.innerHTML = "<span class='text-muted'><span class='spinner-border spinner-border-sm me-2'></span> Arahkan wajah ke kamera...</span>";
                }

                setTimeout(autoScan, 800); // Looping setiap 800ms
            }
            
            autoScan(); // Mulai loop saat video play
        });

        // --- 4. LOGIKA TOMBOL (HANYA UNTUK SIMPAN WAJAH BARU) ---
        btnAction.addEventListener('click', async () => {
            if (!isModelLoaded || currentMode === 'LOCKED') return;
            
            btnAction.disabled = true;
            scanner.style.display = 'block';
            statusDiv.innerHTML = "Mengambil data wajah baru...";

            // Ambil snapshot wajah saat tombol diklik
            const detect = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceDescriptor();

            if (!detect) {
                failScan("Wajah tidak terdeteksi. Arahkan wajah dengan jelas.");
                return;
            }

            // Kirim ke Database
            saveNewFace(detect.descriptor);
        });

        // --- 5. HELPER FUNCTIONS ---
        function failScan(msg) {
            scanner.style.display = 'none';
            statusDiv.innerHTML = `<span class='text-danger'>${msg}</span>`;
            btnAction.disabled = false;
        }

        function unlockSystem() {
            currentMode = 'UNLOCKED'; // Matikan loop AutoScan
            
            // Ubah Visual Menjadi Mode "Terbuka" (Hijau)
            scanner.style.display = 'none';
            videoContainer.classList.remove('border-locked');
            videoContainer.classList.add('border-unlocked'); 
            
            pageTitle.innerHTML = "<i class='ti ti-lock-open text-success me-2'></i>Akses Terbuka";
            instructionText.innerHTML = "Verifikasi berhasil! Silakan posisikan wajah baru Anda dan klik tombol di bawah.";
            
            badgeStatus.classList.remove('bg-dark');
            badgeStatus.classList.add('bg-success');
            badgeStatus.innerHTML = "<i class='ti ti-check me-1'></i> MODE UPDATE AKTIF";
            statusDiv.innerHTML = "<span class='text-success'>Sistem siap merekam wajah baru.</span>";
            
            // Aktifkan Tombol Simpan
            btnAction.className = "btn btn-success btn-lg shadow-sm";
            btnAction.innerHTML = "<i class='ti ti-camera me-2'></i>Ambil & Simpan Wajah Baru";
            btnAction.disabled = false;

            Swal.fire({
                icon: 'success',
                title: 'Wajah Dikenali!',
                text: 'Kunci keamanan terbuka. Anda kini bisa menyimpan wajah baru.',
                timer: 2000,
                showConfirmButton: false
            });
        }

        function saveNewFace(descriptor) {
            // 1. Ambil Gambar untuk Disimpan
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.translate(canvas.width, 0); ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0);
            const fotoBase64 = canvas.toDataURL('image/jpeg', 0.85);

            // 2. Siapkan Data JSON
            const descriptorJson = JSON.stringify(Array.from(descriptor));

            // 3. Kirim ke Backend
            fetch('registrasi_wajah_aksi.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id_pegawai=${idPegawai}&face_descriptor=${encodeURIComponent(descriptorJson)}&foto_wajah=${encodeURIComponent(fotoBase64)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        title: 'Berhasil Diperbarui!',
                        text: 'Data biometrik Anda yang baru telah disimpan.',
                        icon: 'success',
                        confirmButtonText: 'Lanjutkan ke Presensi'
                    }).then(() => {
                        window.location.href = '../home/home.php'; // Arahkan kembali ke presensi
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                    failScan("Gagal menyimpan data ke server.");
                }
            })
            .catch(err => {
                failScan("Koneksi Error.");
            });
        }

        // Jalankan Sistem
        loadModels();
    </script>
</body>
</html>