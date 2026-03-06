<?php
// FILE: admin/absensi/presensi.php

// 1. CONFIG & SESSION
require_once '../../config.php'; 

if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
session_name(defined('SESS_ADMIN') ? SESS_ADMIN : 'ADMINSESSID');
session_start();

// 2. CEK LOGIN
if (!isset($_SESSION['user']['login']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../../auth/login.php");
    exit;
}

// 3. KONEKSI DB
$db = null;
if (isset($conn)) { $db = $conn; } 
elseif (isset($koneksi)) { $db = $koneksi; }

if (!$db) {
    die("Koneksi database gagal.");
}

$id_pegawai = $_SESSION['user']['id_pegawai'] ?? 0;

        // 4. AMBIL DATA LOKASI, JAM KERJA (MASUK & PULANG), ZONA WAKTU, & WAJAH
$sql = "SELECT p.nama, p.lokasi_presensi, p.face_descriptor, 
               l.latitude, l.longitude, l.radius, l.jam_masuk, l.jam_pulang, l.zona_waktu 
        FROM pegawai p 
        JOIN lokasi_presensi l ON p.lokasi_presensi = l.nama_lokasi 
        WHERE p.id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $id_pegawai);
$stmt->execute();
$data_pegawai = $stmt->get_result()->fetch_assoc();

if (!$data_pegawai) {
    die("Error: Data lokasi presensi belum diatur.");
}

if (empty($data_pegawai['face_descriptor'])) {
    $_SESSION['gagal'] = "Wajah belum terdaftar! Silakan registrasi dulu.";
    header("Location: ../face-recognition/registrasi_wajah.php?id=" . $id_pegawai);
    exit;
}

// =================================================================================
// 5. LOGIKA STATUS ABSENSI & VALIDASI JAM (MASUK & PULANG)
// =================================================================================
$zona_waktu = $data_pegawai['zona_waktu'] ?? 'WIB';

if ($zona_waktu === 'WIB') date_default_timezone_set('Asia/Jakarta');
elseif ($zona_waktu === 'WITA') date_default_timezone_set('Asia/Makassar');
elseif ($zona_waktu === 'WIT') date_default_timezone_set('Asia/Jayapura');

// SETELAH ZONA WAKTU BENAR, BARU KITA AMBIL TANGGAL DAN JAM SEKARANG
$tanggal_hari_ini = date('Y-m-d');
$jam_sekarang_str = date('H:i:s');

$mode_absen = 'masuk'; 
$status_info = '';
$tombol_disabled = false;
$pesan_error_waktu = ''; 
$tipe_error = ''; // 'kepagian' atau 'belum_pulang'

// Cek Data Presensi Hari Ini
// KODE LAMA
// $cek_sql = "SELECT id, jam_masuk, jam_keluar FROM presensi WHERE id_pegawai = ? AND tanggal_masuk = ?";
// GANTI MENJADI (Tambahkan foto_masuk, foto_keluar):
$cek_sql = "SELECT id, jam_masuk, jam_keluar, foto_masuk, foto_keluar FROM presensi WHERE id_pegawai = ? AND tanggal_masuk = ?";

$stmt_cek = $db->prepare($cek_sql);
$stmt_cek->bind_param('is', $id_pegawai, $tanggal_hari_ini);
$stmt_cek->execute();
$data_absen = $stmt_cek->get_result()->fetch_assoc();

if ($data_absen) {
    // === SUDAH ABSEN MASUK ===
    if ($data_absen['jam_keluar'] == NULL || $data_absen['jam_keluar'] == '00:00:00') {
        
        // Cek Apakah Sudah Jam Pulang?
        $jam_pulang_kantor = $data_pegawai['jam_pulang'];
        
        // Jika Jam Sekarang < Jam Pulang Kantor
        if (strtotime($jam_sekarang_str) < strtotime($jam_pulang_kantor)) {
            $mode_absen = 'tunggu_pulang'; // Mode Baru: Nunggu Pulang
            $status_info = "Belum waktunya pulang.";
            $pesan_error_waktu = "Absen PULANG baru dibuka pukul " . $jam_pulang_kantor . ".";
            $tipe_error = 'belum_pulang';
        } else {
            $mode_absen = 'keluar';
            $status_info = 'Anda sudah absen masuk pukul ' . $data_absen['jam_masuk'] . '. Silakan absen pulang.';
        }

    } else {
        // === SUDAH SELESAI ===
        $mode_absen = 'selesai';
        $status_info = 'Presensi hari ini sudah lengkap.';
    }

} else {
    // === BELUM ABSEN MASUK ===
    
    // Cek Apakah Kepagian? (30 Menit sebelum jam masuk)
    $jam_masuk_kantor = $data_pegawai['jam_masuk']; 
    $waktu_buka_absen = strtotime($jam_masuk_kantor) - (30 * 60); 

    if (strtotime($jam_sekarang_str) < $waktu_buka_absen) {
        $mode_absen = 'tunggu_masuk'; // Mode Baru: Nunggu Masuk
        $status_info = "Absensi belum dibuka.";
        $pesan_error_waktu = "Absensi MASUK baru dibuka pukul " . date('H:i', $waktu_buka_absen) . " (30 menit sebelum jam masuk).";
        $tipe_error = 'kepagian';
    } else {
        $mode_absen = 'masuk';
        $status_info = 'Silakan lakukan presensi masuk.';
    }
}

