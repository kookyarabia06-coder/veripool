-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 09, 2026 at 09:26 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `veripool`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_trails`
--

CREATE TABLE `audit_trails` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_trails`
--

INSERT INTO `audit_trails` (`id`, `user_id`, `action`, `table_name`, `record_id`, `old_data`, `new_data`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 4, 'LOGIN', 'users', 4, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:29:04'),
(2, 4, 'LOGOUT', 'users', 4, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:30:17'),
(5, 3, 'LOGIN', 'users', 3, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:34:20'),
(6, 3, 'LOGOUT', 'users', 3, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:36:29'),
(7, 2, 'LOGIN', 'users', 2, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:36:36'),
(8, 2, 'LOGOUT', 'users', 2, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:38:10'),
(11, 4, 'LOGIN', 'users', 4, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:58:31'),
(12, 4, 'LOGOUT', 'users', 4, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:58:43'),
(13, 2, 'LOGIN', 'users', 2, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 05:58:54'),
(14, 2, 'LOGOUT', 'users', 2, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:07:59'),
(15, 2, 'LOGIN', 'users', 2, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:08:09'),
(16, 2, 'LOGOUT', 'users', 2, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:17:28'),
(17, 3, 'LOGIN', 'users', 3, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:17:52'),
(18, 3, 'LOGOUT', 'users', 3, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:18:33'),
(21, 2, 'LOGIN', 'users', 2, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:20:00'),
(22, 2, 'LOGOUT', 'users', 2, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:21:56'),
(24, 4, 'LOGIN', 'users', 4, NULL, '{\"action\":\"login\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:30:32'),
(25, 4, 'LOGOUT', 'users', 4, NULL, '{\"action\":\"logout\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 06:30:36'),
(29, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 08:28:26'),
(30, 4, 'LOGOUT', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 08:31:46'),
(33, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 08:32:54'),
(34, 4, 'CREATE_RESERVATION', 'reservations', 1, NULL, '\"{\\\"check_in\\\":\\\"2026-03-11\\\",\\\"check_out\\\":\\\"2026-03-15\\\",\\\"adults\\\":\\\"3\\\",\\\"children\\\":\\\"2\\\",\\\"room_type_id\\\":\\\"3\\\",\\\"available_rooms\\\":[{\\\"id\\\":\\\"7\\\",\\\"room_number\\\":\\\"301\\\",\\\"room_type_id\\\":\\\"3\\\",\\\"floor\\\":\\\"3\\\",\\\"status\\\":\\\"available\\\",\\\"notes\\\":null,\\\"created_at\\\":\\\"2026-03-03 13:14:23\\\",\\\"room_type_name\\\":\\\"Family Suite\\\",\\\"base_price\\\":\\\"4000.00\\\"},{\\\"id\\\":\\\"8\\\",\\\"room_number\\\":\\\"302\\\",\\\"room_type_id\\\":\\\"3\\\",\\\"floor\\\":\\\"3\\\",\\\"status\\\":\\\"available\\\",\\\"notes\\\":null,\\\"created_at\\\":\\\"2026-03-03 13:14:23\\\",\\\"room_type_name\\\":\\\"Family Suite\\\",\\\"base_price\\\":\\\"4000.00\\\"}],\\\"room_id\\\":\\\"7\\\",\\\"services\\\":[\\\"1\\\"],\\\"total_amount\\\":16500}\"', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 08:34:08'),
(35, 4, 'LOGOUT', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 08:38:19'),
(36, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 13:04:49'),
(37, 4, 'LOGOUT', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 13:05:00'),
(40, 2, 'LOGIN', 'users', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 13:07:33'),
(41, 2, 'LOGOUT', 'users', 2, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 13:07:59'),
(42, 3, 'LOGIN', 'users', 3, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 13:08:07'),
(43, 3, 'LOGOUT', 'users', 3, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 13:08:35'),
(44, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:18:40'),
(45, 4, 'LOGOUT', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:21:03'),
(46, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:22:51'),
(47, 4, 'LOGOUT', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:25:17'),
(48, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:25:28'),
(49, 4, 'LOGOUT', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:29:28'),
(50, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:30:29'),
(51, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:33:04'),
(52, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:40:19'),
(53, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:42:24'),
(54, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 14:47:35'),
(55, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 15:09:48'),
(56, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 15:17:25'),
(57, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 15:17:32'),
(58, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 15:17:44'),
(59, 4, 'LOGIN', 'users', 4, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-03 15:19:14'),
(60, 2, 'DELETE_USER', 'users', 1, NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 08:17:35'),
(61, 8, 'REGISTER', 'users', 8, NULL, '{\"username\":\"jahn\",\"email\":\"Jaahnverdadero@gmail.com\",\"password\":\"$2y$10$PbBFPKn4MVpUHkJNWxCSauAO8E1R4iAYQFWWzZBiVTk3cfyajt5cW\",\"full_name\":\"Jahn Nickole Verdadero\",\"phone\":\"09946315582\",\"address\":\"eqwedWD\",\"role\":\"guest\",\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 16:19:54'),
(62, 2, 'VERIFY_PAYMENT', 'payments', 2, NULL, '{\"action\":\"approve\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 16:21:41'),
(63, 2, 'UPDATE_RESERVATION', 'reservations', 1, NULL, '{\"status\":\"confirmed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 16:53:14'),
(64, 2, 'UPDATE_RESERVATION', 'reservations', 1, NULL, '{\"status\":\"confirmed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 16:58:35'),
(65, 2, 'UPDATE_RESERVATION', 'reservations', 1, NULL, '{\"status\":\"confirmed\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 16:58:41'),
(66, 9, 'REGISTER', 'users', 9, NULL, '{\"username\":\"mika\",\"email\":\"leeyansaurus@gmail.com\",\"password\":\"$2y$10$AJcUaw0ZLjPHWSj3ePUtweFSJvN.LdXZeAEPsqITVMzoADiG0J0r.\",\"full_name\":\"Mikaella Torre\",\"phone\":\"09946315584\",\"address\":\"3 Champaca St\",\"role\":\"guest\",\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 17:03:22'),
(67, 2, 'VERIFY_PAYMENT', 'payments', 6, NULL, '{\"action\":\"approve\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 17:04:48'),
(68, 2, 'VERIFY_PAYMENT', 'payments', 6, NULL, '{\"action\":\"approve\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 17:04:49'),
(69, 2, 'VERIFY_PAYMENT', 'payments', 7, NULL, '{\"action\":\"approve\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 17:08:28'),
(70, 2, 'VERIFY_PAYMENT', 'payments', 8, NULL, '{\"action\":\"approve\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-07 17:13:15'),
(71, 10, 'REGISTER', 'users', 10, NULL, '{\"username\":\"renz\",\"email\":\"fgithub455@gmail.com\",\"password\":\"$2y$10$Jdx7wXj6bXwb1rkDwdqxiuEknu.PQKiWI.44OuzF.3Ey38T3BG17i\",\"full_name\":\"Renz Aaron Mendiola\",\"phone\":\"056451455\",\"address\":\"rdesgfasetg\",\"role\":\"guest\",\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-08 04:38:12'),
(72, 2, 'VERIFY_PAYMENT', 'payments', 9, NULL, '{\"action\":\"approve\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-08 04:40:07'),
(73, 2, 'VERIFY_PAYMENT', 'payments', 13, NULL, '{\"action\":\"approve\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-09 02:12:50'),
(74, 2, 'VERIFY_PAYMENT', 'payments', 14, NULL, '{\"action\":\"approve_with_otp\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-09 02:24:52'),
(75, 2, 'VERIFY_PAYMENT', 'payments', 14, NULL, '{\"action\":\"approve_with_otp\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-09 02:39:22'),
(76, 20, 'REGISTER', 'users', 20, NULL, '{\"username\":\"Marga\",\"email\":\"ab7559711@gmail.com\",\"password\":\"$2y$10$m\\/OXnqTHtcNr2gKEHj3iweh3sT1.Apo9HtCMN64km2u9\\/X88fExkS\",\"full_name\":\"Margarette Duazo\",\"phone\":\"09946315584\",\"address\":\"fgsrghethrtjh\",\"role\":\"guest\",\"status\":\"active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-09 05:10:18'),
(77, 2, 'VERIFY_PAYMENT', 'payments', 21, NULL, '{\"action\":\"approve_with_otp\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-03-09 06:05:38');

-- --------------------------------------------------------

--
-- Table structure for table `check_in_logs`
--

CREATE TABLE `check_in_logs` (
  `id` int(11) NOT NULL,
  `entry_pass_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `actual_date` date NOT NULL,
  `check_in_type` varchar(20) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cottages`
--

CREATE TABLE `cottages` (
  `id` int(11) NOT NULL,
  `cottage_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) DEFAULT 4,
  `size_sqm` decimal(5,2) DEFAULT NULL,
  `amenities` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `cottage_type` enum('open','closed','nipa','family','vip') NOT NULL,
  `status` enum('available','unavailable') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cottages`
--

INSERT INTO `cottages` (`id`, `cottage_name`, `description`, `capacity`, `size_sqm`, `amenities`, `price`, `cottage_type`, `status`, `created_at`) VALUES
(1, 'Umbrella Cottage', 'Perfect for small groups and families, featuring a colorful umbrella-inspired roof design with open-air seating', 6, 30.00, 'Open-air seating, Table and Chairs', 1000.00, 'open', 'available', '2026-03-03 13:03:54'),
(2, 'Party Pavilion', 'Large open space ideal for celebrations and events. Can accommodate big groups with its spacious layout', 25, 120.00, 'Covered Area, Karaoke, Lighting, Tables and Chairs', 4000.00, 'open', '', '2026-03-03 13:03:54'),
(3, 'Party Ernesto', 'Named after our beloved founder, this premium party cottage features a unique blend of traditional and modern design', 15, 80.00, 'Covered Area, Karaoke, Lighting, Tables and Chairs, Lounge Area, Grilling Station', 6000.00, 'vip', '', '2026-03-03 13:03:54'),
(4, 'Cabin Open Space', 'Rustic cabin-style open cottage with native materials and cozy atmosphere', 10, 50.00, 'Nipa Roof, Bamboo Furniture, Hanging Chairs, Fire Pit, String Lights, Native Decor', 2500.00, 'nipa', '', '2026-03-03 13:03:54');

-- --------------------------------------------------------

--
-- Table structure for table `date_adjustment_requests`
--

CREATE TABLE `date_adjustment_requests` (
  `id` int(11) NOT NULL,
  `entry_pass_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `requested_check_in` date DEFAULT NULL,
  `requested_check_out` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `date_adjustment_requests`
--

INSERT INTO `date_adjustment_requests` (`id`, `entry_pass_id`, `reservation_id`, `user_id`, `requested_check_in`, `requested_check_out`, `reason`, `status`, `reviewed_by`, `reviewed_at`, `created_at`) VALUES
(1, 2, 1, 4, '2026-03-11', '2026-03-12', 'emergency', 'pending', NULL, NULL, '2026-03-09 13:00:11'),
(2, 5, 7, 4, '2026-03-10', '2026-03-11', 'got a emergency', 'approved', 3, '2026-03-09 13:22:11', '2026-03-09 13:21:44');

-- --------------------------------------------------------

--
-- Table structure for table `entrance_fees`
--

CREATE TABLE `entrance_fees` (
  `id` int(11) NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `fee_type` enum('adult','child','senior','group') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `entrance_fees`
--

INSERT INTO `entrance_fees` (`id`, `fee_name`, `fee_type`, `amount`, `description`, `status`, `created_at`) VALUES
(1, 'Adult Entrance', 'adult', 100.00, 'Entrance fee for adults (18-59 years old)', 'active', '2026-03-07 08:06:06'),
(2, 'Child Entrance', 'child', 50.00, 'Entrance fee for children (3-12 years old)', 'active', '2026-03-07 08:06:06'),
(3, 'Senior Citizen', 'senior', 80.00, 'Entrance fee for senior citizens (60+ with valid ID)', 'active', '2026-03-07 08:06:06'),
(4, 'Group Rate (10+)', 'group', 80.00, 'Per person rate for groups of 10 or more', 'active', '2026-03-07 08:06:06');

-- --------------------------------------------------------

--
-- Table structure for table `entrance_fee_payments`
--

CREATE TABLE `entrance_fee_payments` (
  `id` int(11) NOT NULL,
  `payment_number` varchar(20) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `guest_name` varchar(100) NOT NULL,
  `guest_count` int(11) NOT NULL,
  `fee_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fee_breakdown`)),
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','bank_transfer','online') DEFAULT 'cash',
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `entrance_fee_payments`
--

INSERT INTO `entrance_fee_payments` (`id`, `payment_number`, `reservation_id`, `guest_name`, `guest_count`, `fee_breakdown`, `total_amount`, `payment_method`, `payment_status`, `created_by`, `payment_date`, `notes`) VALUES
(1, 'ENT202603073714', 3, 'Kooky Lyann Arabia', 1, '{\"adults\":1,\"children\":0,\"seniors\":0,\"adult_fee\":\"100.00\",\"child_fee\":\"50.00\",\"senior_fee\":\"80.00\"}', 100.00, 'cash', 'completed', 3, '2026-03-07 08:16:36', 'Entrance fee for walk-in guest'),
(2, 'ENT202603094256', 9, 'Immanuelle Deleon', 3, '{\"adults\":2,\"children\":1,\"seniors\":0,\"adult_fee\":\"100.00\",\"child_fee\":\"50.00\",\"senior_fee\":\"80.00\"}', 250.00, 'cash', 'completed', 3, '2026-03-09 02:33:21', 'Entrance fee for walk-in guest'),
(3, 'ENT202603099994', 10, 'Immanuelle Deleon', 6, '{\"adults\":5,\"children\":1,\"seniors\":0,\"adult_fee\":\"100.00\",\"child_fee\":\"50.00\",\"senior_fee\":\"80.00\"}', 550.00, 'cash', 'completed', 3, '2026-03-09 02:43:01', 'Entrance fee for walk-in guest'),
(4, 'ENT202603091641', 11, 'Rosalie Arabia', 5, '{\"adults\":3,\"children\":2,\"seniors\":0,\"adult_fee\":\"100.00\",\"child_fee\":\"50.00\",\"senior_fee\":\"80.00\"}', 400.00, 'cash', 'completed', 3, '2026-03-09 02:48:37', 'Entrance fee for walk-in guest'),
(5, 'ENT202603093534', 12, 'Rosalie Arabia', 4, '{\"adults\":4,\"children\":0,\"seniors\":0,\"adult_fee\":\"100.00\",\"child_fee\":\"50.00\",\"senior_fee\":\"80.00\"}', 400.00, 'cash', 'completed', 3, '2026-03-09 03:34:41', 'Entrance fee for walk-in guest');

-- --------------------------------------------------------

--
-- Table structure for table `entry_passes`
--

CREATE TABLE `entry_passes` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `status` enum('active','used','expired','cancelled') DEFAULT 'active',
  `valid_from` datetime NOT NULL,
  `valid_until` datetime NOT NULL,
  `is_flexible` tinyint(1) DEFAULT 0,
  `original_check_in` date DEFAULT NULL,
  `original_check_out` date DEFAULT NULL,
  `date_adjustments` int(11) DEFAULT 0,
  `last_adjustment_date` datetime DEFAULT NULL,
  `adjustment_history` text DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL COMMENT 'Admin/Staff ID who generated',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `entry_passes`
--

INSERT INTO `entry_passes` (`id`, `reservation_id`, `user_id`, `otp_code`, `status`, `valid_from`, `valid_until`, `is_flexible`, `original_check_in`, `original_check_out`, `date_adjustments`, `last_adjustment_date`, `adjustment_history`, `used_at`, `generated_by`, `created_at`) VALUES
(1, 4, 8, '596405', 'active', '2026-03-17 14:00:00', '2026-03-18 12:00:00', 0, NULL, NULL, 0, NULL, NULL, NULL, 2, '2026-03-07 16:53:23'),
(2, 1, 4, '647468', 'active', '2026-03-11 14:00:00', '2026-03-15 12:00:00', 0, NULL, NULL, 0, NULL, NULL, NULL, 2, '2026-03-07 16:58:49'),
(3, 5, 9, '394170', 'active', '2026-03-07 14:00:00', '2026-03-08 12:00:00', 0, NULL, NULL, 0, NULL, NULL, NULL, 2, '2026-03-07 17:04:44'),
(4, 6, 9, '743200', 'used', '2026-03-08 14:00:00', '2026-03-09 12:00:00', 0, NULL, NULL, 0, NULL, NULL, '2026-03-08 16:38:23', 2, '2026-03-07 17:08:25'),
(5, 7, 4, '302888', 'used', '2026-03-10 14:00:00', '2026-03-11 12:00:00', 0, NULL, NULL, 1, '2026-03-09 13:22:11', '[{\"date\":\"2026-03-09 13:22:11\",\"old_check_in\":\"2026-03-08 14:00:00\",\"old_check_out\":\"2026-03-09 12:00:00\",\"new_check_in\":\"2026-03-10 14:00:00\",\"new_check_out\":\"2026-03-11 12:00:00\",\"reason\":\"got a emergency\",\"approved_by\":3}]', '2026-03-09 08:17:11', 2, '2026-03-07 17:13:11'),
(6, 8, 10, '627529', 'used', '2026-03-08 14:00:00', '2026-03-09 12:00:00', 0, NULL, NULL, 0, NULL, NULL, '2026-03-09 08:17:13', 2, '2026-03-08 06:48:48');

-- --------------------------------------------------------

--
-- Table structure for table `entry_pass_logs`
--

CREATE TABLE `entry_pass_logs` (
  `id` int(11) NOT NULL,
  `entry_pass_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `payment_number` varchar(20) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `payment_method` enum('cash','gcash') DEFAULT 'cash',
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `transaction_id` varchar(100) DEFAULT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `screenshot` varchar(255) DEFAULT NULL,
  `screenshot_uploaded_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `payment_number`, `reservation_id`, `amount`, `payment_method`, `payment_status`, `transaction_id`, `payment_date`, `notes`, `screenshot`, `screenshot_uploaded_at`, `created_by`) VALUES
(1, 'PAY202603074179', 3, 1500.00, 'cash', 'completed', NULL, '2026-03-07 08:16:36', 'Accommodation payment for walk-in guest', NULL, NULL, 3),
(2, 'PAY202603087007', 4, 2000.00, '', 'completed', NULL, '2026-03-07 16:20:56', 'Full payment at booking | Screenshot: payment_4_1772900456.jpg', NULL, NULL, 8),
(3, 'PAY202603082560', 1, 16500.00, 'cash', 'completed', NULL, '2026-03-07 16:58:49', 'Manual payment by admin', NULL, NULL, 2),
(4, 'PAY202603088453', 1, 16500.00, '', 'completed', NULL, '2026-03-07 16:58:53', 'Manual payment by admin', NULL, NULL, 2),
(5, 'PAY202603083965', 1, 16500.00, '', 'completed', NULL, '2026-03-07 16:58:53', 'Manual payment by admin', NULL, NULL, 2),
(6, 'PAY202603088066', 5, 1000.00, '', 'completed', NULL, '2026-03-07 17:04:17', 'Full payment at booking | Screenshot: payment_5_1772903057.jpg', NULL, NULL, 9),
(7, 'PAY202603082511', 6, 1100.00, '', 'completed', NULL, '2026-03-07 17:07:57', 'Downpayment at booking | Screenshot: payment_6_1772903277.jpg', NULL, NULL, 9),
(8, 'PAY202603083042', 7, 1375.00, '', 'completed', NULL, '2026-03-07 17:12:46', 'Downpayment at booking | Screenshot: payment_7_1772903566.jpg', NULL, NULL, 4),
(9, 'PAY202603085624', 8, 2200.00, '', 'completed', NULL, '2026-03-08 04:38:59', 'Downpayment at booking | Screenshot: payment_8_1772944739.jpg', NULL, NULL, 10),
(13, 'PAY202603098590', 8, 1800.00, 'gcash', 'completed', NULL, '2026-03-09 02:12:34', 'Payment from guest portal via GCash', 'payment_8_1773022354.jpg', NULL, 10),
(14, 'PAY202603092048', 7, 1125.00, 'gcash', 'completed', NULL, '2026-03-09 02:24:19', 'Payment from guest portal via GCash', 'payment_7_1773023059.png', NULL, 4),
(15, 'PAY202603092162', 9, 1500.00, 'cash', 'completed', NULL, '2026-03-09 02:33:21', 'Full accommodation payment for walk-in guest', NULL, NULL, 3),
(16, 'PAY202603093365', 10, 2500.00, 'cash', 'completed', NULL, '2026-03-09 02:43:01', 'Full accommodation payment for walk-in guest', NULL, NULL, 3),
(17, 'PAY202603098281', 11, 2000.00, 'cash', 'completed', NULL, '2026-03-09 02:48:37', 'Full accommodation payment for walk-in guest', NULL, NULL, 3),
(18, 'PAY202603091352', 12, 1500.00, 'cash', 'completed', NULL, '2026-03-09 03:34:41', 'Full accommodation payment for walk-in guest', NULL, NULL, 3),
(19, 'EXT202603094193', 3, 1500.00, 'cash', 'completed', NULL, '2026-03-09 03:39:09', 'Extension payment for 1 additional day(s)', NULL, NULL, 3),
(20, 'EXT202603093747', 3, 1500.00, 'gcash', 'completed', NULL, '2026-03-09 03:39:20', 'Extension payment for 1 additional day(s)', NULL, NULL, 3),
(21, 'PAY202603094457', 15, 5910.00, 'gcash', 'completed', NULL, '2026-03-09 05:31:15', 'Full payment at booking | Screenshot: payment_15_1773034275.png', 'payment_15_1773034275.png', NULL, 10),
(22, 'PAY202603092264', 16, 1100.00, 'gcash', 'pending', NULL, '2026-03-09 06:13:52', 'Full payment at booking', 'payment_16_1773036832.png', NULL, 20);

-- --------------------------------------------------------

--
-- Table structure for table `pools`
--

CREATE TABLE `pools` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('adult','children') NOT NULL,
  `capacity` int(11) DEFAULT NULL,
  `status` enum('open','closed','maintenance') DEFAULT 'open',
  `operating_hours` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pools`
--

INSERT INTO `pools` (`id`, `name`, `type`, `capacity`, `status`, `operating_hours`, `created_at`) VALUES
(1, 'Ernesto', 'adult', 50, 'open', '7:00 AM - 8:00 PM', '2026-03-03 05:14:23'),
(2, 'Ernesto', 'children', 20, 'open', '8:00 AM - 6:00 PM', '2026-03-03 05:14:23'),
(5, 'Pavillon', 'adult', 50, 'open', '7:00 AM - 8:00 PM', '2026-03-03 08:55:58'),
(6, 'Pavillon', 'children', 20, 'open', '8:00 AM - 6:00 PM', '2026-03-03 08:56:26');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `report_name` varchar(100) NOT NULL,
  `report_type` enum('occupancy','revenue','payments','guests') NOT NULL,
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parameters`)),
  `generated_by` int(11) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `reservation_number` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `cottage_id` int(11) DEFAULT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `adults` int(11) DEFAULT 1,
  `children` int(11) DEFAULT 0,
  `seniors` int(11) NOT NULL DEFAULT 0,
  `total_guests` int(11) DEFAULT NULL,
  `accommodation_total` decimal(10,2) DEFAULT NULL,
  `entrance_fee_total` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `payment_status` varchar(50) DEFAULT 'pending',
  `status` enum('pending','confirmed','checked_in','checked_out','cancelled') DEFAULT 'pending',
  `reservation_type` varchar(50) DEFAULT 'daytour',
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_verified` tinyint(1) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_by` varchar(20) DEFAULT 'online',
  `has_entrance_fee` tinyint(1) DEFAULT 0,
  `entrance_fee_amount` decimal(10,2) DEFAULT 0.00,
  `entrance_fee_paid` decimal(10,2) DEFAULT 0.00,
  `entrance_fee_guests` int(11) DEFAULT 0,
  `special_requests` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `otp_required` tinyint(1) DEFAULT 1,
  `entry_pass_generated` tinyint(1) DEFAULT 0,
  `reminder_sent_3days` tinyint(1) DEFAULT 0,
  `reminder_sent_1day` tinyint(1) DEFAULT 0,
  `reminder_sent_today` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `checked_out_by` int(11) DEFAULT NULL,
  `checked_out_at` datetime DEFAULT NULL,
  `extended_at` datetime DEFAULT NULL,
  `extended_by` int(11) DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`id`, `reservation_number`, `user_id`, `room_id`, `cottage_id`, `check_in_date`, `check_out_date`, `adults`, `children`, `seniors`, `total_guests`, `accommodation_total`, `entrance_fee_total`, `total_amount`, `amount_paid`, `payment_status`, `status`, `reservation_type`, `otp_code`, `otp_verified`, `verified_by`, `verified_at`, `created_by`, `has_entrance_fee`, `entrance_fee_amount`, `entrance_fee_paid`, `entrance_fee_guests`, `special_requests`, `created_at`, `otp_required`, `entry_pass_generated`, `reminder_sent_3days`, `reminder_sent_1day`, `reminder_sent_today`, `updated_at`, `checked_out_by`, `checked_out_at`, `extended_at`, `extended_by`, `cancelled_by`, `cancelled_at`, `cancellation_reason`) VALUES
(1, 'RES202603039312', 4, 7, NULL, '2026-03-11', '2026-03-15', 3, 2, 0, 5, NULL, NULL, 16500.00, 49500.00, 'pending', 'confirmed', 'daytour', '242893', 0, NULL, NULL, 'online', 0, 0.00, 0.00, 0, NULL, '2026-03-03 08:34:08', 1, 1, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'WALK202603073672', 7, 1, NULL, '2026-03-07', '2026-03-10', 1, 0, 0, 1, NULL, NULL, 4600.00, 4600.00, 'pending', 'checked_in', 'daytour', '642885', 1, 3, '2026-03-07 16:30:12', 'walkin', 1, 100.00, 100.00, 1, NULL, '2026-03-07 08:16:36', 1, 0, 0, 0, 0, '2026-03-09 03:39:20', 3, '2026-03-09 08:17:21', '2026-03-09 11:39:20', 3, NULL, NULL, NULL),
(4, 'RES202603083607', 8, 4, NULL, '2026-03-17', '2026-03-18', 1, 0, 0, 1, NULL, NULL, 2000.00, 4000.00, 'pending', 'confirmed', 'daytour', '368815', 0, NULL, NULL, 'online', 0, 0.00, 0.00, 0, NULL, '2026-03-07 16:20:56', 1, 1, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'RES202603085444', 9, 7, NULL, '2026-03-07', '2026-03-08', 1, 0, 0, 1, NULL, NULL, 1000.00, 3000.00, 'pending', 'confirmed', 'daytour', '996083', 0, NULL, NULL, 'online', 0, 0.00, 0.00, 0, NULL, '2026-03-07 17:04:17', 1, 1, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'RES202603081953', 9, 4, NULL, '2026-03-08', '2026-03-09', 1, 0, 0, 1, NULL, NULL, 2000.00, 2200.00, 'pending', 'confirmed', 'daytour', '223414', 0, NULL, NULL, 'online', 0, 0.00, 0.00, 0, NULL, '2026-03-07 17:07:57', 1, 1, 0, 0, 0, '2026-03-09 03:29:23', 3, '2026-03-09 08:17:07', NULL, NULL, NULL, NULL, NULL),
(7, 'RES202603081537', 4, NULL, NULL, '2026-03-10', '2026-03-11', 1, 0, 0, 1, NULL, NULL, 2500.00, 5000.00, 'pending', 'confirmed', 'daytour', '021600', 1, 3, '2026-03-08 16:38:30', 'online', 0, 0.00, 0.00, 0, NULL, '2026-03-07 17:12:46', 1, 1, 0, 0, 0, '2026-03-09 05:22:11', 3, '2026-03-09 08:17:11', NULL, NULL, NULL, NULL, NULL),
(8, 'RES202603083035', 10, 9, NULL, '2026-03-08', '2026-03-09', 1, 0, 0, 1, NULL, NULL, 4000.00, 6200.00, 'pending', 'confirmed', 'daytour', '581878', 1, 3, '2026-03-08 16:38:34', 'online', 0, 0.00, 0.00, 0, NULL, '2026-03-08 04:38:59', 1, 1, 0, 0, 0, '2026-03-09 02:12:48', 3, '2026-03-09 08:17:13', NULL, NULL, NULL, NULL, NULL),
(9, 'OVN202603099794', 4, 1, NULL, '2026-03-09', '2026-03-10', 2, 1, 0, 3, NULL, NULL, 1750.00, 1750.00, 'paid', 'checked_in', 'overnight', '860031', 0, 3, '2026-03-09 10:41:37', 'walkin', 1, 250.00, 250.00, 3, NULL, '2026-03-09 02:33:21', 1, 0, 0, 0, 0, '2026-03-09 02:41:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'OVN202603093369', 18, NULL, NULL, '2026-03-09', '2026-03-10', 5, 1, 0, 6, NULL, NULL, 3050.00, 3050.00, 'paid', 'checked_in', 'overnight', '221870', 0, 3, '2026-03-09 10:43:58', 'walkin', 1, 550.00, 550.00, 6, NULL, '2026-03-09 02:43:01', 1, 0, 0, 0, 0, '2026-03-09 02:43:58', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'OVN202603092102', 19, 4, NULL, '2026-03-09', '2026-03-10', 3, 2, 0, 5, NULL, NULL, 2400.00, 2400.00, 'pending', 'checked_in', 'overnight', NULL, 0, 3, '2026-03-09 11:10:41', 'walkin', 1, 400.00, 400.00, 5, NULL, '2026-03-09 02:48:37', 1, 0, 0, 0, 0, '2026-03-09 03:10:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'OVN202603093212', 18, 9, NULL, '2026-03-09', '2026-03-10', 4, 0, 0, 4, NULL, NULL, 1900.00, 1900.00, 'pending', 'checked_in', 'overnight', NULL, 0, NULL, NULL, 'walkin', 1, 400.00, 400.00, 4, NULL, '2026-03-09 03:34:41', 1, 0, 0, 0, 0, '2026-03-09 03:36:19', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'RES202603099267', 10, NULL, 2, '2026-03-09', '2026-03-10', 15, 5, 2, 22, 4000.00, 1910.00, 5910.00, 11820.00, 'pending', 'confirmed', 'daytour_with_cottage', '907896', 0, NULL, NULL, 'online', 1, 1760.00, 0.00, 22, '', '2026-03-09 05:31:15', 1, 0, 0, 0, 0, '2026-03-09 08:10:40', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'RES202603096757', 20, NULL, NULL, '2026-03-10', '2026-03-11', 1, 0, 0, 1, 1000.00, 100.00, 1100.00, 1100.00, 'pending', 'pending', 'daytour_with_cottage', '099916', 0, NULL, NULL, 'online', 1, 100.00, 0.00, 1, NULL, '2026-03-09 06:13:52', 1, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `reservation_cottages`
--

CREATE TABLE `reservation_cottages` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `cottage_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `price_at_time` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservation_cottages`
--

INSERT INTO `reservation_cottages` (`id`, `reservation_id`, `cottage_id`, `quantity`, `price_at_time`, `created_at`) VALUES
(1, 7, 4, 1, 2500.00, '2026-03-07 17:12:46'),
(2, 8, 4, 1, 2500.00, '2026-03-08 04:38:59'),
(3, 10, 4, 1, 2500.00, '2026-03-09 02:43:01'),
(6, 15, 2, 1, 4000.00, '2026-03-09 05:31:15'),
(7, 16, 1, 1, 1000.00, '2026-03-09 06:13:52');

-- --------------------------------------------------------

--
-- Table structure for table `reservation_pools`
--

CREATE TABLE `reservation_pools` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `pool_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservation_pools`
--

INSERT INTO `reservation_pools` (`id`, `reservation_id`, `pool_id`, `created_at`) VALUES
(1, 11, 5, '2026-03-09 10:48:37'),
(2, 11, 6, '2026-03-09 10:48:37'),
(3, 12, 5, '2026-03-09 11:34:41'),
(4, 12, 6, '2026-03-09 11:34:41'),
(5, 15, 5, '2026-03-09 13:31:15'),
(6, 15, 6, '2026-03-09 13:31:15'),
(7, 16, 1, '2026-03-09 14:13:52'),
(8, 16, 2, '2026-03-09 14:13:52');

-- --------------------------------------------------------

--
-- Table structure for table `reservation_reminders`
--

CREATE TABLE `reservation_reminders` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reminder_type` enum('3_days_before','1_day_before','on_day','custom') DEFAULT '3_days_before',
  `reminder_date` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `status` enum('pending','sent','failed','cancelled') DEFAULT 'pending',
  `sent_via` enum('email','sms','both') DEFAULT 'email',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservation_reminders`
--

INSERT INTO `reservation_reminders` (`id`, `reservation_id`, `user_id`, `reminder_type`, `reminder_date`, `sent_at`, `status`, `sent_via`, `created_at`) VALUES
(1, 4, 8, '3_days_before', '2026-03-14 00:00:00', NULL, 'pending', 'email', '2026-03-07 16:53:23'),
(2, 4, 8, '1_day_before', '2026-03-16 00:00:00', NULL, 'pending', 'email', '2026-03-07 16:53:23'),
(3, 4, 8, 'on_day', '2026-03-17 08:00:00', NULL, 'pending', 'email', '2026-03-07 16:53:23'),
(4, 1, 4, '1_day_before', '2026-03-10 00:00:00', NULL, 'pending', 'email', '2026-03-07 16:58:49'),
(5, 1, 4, 'on_day', '2026-03-11 08:00:00', NULL, 'pending', 'email', '2026-03-07 16:58:49'),
(6, 6, 9, 'on_day', '2026-03-08 08:00:00', NULL, 'pending', 'email', '2026-03-07 17:08:25'),
(7, 7, 4, 'on_day', '2026-03-08 08:00:00', NULL, 'pending', 'email', '2026-03-07 17:13:11');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `room_number` varchar(10) NOT NULL,
  `room_type_id` int(11) DEFAULT NULL,
  `floor` int(11) DEFAULT NULL,
  `status` enum('available','occupied','maintenance','reserved') DEFAULT 'available',
  `cleaning_status` enum('clean','pending','in_progress') DEFAULT 'clean',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`id`, `room_number`, `room_type_id`, `floor`, `status`, `cleaning_status`, `notes`, `created_at`) VALUES
(1, '101', 1, 1, 'occupied', 'clean', NULL, '2026-03-03 05:14:23'),
(4, '201', 2, 2, 'occupied', 'in_progress', NULL, '2026-03-03 05:14:23'),
(7, '301', 3, 3, 'maintenance', 'in_progress', NULL, '2026-03-03 05:14:23'),
(9, '401', 4, 4, 'occupied', 'pending', NULL, '2026-03-03 05:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `room_types`
--

CREATE TABLE `room_types` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `max_occupancy` int(11) NOT NULL,
  `base_price` decimal(10,2) NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `amenities` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `room_types`
--

INSERT INTO `room_types` (`id`, `name`, `description`, `max_occupancy`, `base_price`, `image_path`, `amenities`, `created_at`) VALUES
(1, 'Room 1', 'Comfortable room with basic amenities', 4, 1500.00, NULL, 'Air Conditioning, Mini Fridge', '2026-03-03 05:14:23'),
(2, 'Room 2', 'Comfortable room with basic amenities', 6, 2000.00, NULL, 'Air Conditioning, Mini Fridge', '2026-03-03 05:14:23'),
(3, 'Room 3', 'Comfortable room with basic amenities', 4, 1000.00, NULL, 'Mini Fridge', '2026-03-03 05:14:23'),
(4, 'Room 4', 'Comfortable room with basic amenities', 6, 1500.00, NULL, 'Mini Fridge', '2026-03-03 05:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `role` enum('super_admin','admin','staff','guest') DEFAULT 'guest',
  `status` enum('active','inactive') DEFAULT 'active',
  `is_walkin_account` tinyint(1) DEFAULT 0,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `phone`, `address`, `role`, `status`, `is_walkin_account`, `last_login`, `created_at`, `updated_at`) VALUES
(2, 'admin1', 'admin@veripool.com', '$2y$10$lHlL4cp4e9.JmALtwe3dPu/Ky.Aib4jVUJULbZvUBpb1aMNmXCt8i', 'Resort Manager', NULL, NULL, 'admin', 'active', 0, '2026-03-09 06:22:48', '2026-03-03 05:14:23', '2026-03-09 06:22:48'),
(3, 'staff1', 'staff@veripool.com', '$2y$10$PHHYqDCk4YLM56mBVJLcBO7.rEVGBy6gB5M.lOMDoLvyYx5PJJUf6', 'Front Desk Staff', NULL, NULL, 'staff', 'active', 0, '2026-03-09 07:18:30', '2026-03-03 05:14:23', '2026-03-09 07:18:30'),
(4, 'kooky', 'kookyarabia06@gmail.com', '$2y$10$RZRmWGX2hCJFkMT46zs0G.EubBZa8fO6Js/t2jqYmGxFj89H7yGCu', 'Kooky Lyann Arabia', '09946315584', 'wrfwegwertywe4yg', 'guest', 'active', 0, '2026-03-09 08:20:38', '2026-03-03 05:28:54', '2026-03-09 08:20:38'),
(7, 'guest_1772871396808', 'DSHRFHWDFG@gmail.com', '$2y$10$YRUbIdotNjYBsm6iF8NmF.YgEf9t7qrbQaRttKGsaRPqsQY.sCFv2', 'Kooky Lyann Arabia', '23168451', NULL, 'guest', 'active', 0, NULL, '2026-03-07 08:16:36', '2026-03-07 08:16:36'),
(8, 'jahn', 'Jaahnverdadero@gmail.com', '$2y$10$PbBFPKn4MVpUHkJNWxCSauAO8E1R4iAYQFWWzZBiVTk3cfyajt5cW', 'Jahn Nickole Verdadero', '09946315582', 'eqwedWD', 'guest', 'active', 0, '2026-03-07 16:20:17', '2026-03-07 16:19:54', '2026-03-07 16:20:17'),
(9, 'mika', 'leeyansaurus@gmail.com', '$2y$10$AJcUaw0ZLjPHWSj3ePUtweFSJvN.LdXZeAEPsqITVMzoADiG0J0r.', 'Mikaella Torre', '09946315584', '3 Champaca St', 'guest', 'active', 0, '2026-03-07 17:06:38', '2026-03-07 17:03:22', '2026-03-07 17:06:38'),
(10, 'renz', 'fgithub455@gmail.com', '$2y$10$Jdx7wXj6bXwb1rkDwdqxiuEknu.PQKiWI.44OuzF.3Ey38T3BG17i', 'Renz Aaron Mendiola', '056451455', 'rdesgfasetg', 'guest', 'active', 0, '2026-03-09 05:25:01', '2026-03-08 04:38:12', '2026-03-09 05:25:01'),
(18, 'walkin_1773024181925', 'jahnnickoleverdadero@gmail.com', '$2y$10$1tHQkGd4SoiyQlYTvUpC0.auje9O7HLwqAmlCsedLT4DpQZdSqKqK', 'Rosalie Arabia', '0655635674', 'sedfrtgry', 'guest', 'active', 0, NULL, '2026-03-09 02:43:01', '2026-03-09 03:34:41'),
(19, 'walkin_1773024517892', 'dgayhd@gmail.com', '$2y$10$t5zODsP5glIJWthIAk07qeE63XTvrjL4F0BJwU3B8bqDVUgw57MPG', 'Rosalie Arabia', '035854186', 'dfgsdrg', 'guest', 'active', 1, NULL, '2026-03-09 02:48:37', '2026-03-09 02:48:37'),
(20, 'Marga', 'ab7559711@gmail.com', '$2y$10$m/OXnqTHtcNr2gKEHj3iweh3sT1.Apo9HtCMN64km2u9/X88fExkS', 'Margarette Duazo', '09946315584', 'fgsrghethrtjh', 'guest', 'active', 0, '2026-03-09 06:40:55', '2026-03-09 05:10:18', '2026-03-09 06:40:55');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_trails`
--
ALTER TABLE `audit_trails`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `check_in_logs`
--
ALTER TABLE `check_in_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `entry_pass_id` (`entry_pass_id`),
  ADD KEY `reservation_id` (`reservation_id`);

--
-- Indexes for table `cottages`
--
ALTER TABLE `cottages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `date_adjustment_requests`
--
ALTER TABLE `date_adjustment_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `entry_pass_id` (`entry_pass_id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `entrance_fees`
--
ALTER TABLE `entrance_fees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `entrance_fee_payments`
--
ALTER TABLE `entrance_fee_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `entry_passes`
--
ALTER TABLE `entry_passes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `otp_code` (`otp_code`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `entry_pass_logs`
--
ALTER TABLE `entry_pass_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `entry_pass_id` (`entry_pass_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `pools`
--
ALTER TABLE `pools`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reservation_number` (`reservation_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `verified_by` (`verified_by`),
  ADD KEY `idx_cottage_id` (`cottage_id`);

--
-- Indexes for table `reservation_cottages`
--
ALTER TABLE `reservation_cottages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `service_id` (`cottage_id`);

--
-- Indexes for table `reservation_pools`
--
ALTER TABLE `reservation_pools`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `pool_id` (`pool_id`);

--
-- Indexes for table `reservation_reminders`
--
ALTER TABLE `reservation_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reservation_id` (`reservation_id`),
  ADD KEY `reminder_date` (`reminder_date`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `room_number` (`room_number`),
  ADD KEY `room_type_id` (`room_type_id`);

--
-- Indexes for table `room_types`
--
ALTER TABLE `room_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_trails`
--
ALTER TABLE `audit_trails`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `check_in_logs`
--
ALTER TABLE `check_in_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cottages`
--
ALTER TABLE `cottages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `date_adjustment_requests`
--
ALTER TABLE `date_adjustment_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `entrance_fees`
--
ALTER TABLE `entrance_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `entrance_fee_payments`
--
ALTER TABLE `entrance_fee_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `entry_passes`
--
ALTER TABLE `entry_passes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `entry_pass_logs`
--
ALTER TABLE `entry_pass_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `pools`
--
ALTER TABLE `pools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `reservation_cottages`
--
ALTER TABLE `reservation_cottages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `reservation_pools`
--
ALTER TABLE `reservation_pools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `reservation_reminders`
--
ALTER TABLE `reservation_reminders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `room_types`
--
ALTER TABLE `room_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_trails`
--
ALTER TABLE `audit_trails`
  ADD CONSTRAINT `audit_trails_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `date_adjustment_requests`
--
ALTER TABLE `date_adjustment_requests`
  ADD CONSTRAINT `date_adjustment_requests_ibfk_1` FOREIGN KEY (`entry_pass_id`) REFERENCES `entry_passes` (`id`),
  ADD CONSTRAINT `date_adjustment_requests_ibfk_2` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`),
  ADD CONSTRAINT `date_adjustment_requests_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `date_adjustment_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_reservations_cottage` FOREIGN KEY (`cottage_id`) REFERENCES `cottages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  ADD CONSTRAINT `reservations_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `reservation_cottages`
--
ALTER TABLE `reservation_cottages`
  ADD CONSTRAINT `reservation_cottages_ibfk_1` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`),
  ADD CONSTRAINT `reservation_cottages_ibfk_2` FOREIGN KEY (`cottage_id`) REFERENCES `cottages` (`id`);

--
-- Constraints for table `reservation_pools`
--
ALTER TABLE `reservation_pools`
  ADD CONSTRAINT `reservation_pools_ibfk_1` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservation_pools_ibfk_2` FOREIGN KEY (`pool_id`) REFERENCES `pools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
