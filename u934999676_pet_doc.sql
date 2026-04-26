-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Apr 26, 2026 at 06:29 AM
-- Server version: 11.8.6-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u934999676_pet_doc`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`u934999676_narayans`@`127.0.0.1` PROCEDURE `find_nearby_doctors` (IN `p_lat` DECIMAL(10,8), IN `p_lng` DECIMAL(11,8), IN `p_radius_km` INT, IN `p_limit` INT, IN `p_offset` INT)   BEGIN
  SELECT
    u.id,
    u.name,
    u.phone,
    u.profile_pic,
    u.lat,
    u.lng,
    dp.specialization,
    dp.consultation_fee,
    dp.rating,
    dp.is_available,
    dp.clinic_name,
    dll.updated_at AS location_updated_at,
    -- Haversine distance in km
    (6371 * ACOS(
       COS(RADIANS(p_lat)) * COS(RADIANS(u.lat)) *
       COS(RADIANS(u.lng) - RADIANS(p_lng)) +
       SIN(RADIANS(p_lat)) * SIN(RADIANS(u.lat))
    )) AS distance_km
  FROM users u
  INNER JOIN doctor_profiles dp ON dp.user_id = u.id
  LEFT JOIN doctor_location_latest dll ON dll.doctor_id = u.id
  WHERE
    u.role = 'doctor'
    AND u.is_active = 1
    AND dp.is_available = 1
    AND u.lat IS NOT NULL
  HAVING distance_km <= p_radius_km
  ORDER BY distance_km ASC
  LIMIT p_limit OFFSET p_offset;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `animals`
--

CREATE TABLE `animals` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('dog','cat','cow','buffalo','horse','goat','sheep','poultry','other') NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `gender` enum('male','female','unknown') NOT NULL DEFAULT 'unknown',
  `dob` date DEFAULT NULL,
  `weight_kg` decimal(6,2) DEFAULT NULL,
  `color` varchar(80) DEFAULT NULL,
  `tag_number` varchar(50) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `animals`
--

INSERT INTO `animals` (`id`, `owner_id`, `name`, `type`, `breed`, `gender`, `dob`, `weight_kg`, `color`, `tag_number`, `photo`, `allergies`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'cow 1', 'cow', 'zarcy', 'female', '0000-00-00', 900.00, 'black', '1212', NULL, 'tempreture', 'my cow having problems', 1, '2026-04-23 15:23:29', '2026-04-23 15:23:29');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `doctor_id`, `owner_id`, `name`, `phone`, `address`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, NULL, 'dipak sarvade', '9999999999', 'beed', '', 1, '2026-04-25 00:09:58', '2026-04-25 00:09:58');

-- --------------------------------------------------------

--
-- Table structure for table `customer_payments`
--

CREATE TABLE `customer_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `treatment_id` int(10) UNSIGNED DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('charge','payment') NOT NULL DEFAULT 'charge',
  `description` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctor_location_latest`
--

CREATE TABLE `doctor_location_latest` (
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `heading` smallint(6) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctor_profiles`
--

CREATE TABLE `doctor_profiles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `specialization` varchar(120) NOT NULL DEFAULT 'General',
  `license_number` varchar(60) NOT NULL,
  `experience_yrs` tinyint(4) NOT NULL DEFAULT 0,
  `consultation_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `bio` text DEFAULT NULL,
  `clinic_name` varchar(191) DEFAULT NULL,
  `clinic_address` text DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `total_ratings` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `doctor_profiles`
--

INSERT INTO `doctor_profiles` (`id`, `user_id`, `specialization`, `license_number`, `experience_yrs`, `consultation_fee`, `bio`, `clinic_name`, `clinic_address`, `is_available`, `rating`, `total_ratings`, `created_at`) VALUES
(1, 2, 'heart', '12345678', 5, 50.00, NULL, 'ganesh', NULL, 1, 0.00, 0, '2026-04-23 15:58:00');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(10) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `medicine_name` varchar(191) NOT NULL,
  `quantity` int(10) NOT NULL DEFAULT 0,
  `unit` varchar(30) NOT NULL DEFAULT 'units',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `expiry_date` date DEFAULT NULL,
  `batch_number` varchar(60) DEFAULT NULL,
  `low_stock_at` int(10) NOT NULL DEFAULT 5,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `doctor_id`, `medicine_name`, `quantity`, `unit`, `price`, `expiry_date`, `batch_number`, `low_stock_at`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, 'Mc33001', 10, '5', 100.00, '0000-00-00', '6', 5, 1, '2026-04-25 00:11:02', '2026-04-25 00:11:02');

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `treatment_id` int(10) UNSIGNED NOT NULL,
  `animal_id` int(10) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `medicine` varchar(191) NOT NULL,
  `dosage` varchar(120) NOT NULL,
  `frequency` varchar(80) NOT NULL,
  `duration_days` tinyint(4) NOT NULL DEFAULT 5,
  `route` varchar(60) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limit_log`
--

CREATE TABLE `rate_limit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `refresh_tokens`
--