$judul="Presensi Harian Admin";

require_once '../layout/header.php'; 
?>
<div class="text-muted"><?= date('l, d F Y') ?> <span id="jam-realtime"></span></div>

<style>
    .webcam-capture-body { 
        position: relative; 
        width: 100%; 
        max-width: 400px; 
        margin: auto; 
        border-radius: 20px; 
        overflow: hidden; 
        border: 5px solid #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        aspect-ratio: 3/4;
        background: #000;
    }
    #video { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
    
    /* Overlay Oval Masking */
    .face-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 10; pointer-events: none; }
    .face-cutout { 
        width: 65%; height: 60%; border-radius: 50%; 
        box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.75), 0 0 20px rgba(0,0,0,0.5) inset;
        border: 3px solid rgba(255, 255, 255, 0.5);
        transition: all 0.4s;
    }
</style>
<!-- 
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title">Presensi Harian Admin</h2>
                <div class="text-muted"><?= date('l, d F Y') ?> <span id="jam-realtime"></span></div>
            </div>
        </div>
    </div>
</div> -->

<div class="page-body">
    <div class="container-xl">
        
        <?php if ($mode_absen === 'tunggu_masuk' || $mode_absen === 'tunggu_pulang'): ?>
            <div class="alert alert-warning mb-3" role="alert">
                <div class="d-flex">
                    <div>
                        <i class="fe fe-clock me-2" style="font-size: 1.5rem;"></i>
                    </div>
                    <div>
                        <h4 class="alert-title">Belum Waktunya!</h4>
                        <div class="text-secondary"><?= $pesan_error_waktu ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mode_absen !== 'tunggu_masuk' && $mode_absen !== 'tunggu_pulang'): ?>
        <div class="alert <?= ($mode_absen == 'selesai') ? 'alert-success' : 'alert-info' ?> mb-3">
            <div class="d-flex">
                <div>
                    <h4 class="alert-title">Status: <?= strtoupper($mode_absen) ?></h4>
                    <div class="text-secondary"><?= $status_info ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mode_absen === 'selesai'): ?>
    
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title">
                        <i class="fe fe-clipboard me-2"></i>Laporan Presensi Hari Ini
                    </h3>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h2 class="text-success">Presensi Tuntas!</h2>
                        <p class="text-muted">Berikut adalah rekam jejak kehadiran Anda hari ini.</p>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card border-success">
                                <div class="card-body text-center p-3">
                                    <div class="badge bg-success mb-2 w-100">MASUK</div>
                                    <div class="mb-3">
                                        <img src="<?= base_url('assets/uploads/presensi/' . $data_absen['foto_masuk']) ?>" 
                                             class="img-fluid rounded border border-3 border-success p-1" 
                                             style="height: 150px; width: 150px; object-fit: cover;">
                                    </div>
                                    <h2 class="mb-0 fw-bold"><?= $data_absen['jam_masuk'] ?></h2>
                                    <small class="text-muted"><i class="fe fe-map-pin me-1"></i>Kantor Pusat</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card border-danger">
                                <div class="card-body text-center p-3">
                                    <div class="badge bg-danger mb-2 w-100">PULANG</div>
                                    <div class="mb-3">
                                        <img src="<?= base_url('assets/uploads/presensi/' . $data_absen['foto_keluar']) ?>" 
                                             class="img-fluid rounded border border-3 border-danger p-1" 
                                             style="height: 150px; width: 150px; object-fit: cover;">
                                    </div>
                                    <h2 class="mb-0 fw-bold"><?= $data_absen['jam_keluar'] ?></h2>
                                    <small class="text-muted"><i class="fe fe-map-pin me-1"></i>Kantor Pusat</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top">
                        <div class="row g-2">
                            <div class="col-12">
                                <a href="../home/home.php" class="btn btn-primary w-100 btn-lg">
                                    <i class="fe fe-home me-2"></i> Kembali ke Dashboard
                                </a>
                            </div>
                            
                            <div class="col-12">
                                <a href="../face-recognition/registrasi_wajah.php?id=<?= $id_pegawai ?>" class="btn btn-outline-secondary w-100">
                                    <i class="fe fe-camera me-2"></i> Registrasi Ulang Wajah
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

