<?php
require_once __DIR__ . '/../config.php';

function kill_session_by_name(string $sessName, array $paths=['/']): void {
  if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
  session_name($sessName);
  session_start();
  $_SESSION = [];
  session_destroy();
  foreach ($paths as $p) {
    setcookie($sessName, '', time()-3600, $p);
  }
}

$area = strtolower($_GET['area'] ?? ''); // 'admin' | 'pegawai' | 'all'

// Deteksi fallback via referer bila area kosong
if ($area === '' && !empty($_SERVER['HTTP_REFERER'])) {
  $ref = $_SERVER['HTTP_REFERER'];
  if (strpos($ref, '/admin/') !== false)   $area = 'admin';
  if (strpos($ref, '/pegawai/') !== false) $area = 'pegawai';
}

switch ($area) {
  case 'admin':
    kill_session_by_name(SESS_ADMIN,   ['/', '/presensi/admin']);
    $redir = base_url('auth/login.php?pesan=logout');
    break;

  case 'pegawai':
    kill_session_by_name(SESS_PEGAWAI, ['/', '/presensi/pegawai']);
    $redir = base_url('auth/login.php?pesan=logout');
    break;

  case 'all': // hanya kalau kamu sengaja ingin keluar dari keduanya
    kill_session_by_name(SESS_ADMIN,   ['/', '/presensi/admin']);
    kill_session_by_name(SESS_PEGAWAI, ['/', '/presensi/pegawai']);
    $redir = base_url('auth/login.php?pesan=logout');
    break;

  default:
    // Jika tetap tak terdeteksi, JANGAN logout apa pun (biar aman)
    $redir = base_url('auth/login.php');
    break;
}

header("Location: {$redir}");
exit;
