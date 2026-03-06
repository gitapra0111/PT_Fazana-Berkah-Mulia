<?php
// FILE: config.php
declare(strict_types=1);

/**
 * ===========================
 * 1) DEBUG & TIMEZONE
 * ===========================
 */
if (!defined('APP_DEBUG')) define('APP_DEBUG', true); // set false di production
if (APP_DEBUG) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
}
date_default_timezone_set('Asia/Jakarta');

/**
 * ===========================
 * 2) KONSTANTA NAMA SESI (PENTING!)
 *    Pisahkan "kamar" sesi per area
 * ===========================
 */
if (!defined('SESS_ADMIN'))   define('SESS_ADMIN',   'ADMINSESSID');
if (!defined('SESS_PEGAWAI')) define('SESS_PEGAWAI', 'PEGAWAISESSID');
if (!defined('SESS_AUTH'))    define('SESS_AUTH',    'AUTHSESSID');

/**
 * ===========================
 * 3) KONEKSI DATABASE (MySQLi)
 * ===========================
 */
$db_host = "localhost";   // XAMPP default
$db_user = "root";
$db_pass = "";
$db_name = "presensi";    // ganti sesuai DB Anda

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
  mysqli_set_charset($conn, 'utf8mb4');
} catch (Throwable $e) {
  http_response_code(500);
  die("Koneksi database gagal: " . $e->getMessage());
}

/**
 * ===========================
 * 4) UTIL: base_url() yang robust
 *    - Deteksi http/https
 *    - Hitung path proyek dari posisi config.php vs DOCUMENT_ROOT
 *    - Fallback aman di belakang proxy (HTTP_X_FORWARDED_PROTO)
 * ===========================
 */
if (!function_exists('str_starts_with')) {
  // Fallback untuk PHP < 8
  function str_starts_with($haystack, $needle) {
    return $needle === '' || strpos($haystack, $needle) === 0;
  }
}

if (!function_exists('base_url')) {
  /**
   * base_url('assets/css/app.css') -> http(s)://host/presensi/assets/css/app.css
   */
  function base_url(string $path = ''): string {
    // Skema
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
             || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';

    // Host
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Path proyek dari posisi file config.php terhadap DOCUMENT_ROOT
    $docRoot   = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')) : '';
    $configDir = str_replace('\\', '/', __DIR__);

    if ($docRoot !== '' && str_starts_with($configDir, $docRoot)) {
      // Contoh: C:/xampp/htdocs  +  C:/xampp/htdocs/presensi -> /presensi
      $projectPath = substr($configDir, strlen($docRoot));
      if ($projectPath === '') $projectPath = '/';
    } else {
      // Fallback berbasis SCRIPT_NAME
      $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
      // Coba cari segmen proyek bernama 'presensi' (boleh ganti jika nama folder beda)
      $seg = '/presensi';
      $pos = strpos($script, $seg);
      if ($pos !== false) {
        $projectPath = substr($script, 0, $pos + strlen($seg));
      } else {
        $projectPath = rtrim(dirname($script), '/\\');
        if ($projectPath === '') $projectPath = '/';
      }
    }

    $base = rtrim($scheme . '://' . $host . rtrim($projectPath, '/'), '/') . '/';
    return $path === '' ? $base : $base . ltrim($path, '/');
  }
}

/**
 * ===========================
 * 5) Helper aman HTML (opsional)
 * ===========================
 */
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