<?php else: ?>
            <div class="row row-cards">
                
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">1. Verifikasi Wajah</h3>
                        </div>
                        <div class="card-body text-center">
                            <div class="webcam-capture-body">
                                <video id="video" autoplay muted playsinline></video>
                                <div class="face-overlay">
                                    <div id="face-cutout" class="face-cutout"></div>
                                </div>
                                </div>
                            
                            <div class="mt-3">
                                <span id="loading-model" class="badge bg-warning text-white spinner-border-sm">
                                    <span class="spinner-border spinner-border-sm me-1"></span> Memuat AI...
                                </span>
                                <div id="face-status" class="mt-2 text-muted fw-bold">Menunggu wajah...</div>
                            </div>

                            <div class="mt-3">

                            <form action="presensi_aksi.php" method="POST" id="form-absen">
                                <input type="hidden" name="latitude" id="input-lat">
                                <input type="hidden" name="longitude" id="input-long">
                                <input type="hidden" name="foto_base64" id="input-foto">
                                <input type="hidden" name="accuracy" id="input-accuracy">
                                
                                <?php
                                    $value_tipe = 'masuk';
                                    if ($mode_absen == 'keluar' || $mode_absen == 'tunggu_pulang') {
                                        $value_tipe = 'keluar';
                                    }
                                ?>
                                <input type="hidden" name="tipe_absen" value="<?= $value_tipe ?>"> 
                                
                                <button type="button" id="btn-absen" class="btn w-100 btn-lg btn-secondary" disabled>
                                    <i class="fe fe-lock me-2"></i> Akses Terkunci
                                </button>
                            </form>
                            </div>

                            <div class="mt-4 pt-3 border-top">
                                <a href="../face-recognition/verifikasi_wajah.php?id=<?= $id_pegawai ?>" class="btn btn-outline-secondary w-100 btn-sm">
                                    <i class="fe fe-settings me-2"></i> Atur Ulang Wajah
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">2. Detail Jadwal</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td>Jam Masuk</td>
                                    <td>: <span class="badge bg-green-lt" style="color: #000 !important; font-weight: bold; font-size: 14px;"><?= $data_pegawai['jam_masuk'] ?></span></td>
                                </tr>
                                <tr>
                                    <td>Jam Pulang</td>
                                    <td>: <span class="badge bg-red-lt" style="color: #000 !important; font-weight: bold; font-size: 14px;"><?= $data_pegawai['jam_pulang'] ?></span></td>
                                </tr>
                                <tr>
                                    <td>Lokasi</td>
                                    <td>: <?= htmlspecialchars($data_pegawai['lokasi_presensi']) ?></td>
                                </tr>
                                <tr>
                                    <td>Posisi Anda</td>
                                    <td>: <span id="jarak-result" class="text-danger fw-bold">Mendeteksi GPS...</span></td>
                                </tr>
                            </table>

                            <div id="lokasi-alert" class="alert alert-warning">Sedang mencari koordinat GPS...</div>

                            
                        </div>
                    </div>
                </div>

            </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($mode_absen !== 'selesai'): ?>
<script src="<?= base_url('assets/js/face-api.min.js') ?>"></script>

