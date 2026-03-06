<?php
declare(strict_types=1);

/* --- 1. SESSION & AUTH --- */
if (session_status() === PHP_SESSION_ACTIVE) { 
    session_write_close(); 
}
session_name('PEGAWAISESSID');
session_start();

if (!isset($_SESSION['user']['login']) || ($_SESSION['user']['role'] ?? '') !== 'pegawai') {
    header("Location: ../../auth/login.php?pesan=tolak_akses"); 
    exit;
}

require_once '../../config.php'; 
$base_url = base_url(); 
$home_url = $base_url . 'pegawai/home/home.php';

/* --- 2. AMBIL DATA BIOMETRIK DARI DB --- */
$id_pegawai = (int)$_SESSION['user']['id_pegawai'];
$query_wajah = mysqli_query($conn, "SELECT face_descriptor FROM pegawai WHERE id = '$id_pegawai'");
$data_wajah = mysqli_fetch_assoc($query_wajah);
$descriptor_db = $data_wajah['face_descriptor'] ?? '';

if (empty($descriptor_db)) {
    echo "<script>alert('Anda belum mendaftarkan biometrik wajah!'); window.location.href='../face-recg/registrasi_wajah.php';</script>";
    exit;
}

/* --- 3. TANGKAP KOORDINAT DARI HOME --- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tombol_keluar'])) {
    header("Location: ../home/home.php"); exit;
}

// =========================================================================
// --- SATPAM WAKTU: BLOKIR JIKA MEMBOBOL TOMBOL SEBELUM JAM PULANG ---
// =========================================================================
$lokasi_presensi = $_SESSION['user']['lokasi_presensi'] ?? '';
$query_lokasi = mysqli_query($conn, "SELECT jam_pulang, zona_waktu FROM lokasi_presensi WHERE nama_lokasi = '$lokasi_presensi'");
$data_lokasi = mysqli_fetch_assoc($query_lokasi);

if ($data_lokasi) {
    // Sesuaikan Zona Waktu
    $zona = $data_lokasi['zona_waktu'];
    if ($zona === 'WIB') date_default_timezone_set('Asia/Jakarta');
    elseif ($zona === 'WITA') date_default_timezone_set('Asia/Makassar');
    elseif ($zona === 'WIT') date_default_timezone_set('Asia/Jayapura');

    // Cek Jam Server vs Jam Pulang Kantor
    $waktu_sekarang = time();
    $jam_pulang_master = $data_lokasi['jam_pulang'];
    $jamPulangToday = strtotime(date('Y-m-d') . ' ' . $jam_pulang_master);

    // BLOKIR JIKA JAM SEKARANG MASIH KURANG DARI JAM PULANG
    if ($waktu_sekarang < $jamPulangToday) {
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script></head>
        <body><script>
            Swal.fire({
                title: 'Belum Waktunya Pulang!',
                html: 'Anda tidak bisa pulang lebih awal.<br>Jam pulang Anda adalah pukul <b><?= $jam_pulang_master ?></b>.',
                icon: 'error',
                allowOutsideClick: false
            }).then(() => { window.location.href = '<?= $home_url ?>'; });
        </script></body></html>
        <?php exit;
    }
}
// =========================================================================

$latPegawai   = (float)($_POST['latitude_pegawai'] ?? 0);
$lngPegawai   = (float)($_POST['longitude_pegawai'] ?? 0);
$latKantor    = (float)($_POST['latitude_kantor'] ?? 0);
$lngKantor    = (float)($_POST['longitude_kantor'] ?? 0);
$radiusMeter  = (float)($_POST['radius'] ?? 0);

// --- TAMBAHAN 1: TANGKAP AKURASI ---
$accuracy = (float)($_POST['accuracy'] ?? 0); 

// --- TAMBAHAN 2: CEK ANTI-FAKE GPS ---
if ($accuracy <= 1.0 && $accuracy > 0) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>body { font-family: sans-serif; background: #f4f6fa; }</style>
    </head>
    <body>
        <script>
            Swal.fire({
                title: 'Terdeteksi Lokasi Palsu!',
                text: 'Akurasi GPS Anda terlalu sempurna (<?= $accuracy ?>m). Sistem mendeteksi penggunaan Fake GPS.',
                icon: 'error',
                confirmButtonText: 'Kembali',
                allowOutsideClick: false
            }).then(() => { window.location.href = '<?= $home_url ?>'; });
        </script>
    </body>
    </html>
    <?php
    exit; // STOP SCRIPT DISINI
}

// Hitung Jarak (Server-side validation)
function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000.0;
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1); $dlmb = deg2rad($lon2 - $lon1);
    $a = sin($dphi/2) ** 2 + cos($phi1) * cos($phi2) * sin($dlmb/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
$jarakMeter = haversine_m($latPegawai, $lngPegawai, $latKantor, $lngKantor);

// Proteksi Radius
if ($jarakMeter > $radiusMeter) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script></head>
    <body><script>
        Swal.fire({
            title: 'Di Luar Radius!',
            html: 'Jarak Anda: <b><?= number_format($jarakMeter, 1) ?>m</b>.<br>Maksimal: <?= $radiusMeter ?>m',
            icon: 'error',
            confirmButtonText: 'Kembali'
        }).then(() => { window.location.href = '<?= $home_url ?>'; });
    </script></body></html>
    <?php exit;
}

$jamKeluar = date('H:i:s');

/* --- 4. HUBUNGKAN HEADER --- */
$judul = "Presensi Keluar";
require_once __DIR__ . '/../layout/header.php'; 
?>

