<?php
// FILE: admin/face-recognition/registrasi_wajah.php

// 1. CONFIG & SESSION (Wajib urutan ini)
require_once '../../config.php'; 

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// 2. CEK LOGIN
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

// 4. AMBIL DATA PEGAWAI
$id_pegawai = $_GET['id'] ?? 0;

if ($id_pegawai == 0) {
    echo "<script>alert('ID Pegawai tidak ditemukan!'); window.location='../home/home.php';</script>";
    exit;
}

// Ambil Nama & Face Descriptor saat ini
$stmt = $db->prepare("SELECT nama, face_descriptor FROM pegawai WHERE id = ?");
$stmt->bind_param('i', $id_pegawai);
$stmt->execute();
$pegawai = $stmt->get_result()->fetch_assoc();

if (!$pegawai) die("Data Pegawai tidak ditemukan.");

// =================================================================================
// LOGIKA "GEMBOK" / HAK AKSES
// =================================================================================
$sudah_punya_wajah = !empty($pegawai['face_descriptor']);
$punya_tiket_akses = isset($_SESSION['izin_ganti_wajah']) && $_SESSION['izin_ganti_wajah'] === true;

// Tentukan apakah user boleh melihat kamera atau harus melihat pengumuman
// Boleh akses jika: (Belum punya wajah) ATAU (Sudah punya tapi punya tiket verifikasi)
$akses_diizinkan = (!$sudah_punya_wajah) || ($sudah_punya_wajah && $punya_tiket_akses);

// =================================================================================
// 5. PROSES SIMPAN DATA (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['face_descriptor'])) {
    
    // Security Check: Jangan izinkan simpan jika akses sebenarnya tertutup
    if (!$akses_diizinkan) {
        die("Akses ilegal.");
    }

    $descriptor = $_POST['face_descriptor'];
    
    $sql = "UPDATE pegawai SET face_descriptor = ? WHERE id = ?";
    $stmt = $db->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param('si', $descriptor, $id_pegawai);
        
        if ($stmt->execute()) {
            // Hapus tiket izin setelah berhasil simpan (agar aman)
            unset($_SESSION['izin_ganti_wajah']);

            // Set notifikasi sukses
            $_SESSION['berhasil'] = "Data wajah berhasil disimpan! Anda kini bisa melakukan presensi.";
            
            // Redirect ke halaman Presensi Admin
            header("Location: ../absensi/presensi.php");
            exit;
        } else {
            echo "<script>alert('Database Error: " . $stmt->error . "');</script>";
        }
    }
    exit;
}

// 6. PANGGIL LAYOUT
// TAMBAHKAN BARIS INI
$judul = "Registrasi Wajah";
require_once '../layout/header.php'; 
?>

