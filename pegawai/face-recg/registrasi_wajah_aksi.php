<?php
declare(strict_types=1);

/* --- 1. SINKRONISASI SESSION --- */
if (session_status() === PHP_SESSION_ACTIVE) { 
    session_write_close(); 
}
session_name('PEGAWAISESSID'); 
session_start();

header('Content-Type: application/json');

/* --- 2. VALIDASI AKSES --- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.']);
    exit;
}

if (!isset($_SESSION['user']['login']) || $_SESSION['user']['role'] !== 'pegawai') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Sesi berakhir.']);
    exit;
}

require_once '../../config.php';

try {
    /* --- 3. TANGKAP INPUT --- */
    $id_pegawai = (int)$_SESSION['user']['id_pegawai'];
    $descriptor = $_POST['face_descriptor'] ?? '';
    
    // Kita abaikan $_POST['foto_wajah'] karena tidak ingin disimpan.

    // Validasi Kelengkapan Data Biometrik
    if (empty($descriptor)) {
        throw new Exception('Data pola wajah (descriptor) tidak diterima.');
    }

    // Validasi Format JSON
    json_decode($descriptor);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Format data wajah rusak.');
    }

    /* --- 4. UPDATE DATABASE (HANYA DESCRIPTOR) --- */
    // Perhatikan: Kita menghapus bagian "foto = ?" dari query
    $sql = "UPDATE pegawai SET face_descriptor = ? WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Database Error: " . $conn->error);
    }

    // Bind Parameter: s (string untuk descriptor), i (integer untuk id)
    $stmt->bind_param("si", $descriptor, $id_pegawai);

    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success', 
            'message' => 'Pola wajah berhasil diperbarui (Tanpa simpan foto).'
        ]);
    } else {
        throw new Exception('Gagal memperbarui database: ' . $stmt->error);
    }

    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage()
    ]);
}
?>