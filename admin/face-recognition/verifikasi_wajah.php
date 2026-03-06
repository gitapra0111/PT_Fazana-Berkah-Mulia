<?php
// FILE: admin/face-recognition/verifikasi_wajah.php

// 1. CONFIG & SESSION
require_once '../../config.php'; 

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// 2. CEK LOGIN (Hanya Admin)
if (!isset($_SESSION['user']['login']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../../auth/login.php");
    exit;
}

// 3. KONEKSI DATABASE
$db = null;
if (isset($conn)) { $db = $conn; } 
elseif (isset($koneksi)) { $db = $koneksi; }

if (!$db) {
    die("Error: Koneksi database gagal. Cek config.php.");
}

// --- PERBAIKAN UTAMA DI SINI ---
// Jangan hanya mengandalkan $_GET['id']. Gunakan ID dari Session Login.
$id_pegawai = $_SESSION['user']['id_pegawai'] ?? 0;

// Fallback: Jika session tidak terbaca (jarang terjadi), baru cek URL
if ($id_pegawai == 0) {
    $id_pegawai = $_GET['id'] ?? 0;
}

// Jika masih 0 juga, berarti error
if ($id_pegawai == 0) {
    die("Error: ID Pegawai tidak terdeteksi di Session maupun URL.");
}
// ------------------------------

// 4. PROSES VERIFIKASI BERHASIL (Diterima dari JavaScript)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verifikasi_status'])) {
    if ($_POST['verifikasi_status'] === 'matched') {
        
        // BERIKAN TIKET AKSES
        $_SESSION['izin_ganti_wajah'] = true;
        
        // Redirect kembali ke halaman registrasi
        echo "<script>
            window.location.href = 'registrasi_wajah.php?id=" . $id_pegawai . "';
        </script>";
        exit;
    }
}

// 5. AMBIL DATA WAJAH LAMA
$stmt = $db->prepare("SELECT nama, face_descriptor FROM pegawai WHERE id = ?");
$stmt->bind_param('i', $id_pegawai);
$stmt->execute();
$data_pegawai = $stmt->get_result()->fetch_assoc();

if (!$data_pegawai) {
    die("Pegawai dengan ID $id_pegawai tidak ditemukan di database.");
}

// Jika wajah belum ada, langsung lempar ke registrasi (tanpa verifikasi)
if (empty($data_pegawai['face_descriptor'])) {
    header("Location: registrasi_wajah.php?id=" . $id_pegawai);
    exit;
}

// 6. LAYOUT
require_once '../layout/header.php'; 
?>