<style>
    .webcam-container { position: relative; width: 100%; max-width: 500px; margin: auto; overflow: hidden; border-radius: 8px; border: 2px solid #e2e8f0; }
    video { width: 100%; height: auto; transform: scaleX(-1); display: block; }
    canvas { position: absolute; top: 0; left: 0; }
    .locked-container { max-width: 500px; margin: auto; text-align: center; padding: 40px 20px; }
    .icon-locked { font-size: 4rem; color: #d63939; margin-bottom: 20px; }
</style>



<div class="page-body">
    <div class="container-xl">
        
        <?php if (!$akses_diizinkan): ?>
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <div class="locked-container">
                            <i class="fe fe-check-circle text-success icon-locked" style="font-size: 5rem;"></i>
                            <h2 class="mb-3">Wajah Sudah Terdaftar!</h2>
                            <p class="text-muted mb-4">
                                Data wajah untuk pegawai ini sudah tersimpan di database. 
                                Untuk alasan keamanan, Anda tidak bisa langsung mengubahnya.
                            </p>
                            
                            <div class="d-grid gap-2">
                                <a href="verifikasi_wajah.php?id=<?= $id_pegawai ?>" class="btn btn-primary btn-lg">
                                    <i class="fe fe-unlock me-2"></i> Verifikasi untuk Ganti Wajah
                                </a>
                                <a href="../absensi/presensi.php" class="btn btn-ghost-secondary">
                                    Batal, Kembali ke Presensi
                                </a>
                            </div>
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title">
                            <?= $sudah_punya_wajah ? 'Update Data Wajah' : 'Pendaftaran Wajah Baru' ?>
                        </h3>
                    </div>
                    <div class="card-body text-center">
                        
                        <div class="webcam-container">
                            <video id="video" autoplay muted playsinline></video>
                            <canvas id="overlay"></canvas>
                        </div>

                        <div class="mt-3">
                            <div id="loading-badge" class="badge bg-warning text-white mb-2">
                                <span class="spinner-border spinner-border-sm me-1"></span> Memuat Model AI...
                            </div>
                            <div id="status-text" class="text-muted small">Tunggu hingga kotak hijau muncul di wajah.</div>
                        </div>

                        <form method="POST" id="form-registrasi" class="mt-4">
                            <input type="hidden" name="face_descriptor" id="input-descriptor">
                            
                            <button type="button" id="btn-scan" class="btn btn-primary w-100 btn-lg" disabled>
                                <i class="fe fe-camera me-2"></i> Ambil & Simpan Data Wajah
                            </button>
                        </form>

                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($akses_diizinkan): ?>
<script src="<?= base_url('assets/js/face-api.min.js') ?>"></script>

<script>
    const MODEL_URL = '<?= base_url("models") ?>';
    const video = document.getElementById('video');
    const btnScan = document.getElementById('btn-scan');
    const loadingBadge = document.getElementById('loading-badge');
    const statusText = document.getElementById('status-text');
    let currentDescriptor = null;

    async function loadModels() {
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
            await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            
            loadingBadge.className = "badge bg-success text-white";
            loadingBadge.innerHTML = "Sistem Siap";
            statusText.innerText = "Silakan hadapkan wajah ke kamera.";
            startCamera();
        } catch (err) {
            console.error(err);
            alert("Gagal memuat model AI. Cek path folder models!");
        }
    }

    function startCamera() {
        navigator.mediaDevices.getUserMedia({ video: {} })
            .then(stream => video.srcObject = stream)
            .catch(err => alert("Gagal akses kamera: " + err));
    }

    video.addEventListener('play', () => {
        const canvas = document.getElementById('overlay');
        const displaySize = { width: video.clientWidth, height: video.clientHeight };
        faceapi.matchDimensions(canvas, displaySize);

        setInterval(async () => {
            const detections = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (detections) {
                const resizedDetections = faceapi.resizeResults(detections, displaySize);
                canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                faceapi.draw.drawDetections(canvas, resizedDetections);
                
                if (detections.descriptor) {
                    currentDescriptor = detections.descriptor;
                    btnScan.disabled = false;
                    btnScan.innerHTML = '<i class="fe fe-check-circle me-2"></i> Wajah Terdeteksi! Simpan Sekarang';
                    btnScan.classList.remove('btn-primary');
                    btnScan.classList.add('btn-success');
                }
            } else {
                canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                btnScan.disabled = true;
                btnScan.innerHTML = 'Mencari Wajah...';
                btnScan.classList.remove('btn-success');
                btnScan.classList.add('btn-primary');
            }
        }, 500);
    });

    btnScan.addEventListener('click', () => {
        if (currentDescriptor) {
            const jsonDescriptor = JSON.stringify(Array.from(currentDescriptor));
            document.getElementById('input-descriptor').value = jsonDescriptor;
            document.getElementById('form-registrasi').submit();
        }
    });

    loadModels();
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (isset($_SESSION['gagal'])): ?>
    <script>
        Swal.fire({
            icon: 'warning',
            title: 'Perhatian',
            text: '<?= $_SESSION['gagal']; ?>',
            confirmButtonText: 'Baik, Saya Mengerti',
            confirmButtonColor: '#206bc4'
        });
    </script>
    <?php unset($_SESSION['gagal']); ?>
<?php endif; ?>

<?php 
require_once '../layout/footer.php'; 
?>