CREATE TABLE `refresh_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(512) NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `refresh_tokens`
--

INSERT INTO `refresh_tokens` (`id`, `user_id`, `token`, `expires_at`, `revoked`, `created_at`) VALUES
(1, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTUyOTgyOCwidHlwZSI6InJlZnJlc2gifQ.V0efwyjLkJE2dCAnm3ZIMzQqBKwCTnWQfXGla6xfIV4', '2026-05-23 09:50:28', 0, '2026-04-23 15:20:28'),
(2, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1MzIwODAsInR5cGUiOiJyZWZyZXNoIn0.VR8IUNuaC8BPWmeYGtaud1-ida_NwmYAIVN8WmAwXrg', '2026-05-23 10:28:00', 0, '2026-04-23 15:58:00'),
(3, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTUzMzEzNywidHlwZSI6InJlZnJlc2gifQ.EMxpbgPG7wg1uxnwT8_F2YtABdOwPeLbzwCWPcqrRSY', '2026-05-23 10:45:37', 0, '2026-04-23 16:15:37'),
(4, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTU1NDQ2OSwidHlwZSI6InJlZnJlc2gifQ.m-vcjLEgOdt0LoAMwWP4tumpESOW2qWdFHkUm5nn4fA', '2026-05-23 16:41:09', 0, '2026-04-23 22:11:09'),
(5, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTU1ODMyNSwidHlwZSI6InJlZnJlc2gifQ.EhLERZubmXIKqoFzxL9A_Dp9M2s07PnwlV02lqdY9RI', '2026-05-23 17:45:25', 0, '2026-04-23 23:15:25'),
(6, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjE1NzgsInR5cGUiOiJyZWZyZXNoIn0.34d7qkuK3OJ2JGlDWY6N1Cj6gWCBL-LeXPO3nXmuaZ4', '2026-05-23 18:39:38', 0, '2026-04-24 00:09:38'),
(7, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjE1ODUsInR5cGUiOiJyZWZyZXNoIn0.E2NeehcM_vXY-_7RNOObznJEXe0eKwK-XpHB0R_Rsk8', '2026-05-23 18:39:45', 0, '2026-04-24 00:09:45'),
(8, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjE2MTEsInR5cGUiOiJyZWZyZXNoIn0.56J18ikZfEy2Va0vKeThUImM5mzxHCOdb-GQYtvAlFA', '2026-05-23 18:40:11', 0, '2026-04-24 00:10:11'),
(9, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjE2NjYsInR5cGUiOiJyZWZyZXNoIn0.PaZYSIOiliy6cb7BvoZcfcP0Hyvpb-Bo2XKgGACRUl8', '2026-05-23 18:41:06', 0, '2026-04-24 00:11:06'),
(10, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjE3NzAsInR5cGUiOiJyZWZyZXNoIn0.BBatD1jRXaodb78IVDa8PAGIVcpB3t5sPhjFkof0TcM', '2026-05-23 18:42:50', 0, '2026-04-24 00:12:50'),
(11, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjIxMTIsInR5cGUiOiJyZWZyZXNoIn0.0nx2tJvPdZc6-ElhBb-3s2s0qYSKgr6WdJU9EjjOePA', '2026-05-23 18:48:32', 0, '2026-04-24 00:18:32'),
(12, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjIxMTYsInR5cGUiOiJyZWZyZXNoIn0.E25uMWOfKNUmQ9R4-8ywdDejwUHek-DtBUAT32a4MpI', '2026-05-23 18:48:36', 0, '2026-04-24 00:18:36'),
(13, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjIyMjAsInR5cGUiOiJyZWZyZXNoIn0.EVhglIvFquwtZLRZ0Iu7yxW8XDcBRe6BL1dbCNzRuXo', '2026-05-23 18:50:20', 0, '2026-04-24 00:20:20'),
(14, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjIyMjQsInR5cGUiOiJyZWZyZXNoIn0.GsQg1Jn_Hh7QsVAZX738UhzWih1-UhsyJlkv9d0u65A', '2026-05-23 18:50:24', 0, '2026-04-24 00:20:24'),
(15, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjIzNDAsInR5cGUiOiJyZWZyZXNoIn0.EddYlCL-Xp4-jxCJ-mikksVXuCPY_pe7jMu7Fff4waU', '2026-05-23 18:52:20', 0, '2026-04-24 00:22:20'),
(16, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk1NjI2OTcsInR5cGUiOiJyZWZyZXNoIn0.UqyNE62U8_zpGQ4fS7d6jxBcIiPKSUFUKTPyJjSF520', '2026-05-23 18:58:17', 0, '2026-04-24 00:28:17'),
(17, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTU2MjcxMywidHlwZSI6InJlZnJlc2gifQ.e6Di5k8olyKxT1WRkoFZ3PVY9WejO2F7Kn-advs-3iw', '2026-05-23 18:58:33', 0, '2026-04-24 00:28:33'),
(18, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTY0NzYyNSwidHlwZSI6InJlZnJlc2gifQ.LjiOFNewQQPX-PDkqackRt6dwVkhD4RVjNTjobmPyT8', '2026-05-24 18:33:45', 0, '2026-04-25 00:03:45'),
(19, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk2NDc3MTgsInR5cGUiOiJyZWZyZXNoIn0.lHThkDhp-XhwgSQBZN91Ja-Xsy7Jjs_R7oTBGv5VdO0', '2026-05-24 18:35:18', 0, '2026-04-25 00:05:18'),
(20, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk2NDc3MzAsInR5cGUiOiJyZWZyZXNoIn0.WUOdj9OdxC0_POp1bZzNN-tsG1jmR0ncGz3hpxHf9IU', '2026-05-24 18:35:30', 0, '2026-04-25 00:05:30'),
(21, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk2NDc3MzcsInR5cGUiOiJyZWZyZXNoIn0.4-RoR96YzWQK3X1Dj_XL76ZgpVcIdfEBjzLu5XoyTg0', '2026-05-24 18:35:37', 0, '2026-04-25 00:05:37'),
(22, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk2NDc3NDQsInR5cGUiOiJyZWZyZXNoIn0.JeORaKmuEz979vS7zzoG4CP0GBikOue13SFOh57S88w', '2026-05-24 18:35:44', 0, '2026-04-25 00:05:44'),
(23, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk2NDc3NTYsInR5cGUiOiJyZWZyZXNoIn0.jlqxtx-VukobY25UZtwNNbcIxS7Xn8equGdqMC1LR_8', '2026-05-24 18:35:56', 0, '2026-04-25 00:05:56'),
(24, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk2NDc4NzYsInR5cGUiOiJyZWZyZXNoIn0.33xV7lt6ExVzp8xfr72p8eJdYQe53kIEDzmz2QstaXI', '2026-05-24 18:37:56', 0, '2026-04-25 00:07:56'),
(25, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTcwNzMyNiwidHlwZSI6InJlZnJlc2gifQ.TEHvwgqqQ0FRu9Pfb8Ly1gc45yDL6D0C-L5u9vI_lCA', '2026-05-25 11:08:46', 0, '2026-04-25 16:38:46'),
(26, 1, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInJvbGUiOiJvd25lciIsImV4cCI6MTc3OTcxMTYxNSwidHlwZSI6InJlZnJlc2gifQ.FuEzTCxmIwZnYl3jTaDwTYPkFkP7sPm9AkqkQ6PoSAQ', '2026-05-25 12:20:15', 0, '2026-04-25 17:50:15'),
(27, 2, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjIsInJvbGUiOiJkb2N0b3IiLCJleHAiOjE3Nzk3NzUxNzksInR5cGUiOiJyZWZyZXNoIn0.zgKQaak9t0I1vdhHwNL2bYUdcMGDumfiC3AbUQStm7s', '2026-05-26 05:59:39', 0, '2026-04-26 11:29:39');

-- --------------------------------------------------------

--
-- Table structure for table `reminders`
--

CREATE TABLE `reminders` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `animal_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `remind_at` datetime NOT NULL,
  `is_sent` tinyint(1) NOT NULL DEFAULT 0,
  `type` enum('vaccine','medicine','checkup','deworming','other') NOT NULL DEFAULT 'other',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracking`
--

CREATE TABLE `tracking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `heading` smallint(6) DEFAULT NULL,
  `speed_kmh` decimal(5,2) DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `treatments`
