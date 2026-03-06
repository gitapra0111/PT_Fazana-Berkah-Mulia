logic perlu perbaikan
bismillah
PEMBARUAN DI:
Pukul : 12.18
Tanggal: 26 FEBRUARI 2026

PEGAWAi :
home.php DONE

1. perubahan untuk keamanan waktu di baris 527
2. menambahkan waktu dinamis di home.php, agar saat admin merubah ke wit/wib/wita waktu tersebut sesuai peraturan dari admin 527-581

Perbaikan keamanan WAKTU di bagian :
#presensi_masuk.php DONE :

1. line 77
2. menghapus input button form jam masuk 307
3. 283 dan 316
4. jumlah baris 480

#presensi_masuk_aksi.php DONE:

1. 53-74
2. jumlah baris 190

#presensi_keluar.php DONE :

1. line 37-72
2. 247 dan 274
3. jumlah baris 430

#presensi_keluar_aksi.php DONE:

1. 66-89
2. jumlah baris 221

ADMIN :
#data_pegawai/tambah.php:
line 49-57 username uniq

#data_pegawai/edit.php:
62-79 username uniq

#presensi.php dan presensi_aksi.php

1. menambahkan logika jika fake gps terdeteksi maka tidak bisa menekan tombol akses masuk

catatan penting !!

Saat di deploy / hosting tambahkan file bernama .htaccess dengan isi :

<!-- RewriteEngine On
RewriteCond %{HTTP:X-Forwarded-Proto} !https
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L] -->