<style>
    .webcam-container { 
        position: relative; 
        width: 100%; 
        max-width: 480px; 
        margin: auto; 
        overflow: hidden; 
        border-radius: 12px; 
        border: 3px solid #d63939; /* Merah = Security/Locked */
        background: #000;
    }
    video { width: 100%; height: auto; transform: scaleX(-1); display: block; }
    canvas { position: absolute; top: 0; left: 0; }
    
    .security-header { text-align: center; margin-bottom: 20px; }
    .security-icon { font-size: 3rem; color: #d63939; margin-bottom: 10px; }
</style>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title">Keamanan Sistem</h2>
                <div class="text-muted">Verifikasi Identitas: <strong><?= htmlspecialchars($data_pegawai['nama']) ?></strong></div>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-md-6">
                
                <div class="card shadow-lg">
                    <div class="card-status-top bg-danger"></div>
                    
                    <div class="card-body">
                        
                        <div class="security-header">
                            <i class="fe fe-lock security-icon"></i>
                            <h3>Akses Terkunci</h3>
                            <p class="text-muted small">
                                Sistem mendeteksi data wajah sudah ada.<br>
                                Silakan scan wajah Anda untuk membuka kunci pembaruan.
                            </p>
                        </div>

                        <div class="webcam-container">
                            <video id="video" autoplay muted playsinline></video>
                            <canvas id="overlay"></canvas>
                        </div>

                        <div class="mt-3 text-center">
                            <div id="loading-badge" class="badge bg-secondary text-white mb-2">
                                <span class="spinner-border spinner-border-sm me-1"></span> Menyiapkan Sistem...
                            </div>
                            <div id="status-text" class="fw-bold text-danger">Mencocokkan Wajah...</div>
                        </div>

                        <div class="mt-4 text-center">
                            <a href="../absensi/presensi.php" class="btn btn-ghost-secondary w-100">
                                Batal, Kembali ke Presensi
                            </a>
                        </div>

                        <form method="POST" id="form-verifikasi">
                            <input type="hidden" name="verifikasi_status" value="matched">
                        </form>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script src="<?= base_url('assets/js/face-api.min.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Konfigurasi Path Model (Pastikan folder models ada di root)
    const MODEL_URL = '<?= base_url("models") ?>';
    
    // Ambil Data Wajah Lama dari PHP (JSON)
    // Pastikan tidak error JSON saat parsing
    const DB_DESCRIPTOR = <?= json_encode(json_decode($data_pegawai['face_descriptor'])) ?>;

    const video = document.getElementById('video');
    const loadingBadge = document.getElementById('loading-badge');
    const statusText = document.getElementById('status-text');
    let isVerified = false; // Flag agar tidak submit berkali-kali

    // 1. Load Model
    async function loadModels() {
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
            await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            
            loadingBadge.className = "badge bg-warning text-white";
            loadingBadge.innerHTML = "Kamera Aktif";
            startCamera();
        } catch (err) {
            console.error(err);
            alert("Gagal memuat model. Pastikan folder 'models' ada di root!");
        }
    }

    // 2. Start Kamera
    function startCamera() {
        navigator.mediaDevices.getUserMedia({ video: {} })
            .then(stream => video.srcObject = stream)
            .catch(err => alert("Gagal akses kamera: " + err));
    }

    // 3. Proses Deteksi & Pencocokan
    video.addEventListener('play', () => {
        const canvas = document.getElementById('overlay');
        const displaySize = { width: video.clientWidth, height: video.clientHeight };
        faceapi.matchDimensions(canvas, displaySize);

        // Validasi Data Descriptor
        if (!DB_DESCRIPTOR) {
            alert("Data wajah di database rusak/kosong.");
            return;
        }

        // Buat FaceMatcher
        const labeledDescriptor = new faceapi.LabeledFaceDescriptors('User', [new Float32Array(Object.values(DB_DESCRIPTOR))]);
        const faceMatcher = new faceapi.FaceMatcher(labeledDescriptor, 0.45); // Toleransi 0.45

        setInterval(async () => {
            if (isVerified) return; // Stop jika sudah sukses

            const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (detection) {
                const resizedDetections = faceapi.resizeResults(detection, displaySize);
                canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                
                // Gambar kotak wajah
                const box = resizedDetections.detection.box;
                const drawBox = new faceapi.draw.DrawBox(box, { label: 'Mencocokkan...', boxColor: 'red' });
                drawBox.draw(canvas);

                // --- LOGIKA PENCOCOKAN ---
                const match = faceMatcher.findBestMatch(detection.descriptor);

                if (match.label !== 'unknown') {
                    // JIKA COCOK (MATCH)
                    isVerified = true;
                    
                    // Ubah UI jadi Hijau
                    new faceapi.draw.DrawBox(box, { label: 'COCOK!', boxColor: 'green' }).draw(canvas);
                    statusText.innerHTML = "Wajah Terverifikasi!";
                    statusText.className = "fw-bold text-success";
                    video.pause();

                    // Tampilkan Alert Sukses & Submit
                    Swal.fire({
                        icon: 'success',
                        title: 'Identitas Terkonfirmasi',
                        text: 'Mengarahkan ke halaman update wajah...',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        document.getElementById('form-verifikasi').submit();
                    });

                } else {
                    // JIKA TIDAK COCOK
                    statusText.innerHTML = "Wajah Tidak Dikenali!";
                }
            } else {
                canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
            }
        }, 500); 
    });

    loadModels();
</script>

<?php require_once '../layout/footer.php'; ?>