<script>
    const MODEL_URL = '<?= base_url("models") ?>';
    const LAT_KANTOR = <?= $data_pegawai['latitude'] ?>;
    const LNG_KANTOR = <?= $data_pegawai['longitude'] ?>;
    const RADIUS_IZIN = <?= $data_pegawai['radius'] ?>;
    const USER_DESCRIPTOR = <?= $data_pegawai['face_descriptor'] ? $data_pegawai['face_descriptor'] : 'null' ?>;
    
    // PHP Variables ke JS
    const MODE_ABSEN = '<?= $mode_absen ?>'; 

    const video = document.getElementById('video');
    const btnAbsen = document.getElementById('btn-absen');
    const statusFace = document.getElementById('face-status');
    const loadingBadge = document.getElementById('loading-model');
    
    let isFaceValid = false;
    let isLocationValid = false;
    let isFakeGPS = false; // <-- TAMBAHAN BARU

    // 1. FACE API (Sama seperti sebelumnya)
    async function startFaceRecognition() {
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
            await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
            await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
            loadingBadge.className = "badge bg-success text-white";
            loadingBadge.innerHTML = "Sistem AI Siap";
            const stream = await navigator.mediaDevices.getUserMedia({ video: {} });
            video.srcObject = stream;
        } catch (err) { console.error(err); alert("Gagal memuat model Wajah."); }
    }

    video.addEventListener('play', () => {
        const faceCutout = document.getElementById('face-cutout');

        async function scanWajah() {
            if (video.paused || video.ended) return;

            const detect = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                                        .withFaceLandmarks().withFaceDescriptor();

            if (detect) {
                const descriptorDB = new Float32Array(Object.values(USER_DESCRIPTOR));
                const distance = faceapi.euclideanDistance(detect.descriptor, descriptorDB);
                const confidenceScore = detect.detection.score;

                // Hitung Rasio Wajah (Keamanan agar tidak dianggap wajah jika terlalu jauh)
                const faceWidth = detect.detection.box.width;
                const videoWidth = video.videoWidth;
                const faceRatio = faceWidth / videoWidth;

                // Logika Validasi: Tajam (>0.70), Mirip (<0.45), dan Cukup Dekat (>0.20)
                if (confidenceScore > 0.70 && distance < 0.45 && faceRatio > 0.20) {
                    isFaceValid = true;
                    statusFace.innerHTML = `<span class="text-success"><i class="fe fe-check-circle me-1"></i> Wajah Terverifikasi</span>`;
                    
                    // Efek Bingkai Hijau
                    faceCutout.style.borderColor = "#28a745";
                    faceCutout.style.boxShadow = "0 0 0 9999px rgba(0, 0, 0, 0.70), 0 0 25px rgba(40, 167, 69, 0.6) inset";
                } else {
                    isFaceValid = false;
                    
                    if (faceRatio <= 0.20) {
                        statusFace.innerHTML = `<span class="text-warning">Dekatkan wajah ke area oval!</span>`;
                    } else if (confidenceScore <= 0.70) {
                        statusFace.innerHTML = `<span class="text-warning">Wajah kurang jelas / Gelap!</span>`;
                    } else {
                        statusFace.innerHTML = `<span class="text-danger">Wajah Tidak Cocok!</span>`;
                    }

                    // Efek Bingkai Merah
                    faceCutout.style.borderColor = "#dc3545";
                    faceCutout.style.boxShadow = "0 0 0 9999px rgba(0, 0, 0, 0.70), 0 0 25px rgba(220, 53, 69, 0.6) inset";
                }
            } else {
                isFaceValid = false;
                statusFace.innerHTML = `<span class="text-muted">Arahkan wajah ke dalam area...</span>`;
                
                // Reset Bingkai ke Putih Transparan
                faceCutout.style.borderColor = "rgba(255, 255, 255, 0.5)";
                faceCutout.style.boxShadow = "0 0 0 9999px rgba(0, 0, 0, 0.70), 0 0 20px rgba(0,0,0,0.5) inset";
            }

            checkRequirements(); // Update status tombol absen
            setTimeout(scanWajah, 600); // Looping ringan
        }

        scanWajah();
    });

    // 2. GEOLOCATION (Sama seperti sebelumnya)
    function getDistance(lat1, lon1, lat2, lon2) {
        const R = 6371e3; const dLat = (lat2 - lat1) * Math.PI / 180; const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon/2) * Math.sin(dLon/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    if (navigator.geolocation) {
        navigator.geolocation.watchPosition((position) => {
            const lat = position.coords.latitude; 
            const long = position.coords.longitude;
            const accuracy = position.coords.accuracy;
            const jarak = getDistance(lat, long, LAT_KANTOR, LNG_KANTOR);

            document.getElementById('jarak-result').innerText = Math.round(jarak) + " Meter";
            document.getElementById('input-lat').value = lat; document.getElementById('input-long').value = long;
            // <-- TAMBAHAN: Masukkan nilai ke form hidden
            const inputAcc = document.getElementById('input-accuracy');
            if(inputAcc) inputAcc.value = accuracy;

            // --- LOGIKA DETEKSI FAKE GPS ---
            if (accuracy <= 1.0 && accuracy > 0) {
                isFakeGPS = true;
                isLocationValid = false;
                document.getElementById('lokasi-alert').className = "alert alert-danger";
                document.getElementById('lokasi-alert').innerHTML = "<i class='fe fe-alert-triangle me-1'></i> <b>Terdeteksi Fake GPS!</b> (Akurasi: " + accuracy + "m)";
            } 
            // --- JIKA AMAN, CEK RADIUS ---
            else {
                isFakeGPS = false; // Reset jika tiba-benar aman
            }
            
            if (jarak <= RADIUS_IZIN) {
                document.getElementById('lokasi-alert').className = "alert alert-success";
                document.getElementById('lokasi-alert').innerHTML = "Lokasi <b>Valid</b>";
                isLocationValid = true;
            } else {
                document.getElementById('lokasi-alert').className = "alert alert-danger";
                document.getElementById('lokasi-alert').innerHTML = "Lokasi <b>Invalid</b>";
                isLocationValid = false;
            }
            checkRequirements();
        }, (err) => { alert("Gagal mengambil lokasi: " + err.message); }, {
            enableHighAccuracy: true,
            maximumAge: 0
        });
    }

    // 3. LOGIKA TOMBOL ABSEN (DENGAN BLOKIR WAKTU)
    function checkRequirements() {
        // Jika mode Tunggu (Entah itu tunggu masuk atau tunggu pulang), matikan tombol
        if (MODE_ABSEN === 'tunggu_masuk' || MODE_ABSEN === 'tunggu_pulang') {
            btnAbsen.disabled = true;
            btnAbsen.className = "btn w-100 btn-lg btn-secondary";
            
            if(MODE_ABSEN === 'tunggu_masuk') btnAbsen.innerHTML = '<i class="fe fe-clock me-2"></i> BELUM WAKTUNYA MASUK';
            else btnAbsen.innerHTML = '<i class="fe fe-clock me-2"></i> BELUM WAKTUNYA PULANG';
            
            return;
        }

        // Jika terdeteksi Fake GPS, KUNCI TOMBOL dan JADIKAN MERAH
        if (isFakeGPS) {
            btnAbsen.disabled = true;
            btnAbsen.innerHTML = '<i class="fe fe-alert-triangle me-2"></i> FAKE GPS TERDETEKSI';
            btnAbsen.className = "btn btn-danger w-100 btn-lg";
            return; // Hentikan pengecekan selanjutnya
        }

        // Jika Waktu Valid, baru cek Wajah & Lokasi
        if (isFaceValid && isLocationValid) {
            btnAbsen.disabled = false;
            if(MODE_ABSEN === 'masuk') {
                btnAbsen.innerHTML = '<i class="fe fe-check-circle me-2"></i> KONFIRMASI MASUK';
                btnAbsen.className = "btn btn-success w-100 btn-lg";
            } else if (MODE_ABSEN === 'keluar') {
                btnAbsen.innerHTML = '<i class="fe fe-check-circle me-2"></i> KONFIRMASI PULANG';
                btnAbsen.className = "btn btn-danger w-100 btn-lg";
            }
        } else {
            btnAbsen.disabled = true;
            btnAbsen.innerHTML = 'Menunggu Wajah & Lokasi...';
            btnAbsen.className = "btn btn-secondary w-100 btn-lg";
        }
    }

    btnAbsen.addEventListener('click', () => {
        btnAbsen.disabled = true;
        statusFace.innerHTML = "Memproses kehadiran...";

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth; 
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        
        // --- LOGIKA MIRRORING: Agar foto tidak terbalik ---
        ctx.translate(canvas.width, 0); 
        ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0);
        
        // Simpan hasil foto ke input hidden
        document.getElementById('input-foto').value = canvas.toDataURL('image/jpeg', 0.9);
        
        // Kirim form
        document.getElementById('form-absen').submit();
    });

    startFaceRecognition();

   // ===== LIVE CLOCK UI (KEBAL MANIPULASI & FULL ZONASI) =====
    const serverTimeMs = <?= time() * 1000 ?>;
    const startPerf = performance.now();
    const tzString = '<?= ($zona_waktu === "WITA") ? "Asia/Makassar" : (($zona_waktu === "WIT") ? "Asia/Jayapura" : "Asia/Jakarta") ?>';

    setInterval(() => {
        const now = new Date(serverTimeMs + (performance.now() - startPerf));
        const options = { 
            timeZone: tzString,
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false 
        };
        const t = new Intl.DateTimeFormat('id-ID', options).format(now).replace(/\./g, ':');
        document.getElementById('jam-realtime').innerText = t;
    }, 1000);
</script>
<?php endif; ?>

<?php require_once '../layout/footer.php'; ?>