--

CREATE TABLE `treatments` (
  `id` int(10) UNSIGNED NOT NULL,
  `animal_id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `doctor_id` int(10) UNSIGNED DEFAULT NULL,
  `symptoms` text NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `treatment_notes` text DEFAULT NULL,
  `status` enum('pending','accepted','in_progress','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `owner_lat` decimal(10,8) DEFAULT NULL,
  `owner_lng` decimal(11,8) DEFAULT NULL,
  `visit_type` enum('home_visit','clinic','telemedicine') NOT NULL DEFAULT 'home_visit',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `rating` tinyint(4) DEFAULT NULL,
  `review` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `treatments`
--

INSERT INTO `treatments` (`id`, `animal_id`, `owner_id`, `doctor_id`, `symptoms`, `diagnosis`, `treatment_notes`, `status`, `owner_lat`, `owner_lng`, `visit_type`, `requested_at`, `accepted_at`, `completed_at`, `rejection_reason`, `rating`, `review`, `created_at`, `updated_at`) VALUES
(1, 1, 1, NULL, 'tempreture', NULL, NULL, 'pending', 17.30134230, 74.18766520, 'home_visit', '2026-04-24 00:08:40', NULL, NULL, NULL, NULL, NULL, '2026-04-24 00:08:40', '2026-04-24 00:08:40');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('owner','doctor') NOT NULL DEFAULT 'owner',
  `profile_pic` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `lat` decimal(10,8) DEFAULT NULL,
  `lng` decimal(11,8) DEFAULT NULL,
  `language` enum('en','mr') NOT NULL DEFAULT 'en',
  `fcm_token` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `phone`, `email`, `password`, `role`, `profile_pic`, `address`, `lat`, `lng`, `language`, `fcm_token`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Narayan Sangale', '9623327931', 'narayanvs726@gmail.com', '$2y$10$cWKxlBnwapjZ6Ew2oAxaauP9WI3NrmrwpWUp6TrJftjPQRxhupneK', 'owner', NULL, NULL, 16.94050500, 74.41773370, 'mr', NULL, 1, '2026-04-23 15:20:28', '2026-04-25 17:50:15'),
(2, 'Narayan Sangale', '7821005595', 'narayanns726@gmail.com', '$2y$10$xukjrPpHp2/vxv7XHgO8N.zkl58aAqimh9iVjOrRx3q3KWoYJKCgq', 'doctor', NULL, NULL, 17.29893980, 74.19025510, 'mr', NULL, 1, '2026-04-23 15:58:00', '2026-04-26 11:29:39');

-- --------------------------------------------------------

--
-- Table structure for table `vaccinations`
--

CREATE TABLE `vaccinations` (
  `id` int(10) UNSIGNED NOT NULL,
  `animal_id` int(10) UNSIGNED NOT NULL,
  `vaccine_name` varchar(120) NOT NULL,
  `given_date` date NOT NULL,
  `next_due_date` date DEFAULT NULL,
  `given_by` varchar(120) DEFAULT NULL,
  `batch_number` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_customer_balance`
-- (See below for the actual view)
--
CREATE TABLE `v_customer_balance` (
`customer_id` int(10) unsigned
,`doctor_id` int(10) unsigned
,`customer_name` varchar(120)
,`phone` varchar(15)
,`total_charged` decimal(32,2)
,`total_paid` decimal(32,2)
,`outstanding` decimal(32,2)
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `animals`
--
ALTER TABLE `animals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner` (`owner_id`),
  ADD KEY `idx_type` (`type`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_doctor` (`doctor_id`),
  ADD KEY `idx_owner` (`owner_id`);

--
-- Indexes for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_doctor` (`doctor_id`),
  ADD KEY `fk_pay_treatment` (`treatment_id`);

--
-- Indexes for table `doctor_location_latest`
--
ALTER TABLE `doctor_location_latest`
  ADD PRIMARY KEY (`doctor_id`);

--
-- Indexes for table `doctor_profiles`
--
ALTER TABLE `doctor_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user` (`user_id`),
  ADD UNIQUE KEY `uq_license` (`license_number`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_doctor` (`doctor_id`),
  ADD KEY `idx_expiry` (`expiry_date`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_treatment` (`treatment_id`),
  ADD KEY `idx_animal` (`animal_id`),
  ADD KEY `fk_rx_doctor` (`doctor_id`);

--
-- Indexes for table `rate_limit_log`
--
ALTER TABLE `rate_limit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_key_created` (`key`,`created_at`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`(64)),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `reminders`
--
ALTER TABLE `reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner` (`owner_id`),
  ADD KEY `idx_remind_at` (`remind_at`,`is_sent`),
  ADD KEY `fk_rem_animal` (`animal_id`);

--
-- Indexes for table `tracking`
--
ALTER TABLE `tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_doctor_ts` (`doctor_id`,`timestamp` DESC);

--
-- Indexes for table `treatments`
--
ALTER TABLE `treatments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_animal` (`animal_id`),
  ADD KEY `idx_owner` (`owner_id`),
  ADD KEY `idx_doctor` (`doctor_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_requested` (`requested_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_phone_role` (`phone`,`role`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_location` (`lat`,`lng`);

--
-- Indexes for table `vaccinations`
--
ALTER TABLE `vaccinations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_animal` (`animal_id`),
  ADD KEY `idx_due` (`next_due_date`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `animals`
--
ALTER TABLE `animals`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customer_payments`
--
ALTER TABLE `customer_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `doctor_profiles`
--
ALTER TABLE `doctor_profiles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limit_log`
--
ALTER TABLE `rate_limit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `reminders`
--
ALTER TABLE `reminders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracking`
--
ALTER TABLE `tracking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `treatments`
--
ALTER TABLE `treatments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `vaccinations`
--
ALTER TABLE `vaccinations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `v_customer_balance`
--
DROP TABLE IF EXISTS `v_customer_balance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`u934999676_narayans`@`127.0.0.1` SQL SECURITY DEFINER VIEW `v_customer_balance`  AS SELECT `c`.`id` AS `customer_id`, `c`.`doctor_id` AS `doctor_id`, `c`.`name` AS `customer_name`, `c`.`phone` AS `phone`, coalesce(sum(case when `cp`.`type` = 'charge' then `cp`.`amount` else 0 end),0) AS `total_charged`, coalesce(sum(case when `cp`.`type` = 'payment' then `cp`.`amount` else 0 end),0) AS `total_paid`, coalesce(sum(case when `cp`.`type` = 'charge' then `cp`.`amount` when `cp`.`type` = 'payment' then -`cp`.`amount` else 0 end),0) AS `outstanding` FROM (`customers` `c` left join `customer_payments` `cp` on(`cp`.`customer_id` = `c`.`id`)) WHERE `c`.`is_active` = 1 GROUP BY `c`.`id`, `c`.`doctor_id`, `c`.`name`, `c`.`phone` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `animals`
--
ALTER TABLE `animals`
  ADD CONSTRAINT `fk_animal_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_cust_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cust_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD CONSTRAINT `fk_pay_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pay_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_pay_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `treatments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `doctor_location_latest`
--
ALTER TABLE `doctor_location_latest`
  ADD CONSTRAINT `fk_dll_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `doctor_profiles`
--
ALTER TABLE `doctor_profiles`
  ADD CONSTRAINT `fk_dp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `fk_inv_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `fk_rx_animal` FOREIGN KEY (`animal_id`) REFERENCES `animals` (`id`),
  ADD CONSTRAINT `fk_rx_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_rx_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `treatments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reminders`
--
ALTER TABLE `reminders`
  ADD CONSTRAINT `fk_rem_animal` FOREIGN KEY (`animal_id`) REFERENCES `animals` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rem_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tracking`
--
ALTER TABLE `tracking`
  ADD CONSTRAINT `fk_track_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `treatments`
--
ALTER TABLE `treatments`
  ADD CONSTRAINT `fk_treat_animal` FOREIGN KEY (`animal_id`) REFERENCES `animals` (`id`),
  ADD CONSTRAINT `fk_treat_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_treat_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `vaccinations`
--
ALTER TABLE `vaccinations`
  ADD CONSTRAINT `fk_vax_animal` FOREIGN KEY (`animal_id`) REFERENCES `animals` (`id`) ON DELETE CASCADE;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`u934999676_narayans`@`127.0.0.1` EVENT `evt_purge_tracking` ON SCHEDULE EVERY 1 HOUR STARTS '2026-04-23 08:03:34' ON COMPLETION NOT PRESERVE ENABLE DO DELETE FROM `tracking`
    WHERE `timestamp` < DATE_SUB(NOW(), INTERVAL 24 HOUR)$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
