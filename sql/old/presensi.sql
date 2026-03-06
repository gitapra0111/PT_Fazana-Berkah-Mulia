-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 08, 2026 at 02:39 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `presensi`
--

-- --------------------------------------------------------

--
-- Table structure for table `jabatan`
--

CREATE TABLE `jabatan` (
  `id` int NOT NULL,
  `jabatan` varchar(225) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jabatan`
--

INSERT INTO `jabatan` (`id`, `jabatan`) VALUES
(2, 'Admin'),
(7, 'Teknik'),
(8, 'K3'),
(9, 'SPV'),
(10, 'Teknisi');

-- --------------------------------------------------------

--
-- Table structure for table `ketidakhadiran`
--

CREATE TABLE `ketidakhadiran` (
  `id` int NOT NULL,
  `id_pegawai` int NOT NULL,
  `keterangan` enum('Cuti','Izin','Sakit','Dinas Luar') COLLATE utf8mb4_general_ci NOT NULL,
  `tanggal` date NOT NULL,
  `deskripsi` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `file` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status_pengajuan` enum('PENDING','DISETUJUI','DITOLAK') COLLATE utf8mb4_general_ci DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lokasi_presensi`
--

CREATE TABLE `lokasi_presensi` (
  `id` int NOT NULL,
  `nama_lokasi` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `alamat_lokasi` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `tipe_lokasi` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `latitude` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `longitude` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `radius` int NOT NULL,
  `zona_waktu` varchar(4) COLLATE utf8mb4_general_ci NOT NULL,
  `jam_masuk` time NOT NULL,
  `jam_pulang` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lokasi_presensi`
--

INSERT INTO `lokasi_presensi` (`id`, `nama_lokasi`, `alamat_lokasi`, `tipe_lokasi`, `latitude`, `longitude`, `radius`, `zona_waktu`, `jam_masuk`, `jam_pulang`) VALUES
(8, 'Kantor Pusat', 'PLTU SUDIMORO', 'pusat', '-8.25814902840356', '111.37391054215081', 1800, 'WIB', '08:00:00', '01:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `pegawai`
--

CREATE TABLE `pegawai` (
  `id` int NOT NULL,
  `nip` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `nama` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `jenis_kelamin` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `alamat` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `no_handphone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `jabatan` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `lokasi_presensi` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `foto` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `face_descriptor` text COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pegawai`
--

INSERT INTO `pegawai` (`id`, `nip`, `nama`, `jenis_kelamin`, `alamat`, `no_handphone`, `jabatan`, `lokasi_presensi`, `foto`, `face_descriptor`) VALUES
(1, 'PEG-0001', 'Rosyid', 'Laki-laki', 'Pacitan', '0888888888', 'Admin', 'Kantor Pusat', '', NULL),
(3, 'PEG-0003', 'Enggareta Aryahadi', 'Laki-laki', 'Pacitan', '08080808008', 'K3', 'Kantor Pusat', '', NULL),
(4, 'PEG-0004', 'Oky Ardiyanto', 'Laki-laki', 'Pacitan', '08080808080', 'SPV', 'Kantor Pusat', '', NULL),
(5, 'PEG-0005', 'Tommy Adi Pratama', 'Laki-laki', 'Pacitan', '0808080808', 'K3', 'Kantor Pusat', '', NULL),
(6, 'PEG-0006', 'Imam Mujib', 'Laki-laki', 'Pacitan', '0808080808', 'Teknisi', 'Kantor Pusat', '', NULL),
(7, 'PEG-0007', 'Ndolis Saputra', 'Laki-laki', 'Pacitan', '08080808080', 'Teknisi', 'Kantor Pusat', '', NULL),
(14, 'PEG-0008', 'Ivan Rizqi Pangestu', 'Laki-laki', 'Pacitan', '083851999263', 'Teknisi', 'Kantor Pusat', 'peg_14_1770458966.jpg', NULL),
(15, 'PEG-0009', 'Zainuddin', 'Laki-laki', 'Pacitan', '08080863573', 'Teknisi', 'Kantor Pusat', '', NULL),
(16, 'PEG-0010', 'SAGITA PRA KOSA', 'Laki-laki', 'Pacitan', '082317996089', 'Admin', 'Kantor Pusat', '', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `presensi`
--

CREATE TABLE `presensi` (
  `id` int NOT NULL,
  `id_pegawai` int NOT NULL,
  `tanggal_masuk` date NOT NULL,
  `jam_masuk` time NOT NULL,
  `foto_masuk` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `latitude_masuk` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `longitude_masuk` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tanggal_keluar` date DEFAULT NULL,
  `jam_keluar` time DEFAULT NULL,
  `foto_keluar` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `latitude_keluar` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `longitude_keluar` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `id_pegawai` int NOT NULL,
  `username` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(225) COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `role` varchar(20) COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `id_pegawai`, `username`, `password`, `status`, `role`) VALUES
(1, 1, 'rosyid123', '$2y$10$I/J3a3/NJLSSpuvRUf0LAecq8nsG8EXxy6/AsrMmGyv/VQ6r/FWDy', 'Aktif', 'admin'),
(6, 3, 'enggareta123', '$2y$10$qp2dproa4FEpi7Cmo4naRe1YdoxN7uEVzMPYb6eTWKjiIfYKY3IIS', 'Aktif', 'pegawai'),
(7, 4, 'oky123', '$2y$10$mraFQ7S3Vxt.fZdz2ifhz.ucOivwxRcg9urtDpxvOH/OU7q8BVDEK', 'Aktif', 'pegawai'),
(8, 5, 'tommy123', '$2y$10$HzN6Bo.12ArUCuKMdrhcX./4lozOEQQFQyrtsIhG85gQYG.McaVLy', 'Aktif', 'pegawai'),
(9, 6, 'imam123', '$2y$10$Mzm3SawMe3OM1ZRnB2MfOef4bMx2UJWTmAO8SELNN0ZS0yECm7zK6', 'Aktif', 'pegawai'),
(10, 7, 'ndolis123', '$2y$10$8SMRFDAy0zhgIQEBdXOYve2j/E761aDnE.4VwJrse2MyzX9gxVjSq', 'Aktif', 'pegawai'),
(14, 14, 'ivan123', '$2y$10$pI5zGON6yQocVhdUMinQv.PCsa441qjtjh4DF00WQ48P3M61uvllm', 'Aktif', 'pegawai'),
(15, 15, 'zainuddin123', '$2y$10$qq1BLWdHOnPV6xLITD.O9eLG9EzJqY4DUAx4zdFYgPI4k/.Y7oM8C', 'Aktif', 'pegawai'),
(16, 16, 'sagita123', '$2y$10$faAzIPrJDDQxGy832K/hju9JNDppcj7l5NvrOTJ4BWmsgQSIePOpC', 'Aktif', 'admin');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `jabatan`
--
ALTER TABLE `jabatan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ketidakhadiran`
--
ALTER TABLE `ketidakhadiran`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ketidakhadiran_pegawai` (`id_pegawai`);

--
-- Indexes for table `lokasi_presensi`
--
ALTER TABLE `lokasi_presensi`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pegawai`
--
ALTER TABLE `pegawai`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `presensi`
--
ALTER TABLE `presensi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_pegawai` (`id_pegawai`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_pegawai` (`id_pegawai`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `jabatan`
--
ALTER TABLE `jabatan`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `ketidakhadiran`
--
ALTER TABLE `ketidakhadiran`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `lokasi_presensi`
--
ALTER TABLE `lokasi_presensi`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `pegawai`
--
ALTER TABLE `pegawai`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `presensi`
--
ALTER TABLE `presensi`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ketidakhadiran`
--
ALTER TABLE `ketidakhadiran`
  ADD CONSTRAINT `fk_ketidakhadiran_pegawai` FOREIGN KEY (`id_pegawai`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `presensi`
--
ALTER TABLE `presensi`
  ADD CONSTRAINT `presensi_ibfk_1` FOREIGN KEY (`id_pegawai`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`id_pegawai`) REFERENCES `pegawai` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