<script>
    if (typeof define === 'function' && define.amd) {
        window._tempDefine = define;
        define = null; 
    }
</script>

<script src="<?= $base_url ?>assets/js/face-api.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    if (typeof _tempDefine === 'function') {
        define = _tempDefine; 
    }
</script>

<style>
    /* --- CSS GLOBAL --- */
    body { background-color: #f4f6fa !important; }
    .card-bio { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow: hidden; }
    #map-box { height: 320px; border-radius: 15px; border: 1px solid #e6e8eb; z-index: 1; }
    
    /* --- CSS KAMERA & MASKING --- */
    #cam-box { 
        position: relative; 
        border-radius: 20px; 
        overflow: hidden; 
        background: #000; 
        aspect-ratio: 3/4; 
        border: 5px solid #fff; 
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        width: 100%;
        max-width: 400px; /* Mencegah kamera raksasa */
        margin: 0 auto; /* Posisi tengah */
    }
    
    video { 
        width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); 
    }

    .face-overlay {
        position: absolute;
        inset: 0; 
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
        pointer-events: none; 
    }

    .face-cutout {
        width: 60%;
        height: 55%;
        border-radius: 50%; 
        box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.75), 0 0 20px rgba(0,0,0,0.5) inset;
        border: 3px solid rgba(255, 255, 255, 0.5);
        position: relative;
        overflow: hidden;
        transition: all 0.4s ease-in-out;
    }
</style>

<div class="page-body">
    <div class="container-xl">
        <div class="row row-cards justify-content-center">
            
            <div class="col-lg-5">
                <div class="card card-bio">
                    <div class="card-header">
                        <h3 class="card-title text-danger fw-bold"><i class="ti ti-logout me-2"></i> Konfirmasi Pulang</h3>
                    </div>
                    <div class="card-body text-center">
                        <div id="status-ai" class="alert alert-info py-2 small mb-3">
                            <span class="spinner-border spinner-border-sm me-2"></span> Menyiapkan Sistem...
                        </div>
                        <div id="cam-box">
                            <video id="vSource" autoplay muted playsinline></video>
                            
                            <div class="face-overlay">
                                <div id="face-cutout" class="face-cutout"></div>
                            </div>
                        </div>
                        <button id="btnSubmit" class="btn btn-danger btn-lg w-100 mt-4 shadow-sm" disabled>
                            Ambil Foto & Pulang
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card card-bio">
                    <div class="card-body">
                        <div class="row g-2 mb-3 text-center">
                            <div class="col-6">
                                <div class="p-2 border rounded bg-light">
                                    <small class="text-muted d-block">Waktu</small>
                                    <div class="h3 mb-0 text-danger" id="liveClockKeluar"><?= $jamKeluar ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 border rounded bg-light">
                                    <small class="text-muted d-block">Jarak</small>
                                    <div class="h3 mb-0"><?= number_format($jarakMeter, 1) ?>m</div>
                                </div>
                            </div>
                        </div>
                        <div id="map-box"></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<form id="formAction" method="post" action="presensi_keluar_aksi.php">
    <input type="hidden" name="foto_base64" id="foto_base64">
    <input type="hidden" name="lat_keluar" value="<?= $latPegawai ?>">
    <input type="hidden" name="lng_keluar" value="<?= $lngPegawai ?>">
    <input type="hidden" name="accuracy" value="<?= $accuracy ?>">
</form>

<script>
document.addEventListener('DOMContentLoaded', async function() {
    const video = document.getElementById('vSource');
    const btn = document.getElementById('btnSubmit');
    const info = document.getElementById('status-ai');

    // ===== LIVE CLOCK UI (KEBAL MANIPULASI) NEW =====
    // ===== LIVE CLOCK UI (KEBAL MANIPULASI & FULL ZONASI) NEW =====
    const serverTimeMsKeluar = <?= time() * 1000 ?>;
    const startPerfKeluar = performance.now();
    
    // Ambil zona waktu dari PHP (default Asia/Jakarta)
    const tzString = '<?= ($zona === "WITA") ? "Asia/Makassar" : (($zona === "WIT") ? "Asia/Jayapura" : "Asia/Jakarta") ?>';

    setInterval(() => {
        const now = new Date(serverTimeMsKeluar + (performance.now() - startPerfKeluar));
        
        // Paksa format waktu sesuai zona dari database
        const options = { 
            timeZone: tzString,
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false 
        };
        
        const t = new Intl.DateTimeFormat('id-ID', options).format(now).replace(/\./g, ':');
        const clockUI = document.getElementById('liveClockKeluar');
        if (clockUI) clockUI.textContent = t;
    }, 1000);

    // [FIX 2] MAP INITIALIZATION (Fix White Box & Radius)
    const map = L.map('map-box').setView([<?= $latPegawai ?>, <?= $lngPegawai ?>], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
    
    L.marker([<?= $latKantor ?>, <?= $lngKantor ?>]).addTo(map).bindPopup("Kantor PT. Fazana");
    L.marker([<?= $latPegawai ?>, <?= $lngPegawai ?>]).addTo(map).bindPopup("Lokasi Anda").openPopup();
    L.circle([<?= $latKantor ?>, <?= $lngKantor ?>], { radius: <?= $radiusMeter ?>, color: 'red', fillOpacity: 0.1 }).addTo(map);

    // Paksa peta merender ulang agar tidak putih
    setTimeout(() => { map.invalidateSize(); }, 800);

   // [FIX 3] LOAD MODELS & SCAN REAL-TIME
    try {
        const descriptorDB = new Float32Array(Object.values(<?= $descriptor_db ?>));
        const MODEL_URL = '<?= $base_url ?>models';
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
        ]);
        
        const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640 } });
        video.srcObject = stream;
        
        info.innerHTML = "<span class='spinner-border spinner-border-sm me-2'></span>Kamera siap, mulai memindai wajah...";
        info.className = "alert alert-warning py-2 small mb-3";

        // MENDETEKSI WAJAH SECARA OTOMATIS DAN TERUS MENERUS
        video.addEventListener('play', () => {
            const faceCutout = document.getElementById('face-cutout');
            
            async function scanWajah() {
                if (video.paused || video.ended) return; 

                const detect = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                                            .withFaceLandmarks().withFaceDescriptor();

                if (detect) {
                    const distance = faceapi.euclideanDistance(detect.descriptor, descriptorDB);
                    const confidenceScore = detect.detection.score; 

                    // --- SISIPKAN 3 BARIS INI ---
                    const faceWidth = detect.detection.box.width; // Lebar kotak deteksi wajah
                    const videoWidth = video.videoWidth;          // Lebar total tampilan video
                    const faceRatio = faceWidth / videoWidth;     // Rasio ukuran wajah terhadap layar
                    
                    // if (confidenceScore > 0.70 && distance < 0.45) { 

                    // Sekarang ada 3 filter: Tajam (>0.70), Mirip (<0.45), dan Cukup Dekat (>0.20)
                    if (confidenceScore > 0.70 && distance < 0.45 && faceRatio > 0.20) {
                        // WAJAH COCOK
                        btn.disabled = false;
                        info.innerHTML = "<i class='ti ti-face-id me-2'></i> Wajah Valid. Klik Ambil Foto.";
                        info.className = "alert alert-success py-2 small mb-3";
                        
                        faceCutout.style.borderColor = "#28a745";
                        faceCutout.style.boxShadow = "0 0 0 9999px rgba(0, 0, 0, 0.75), 0 0 25px rgba(40, 167, 69, 0.6) inset";
                    } else {
                        btn.disabled = true;
                        
                        // Cek apakah alasannya karena wajah terlalu kecil (terlalu jauh)
                        if (faceRatio <= 0.20) {
                            info.innerHTML = "<i class='ti ti-zoom-in me-2'></i> Dekatkan wajah Anda ke area oval!";
                        } else if (confidenceScore <= 0.70) {
                            info.innerHTML = "<i class='ti ti-alert-triangle me-2'></i> Wajah kurang jelas / Cahaya kurang!";
                        } else {
                            info.innerHTML = "<i class='ti ti-alert-triangle me-2'></i> Wajah tidak cocok!";
                        }
                        info.className = "alert alert-danger py-2 small mb-3";
                        
                        faceCutout.style.borderColor = "#dc3545";
                        faceCutout.style.boxShadow = "0 0 0 9999px rgba(0, 0, 0, 0.75), 0 0 25px rgba(220, 53, 69, 0.6) inset";
                    }



                } else {
                    // TIDAK ADA WAJAH
                    btn.disabled = true;
                    info.innerHTML = "<span class='spinner-border spinner-border-sm me-2'></span> Arahkan wajah ke dalam area...";
                    info.className = "alert alert-warning py-2 small mb-3";
                    
                    faceCutout.style.borderColor = "rgba(255, 255, 255, 0.5)";
                    faceCutout.style.boxShadow = "0 0 0 9999px rgba(0, 0, 0, 0.75), 0 0 20px rgba(0,0,0,0.5) inset";
                }

                setTimeout(scanWajah, 600); 
            }
            scanWajah();
        });

    } catch (e) { 
        info.innerHTML = "Gagal memuat AI. Pastikan izin kamera aktif.";
        info.className = "alert alert-danger py-2 small mb-3";
    }

    // [LOGIC] Submit (Ambil Foto & Pulang)
    btn.addEventListener('click', () => {
        btn.disabled = true; // Kunci tombol agar tidak diklik 2x
        info.innerHTML = "Memproses kehadiran pulang...";
        info.className = "alert alert-info py-2 small mb-3";

        // Langsung ambil gambar dari video yang sedang berjalan
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth; 
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        
        // Mirror gambar agar tidak terbalik
        ctx.translate(canvas.width, 0); 
        ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0);
        
        // Simpan ke input hidden
        document.getElementById('foto_base64').value = canvas.toDataURL('image/jpeg', 0.9);
        
        Swal.fire({
            title: "Verifikasi Berhasil!", 
            text: "Sedang menyimpan data presensi pulang...", 
            icon: "success", 
            showConfirmButton: false, 
            timer: 1500
        }).then(() => {
            document.getElementById('formAction').submit();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>


 <!-- berjalan dengan baik -->
 <!-- presnsi_keluar FIX -->