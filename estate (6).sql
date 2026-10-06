-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 25, 2026 at 04:18 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `estate`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `module`, `details`, `ip_address`, `timestamp`, `estate_id`) VALUES
(1, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-09 09:47:27', 1),
(4, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-10 09:09:13', 1),
(5, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-10 09:21:36', 1),
(6, 13, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '', '2026-09-11 16:16:05', 1),
(7, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-11 16:17:54', 1),
(8, 1, 'Zone Created', 'Zones', 'Created zone \'Zone ABC\' (ZN-03) with ID: 6', '::1', '2026-09-11 16:35:28', 1),
(9, 14, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '', '2026-09-11 16:40:49', 1),
(10, 15, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '', '2026-09-11 16:40:49', 1),
(11, 14, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '::1', '2026-09-11 16:43:09', 1),
(12, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-11 17:08:13', 1),
(13, 14, 'Zone Charge Created', 'Finance', 'Created zonal levy \'Developmental levy\' (₦100000) for Zone #6', '::1', '2026-09-11 17:36:03', 1),
(14, 14, 'Zone Invoices Generated', 'Finance', 'Generated 1 zonal invoice(s) for title: Developmental levy (₦100000) in Zone #6', '::1', '2026-09-11 17:36:47', 1),
(17, 18, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-11 17:47:13', 1),
(18, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-11 18:11:40', 1),
(19, 1, 'Zone Created', 'Zones', 'Created zone \'Zone DEF\' (ZN-07) with ID: 8', '::1', '2026-09-11 18:13:32', 1),
(20, 1, 'Zone DVA Assigned', 'Finance', 'Assigned Virtual Account 9973940643 (Wema Bank (Paystack DVA)) to Zone #8', '::1', '2026-09-11 18:13:34', 1),
(21, 1, 'Zone Admin Created', 'Zones', 'Created Zone Admin \'Zone DEF Admin\' (zonedef@gmail.com) for Zone #8', '::1', '2026-09-11 18:13:34', 1),
(23, 14, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '::1', '2026-09-11 18:25:32', 1),
(24, 1, 'Zone DVA Refreshed', 'Finance', 'Refreshed DVA 9923157484 (Wema%20Bank%20(Paystack%20DVA)) for Zone #6', '::1', '2026-09-11 18:58:31', 1),
(25, 1, 'Zone Updated', 'Zones', 'Updated zone #6 (Zone ABC)', '::1', '2026-09-11 19:00:49', 1),
(26, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-11 19:06:33', 1),
(27, 1, 'Zone DVA Refreshed', 'Finance', 'Refreshed DVA 3209812414 (First Bank of Nigeria) for Zone #6', '::1', '2026-09-11 19:09:45', 1),
(28, 14, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '::1', '2026-09-11 19:12:20', 1),
(29, 14, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '::1', '2026-09-11 19:16:26', 1),
(30, 18, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-11 19:17:02', 1),
(31, 14, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '::1', '2026-09-11 19:18:16', 1),
(32, 14, 'Zone Invoices Generated', 'Finance', 'Generated 2 zonal invoice(s) for title: Developmental levy (₦100000) in Zone #6', '::1', '2026-09-11 19:18:46', 1),
(33, 18, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-11 19:19:39', 1),
(34, 18, 'Paystack Payment Verified', 'Finance', 'Verified payment reference: EST_220504247 for Invoice #6 via card [Remitted to Zone ABC]. Generated Receipt: REC-260911-788', '::1', '2026-09-11 19:20:42', 1),
(35, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-12 04:39:25', 1),
(36, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-12 05:12:57', 1),
(37, 1, 'Zonal Billing Config Updated', 'Finance', 'Updated installment payment rules for Zone #1: 1st Pay 40%, 3 subsequent times', '::1', '2026-09-12 11:37:03', 1),
(38, 1, 'Zonal Billing Config Updated', 'Finance', 'Updated installment payment rules for Zone #1: 1st Pay 30%, 2 subsequent times', '::1', '2026-09-12 11:37:39', 1),
(39, 1, 'Zonal Billing Config Updated', 'Finance', 'Updated installment payment rules for Zone #1: 1st Pay 50%, 2 subsequent times', '::1', '2026-09-12 11:44:20', 1),
(40, 1, 'Zone Charge Created', 'Finance', 'Created zonal charge \'Security & Vigilante Levy\' (₦2500025000, Plan: Monthly) for Zone #1', '::1', '2026-09-12 12:10:43', 1),
(41, 1, 'Zone Charge Updated', 'Finance', 'Updated zonal charge #5 (\'Security & Vigilante Levy\', Plan: Monthly)', '::1', '2026-09-12 12:13:38', 1),
(42, 14, 'User Login', 'Auth', 'Logged in via Zone Portal successfully.', '::1', '2026-09-12 12:44:45', 1),
(43, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-12 15:33:35', 1),
(44, 18, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-12 15:50:38', 1),
(45, 18, 'Maintenance Request Created', 'Maintenance', 'Resident submitted ticket: Low water pressure in main bathroom', '::1', '2026-09-12 16:14:34', 1),
(46, 18, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-14 16:00:14', 1),
(47, 18, 'Visitor Pre-registered', 'Visitors', 'Visitor David  Johnson pre-registered with code: EST-328C0B', '::1', '2026-09-14 16:01:24', 1),
(48, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-14 16:52:35', 1),
(49, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '', '2026-09-15 07:19:31', 1),
(50, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-15 07:21:50', 1),
(51, 1, 'Item Restored', 'Archives Vault', 'Restored ID 11 in table residents', '::1', '2026-09-15 07:23:08', 1),
(52, 1, 'Invoices Generated', 'Finance', 'Generated 3 invoice(s) for title: Security & Vigilante Levy (₦25000)', '::1', '2026-09-15 09:03:49', 1),
(53, 1, 'Visitor Entry Recorded', 'Security', 'Visitor David  Johnson (Code: EST-328C0B) entered via Main Gate', '::1', '2026-09-15 09:06:40', 1),
(54, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-15 15:41:10', 1),
(55, 1, 'Duty Incident Forensics', 'Security', 'Investigated who was on duty at 2026-09-16 10:00:00. Found 1 officer(s).', '', '2026-09-16 20:18:42', 1),
(56, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-16 20:21:18', 1),
(57, 1, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260918-745 (Medical Emergency) triggered by System Admin (central_admin).', '::1', '2026-09-18 11:36:26', 1),
(58, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #1 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 11:41:01', 1),
(59, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #1 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 11:41:05', 1),
(60, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #1 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 11:41:06', 1),
(61, 1, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260918-583 (Break-In / Burglary) triggered by System Admin (central_admin).', '::1', '2026-09-18 17:19:20', 1),
(62, 1, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260918-184 (Medical Emergency) triggered by System Admin (central_admin).', '::1', '2026-09-18 17:22:38', 1),
(63, 1, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260918-333 (Fire / Smoke) triggered by System Admin (central_admin).', '::1', '2026-09-18 17:23:30', 1),
(64, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #4 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 17:35:20', 1),
(65, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #4 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 17:35:20', 1),
(66, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #4 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 17:35:31', 1),
(67, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #4 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 17:35:33', 1),
(68, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #4 marked false_alarm by user ID 1. Notes: Security has been dispatched', '::1', '2026-09-18 17:36:10', 1),
(69, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #3 marked dispatched by user ID 1 (admin).', '::1', '2026-09-18 17:39:24', 1),
(70, 18, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-18 17:44:55', 1),
(71, 18, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260918-683 (Security Threat) triggered by Femi Oke (resident).', '::1', '2026-09-18 17:47:34', 1),
(72, 1, 'Roster Schedule Created', 'Security', 'Scheduled 1 shift(s) for Femi Oke from 2026-09-18 to 2026-09-18.', '::1', '2026-09-18 17:59:07', 1),
(73, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-19 07:47:46', 1),
(74, 1, 'Emergency Siren Silenced', 'Emergency', 'Siren for alert #3 silenced by user ID 1 (admin).', '::1', '2026-09-19 08:26:28', 1),
(75, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #3 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 08:26:41', 1),
(76, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #1 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 08:26:48', 1),
(77, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #2 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 08:26:53', 1),
(78, 1, 'Emergency Siren Silenced', 'Emergency', 'Siren for alert #5 silenced by user ID 1 (admin).', '::1', '2026-09-19 08:26:58', 1),
(79, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #5 marked dispatched by user ID 1 (admin).', '::1', '2026-09-19 08:29:59', 1),
(80, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #5 marked resolved by user ID 1. Action: Security Patrol Dispatched to Unit. Notes: Gate patrol dispatched immediately to inspect location and secure premises', '::1', '2026-09-19 08:30:39', 1),
(81, 1, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260919-568 (Medical Emergency) triggered by System Admin (central_admin). Stakeholders: all_residents.', '', '2026-09-19 08:35:18', 1),
(82, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #6 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 08:38:02', 1),
(83, 1, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260919-960 (Medical Emergency) triggered by System Admin (central_admin). Stakeholders: all_residents.', '::1', '2026-09-19 10:10:29', 1),
(84, 1, 'Emergency Panic Triggered', 'Emergency', 'Alert SOS-260919-168 (Medical Emergency) triggered by System Admin (central_admin). Stakeholders: all_residents.', '::1', '2026-09-19 10:11:06', 1),
(85, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #8 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 10:11:44', 1),
(86, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #8 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 10:11:44', 1),
(87, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #8 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 10:11:45', 1),
(88, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #8 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 10:11:45', 1),
(89, 1, 'Emergency Alert dispatched', 'Emergency', 'Alert #9 marked dispatched by user ID 1 (admin).', '::1', '2026-09-19 10:11:50', 1),
(90, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #9 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 10:11:51', 1),
(91, 1, 'Emergency Alert Closed', 'Emergency', 'Alert #9 marked false_alarm by user ID 1. Action: Verified False Alarm - Resident Safe. Notes: Initiator canceled alert', '::1', '2026-09-19 10:11:52', 1),
(92, 1, 'Roster Status Update', 'Security', 'Roster #1 status updated to on_duty.', '::1', '2026-09-19 10:33:12', 1),
(93, 1, 'Roster Status Update', 'Security', 'Roster #1 status updated to completed.', '::1', '2026-09-19 10:33:21', 1),
(94, 1, 'Roster Status Update', 'Security', 'Roster #2 status updated to on_duty.', '::1', '2026-09-19 10:33:58', 1),
(95, 12, 'User Login', 'Auth', 'Logged in via Staff Portal successfully.', '::1', '2026-09-19 10:40:15', 1),
(96, 1, 'Roster Schedule Distributed', 'Security', 'Distributed 22 shift(s) across 1 personnel (Femi Oke) from 2026-09-19 to 2026-10-18.', '::1', '2026-09-19 10:59:46', 1),
(97, 12, 'Security Clock-In', 'Security', 'Officer clocked in for shift #3.', '::1', '2026-09-19 11:00:20', 1),
(98, 1, 'Security Clock-Out', 'Security', 'Officer Femi Oke clocked out of Main Entrance Gate (Afternoon Shift).', '::1', '2026-09-19 11:04:18', 1),
(99, 12, 'Security Clock-Out', 'Security', 'Officer Femi Oke clocked out of Main Entrance Gate (Afternoon Shift).', '::1', '2026-09-19 11:29:38', 1),
(100, 1, 'Visitor Policy Updated', 'Settings', 'Resident visitor confirmation requirement set to Disabled', '::1', '2026-09-19 11:49:35', 1),
(101, 1, 'Visitor Checked Out', 'Security', 'Visitor David  Johnson checked out via Main Gate (Direct Clearance Mode)', '::1', '2026-09-19 11:49:56', 1),
(102, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-19 17:27:21', 1),
(103, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-19 19:37:58', 1),
(104, NULL, 'Resident Vehicle Entry', 'Security', 'Vehicle KJA-849-XA (VEH-00001) logged entry at Main Entrance Gate during Morning Security Shift', '', '2026-09-19 20:39:36', 1),
(105, NULL, 'Resident Vehicle Exit', 'Security', 'Vehicle KJA-849-XA (VEH-00001) logged exit at Main Entrance Gate during Morning Security Shift', '', '2026-09-19 20:39:36', 1),
(106, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-19 20:41:14', 1),
(107, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-21 10:07:13', 1),
(108, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-22 00:49:10', 1),
(109, 20, 'User Login', 'Auth', 'Logged in via Staff Portal successfully.', '', '2026-09-22 10:45:11', 1),
(110, 30, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '', '2026-09-22 10:45:11', 1),
(111, 1, 'Staff Force Onboarding', 'Security Operations', 'Successfully provisioned and deployed 10 Security Officers across gate posts and sectors with active credentials.', '127.0.0.1', '2026-09-22 11:30:08', 1),
(112, 1, 'Residential Onboarding', 'Resident Management', 'Successfully registered and housed 10 Primary Residents across Zone 1, Zone 2, Zone 3, and Zone 4.', '127.0.0.1', '2026-09-22 11:30:08', 1),
(113, 1, 'Infrastructure Expansion', 'Property Portfolio', 'Established complete spatial hierarchy: 28 Streets (7 per Zone) and 280 Buildings with multi-unit apartment complexes.', '127.0.0.1', '2026-09-22 11:30:08', 1),
(114, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-22 11:36:43', 1),
(115, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-22 11:38:08', 1),
(116, 30, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-24 13:22:07', 1),
(117, 30, 'Contact Change Requested', 'Settings', 'Resident Babatunde Adebayo requested new email: \'\', new phone: \'07040352560\'', '::1', '2026-09-24 13:53:01', 1),
(118, 1, 'Contact Change Approved', 'Residents Registry', 'Approved contact change for User #30 ( / 07040352560)', '::1', '2026-09-24 14:06:15', 1),
(119, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-24 16:11:35', 1),
(120, 30, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-24 17:10:16', 1),
(121, 1, 'User Login', 'Auth', 'Logged in via Admin Portal successfully.', '::1', '2026-09-24 23:49:38', 1),
(122, 30, 'User Login', 'Auth', 'Logged in via Resident Portal successfully.', '::1', '2026-09-24 23:57:53', 1);

-- --------------------------------------------------------

--
-- Table structure for table `buildings`
--

CREATE TABLE `buildings` (
  `id` int(11) NOT NULL,
  `street_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `total_floors` varchar(50) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','archived') DEFAULT 'active',
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  `property_number` varchar(50) DEFAULT NULL,
  `category` enum('Residential','Commercial') DEFAULT 'Residential',
  `commercial_data` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `buildings`
--

INSERT INTO `buildings` (`id`, `street_id`, `name`, `type`, `total_floors`, `created_at`, `status`, `custom_id`, `image_path`, `registration_date`, `estate_id`, `property_number`, `category`, `commercial_data`) VALUES
(1, 1, 'Sunrise Apartments', 'Apartment', '1', '2026-03-30 11:36:27', 'active', 'BLD-00001', NULL, NULL, 1, NULL, 'Residential', NULL),
(2, 1, 'Sunset Plaza', 'Apartment', '1', '2026-03-30 11:36:27', 'active', 'BLD-00002', NULL, NULL, 1, NULL, 'Residential', NULL),
(3, 5, 'Ajao house', 'Duplex', '2', '2026-09-11 17:20:35', 'active', 'BLD-00003', '', '2026-09-11', 1, '22a', 'Commercial', NULL),
(4, 1, 'Palm Avenue Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00004', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(5, 1, 'Palm Avenue Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00005', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(6, 1, 'Palm Avenue View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00006', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(7, 1, 'Palm Avenue Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00007', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(8, 1, 'Palm Avenue Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00008', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(9, 1, 'Palm Avenue Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00009', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(10, 1, 'Palm Avenue Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00010', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(11, 1, 'Palm Avenue Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00011', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(12, 2, 'Orchid Road Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00012', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(13, 2, 'Orchid Road Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00013', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(14, 2, 'Orchid Road Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00014', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(15, 2, 'Orchid Road Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00015', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(16, 2, 'Orchid Road View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00016', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(17, 2, 'Orchid Road Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00017', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(18, 2, 'Orchid Road Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00018', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(19, 2, 'Orchid Road Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00019', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(20, 2, 'Orchid Road Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00020', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(21, 2, 'Orchid Road Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00021', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(22, 3, 'Hibiscus Lane Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00022', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(23, 3, 'Hibiscus Lane Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00023', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(24, 3, 'Hibiscus Lane Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00024', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(25, 3, 'Hibiscus Lane Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00025', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(26, 3, 'Hibiscus Lane View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00026', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(27, 3, 'Hibiscus Lane Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00027', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(28, 3, 'Hibiscus Lane Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00028', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(29, 3, 'Hibiscus Lane Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00029', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(30, 3, 'Hibiscus Lane Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00030', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(31, 3, 'Hibiscus Lane Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00031', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(32, 7, 'Sapphire Crescent Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00032', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(33, 7, 'Sapphire Crescent Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00033', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(34, 7, 'Sapphire Crescent Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00034', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(35, 7, 'Sapphire Crescent Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00035', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(36, 7, 'Sapphire Crescent View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00036', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(37, 7, 'Sapphire Crescent Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00037', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(38, 7, 'Sapphire Crescent Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00038', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(39, 7, 'Sapphire Crescent Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00039', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(40, 7, 'Sapphire Crescent Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00040', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(41, 7, 'Sapphire Crescent Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00041', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(42, 8, 'Emerald Boulevard Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00042', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(43, 8, 'Emerald Boulevard Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00043', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(44, 8, 'Emerald Boulevard Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00044', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(45, 8, 'Emerald Boulevard Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00045', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(46, 8, 'Emerald Boulevard View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00046', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(47, 8, 'Emerald Boulevard Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00047', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(48, 8, 'Emerald Boulevard Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00048', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(49, 8, 'Emerald Boulevard Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00049', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(50, 8, 'Emerald Boulevard Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00050', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(51, 8, 'Emerald Boulevard Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00051', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(52, 9, 'Diamond Close Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00052', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(53, 9, 'Diamond Close Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00053', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(54, 9, 'Diamond Close Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00054', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(55, 9, 'Diamond Close Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00055', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(56, 9, 'Diamond Close View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00056', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(57, 9, 'Diamond Close Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00057', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(58, 9, 'Diamond Close Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00058', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(59, 9, 'Diamond Close Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00059', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(60, 9, 'Diamond Close Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00060', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(61, 9, 'Diamond Close Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00061', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(62, 10, 'Crystal Court Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00062', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(63, 10, 'Crystal Court Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00063', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(64, 10, 'Crystal Court Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00064', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(65, 10, 'Crystal Court Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00065', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(66, 10, 'Crystal Court View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00066', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(67, 10, 'Crystal Court Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00067', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(68, 10, 'Crystal Court Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00068', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(69, 10, 'Crystal Court Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00069', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(70, 10, 'Crystal Court Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00070', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(71, 10, 'Crystal Court Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00071', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(72, 4, 'West Boulevard Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00072', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(73, 4, 'West Boulevard Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00073', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(74, 4, 'West Boulevard Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00074', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(75, 4, 'West Boulevard Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00075', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(76, 4, 'West Boulevard View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00076', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(77, 4, 'West Boulevard Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00077', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(78, 4, 'West Boulevard Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00078', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(79, 4, 'West Boulevard Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00079', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(80, 4, 'West Boulevard Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00080', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(81, 4, 'West Boulevard Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00081', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(82, 11, 'Silver Spring Way Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00082', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(83, 11, 'Silver Spring Way Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00083', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(84, 11, 'Silver Spring Way Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00084', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(85, 11, 'Silver Spring Way Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00085', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(86, 11, 'Silver Spring Way View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00086', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(87, 11, 'Silver Spring Way Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00087', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(88, 11, 'Silver Spring Way Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00088', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(89, 11, 'Silver Spring Way Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00089', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(90, 11, 'Silver Spring Way Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00090', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(91, 11, 'Silver Spring Way Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00091', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(92, 12, 'Magnolia Drive Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00092', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(93, 12, 'Magnolia Drive Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00093', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(94, 12, 'Magnolia Drive Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00094', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(95, 12, 'Magnolia Drive Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00095', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(96, 12, 'Magnolia Drive View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00096', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(97, 12, 'Magnolia Drive Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00097', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(98, 12, 'Magnolia Drive Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00098', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(99, 12, 'Magnolia Drive Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00099', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(100, 12, 'Magnolia Drive Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00100', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(101, 12, 'Magnolia Drive Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00101', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(102, 13, 'Sunset Ridge Road Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00102', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(103, 13, 'Sunset Ridge Road Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00103', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(104, 13, 'Sunset Ridge Road Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00104', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(105, 13, 'Sunset Ridge Road Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00105', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(106, 13, 'Sunset Ridge Road View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00106', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(107, 13, 'Sunset Ridge Road Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00107', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(108, 13, 'Sunset Ridge Road Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00108', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(109, 13, 'Sunset Ridge Road Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00109', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(110, 13, 'Sunset Ridge Road Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00110', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(111, 13, 'Sunset Ridge Road Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00111', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(112, 14, 'Canyon View Lane Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00112', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(113, 14, 'Canyon View Lane Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00113', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(114, 14, 'Canyon View Lane Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00114', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(115, 14, 'Canyon View Lane Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00115', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(116, 14, 'Canyon View Lane View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00116', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(117, 14, 'Canyon View Lane Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00117', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(118, 14, 'Canyon View Lane Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00118', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(119, 14, 'Canyon View Lane Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00119', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(120, 14, 'Canyon View Lane Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00120', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(121, 14, 'Canyon View Lane Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00121', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(122, 15, 'Lakeside Parkway Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00122', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(123, 15, 'Lakeside Parkway Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00123', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(124, 15, 'Lakeside Parkway Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00124', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(125, 15, 'Lakeside Parkway Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00125', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(126, 15, 'Lakeside Parkway View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00126', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(127, 15, 'Lakeside Parkway Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00127', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(128, 15, 'Lakeside Parkway Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00128', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(129, 15, 'Lakeside Parkway Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00129', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(130, 15, 'Lakeside Parkway Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00130', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(131, 15, 'Lakeside Parkway Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00131', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(132, 16, 'Sycamore Terrace Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00132', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(133, 16, 'Sycamore Terrace Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00133', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(134, 16, 'Sycamore Terrace Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00134', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(135, 16, 'Sycamore Terrace Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00135', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(136, 16, 'Sycamore Terrace View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00136', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(137, 16, 'Sycamore Terrace Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00137', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(138, 16, 'Sycamore Terrace Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00138', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(139, 16, 'Sycamore Terrace Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00139', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(140, 16, 'Sycamore Terrace Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00140', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(141, 16, 'Sycamore Terrace Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00141', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(142, 5, 'Felix street Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00142', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(143, 5, 'Felix street Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00143', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(144, 5, 'Felix street Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00144', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(145, 5, 'Felix street View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00145', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(146, 5, 'Felix street Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00146', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(147, 5, 'Felix street Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00147', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(148, 5, 'Felix street Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00148', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(149, 5, 'Felix street Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00149', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(150, 5, 'Felix street Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00150', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(151, 6, 'Johnn street Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00151', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(152, 6, 'Johnn street Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00152', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(153, 6, 'Johnn street Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00153', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(154, 6, 'Johnn street Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00154', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(155, 6, 'Johnn street View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00155', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(156, 6, 'Johnn street Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00156', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(157, 6, 'Johnn street Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00157', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(158, 6, 'Johnn street Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00158', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(159, 6, 'Johnn street Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00159', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(160, 6, 'Johnn street Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00160', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(161, 17, 'Golden Gate Avenue Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00161', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(162, 17, 'Golden Gate Avenue Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00162', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(163, 17, 'Golden Gate Avenue Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00163', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(164, 17, 'Golden Gate Avenue Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00164', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(165, 17, 'Golden Gate Avenue View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00165', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(166, 17, 'Golden Gate Avenue Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00166', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(167, 17, 'Golden Gate Avenue Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00167', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(168, 17, 'Golden Gate Avenue Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00168', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(169, 17, 'Golden Gate Avenue Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00169', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(170, 17, 'Golden Gate Avenue Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00170', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(171, 18, 'Ruby Way Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00171', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(172, 18, 'Ruby Way Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00172', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(173, 18, 'Ruby Way Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00173', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(174, 18, 'Ruby Way Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00174', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(175, 18, 'Ruby Way View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00175', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(176, 18, 'Ruby Way Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00176', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(177, 18, 'Ruby Way Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00177', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(178, 18, 'Ruby Way Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00178', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(179, 18, 'Ruby Way Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00179', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(180, 18, 'Ruby Way Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00180', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(181, 19, 'Topaz Gardens Close Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00181', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(182, 19, 'Topaz Gardens Close Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00182', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(183, 19, 'Topaz Gardens Close Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00183', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(184, 19, 'Topaz Gardens Close Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00184', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(185, 19, 'Topaz Gardens Close View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00185', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(186, 19, 'Topaz Gardens Close Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00186', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(187, 19, 'Topaz Gardens Close Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00187', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(188, 19, 'Topaz Gardens Close Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00188', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(189, 19, 'Topaz Gardens Close Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00189', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(190, 19, 'Topaz Gardens Close Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00190', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(191, 20, 'Amber Hill Road Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00191', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(192, 20, 'Amber Hill Road Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00192', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(193, 20, 'Amber Hill Road Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00193', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(194, 20, 'Amber Hill Road Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00194', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(195, 20, 'Amber Hill Road View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00195', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(196, 20, 'Amber Hill Road Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00196', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(197, 20, 'Amber Hill Road Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00197', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(198, 20, 'Amber Hill Road Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00198', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(199, 20, 'Amber Hill Road Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00199', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(200, 20, 'Amber Hill Road Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00200', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(201, 21, 'Coral Reef Drive Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00201', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(202, 21, 'Coral Reef Drive Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00202', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(203, 21, 'Coral Reef Drive Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00203', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(204, 21, 'Coral Reef Drive Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00204', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(205, 21, 'Coral Reef Drive View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00205', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(206, 21, 'Coral Reef Drive Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00206', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(207, 21, 'Coral Reef Drive Residences 7', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00207', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(208, 21, 'Coral Reef Drive Haven 8', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00208', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(209, 21, 'Coral Reef Drive Villas 9', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00209', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(210, 21, 'Coral Reef Drive Towers 10', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00210', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(211, 22, 'Northern Star Crescent Heights 1', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00211', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(212, 22, 'Northern Star Crescent Court 2', 'Villa', '2', '2026-09-22 10:08:53', 'active', 'BLD-00212', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(213, 22, 'Northern Star Crescent Terrace 3', 'Duplex', '2', '2026-09-22 10:08:53', 'active', 'BLD-00213', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(214, 22, 'Northern Star Crescent Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:53', 'active', 'BLD-00214', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(215, 22, 'Northern Star Crescent View 5', 'Apartment', '4', '2026-09-22 10:08:53', 'active', 'BLD-00215', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(216, 22, 'Northern Star Crescent Manor 6', 'Apartment', '3', '2026-09-22 10:08:53', 'active', 'BLD-00216', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(217, 22, 'Northern Star Crescent Residences 7', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00217', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(218, 22, 'Northern Star Crescent Haven 8', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00218', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(219, 22, 'Northern Star Crescent Villas 9', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00219', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(220, 22, 'Northern Star Crescent Towers 10', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00220', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(221, 23, 'Pine Valley Way Heights 1', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00221', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(222, 23, 'Pine Valley Way Court 2', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00222', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(223, 23, 'Pine Valley Way Terrace 3', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00223', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(224, 23, 'Pine Valley Way Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00224', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(225, 23, 'Pine Valley Way View 5', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00225', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(226, 23, 'Pine Valley Way Manor 6', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00226', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(227, 23, 'Pine Valley Way Residences 7', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00227', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(228, 23, 'Pine Valley Way Haven 8', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00228', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(229, 23, 'Pine Valley Way Villas 9', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00229', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(230, 23, 'Pine Valley Way Towers 10', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00230', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(231, 24, 'Cedar Crest Lane Heights 1', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00231', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(232, 24, 'Cedar Crest Lane Court 2', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00232', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(233, 24, 'Cedar Crest Lane Terrace 3', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00233', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(234, 24, 'Cedar Crest Lane Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00234', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(235, 24, 'Cedar Crest Lane View 5', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00235', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(236, 24, 'Cedar Crest Lane Manor 6', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00236', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(237, 24, 'Cedar Crest Lane Residences 7', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00237', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(238, 24, 'Cedar Crest Lane Haven 8', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00238', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(239, 24, 'Cedar Crest Lane Villas 9', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00239', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(240, 24, 'Cedar Crest Lane Towers 10', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00240', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(241, 25, 'Maple Grove Close Heights 1', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00241', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(242, 25, 'Maple Grove Close Court 2', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00242', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(243, 25, 'Maple Grove Close Terrace 3', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00243', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(244, 25, 'Maple Grove Close Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00244', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(245, 25, 'Maple Grove Close View 5', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00245', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(246, 25, 'Maple Grove Close Manor 6', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00246', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(247, 25, 'Maple Grove Close Residences 7', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00247', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(248, 25, 'Maple Grove Close Haven 8', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00248', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(249, 25, 'Maple Grove Close Villas 9', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00249', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(250, 25, 'Maple Grove Close Towers 10', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00250', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(251, 26, 'Aspen Heights Road Heights 1', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00251', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(252, 26, 'Aspen Heights Road Court 2', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00252', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(253, 26, 'Aspen Heights Road Terrace 3', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00253', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(254, 26, 'Aspen Heights Road Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00254', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(255, 26, 'Aspen Heights Road View 5', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00255', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(256, 26, 'Aspen Heights Road Manor 6', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00256', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(257, 26, 'Aspen Heights Road Residences 7', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00257', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(258, 26, 'Aspen Heights Road Haven 8', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00258', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(259, 26, 'Aspen Heights Road Villas 9', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00259', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(260, 26, 'Aspen Heights Road Towers 10', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00260', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(261, 27, 'Willow Brook Court Heights 1', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00261', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(262, 27, 'Willow Brook Court Court 2', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00262', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(263, 27, 'Willow Brook Court Terrace 3', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00263', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(264, 27, 'Willow Brook Court Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00264', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(265, 27, 'Willow Brook Court View 5', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00265', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(266, 27, 'Willow Brook Court Manor 6', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00266', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(267, 27, 'Willow Brook Court Residences 7', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00267', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(268, 27, 'Willow Brook Court Haven 8', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00268', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(269, 27, 'Willow Brook Court Villas 9', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00269', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(270, 27, 'Willow Brook Court Towers 10', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00270', NULL, '2026-09-22', 1, '10', 'Residential', NULL),
(271, 28, 'Birchwood Way Heights 1', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00271', NULL, '2026-09-22', 1, '1', 'Residential', NULL),
(272, 28, 'Birchwood Way Court 2', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00272', NULL, '2026-09-22', 1, '2', 'Residential', NULL),
(273, 28, 'Birchwood Way Terrace 3', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00273', NULL, '2026-09-22', 1, '3', 'Residential', NULL),
(274, 28, 'Birchwood Way Plaza 4', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00274', NULL, '2026-09-22', 1, '4', 'Commercial', NULL),
(275, 28, 'Birchwood Way View 5', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00275', NULL, '2026-09-22', 1, '5', 'Residential', NULL),
(276, 28, 'Birchwood Way Manor 6', 'Apartment', '3', '2026-09-22 10:08:54', 'active', 'BLD-00276', NULL, '2026-09-22', 1, '6', 'Residential', NULL),
(277, 28, 'Birchwood Way Residences 7', 'Villa', '2', '2026-09-22 10:08:54', 'active', 'BLD-00277', NULL, '2026-09-22', 1, '7', 'Residential', NULL),
(278, 28, 'Birchwood Way Haven 8', 'Duplex', '2', '2026-09-22 10:08:54', 'active', 'BLD-00278', NULL, '2026-09-22', 1, '8', 'Residential', NULL),
(279, 28, 'Birchwood Way Villas 9', 'Bungalow', '1', '2026-09-22 10:08:54', 'active', 'BLD-00279', NULL, '2026-09-22', 1, '9', 'Commercial', NULL),
(280, 28, 'Birchwood Way Towers 10', 'Apartment', '4', '2026-09-22 10:08:54', 'active', 'BLD-00280', NULL, '2026-09-22', 1, '10', 'Residential', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `building_floor_types`
--

CREATE TABLE `building_floor_types` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `building_statuses`
--

CREATE TABLE `building_statuses` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `building_statuses`
--

INSERT INTO `building_statuses` (`id`, `name`) VALUES
(1, 'Active'),
(4, 'Archived'),
(3, 'Maintenance'),
(2, 'Under Construction');

-- --------------------------------------------------------

--
-- Table structure for table `building_types`
--

CREATE TABLE `building_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `building_types`
--

INSERT INTO `building_types` (`id`, `name`) VALUES
(1, 'Apartment'),
(5, 'Bungalow'),
(3, 'Commercial'),
(4, 'Duplex'),
(2, 'Villa');

-- --------------------------------------------------------

--
-- Table structure for table `commercial_field_definitions`
--

CREATE TABLE `commercial_field_definitions` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `field_label` varchar(100) NOT NULL,
  `field_type` enum('text','number','dropdown','date') DEFAULT 'text',
  `field_options` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `commercial_field_definitions`
--

INSERT INTO `commercial_field_definitions` (`id`, `estate_id`, `field_label`, `field_type`, `field_options`, `is_required`, `created_at`) VALUES
(1, 1, 'Tax ID', 'number', '', 1, '2026-04-13 13:24:54'),
(2, 1, 'VAT Number', 'number', '', 0, '2026-04-13 14:00:02');

-- --------------------------------------------------------

--
-- Table structure for table `community_chat`
--

CREATE TABLE `community_chat` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `community_chat`
--

INSERT INTO `community_chat` (`id`, `user_id`, `message`, `created_at`, `estate_id`) VALUES
(1, 18, 'Good day neighbors, hope everyone has a wonderful weekend!', '2026-09-12 16:20:08', 1);

-- --------------------------------------------------------

--
-- Table structure for table `contact_change_requests`
--

CREATE TABLE `contact_change_requests` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL,
  `zone_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `resident_id` int(11) DEFAULT NULL,
  `current_email` varchar(100) DEFAULT NULL,
  `requested_email` varchar(100) DEFAULT NULL,
  `current_phone` varchar(20) DEFAULT NULL,
  `requested_phone` varchar(20) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contact_change_requests`
--

INSERT INTO `contact_change_requests` (`id`, `estate_id`, `zone_id`, `user_id`, `resident_id`, `current_email`, `requested_email`, `current_phone`, `requested_phone`, `reason`, `status`, `admin_notes`, `reviewed_by`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(3, 1, 1, 30, 13, 'res.adebayo@yopmail.com', '', '+234 803 400 0001', '07040352560', 'Mistake in Input', 'approved', 'Approved by Central Administrator', 1, '2026-09-24 15:06:15', '2026-09-24 13:48:25', '2026-09-24 14:06:15');

-- --------------------------------------------------------

--
-- Table structure for table `domestic_staff_roles`
--

CREATE TABLE `domestic_staff_roles` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `domestic_staff_roles`
--

INSERT INTO `domestic_staff_roles` (`id`, `name`) VALUES
(2, 'Cook'),
(1, 'Driver'),
(5, 'Gardner'),
(3, 'Maid'),
(4, 'Nanny'),
(6, 'Security');

-- --------------------------------------------------------

--
-- Table structure for table `domestic_staff_status`
--

CREATE TABLE `domestic_staff_status` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `domestic_staff_status`
--

INSERT INTO `domestic_staff_status` (`id`, `name`) VALUES
(2, 'Daily'),
(1, 'Live-in'),
(3, 'Temporary'),
(4, 'Weekend Only');

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `recipient_email` varchar(191) NOT NULL,
  `recipient_name` varchar(191) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `template` varchar(64) NOT NULL,
  `reference_id` varchar(64) DEFAULT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_logs`
--

INSERT INTO `email_logs` (`id`, `estate_id`, `recipient_email`, `recipient_name`, `subject`, `template`, `reference_id`, `status`, `error_message`, `created_at`) VALUES
(1, 1, 'test@example.com', 'Test Resident', 'Welcome Test', 'test_diagnostic', 'TEST-001', 'failed', 'Could not instantiate mail function.', '2026-09-10 09:43:43'),
(2, 1, 'peter@gmail.com', 'PETER AKINSANYA', 'Invoice #INV-20260909-0001 - Monthly Estate Security & Waste Levy', 'invoice', 'INV-20260909-0001', 'failed', 'Could not instantiate mail function.', '2026-09-10 09:43:45'),
(3, 1, 'peter@gmail.com', 'PETER AKINSANYA', 'Payment Receipt #REC-260909-972 - ₦2,500.00', 'receipt', 'REC-260909-972', 'failed', 'Could not instantiate mail function.', '2026-09-10 09:43:47'),
(4, 1, 'peter@gmail.com', 'PETER AKINSANYA', 'Welcome to No limit Buzz - Login Credentials', 'welcome', '11', 'failed', 'Could not instantiate mail function.', '2026-09-10 09:43:49'),
(5, 1, 'estate@estate.nolimitbuzz.com.ng', 'Administrator', 'SMTP Test Verification - No limit Buzz', 'test_diagnostic', '', 'sent', NULL, '2026-09-10 09:56:38'),
(6, 1, 'estate@estate.nolimitbuzz.com.ng', 'Administrator', 'SMTP Test Verification - No limit Buzz', 'test_diagnostic', '', 'sent', NULL, '2026-09-10 17:50:21'),
(7, 1, 'peter@gmail.com', 'PETER AKINSANYA', 'Invoice #INV-20260909-0001 - Monthly Estate Security & Waste Levy', 'invoice', 'INV-20260909-0001', 'sent', NULL, '2026-09-10 17:52:21'),
(8, 1, 'peter@gmail.com', 'PETER AKINSANYA', 'Payment Receipt #REC-260909-972 - ₦2,500.00', 'receipt', 'REC-260909-972', 'sent', NULL, '2026-09-10 17:52:25'),
(9, 1, 'akinsanyajtb@gmail.com', 'JOHN AKINSANYA', 'Welcome to No limit Buzz - Login Credentials', 'welcome', '17', 'sent', NULL, '2026-09-11 18:26:35'),
(10, 1, 'akinsanyajtb@gmail.com', 'JOHN AKINSANYA', 'Invoice #INV-260911-820 - Developmental levy', 'invoice', 'INV-260911-820', 'sent', NULL, '2026-09-11 18:36:47'),
(11, 1, 'akinsanyajtb@gmail.com', 'JOHN AKINSANYA', 'Payment Receipt #REC-260911-857 - ₦100,000.00', 'receipt', 'REC-260911-857', 'sent', NULL, '2026-09-11 18:40:15'),
(12, 1, 'femi@yopmail.com', 'Femi Oke', 'Welcome to No limit Buzz - Login Credentials', 'welcome', '18', 'sent', NULL, '2026-09-11 18:44:43'),
(13, 1, 'akinsanyajtb@gmail.com', 'JOHN AKINSANYA', 'Invoice #INV-260911-841 - Developmental levy', 'invoice', 'INV-260911-841', 'sent', NULL, '2026-09-11 20:18:43'),
(14, 1, 'femi@yopmail.com', 'Femi Oke', 'Invoice #INV-260911-784 - Developmental levy', 'invoice', 'INV-260911-784', 'sent', NULL, '2026-09-11 20:18:46'),
(15, 1, 'femi@yopmail.com', 'Femi Oke', 'Payment Receipt #REC-260911-788 - ₦100,000.00', 'receipt', 'REC-260911-788', 'sent', NULL, '2026-09-11 20:20:42'),
(16, 1, 'akinsanyajtb@gmail.com', 'David  Johnson', 'Visitor Gate Pass: EST-328C0B', 'visitor_pass', 'EST-328C0B', 'sent', NULL, '2026-09-14 17:01:21'),
(17, 1, 'femi@yopmail.com', 'Femi Oke', 'Pass Created: David  Johnson (Code: EST-328C0B)', 'visitor_pass', 'EST-328C0B', 'sent', NULL, '2026-09-14 17:01:24'),
(18, 1, 'peter@gmail.com', 'PETER AKINSANYA', 'Invoice #INV-260915-759 - Security & Vigilante Levy', 'invoice', 'INV-260915-759', 'sent', NULL, '2026-09-15 10:03:42'),
(19, 1, 'akinsanyajtb@gmail.com', 'JOHN AKINSANYA', 'Invoice #INV-260915-571 - Security & Vigilante Levy', 'invoice', 'INV-260915-571', 'sent', NULL, '2026-09-15 10:03:45'),
(20, 1, 'femi@yopmail.com', 'Femi Oke', 'Invoice #INV-260915-288 - Security & Vigilante Levy', 'invoice', 'INV-260915-288', 'sent', NULL, '2026-09-15 10:03:49'),
(21, 1, 'femi@yopmail.com', 'Femi Oke', 'Visitor Arrived: David  Johnson at Main Gate', 'visitor_arrival', 'EST-328C0B', 'sent', NULL, '2026-09-15 10:06:40'),
(22, 1, 'admin@admin.com', 'System Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-568 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-19 09:35:22'),
(23, 1, 'superadmin@admin.com', 'Super Administrator', '???? [URGENT SOS] Medical Emergency - SOS-260919-568 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: superadmin@admin.com: The mail server could not deliver mail to superadmin@admin.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-19 09:35:25'),
(24, 1, 'peter@gmail.com', 'PETER AKINSANYA', '???? [URGENT SOS] Medical Emergency - SOS-260919-568 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 09:35:29'),
(25, 1, 'femi@gmail.com', 'Femi Oke', '???? [URGENT SOS] Medical Emergency - SOS-260919-568 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 09:35:34'),
(26, 1, 'admin@admin.com', 'System Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-19 11:10:32'),
(27, 1, 'superadmin@admin.com', 'Super Administrator', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: superadmin@admin.com: The mail server could not deliver mail to superadmin@admin.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-19 11:10:35'),
(28, 1, 'peter@gmail.com', 'PETER AKINSANYA', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:10:39'),
(29, 1, 'femi@gmail.com', 'Femi Oke', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:10:44'),
(30, 1, 'zone2admin@estate.com', 'Zone 2 Manager', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone2admin@estate.com: The mail server could not deliver mail to zone2admin@estate.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-19 11:10:47'),
(31, 1, 'zoneabc@gmail.com', 'Zone ABC Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:10:51'),
(32, 1, 'zone1@estate.com', 'Zone 1 Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone1@estate.com: The mail server could not deliver mail to zone1@estate.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-19 11:10:54'),
(33, 1, 'akinsanyajtb@gmail.com', 'JOHN AKINSANYA', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:10:58'),
(34, 1, 'femi@yopmail.com', 'Femi Oke', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:02'),
(35, 1, 'zonedef@gmail.com', 'Zone DEF Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-960 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:06'),
(36, 1, 'admin@admin.com', 'System Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-19 11:11:09'),
(37, 1, 'superadmin@admin.com', 'Super Administrator', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: superadmin@admin.com: The mail server could not deliver mail to superadmin@admin.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-19 11:11:11'),
(38, 1, 'peter@gmail.com', 'PETER AKINSANYA', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:15'),
(39, 1, 'femi@gmail.com', 'Femi Oke', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:19'),
(40, 1, 'zone2admin@estate.com', 'Zone 2 Manager', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone2admin@estate.com: The mail server could not deliver mail to zone2admin@estate.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-19 11:11:22'),
(41, 1, 'zoneabc@gmail.com', 'Zone ABC Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:26'),
(42, 1, 'zone1@estate.com', 'Zone 1 Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone1@estate.com: The mail server could not deliver mail to zone1@estate.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-19 11:11:29'),
(43, 1, 'akinsanyajtb@gmail.com', 'JOHN AKINSANYA', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:34'),
(44, 1, 'femi@yopmail.com', 'Femi Oke', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:38'),
(45, 1, 'zonedef@gmail.com', 'Zone DEF Admin', '???? [URGENT SOS] Medical Emergency - SOS-260919-168 (Central Administration Command)', 'broadcast', '', 'sent', NULL, '2026-09-19 11:11:42'),
(46, 1, 'admin@admin.com', 'System Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 15:27:43'),
(47, 1, 'superadmin@admin.com', 'Super Administrator', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: superadmin@admin.com: The mail server could not deliver mail to superadmin@admin.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 15:27:46'),
(48, 1, 'femi@gmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:27:51'),
(49, 1, 'zone2admin@estate.com', 'Zone 2 Manager', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone2admin@estate.com: The mail server could not deliver mail to zone2admin@estate.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 15:27:54'),
(50, 1, 'zoneabc@gmail.com', 'Zone ABC Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:27:57'),
(51, 1, 'zone1@estate.com', 'Zone 1 Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone1@estate.com: The mail server could not deliver mail to zone1@estate.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 15:28:00'),
(52, 1, 'femi@yopmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:04'),
(53, 1, 'guard.samuel@yopmail.com', 'Samuel Okon', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:07'),
(54, 1, 'guard.musa@yopmail.com', 'Musa Bello', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:12'),
(55, 1, 'guard.david@yopmail.com', 'David Adeleke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:15'),
(56, 1, 'guard.ibrahim@yopmail.com', 'Ibrahim Yakubu', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:19'),
(57, 1, 'guard.emeka@yopmail.com', 'Emeka Okafor', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:22'),
(58, 1, 'guard.solomon@yopmail.com', 'Solomon Bamidele', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:26'),
(59, 1, 'guard.usman@yopmail.com', 'Usman Danjuma', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:30'),
(60, 1, 'guard.victor@yopmail.com', 'Victor Eze', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:35'),
(61, 1, 'guard.kabir@yopmail.com', 'Kabir Aliyu', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:38'),
(62, 1, 'guard.taiwo@yopmail.com', 'Taiwo Oladipo', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:42'),
(63, 1, 'res.adebayo@yopmail.com', 'Babatunde Adebayo', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:46'),
(64, 1, 'res.chioma@yopmail.com', 'Chioma Nnamdi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:50'),
(65, 1, 'res.olumide@yopmail.com', 'Olumide Bakare', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:54'),
(66, 1, 'res.fatima@yopmail.com', 'Fatima Abdullahi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:28:57'),
(67, 1, 'res.chukwuma@yopmail.com', 'Chukwuma Obi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:01'),
(68, 1, 'res.folashade@yopmail.com', 'Folashade Ajayi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:05'),
(69, 1, 'res.danladi@yopmail.com', 'Danladi Garba', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:08'),
(70, 1, 'res.blessing@yopmail.com', 'Blessing Umeh', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:12'),
(71, 1, 'res.kayode@yopmail.com', 'Kayode Sowore', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:16'),
(72, 1, 'res.amina@yopmail.com', 'Amina Mohammed', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:20'),
(73, 1, 'admin@admin.com', 'System Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 15:29:45'),
(74, 1, 'superadmin@admin.com', 'Super Administrator', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: superadmin@admin.com: The mail server could not deliver mail to superadmin@admin.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 15:29:48'),
(75, 1, 'femi@gmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:51'),
(76, 1, 'zone2admin@estate.com', 'Zone 2 Manager', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone2admin@estate.com: The mail server could not deliver mail to zone2admin@estate.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 15:29:54'),
(77, 1, 'zoneabc@gmail.com', 'Zone ABC Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:29:57'),
(78, 1, 'zone1@estate.com', 'Zone 1 Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone1@estate.com: The mail server could not deliver mail to zone1@estate.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 15:30:00'),
(79, 1, 'femi@yopmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 15:30:04'),
(80, 1, 'test@example.com', 'Test User', 'Test Subject', 'test', '999', 'failed', 'SMTP Error: The following recipients failed: test@example.com: The mail server could not deliver mail to test@example.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 15:58:36'),
(81, 1, 'admin@admin.com', 'System Admin', 'Test Broadcast', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 16:08:05'),
(82, 1, 'admin@admin.com', 'System Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 16:17:06'),
(83, 1, 'superadmin@admin.com', 'Super Administrator', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: superadmin@admin.com: The mail server could not deliver mail to superadmin@admin.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 16:17:07'),
(84, 1, 'femi@gmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:09'),
(85, 1, 'zone2admin@estate.com', 'Zone 2 Manager', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone2admin@estate.com: The mail server could not deliver mail to zone2admin@estate.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 16:17:10'),
(86, 1, 'zoneabc@gmail.com', 'Zone ABC Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:12'),
(87, 1, 'zone1@estate.com', 'Zone 1 Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone1@estate.com: The mail server could not deliver mail to zone1@estate.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 16:17:13'),
(88, 1, 'femi@yopmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:15'),
(89, 1, 'guard.samuel@yopmail.com', 'Samuel Okon', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:16'),
(90, 1, 'guard.musa@yopmail.com', 'Musa Bello', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:18'),
(91, 1, 'guard.david@yopmail.com', 'David Adeleke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:20'),
(92, 1, 'guard.ibrahim@yopmail.com', 'Ibrahim Yakubu', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:22'),
(93, 1, 'guard.emeka@yopmail.com', 'Emeka Okafor', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:23'),
(94, 1, 'guard.solomon@yopmail.com', 'Solomon Bamidele', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:25'),
(95, 1, 'guard.usman@yopmail.com', 'Usman Danjuma', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:27'),
(96, 1, 'guard.victor@yopmail.com', 'Victor Eze', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:29'),
(97, 1, 'guard.kabir@yopmail.com', 'Kabir Aliyu', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:30'),
(98, 1, 'guard.taiwo@yopmail.com', 'Taiwo Oladipo', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:32'),
(99, 1, 'res.adebayo@yopmail.com', 'Babatunde Adebayo', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:34'),
(100, 1, 'res.chioma@yopmail.com', 'Chioma Nnamdi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:35'),
(101, 1, 'res.olumide@yopmail.com', 'Olumide Bakare', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:37'),
(102, 1, 'res.fatima@yopmail.com', 'Fatima Abdullahi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:39'),
(103, 1, 'res.chukwuma@yopmail.com', 'Chukwuma Obi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:41'),
(104, 1, 'res.folashade@yopmail.com', 'Folashade Ajayi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:42'),
(105, 1, 'res.danladi@yopmail.com', 'Danladi Garba', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:17:44'),
(106, 1, 'admin@admin.com', 'System Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: admin@admin.com: The mail server could not deliver mail to admin@admin.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 16:18:18'),
(107, 1, 'superadmin@admin.com', 'Super Administrator', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: superadmin@admin.com: The mail server could not deliver mail to superadmin@admin.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 16:18:19'),
(108, 1, 'femi@gmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:21'),
(109, 1, 'zone2admin@estate.com', 'Zone 2 Manager', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone2admin@estate.com: The mail server could not deliver mail to zone2admin@estate.com.  The\r\naccount or domain may not exist, they may be blacklisted, or missing the\r\nproper dns entries.\r\n', '2026-09-24 16:18:22'),
(110, 1, 'zoneabc@gmail.com', 'Zone ABC Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:24'),
(111, 1, 'zone1@estate.com', 'Zone 1 Admin', '[Estate Broadcast] General cleaning', 'broadcast', '', 'failed', 'SMTP Error: The following recipients failed: zone1@estate.com: The mail server could not deliver mail to zone1@estate.com.  The account or\r\ndomain may not exist, they may be blacklisted, or missing the proper dns\r\nentries.\r\n', '2026-09-24 16:18:25'),
(112, 1, 'femi@yopmail.com', 'Femi Oke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:27'),
(113, 1, 'guard.samuel@yopmail.com', 'Samuel Okon', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:29'),
(114, 1, 'guard.musa@yopmail.com', 'Musa Bello', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:30'),
(115, 1, 'guard.david@yopmail.com', 'David Adeleke', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:32'),
(116, 1, 'guard.ibrahim@yopmail.com', 'Ibrahim Yakubu', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:34'),
(117, 1, 'guard.emeka@yopmail.com', 'Emeka Okafor', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:36'),
(118, 1, 'guard.solomon@yopmail.com', 'Solomon Bamidele', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:37'),
(119, 1, 'guard.usman@yopmail.com', 'Usman Danjuma', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:39'),
(120, 1, 'guard.victor@yopmail.com', 'Victor Eze', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:41'),
(121, 1, 'guard.kabir@yopmail.com', 'Kabir Aliyu', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:43'),
(122, 1, 'guard.taiwo@yopmail.com', 'Taiwo Oladipo', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:44'),
(123, 1, 'res.adebayo@yopmail.com', 'Babatunde Adebayo', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:46'),
(124, 1, 'res.chioma@yopmail.com', 'Chioma Nnamdi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:48'),
(125, 1, 'res.olumide@yopmail.com', 'Olumide Bakare', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:50'),
(126, 1, 'res.fatima@yopmail.com', 'Fatima Abdullahi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:51'),
(127, 1, 'res.chukwuma@yopmail.com', 'Chukwuma Obi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:53'),
(128, 1, 'res.folashade@yopmail.com', 'Folashade Ajayi', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:55'),
(129, 1, 'res.danladi@yopmail.com', 'Danladi Garba', '[Estate Broadcast] General cleaning', 'broadcast', '', 'sent', NULL, '2026-09-24 16:18:57');

-- --------------------------------------------------------

--
-- Table structure for table `estates`
--

CREATE TABLE `estates` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `domain_prefix` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estates`
--

INSERT INTO `estates` (`id`, `name`, `domain_prefix`, `created_at`) VALUES
(1, 'Main Estate', 'main', '2026-04-10 14:52:40');

-- --------------------------------------------------------

--
-- Table structure for table `estate_announcements`
--

CREATE TABLE `estate_announcements` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `target_audience` varchar(50) DEFAULT 'all',
  `priority` enum('normal','important','urgent') DEFAULT 'normal',
  `sender_type` enum('admin','zone','system') DEFAULT 'admin',
  `sender_name` varchar(150) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `pin_to_top` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_announcements`
--

INSERT INTO `estate_announcements` (`id`, `estate_id`, `zone_id`, `title`, `content`, `target_audience`, `priority`, `sender_type`, `sender_name`, `status`, `pin_to_top`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 'Estate Security & Access Control Advisory', 'Please note that estate gates will undergo biometric scanner calibration on Saturday from 8:00 AM to 12:00 PM.', 'all', 'important', 'admin', 'Central Administration', 'active', 1, 1, '2026-09-15 08:11:23', '2026-09-15 08:11:23'),
(2, 1, 1, 'Zone 1 - Central Sector Monthly Sanitation & Clean-Up', 'All residents in Zone 1 - Central Sector are kindly requested to participate in this month\'s zonal clean-up exercise.', 'all', 'normal', 'zone', 'Zone 1 - Central Sector Hub', 'active', 0, 1, '2026-09-15 08:11:23', '2026-09-15 08:11:23'),
(3, 1, NULL, 'Estate Security & Access Control Advisory', 'Please note that estate gates will undergo biometric scanner calibration on Saturday from 8:00 AM to 12:00 PM.', 'all', 'important', 'admin', 'Central Administration', 'active', 1, 1, '2026-09-15 08:11:57', '2026-09-15 08:11:57'),
(4, 1, 1, 'Zone 1 - Central Sector Monthly Sanitation & Clean-Up', 'All residents in Zone 1 - Central Sector are kindly requested to participate in this month\'s zonal clean-up exercise.', 'all', 'normal', 'zone', 'Zone 1 - Central Sector Hub', 'active', 0, 1, '2026-09-15 08:11:57', '2026-09-15 08:11:57'),
(5, 1, NULL, 'EMERGENCY ALERT: Medical Emergency (SOS-260919-568)', 'Emergency situation reported at Central Administration Command by System Admin.\nDetails: Unit 4B severe asthma crisis - need first aid immediately\nSecurity response teams have been dispatched. Stand by for instructions.', 'all', 'urgent', 'system', 'Emergency Response Center', 'active', 1, 1, '2026-09-19 08:35:18', '2026-09-19 08:35:18'),
(6, 1, NULL, 'EMERGENCY ALERT: Medical Emergency (SOS-260919-960)', 'Emergency situation reported at Central Administration Command by System Admin.\nSecurity response teams have been dispatched. Stand by for instructions.', 'all', 'urgent', 'system', 'Emergency Response Center', 'active', 1, 1, '2026-09-19 10:10:29', '2026-09-19 10:10:29'),
(7, 1, NULL, 'EMERGENCY ALERT: Medical Emergency (SOS-260919-168)', 'Emergency situation reported at Central Administration Command by System Admin.\nSecurity response teams have been dispatched. Stand by for instructions.', 'all', 'urgent', 'system', 'Emergency Response Center', 'active', 1, 1, '2026-09-19 10:11:06', '2026-09-19 10:11:06'),
(8, 1, NULL, 'General cleaning', 'There would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity', 'all', 'normal', 'admin', 'Central Administration', 'active', 1, 1, '2026-09-24 14:27:40', '2026-09-24 14:27:40'),
(10, 1, NULL, 'General cleaning', 'There would be a general cleaning across the Estate on Saturday 26th of september 2026', 'all', 'normal', 'admin', 'Central Administration', 'active', 1, 1, '2026-09-24 15:17:02', '2026-09-24 15:17:02'),
(11, 1, NULL, 'General cleaning', 'There would be a general cleaning across the Estate on Saturday 26th of september 2026', 'all', 'normal', 'admin', 'Central Administration', 'active', 1, 1, '2026-09-24 15:18:15', '2026-09-24 15:18:15');

-- --------------------------------------------------------

--
-- Table structure for table `estate_charges`
--

CREATE TABLE `estate_charges` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `charge_type` varchar(100) DEFAULT 'General Levy',
  `charge_type_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `frequency` varchar(100) DEFAULT 'Monthly',
  `due_day` int(11) DEFAULT 1,
  `allow_installments` tinyint(1) DEFAULT 1,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_charges`
--

INSERT INTO `estate_charges` (`id`, `estate_id`, `zone_id`, `name`, `charge_type`, `charge_type_id`, `description`, `amount`, `frequency`, `due_day`, `allow_installments`, `status`, `created_by`, `created_at`) VALUES
(1, 1, NULL, 'Monthly Sanitation Bill', 'General Levy', NULL, 'Enviromental sanitation', 20000.00, 'Monthly', 1, 1, 'Active', 1, '2026-09-05 15:40:29'),
(2, 1, 1, 'Solar Infrastructure Levy', 'General Levy', NULL, 'Quarterly maintenance and upkeep of zonal solar power grid.', 12500.00, 'Monthly', 5, 1, 'Active', NULL, '2026-09-11 17:05:17'),
(3, 1, 6, 'Developmental levy', 'General Levy', NULL, 'For dev of the estate', 100000.00, 'Quarterly', 5, 1, 'Active', 14, '2026-09-11 17:36:03'),
(5, 1, 1, 'Security & Vigilante Levy', 'Security & Vigilante Levy', 1, 'Local zone gate security and patrol', 25000.00, 'Monthly', 1, 1, 'Active', 1, '2026-09-12 12:10:43');

-- --------------------------------------------------------

--
-- Table structure for table `estate_emergency_actions`
--

CREATE TABLE `estate_emergency_actions` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `action_title` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `target_status` enum('resolved','dispatched','acknowledged','false_alarm') DEFAULT 'resolved',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_emergency_actions`
--

INSERT INTO `estate_emergency_actions` (`id`, `estate_id`, `action_title`, `description`, `target_status`, `display_order`, `is_active`, `created_at`) VALUES
(1, 1, 'Security Patrol Dispatched to Unit', 'Gate patrol dispatched immediately to inspect location and secure premises', 'dispatched', 1, 1, '2026-09-19 08:23:27'),
(2, 1, 'Medical Team Responded & Assisted', 'First-aid desk or external medical ambulance assisted resident on-site', 'resolved', 2, 1, '2026-09-19 08:23:27'),
(3, 1, 'Fire Neutralized / Extinguished', 'Estate fire extinguishers utilized, threat neutralized', 'resolved', 3, 1, '2026-09-19 08:23:27'),
(4, 1, 'Intruder Apprehended / Perimeter Secured', 'Security personnel intercepted threat and restored order', 'resolved', 4, 1, '2026-09-19 08:23:27'),
(5, 1, 'Verified False Alarm - Resident Safe', 'Direct contact established with resident; confirmed accidental trigger or safe', 'false_alarm', 5, 1, '2026-09-19 08:23:27'),
(6, 1, 'Direct Phone Contact Established', 'CSO / Admin called resident directly and resolved query', 'resolved', 6, 1, '2026-09-19 08:23:27'),
(7, 1, 'Police Escalation & Escort', 'State police emergency division notified and escorted on-site', 'resolved', 7, 1, '2026-09-19 08:23:27'),
(8, 1, 'Facility Hazard Repaired & Made Safe', 'Maintenance/technical staff isolated electrical or water hazard', 'resolved', 8, 1, '2026-09-19 08:23:27');

-- --------------------------------------------------------

--
-- Table structure for table `estate_emergency_alerts`
--

CREATE TABLE `estate_emergency_alerts` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `alert_code` varchar(50) NOT NULL,
  `sender_type` enum('resident','zone_admin','central_admin') NOT NULL DEFAULT 'resident',
  `sender_id` int(11) NOT NULL,
  `sender_name` varchar(150) DEFAULT NULL,
  `sender_phone` varchar(50) DEFAULT NULL,
  `target_scope` enum('estate_wide','zone') DEFAULT 'estate_wide',
  `target_stakeholders` enum('all_residents','guards_only','guards_and_admin','guards_and_medical') DEFAULT 'all_residents',
  `zone_id` int(11) DEFAULT NULL,
  `flat_id` int(11) DEFAULT NULL,
  `building_name` varchar(150) DEFAULT NULL,
  `flat_number` varchar(50) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `category_name` varchar(100) NOT NULL,
  `headline` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `status` enum('active','acknowledged','dispatched','resolved','false_alarm') DEFAULT 'active',
  `sound_alarm` tinyint(1) DEFAULT 1,
  `acknowledged_by` int(11) DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_action` varchar(150) DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `broadcast_sent` tinyint(1) DEFAULT 0,
  `email_sent` tinyint(1) DEFAULT 0,
  `security_officers_on_duty_snapshot` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_emergency_alerts`
--

INSERT INTO `estate_emergency_alerts` (`id`, `estate_id`, `alert_code`, `sender_type`, `sender_id`, `sender_name`, `sender_phone`, `target_scope`, `target_stakeholders`, `zone_id`, `flat_id`, `building_name`, `flat_number`, `category_id`, `category_name`, `headline`, `note`, `latitude`, `longitude`, `status`, `sound_alarm`, `acknowledged_by`, `acknowledged_at`, `resolved_by`, `resolved_at`, `resolution_action`, `resolution_notes`, `broadcast_sent`, `email_sent`, `security_officers_on_duty_snapshot`, `created_at`, `updated_at`) VALUES
(1, 1, 'SOS-260918-745', 'central_admin', 1, 'System Admin', '', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, 1, 'Medical Emergency', '', '', NULL, NULL, 'false_alarm', 0, 1, '2026-09-18 12:41:06', 1, '2026-09-19 09:26:48', 'Verified False Alarm - Resident Safe', 'Initiator canceled alert', 0, 0, '[]', '2026-09-18 11:36:26', '2026-09-19 08:26:48'),
(2, 1, 'SOS-260918-583', 'central_admin', 1, 'System Admin', '', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, 4, 'Break-In / Burglary', '', '', 6.58179700, 3.22728600, 'false_alarm', 0, NULL, NULL, 1, '2026-09-19 09:26:53', 'Verified False Alarm - Resident Safe', 'Initiator canceled alert', 0, 0, '[]', '2026-09-18 17:19:20', '2026-09-19 08:26:53'),
(3, 1, 'SOS-260918-184', 'central_admin', 1, 'System Admin', '', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, 1, 'Medical Emergency', '', '', 6.58179700, 3.22728600, 'false_alarm', 0, 1, '2026-09-18 18:39:24', 1, '2026-09-19 09:26:41', 'Verified False Alarm - Resident Safe', 'Initiator canceled alert', 0, 0, '[]', '2026-09-18 17:22:38', '2026-09-19 08:26:41'),
(4, 1, 'SOS-260918-333', 'central_admin', 1, 'System Admin', '', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, 3, 'Fire / Smoke', '', '', 6.58179700, 3.22728600, 'false_alarm', 0, 1, '2026-09-18 18:35:33', 1, '2026-09-18 18:36:10', NULL, 'Security has been dispatched', 0, 0, '[]', '2026-09-18 17:23:30', '2026-09-18 17:36:10'),
(5, 1, 'SOS-260918-683', 'resident', 18, 'Femi Oke', '09066832352', 'estate_wide', 'all_residents', 6, 5, 'Ajao house', '4', 2, 'Security Threat', '', '', 6.44740000, 3.39030000, 'resolved', 0, 1, '2026-09-19 09:29:59', 1, '2026-09-19 09:30:39', 'Security Patrol Dispatched to Unit', 'Gate patrol dispatched immediately to inspect location and secure premises', 0, 0, '[]', '2026-09-18 17:47:34', '2026-09-19 08:30:39'),
(6, 1, 'SOS-260919-568', 'central_admin', 1, 'System Admin', '', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, 1, 'Medical Emergency', '', 'Unit 4B severe asthma crisis - need first aid immediately', 6.52440000, 3.37920000, 'false_alarm', 0, NULL, NULL, 1, '2026-09-19 09:38:02', 'Verified False Alarm - Resident Safe', 'Initiator canceled alert', 1, 0, '[]', '2026-09-19 08:35:18', '2026-09-19 08:38:02'),
(7, 1, 'SOS-TEST-466', 'resident', 1, 'Verification Resident', '08012345678', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, NULL, 'Medical Emergency', 'Urgent Test SOS', 'Simulated verification panic', NULL, NULL, 'resolved', 0, NULL, NULL, 1, '2026-09-19 09:37:05', 'Medical Team Responded & Assisted', 'Verified and stabilized', 0, 0, NULL, '2026-09-19 08:37:05', '2026-09-19 08:37:05'),
(8, 1, 'SOS-260919-960', 'central_admin', 1, 'System Admin', '', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, 1, 'Medical Emergency', '', '', 6.58179650, 3.22728550, 'false_alarm', 0, NULL, NULL, 1, '2026-09-19 11:11:45', 'Verified False Alarm - Resident Safe', 'Initiator canceled alert', 1, 1, '[]', '2026-09-19 10:10:29', '2026-09-19 10:11:45'),
(9, 1, 'SOS-260919-168', 'central_admin', 1, 'System Admin', '', 'estate_wide', 'all_residents', NULL, NULL, NULL, NULL, 1, 'Medical Emergency', '', '', 6.58179650, 3.22728550, 'false_alarm', 0, 1, '2026-09-19 11:11:50', 1, '2026-09-19 11:11:52', 'Verified False Alarm - Resident Safe', 'Initiator canceled alert', 1, 1, '[]', '2026-09-19 10:11:06', '2026-09-19 10:11:52');

-- --------------------------------------------------------

--
-- Table structure for table `estate_emergency_categories`
--

CREATE TABLE `estate_emergency_categories` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `category_name` varchar(100) NOT NULL,
  `emoji` varchar(30) DEFAULT NULL,
  `icon` varchar(60) DEFAULT 'fa-solid fa-triangle-exclamation',
  `color` varchar(30) DEFAULT '#dc2626',
  `use_case_description` text NOT NULL,
  `priority` enum('critical','high','medium') DEFAULT 'critical',
  `target_stakeholders` enum('all_residents','guards_only','guards_and_admin','guards_and_medical') DEFAULT 'all_residents',
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_emergency_categories`
--

INSERT INTO `estate_emergency_categories` (`id`, `estate_id`, `category_name`, `emoji`, `icon`, `color`, `use_case_description`, `priority`, `target_stakeholders`, `emergency_contact_phone`, `display_order`, `is_active`, `created_at`) VALUES
(1, 1, 'Medical Emergency', '????', 'fa-solid fa-heart-pulse', '#ef4444', 'Someone is injured, unconscious, having a medical crisis, etc.', 'critical', 'all_residents', '112', 1, 1, '2026-09-16 20:01:57'),
(2, 1, 'Security Threat', '????️', 'fa-solid fa-shield-halved', '#b91c1c', 'Intruder, assault, suspicious person, confrontation, etc.', 'critical', 'all_residents', '08000000001', 2, 1, '2026-09-16 20:01:57'),
(3, 1, 'Fire / Smoke', '????', 'fa-solid fa-fire', '#ea580c', 'Fire, smoke, explosion or suspected fire hazard', 'critical', 'all_residents', '08000000002', 3, 1, '2026-09-16 20:01:57'),
(4, 1, 'Break-In / Burglary', '????', 'fa-solid fa-person-shelter', '#dc2626', 'Active or suspected break-in or burglary in progress', 'critical', 'all_residents', '08000000001', 4, 1, '2026-09-16 20:01:57'),
(5, 1, 'Personal Safety', '????', 'fa-solid fa-user-shield', '#f97316', 'Resident feels threatened, followed, or unsafe', 'high', 'all_residents', '08000000001', 5, 1, '2026-09-16 20:01:57'),
(6, 1, 'Vehicle Emergency', '????', 'fa-solid fa-car-burst', '#d97706', 'Accident, vehicle-related threat, hit-and-run, or blockage', 'high', 'all_residents', '08000000003', 6, 1, '2026-09-16 20:01:57'),
(7, 1, 'Child Emergency', '????', 'fa-solid fa-child-reaching', '#e11d48', 'Missing child or child requiring urgent immediate assistance', 'critical', 'all_residents', '08000000001', 7, 1, '2026-09-16 20:01:57'),
(8, 1, 'Electrical Emergency', '⚡', 'fa-solid fa-bolt-lightning', '#eab308', 'Electrical hazard, exposed high-voltage wire, sparking transformer', 'high', 'all_residents', '08000000004', 8, 1, '2026-09-16 20:01:57'),
(9, 1, 'Flood / Water Emergency', '????', 'fa-solid fa-droplet', '#0284c7', 'Major flooding, burst water main, dangerous water leak', 'medium', 'all_residents', '08000000004', 9, 1, '2026-09-16 20:01:57'),
(10, 1, 'Other Emergency', '????', 'fa-solid fa-triangle-exclamation', '#64748b', 'Anything urgent requiring security/management dispatch that does not fit above', 'high', 'all_residents', '08000000001', 10, 1, '2026-09-16 20:01:57');

-- --------------------------------------------------------

--
-- Table structure for table `estate_emergency_contacts`
--

CREATE TABLE `estate_emergency_contacts` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `label` varchar(100) NOT NULL,
  `phone_number` varchar(50) NOT NULL,
  `contact_type` enum('internal_security','medical','fire','police','management','general') DEFAULT 'internal_security',
  `is_primary` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_emergency_contacts`
--

INSERT INTO `estate_emergency_contacts` (`id`, `estate_id`, `label`, `phone_number`, `contact_type`, `is_primary`, `is_active`, `display_order`, `created_at`) VALUES
(1, 1, 'Main Gate Security Desk', '08012345678', 'internal_security', 1, 1, 1, '2026-09-16 20:01:57'),
(2, 1, 'Rapid Response Patrol Mobile', '08087654321', 'internal_security', 0, 1, 2, '2026-09-16 20:01:57'),
(3, 1, 'Chief Security Officer (CSO)', '09066832352', 'management', 0, 1, 3, '2026-09-16 20:01:57'),
(4, 1, 'Estate First-Aid / Medical Desk', '08033334444', 'medical', 0, 1, 4, '2026-09-16 20:01:57'),
(5, 1, 'State Police Emergency Division', '112', 'police', 0, 1, 5, '2026-09-16 20:01:57'),
(6, 1, 'State Fire & Rescue Service', '119', 'fire', 0, 1, 6, '2026-09-16 20:01:57');

-- --------------------------------------------------------

--
-- Table structure for table `estate_policies`
--

CREATE TABLE `estate_policies` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `scope` enum('central','zonal') NOT NULL DEFAULT 'central',
  `category_slug` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `offence_definition` text DEFAULT NULL,
  `punishment_type` enum('warning','fine','clamping_towing','privilege_suspension','gate_restriction','community_service','legal_eviction','other') DEFAULT 'warning',
  `punishment_details` text DEFAULT NULL,
  `fine_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `repeat_offence_penalty` text DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `enforcement_entity` varchar(120) DEFAULT 'Estate Security & Management',
  `status` enum('active','inactive','under_review') DEFAULT 'active',
  `display_order` int(11) DEFAULT 0,
  `effective_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_policies`
--

INSERT INTO `estate_policies` (`id`, `estate_id`, `zone_id`, `scope`, `category_slug`, `code`, `title`, `description`, `offence_definition`, `punishment_type`, `punishment_details`, `fine_amount`, `repeat_offence_penalty`, `severity`, `enforcement_entity`, `status`, `display_order`, `effective_date`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 'central', 'traffic', 'EST-TRF-001', 'Estate Maximum Speed Limit (20 km/h)', 'A strict maximum speed limit of 20 km/h applies uniformly across all paved estate boulevards, internal avenues, and intersections. Pedestrians and children always possess right of way.', 'Driving any motor vehicle, motorcycle, or electric scooter in excess of 20 km/h or driving recklessly in residential lanes.', 'fine', 'First violation incurs an instant administrative fine of ₦15,000 charged to property ledger and immediate electronic warning flag.', 15000.00, 'Second offence: ₦30,000 fine. Third offence: ₦50,000 fine plus mandatory temporary suspension of automated gate vehicle transponder/pass for 14 days.', 'high', 'Estate Security Patrol & Speed Radar Camera Team', 'active', 1, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(2, 1, NULL, 'central', 'traffic', 'EST-TRF-002', 'Illegal Parking & Obstruction of Access Ways', 'All residents and visitors must park strictly in designated driveways or approved bays. Parking on sidewalks, curbs, green verges, blocking hydrants, or obstructing neighboring driveways is strictly prohibited.', 'Parking a vehicle so as to block traffic flow, obstruct gates/driveways, or park on marked yellow no-parking zones.', 'clamping_towing', 'Immediate vehicle wheel-clamping or towing to the central estate impound depot. De-clamping fee of ₦20,000 must be cleared before release.', 20000.00, 'Repeat offences incur a compounding de-clamping fee of ₦35,000 and towing costs if moved.', 'high', 'Central Traffic Enforcement & Towing Unit', 'active', 2, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(3, 1, NULL, 'central', 'security', 'EST-SEC-001', 'Mandatory Gate Visitor Access Clearance', 'All external visitors, delivery couriers, ride-hailing cabs, and artisans must be pre-cleared via the Resident Portal visitor pass system. Security guards at main gates are prohibited from admitting unverified entrants.', 'Instructing visitors to force entry, verbally abusing gate guards, tailgating behind authorized cars, or providing false gate credentials.', 'fine', '₦25,000 fine levied against host resident and immediate blacklisting of the vehicle.', 25000.00, 'Subsequent violations incur ₦50,000 fine and requirement for in-person escort at the central security gatehouse.', 'critical', 'Chief Security Officer & Gate Commands', 'active', 3, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(4, 1, NULL, 'central', 'noise', 'EST-NOI-001', 'Estate Quiet Hours & Anti-Noise Nuisance Rule', 'Estate quiet hours are strictly observed from 10:00 PM to 06:00 AM on weekdays, and 11:00 PM to 07:00 AM on weekends and public holidays. During quiet hours, amplified music, outdoor loudspeakers, and high-decibel disturbances are prohibited.', 'Emitting unreasonable noise, playing loud musical equipment, running non-soundproofed heavy generators during quiet hours without emergency clearance.', 'fine', 'First incident: Verbal/SMS warning by patrol. Unheeded or repeated noise within 1 hour incurs an administrative penalty of ₦20,000.', 20000.00, 'Second occurrence: ₦40,000 fine. Further occurrences will lead to sound equipment confiscation by security and report to state environmental authorities.', 'medium', 'Estate Night Patrol & Environmental Committee', 'active', 4, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(5, 1, NULL, 'central', 'sanitation', 'EST-SAN-001', 'Waste Disposal, Standard Bin Bagging & Anti-Littering Policy', 'All household domestic waste must be securely bagged in heavy-duty bin liners and placed inside covered waste bins. Open dumping of refuse on road corridors, empty plots, or storm drains is strictly prohibited.', 'Dumping loose refuse, littering streets, discharging sewage/greywater onto roads, or leaving overflowing bins unemptied.', 'fine', 'Administrative environmental sanitation fine of ₦25,000 plus the full cost of commercial cleanup crew dispatch.', 25000.00, 'Repeat violation within 90 days attracts double fine of ₦50,000.', 'medium', 'Environmental Health & Sanitation Unit', 'active', 5, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(6, 1, NULL, 'central', 'pets', 'EST-PET-001', 'Pet Restraint, Leash Requirement & Waste Cleanup', 'All dogs and domestic pets in public estate zones, sidewalks, and parks must be on a sturdy leash held by a capable handler. Handlers must carry waste bags and immediately clean up pet waste.', 'Allowing dogs to roam unleashed in public areas, failure to scoop pet droppings, or keeping aggressive/unvaccinated animals.', 'fine', 'Fine of ₦15,000 per violation. Roaming unattended animals will be impounded by animal control.', 15000.00, 'Repeat offence attracts ₦30,000 fine; persistent aggressive animal incidents will trigger mandatory permanent pet eviction from the estate.', 'medium', 'Estate Security & Animal Welfare Desk', 'active', 6, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(7, 1, NULL, 'central', 'construction', 'EST-CON-001', 'Building Modifications, Architectural Approvals & Work Timings', 'No structural alterations, external facade modifications, painting, or heavy construction may proceed without prior written approval from the Estate Planning & Works Committee. Construction work is permitted strictly Monday - Saturday, 08:00 AM - 05:00 PM.', 'Carrying out building works on Sundays, operating past 5:00 PM, blocking roads with sand/gravel, or building without approved architectural plans.', 'fine', 'Immediate Stop-Work Order sealed by security, denial of artisan gate access, and a contravention fee of ₦50,000.', 50000.00, '₦100,000 penalty plus demolition/remediation of non-compliant structures at owner\'s expense.', 'critical', 'Central Engineering & Physical Planning Bureau', 'active', 7, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(8, 1, NULL, 'central', 'levies', 'EST-FIN-001', 'Estate Service Charge Compliance & Default Sanctions', 'All residents and landlords are obligated to remit monthly or annual estate development levies and service charges within 15 calendar days of invoice generation to maintain estate utilities and security services.', 'Non-payment of mandatory service charges beyond 30 days past the due date without an approved hardship installment plan.', 'gate_restriction', 'Late payment surcharge of 5% per month, restriction of automated vehicle gate transponder (relegated to manual visitor lane clearance), and suspension of club amenities.', 10000.00, 'Continued default exceeding 60 days attracts legal recovery action and publication on the default registry.', 'high', 'Central Revenue & Accounts Office', 'active', 8, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(9, 1, 1, 'zonal', 'traffic', 'ZN1-TRF-001', 'Zone 1: Cul-de-Sac & Inner Crescent Parking Rule', 'Due to narrow turning radii in Zone 1 crescents, parking is permitted only on the even-numbered side of the street. Overnight parking in turning circles is forbidden to ensure fire engine access.', 'Parking vehicle on odd-numbered curbs or inside the cul-de-sac turning circle in Zone 1.', 'clamping_towing', 'Local sector wheel-clamping with a ₦15,000 release surcharge paid into the Zone 1 development purse.', 15000.00, '₦25,000 fine on second violation.', 'medium', 'Zone 1 Sector Marshal & Security', 'active', 9, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(10, 1, 1, 'zonal', 'sanitation', 'ZN1-SAN-001', 'Zone 1: Designated Curbside Trash Bin Timings', 'In Zone 1, communal trash compactors arrive Tuesdays and Fridays between 07:00 AM and 10:00 AM. Residents must wheel their bins to the curb only between 06:00 AM and 07:00 AM and retrieve them before 12:00 PM.', 'Leaving trash bins on curbsides overnight or on non-collection days causing foul odor.', 'fine', '₦5,000 sanitation violation levy per occurrence.', 5000.00, '₦10,000 fine for subsequent infractions.', 'low', 'Zone 1 Sanitation Committee', 'active', 10, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05'),
(11, 1, 2, 'zonal', 'traffic', 'ZN2-TRF-001', 'Zone 2: Heavy Construction Truck Tonnage Restriction', 'Heavy delivery articulated trucks exceeding 15 tons are prohibited from traversing Zone 2 residential pavement after 04:00 PM or during rainy days to prevent road base collapse.', 'Navigating heavy trailers or tippers into Zone 2 past the restriction curfew.', 'fine', 'Driver will be turned back at Zone 2 gate barrier and host resident charged ₦30,000.', 30000.00, '₦60,000 fine and assessment for road asphalt surface damage.', 'high', 'Zone 2 Gate Marshals', 'active', 11, NULL, NULL, '2026-09-21 13:15:05', '2026-09-21 13:15:05');

-- --------------------------------------------------------

--
-- Table structure for table `estate_policy_categories`
--

CREATE TABLE `estate_policy_categories` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `slug` varchar(100) NOT NULL,
  `name` varchar(150) NOT NULL,
  `icon` varchar(60) DEFAULT 'fa-gavel',
  `color` varchar(30) DEFAULT '#3b82f6',
  `description` varchar(255) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_policy_categories`
--

INSERT INTO `estate_policy_categories` (`id`, `estate_id`, `slug`, `name`, `icon`, `color`, `description`, `display_order`, `created_at`) VALUES
(1, 1, 'security', 'Security & Gate Access Control', 'fa-shield-halved', '#ef4444', 'Rules concerning access control, visitor validation, gate passes, perimeter security, and checkpoint compliance.', 1, '2026-09-21 13:15:05'),
(2, 1, 'traffic', 'Traffic, Parking & Speed Regulations', 'fa-car-side', '#f59e0b', 'Estate speed limits, designated parking slots, visitor parking, anti-blocking, and vehicle clamping.', 2, '2026-09-21 13:15:05'),
(3, 1, 'noise', 'Noise Control & Peaceful Living', 'fa-volume-xmark', '#8b5cf6', 'Estate quiet hours, decibel levels, generator noise restrictions, party permits, and neighbor tranquility.', 3, '2026-09-21 13:15:05'),
(4, 1, 'sanitation', 'Sanitation & Environmental Waste', 'fa-recycle', '#10b981', 'Garbage collection schedules, standard bin bagging, hazardous waste, littering, and lawn maintenance.', 4, '2026-09-21 13:15:05'),
(5, 1, 'pets', 'Pets & Domestic Animals', 'fa-paw', '#ec4899', 'Leash requirements, vaccination certificates, dangerous dog breeds, waste cleanup, and barking nuisance.', 5, '2026-09-21 13:15:05'),
(6, 1, 'construction', 'Building & Structural Alterations', 'fa-trowel-bricks', '#6366f1', 'Permitted renovation hours, architectural permits, heavy truck access, scaffolding, and site cleanliness.', 6, '2026-09-21 13:15:05'),
(7, 1, 'amenities', 'Common Amenities & Recreational Facilities', 'fa-swimming-pool', '#06b6d4', 'Usage rules for clubhouse, sports facilities, swimming pool, children play areas, and facility bookings.', 7, '2026-09-21 13:15:05'),
(8, 1, 'levies', 'Levies, Fines & Financial Compliance', 'fa-money-bill-transfer', '#14b8a6', 'Estate development dues, utility payment deadlines, penalty surcharges, and default enforcement.', 8, '2026-09-21 13:15:05'),
(9, 1, 'conduct', 'Resident Conduct & Tenancy Ethics', 'fa-handshake-angle', '#3b82f6', 'Ethical neighbor relations, anti-harassment rules, domestic worker documentation, and disputes.', 9, '2026-09-21 13:15:05');

-- --------------------------------------------------------

--
-- Table structure for table `estate_settings`
--

CREATE TABLE `estate_settings` (
  `id` int(11) NOT NULL,
  `estate_name` varchar(255) DEFAULT 'Estate Management System'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_settings`
--

INSERT INTO `estate_settings` (`id`, `estate_name`) VALUES
(1, 'Estate Management System');

-- --------------------------------------------------------

--
-- Table structure for table `estate_staff`
--

CREATE TABLE `estate_staff` (
  `id` int(11) NOT NULL,
  `custom_id` varchar(50) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `role` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `estate_staff`
--

INSERT INTO `estate_staff` (`id`, `custom_id`, `user_id`, `role_id`, `role`, `phone`, `registration_date`, `image_path`, `status`, `created_at`, `estate_id`) VALUES
(3, 'EST-00003', 12, 5, 'Security Officer', '09066832352', '2026-09-05', '../uploads/1788628758_134246611404313579.jpg', 'active', '2026-09-05 17:19:18', 1),
(4, 'EST-00004', 20, 5, 'Security Officer', '+234 802 300 0001', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(5, 'EST-00005', 21, 5, 'Security Officer', '+234 802 300 0002', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(6, 'EST-00006', 22, 5, 'Security Officer', '+234 802 300 0003', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(7, 'EST-00007', 23, 5, 'Security Officer', '+234 802 300 0004', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(8, 'EST-00008', 24, 5, 'Security Officer', '+234 802 300 0005', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(9, 'EST-00009', 25, 5, 'Security Officer', '+234 802 300 0006', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(10, 'EST-00010', 26, 5, 'Security Officer', '+234 802 300 0007', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(11, 'EST-00011', 27, 5, 'Security Officer', '+234 802 300 0008', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(12, 'EST-00012', 28, 5, 'Security Officer', '+234 802 300 0009', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1),
(13, 'EST-00013', 29, 5, 'Security Officer', '+234 802 300 0010', '2026-09-22', NULL, 'active', '2026-09-22 10:08:54', 1);

-- --------------------------------------------------------

--
-- Table structure for table `flats`
--

CREATE TABLE `flats` (
  `id` int(11) NOT NULL,
  `building_id` int(11) NOT NULL,
  `number` varchar(50) NOT NULL,
  `floor` varchar(50) NOT NULL,
  `type` varchar(50) DEFAULT '2BHK',
  `status` varchar(50) DEFAULT 'vacant',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `current_use` varchar(100) DEFAULT NULL,
  `original_use` varchar(100) DEFAULT NULL,
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `flats`
--

INSERT INTO `flats` (`id`, `building_id`, `number`, `floor`, `type`, `status`, `created_at`, `current_use`, `original_use`, `custom_id`, `image_path`, `registration_date`, `estate_id`) VALUES
(1, 1, '101', '1', '2BHK', 'vacant', '2026-03-30 11:36:27', NULL, NULL, 'FLT-00001', NULL, NULL, 1),
(2, 1, '102', '1', '2BHK', 'vacant', '2026-03-30 11:36:27', NULL, NULL, 'FLT-00002', NULL, NULL, 1),
(3, 1, '201', '1', '2BHK', 'vacant', '2026-03-30 11:36:27', NULL, NULL, 'FLT-00003', NULL, NULL, 1),
(4, 1, '202', '1', '2BHK', 'vacant', '2026-03-30 11:36:28', NULL, NULL, 'FLT-00004', NULL, NULL, 1),
(5, 3, '4', '2', '1 Bedroom', 'occupied', '2026-09-11 17:24:32', NULL, NULL, 'FLT-00005', '', '2026-09-11', 1),
(6, 3, '4', '2', '1 Bedroom', 'occupied', '2026-09-11 17:24:32', NULL, NULL, 'FLT-00006', '', '2026-09-11', 1),
(7, 6, '101', '1', '1BHK', 'occupied', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00007', NULL, '2026-09-22', 1),
(8, 6, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00008', NULL, '2026-09-22', 1),
(9, 6, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00009', NULL, '2026-09-22', 1),
(10, 7, '101', '1', '1BHK', 'occupied', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00010', NULL, '2026-09-22', 1),
(11, 7, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00011', NULL, '2026-09-22', 1),
(12, 7, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00012', NULL, '2026-09-22', 1),
(13, 11, '101', '1', '1BHK', 'occupied', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00013', NULL, '2026-09-22', 1),
(14, 11, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00014', NULL, '2026-09-22', 1),
(15, 11, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00015', NULL, '2026-09-22', 1),
(16, 12, '101', '1', '1BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00016', NULL, '2026-09-22', 1),
(17, 12, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00017', NULL, '2026-09-22', 1),
(18, 12, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00018', NULL, '2026-09-22', 1),
(19, 16, '101', '1', '1BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00019', NULL, '2026-09-22', 1),
(20, 16, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00020', NULL, '2026-09-22', 1),
(21, 16, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00021', NULL, '2026-09-22', 1),
(22, 17, '101', '1', '1BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00022', NULL, '2026-09-22', 1),
(23, 17, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00023', NULL, '2026-09-22', 1),
(24, 17, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00024', NULL, '2026-09-22', 1),
(25, 21, '101', '1', '1BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00025', NULL, '2026-09-22', 1),
(26, 21, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00026', NULL, '2026-09-22', 1),
(27, 21, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00027', NULL, '2026-09-22', 1),
(28, 22, '101', '1', '1BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00028', NULL, '2026-09-22', 1),
(29, 22, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00029', NULL, '2026-09-22', 1),
(30, 22, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00030', NULL, '2026-09-22', 1),
(31, 26, '101', '1', '1BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00031', NULL, '2026-09-22', 1),
(32, 26, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00032', NULL, '2026-09-22', 1),
(33, 26, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00033', NULL, '2026-09-22', 1),
(34, 27, '101', '1', '1BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00034', NULL, '2026-09-22', 1),
(35, 27, '201', '2', '2BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00035', NULL, '2026-09-22', 1),
(36, 27, '301', '3', '3BHK', 'vacant', '2026-09-22 10:08:54', NULL, NULL, 'FLT-00036', NULL, '2026-09-22', 1),
(37, 72, '101', '1', '2BHK', 'occupied', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00037', NULL, '2026-09-22', 1),
(38, 72, '201', '2', '3BHK', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00038', NULL, '2026-09-22', 1),
(39, 72, '301', '3', 'Penthouse', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00039', NULL, '2026-09-22', 1),
(40, 76, '101', '1', '2BHK', 'occupied', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00040', NULL, '2026-09-22', 1),
(41, 76, '201', '2', '3BHK', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00041', NULL, '2026-09-22', 1),
(42, 76, '301', '3', 'Penthouse', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00042', NULL, '2026-09-22', 1),
(43, 145, '101', '1', '2BHK', 'occupied', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00043', NULL, '2026-09-22', 1),
(44, 145, '201', '2', '3BHK', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00044', NULL, '2026-09-22', 1),
(45, 145, '301', '3', 'Penthouse', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00045', NULL, '2026-09-22', 1),
(46, 146, '101', '1', '2BHK', 'occupied', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00046', NULL, '2026-09-22', 1),
(47, 146, '201', '2', '3BHK', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00047', NULL, '2026-09-22', 1),
(48, 146, '301', '3', 'Penthouse', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00048', NULL, '2026-09-22', 1),
(49, 211, '101', '1', '2BHK', 'occupied', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00049', NULL, '2026-09-22', 1),
(50, 211, '201', '2', '3BHK', 'occupied', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00050', NULL, '2026-09-22', 1),
(51, 211, '301', '3', 'Penthouse', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00051', NULL, '2026-09-22', 1),
(52, 215, '101', '1', '2BHK', 'occupied', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00052', NULL, '2026-09-22', 1),
(53, 215, '201', '2', '3BHK', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00053', NULL, '2026-09-22', 1),
(54, 215, '301', '3', 'Penthouse', 'vacant', '2026-09-22 11:30:08', NULL, NULL, 'FLT-00054', NULL, '2026-09-22', 1);

-- --------------------------------------------------------

--
-- Table structure for table `flat_statuses`
--

CREATE TABLE `flat_statuses` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `flat_statuses`
--

INSERT INTO `flat_statuses` (`id`, `name`) VALUES
(4, 'Archived'),
(3, 'Maintenance'),
(1, 'Occupied'),
(2, 'Vacant');

-- --------------------------------------------------------

--
-- Table structure for table `flat_types`
--

CREATE TABLE `flat_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `flat_types`
--

INSERT INTO `flat_types` (`id`, `name`) VALUES
(1, '1BHK'),
(2, '2BHK'),
(3, '3BHK'),
(4, '4BHK'),
(5, 'Penthouse'),
(6, 'Studio');

-- --------------------------------------------------------

--
-- Table structure for table `household_staff`
--

CREATE TABLE `household_staff` (
  `id` int(11) NOT NULL,
  `flat_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `role` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Daily',
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `incident_entity_types`
--

CREATE TABLE `incident_entity_types` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incident_entity_types`
--

INSERT INTO `incident_entity_types` (`id`, `estate_id`, `name`, `slug`, `created_at`) VALUES
(1, 1, 'Estate Resident', 'resident', '2026-09-19 10:57:53'),
(2, 1, 'Visitor / Guest', 'visitor', '2026-09-19 10:57:53'),
(3, 1, 'Staff / Contractor', 'staff_contractor', '2026-09-19 10:57:53'),
(4, 1, 'Unknown / Third Party', 'unknown_third_party', '2026-09-19 10:57:53');

-- --------------------------------------------------------

--
-- Table structure for table `incident_severities`
--

CREATE TABLE `incident_severities` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incident_severities`
--

INSERT INTO `incident_severities` (`id`, `estate_id`, `name`, `slug`, `created_at`) VALUES
(1, 1, 'Low / Routine Observation', 'low', '2026-09-19 10:57:53'),
(2, 1, 'Medium / Disruption', 'medium', '2026-09-19 10:57:53'),
(3, 1, 'High / Serious Security Matter', 'high', '2026-09-19 10:57:53'),
(4, 1, 'Critical / Life & Safety Threat', 'critical', '2026-09-19 10:57:53');

-- --------------------------------------------------------

--
-- Table structure for table `incident_statuses`
--

CREATE TABLE `incident_statuses` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incident_statuses`
--

INSERT INTO `incident_statuses` (`id`, `estate_id`, `name`, `slug`, `created_at`) VALUES
(1, 1, 'Open / Unresolved', 'open', '2026-09-19 10:57:53'),
(2, 1, 'Under Investigation', 'investigating', '2026-09-19 10:57:53'),
(3, 1, 'Resolved', 'resolved', '2026-09-19 10:57:53'),
(4, 1, 'Escalated to Police / Law Enforcement', 'escalated_police', '2026-09-19 10:57:53'),
(5, 1, 'Escalated to Management', 'escalated_mgmt', '2026-09-19 10:57:53'),
(6, 1, 'Dismissed / False Alarm', 'dismissed', '2026-09-19 10:57:53');

-- --------------------------------------------------------

--
-- Table structure for table `incident_types`
--

CREATE TABLE `incident_types` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `incident_types`
--

INSERT INTO `incident_types` (`id`, `estate_id`, `name`, `slug`, `created_at`) VALUES
(1, 1, 'General Occurrence / Routine Log', 'general_occurrence', '2026-09-19 10:57:53'),
(2, 1, 'Bad Behavior / Misconduct', 'bad_behavior', '2026-09-19 10:57:53'),
(3, 1, 'Noise Disturbance', 'noise_disturbance', '2026-09-19 10:57:53'),
(4, 1, 'Speeding & Traffic Violation', 'speeding_traffic', '2026-09-19 10:57:53'),
(5, 1, 'Unauthorized Entry / Gate Breach', 'unauthorized_entry', '2026-09-19 10:57:53'),
(6, 1, 'Suspicious Person / Vehicle', 'suspicious_activity', '2026-09-19 10:57:53'),
(7, 1, 'Property Damage / Vandalism', 'property_damage', '2026-09-19 10:57:53'),
(8, 1, 'Theft / Attempted Burglary', 'theft_burglary', '2026-09-19 10:57:53'),
(9, 1, 'Altercation / Physical Fight', 'altercation_fight', '2026-09-19 10:57:53'),
(10, 1, 'Access Denied at Gate', 'access_denied', '2026-09-19 10:57:53'),
(11, 1, 'Lost & Found', 'lost_found', '2026-09-19 10:57:53');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `flat_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('draft','pending','unpaid','partially_paid','paid','overdue','cancelled') DEFAULT 'unpaid',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `property_id` int(11) DEFAULT NULL,
  `charge_id` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `penalty` decimal(10,2) DEFAULT 0.00,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `issue_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `user_id`, `flat_id`, `title`, `amount`, `status`, `due_date`, `created_at`, `estate_id`, `zone_id`, `invoice_number`, `property_id`, `charge_id`, `subtotal`, `discount`, `penalty`, `amount_paid`, `balance`, `issue_date`) VALUES
(6, 18, 5, 'Developmental levy', 100000.00, 'paid', '2026-09-25', '2026-09-11 19:18:43', 1, 6, 'INV-260911-784', NULL, 3, 100000.00, 0.00, 0.00, 100000.00, 0.00, '2026-09-11'),
(11, 18, 5, 'Security & Vigilante Levy', 25000.00, 'unpaid', '2026-10-15', '2026-09-15 09:03:45', 1, 6, 'INV-260915-288', NULL, NULL, 25000.00, 0.00, 0.00, 0.00, 25000.00, '2026-09-15');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_installments`
--

CREATE TABLE `invoice_installments` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `installment_number` int(11) NOT NULL,
  `title` varchar(100) NOT NULL DEFAULT '',
  `percentage` decimal(5,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `status` enum('pending','paid','partially_paid','overdue') DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_installments`
--

INSERT INTO `invoice_installments` (`id`, `estate_id`, `zone_id`, `invoice_id`, `installment_number`, `title`, `percentage`, `amount`, `due_date`, `amount_paid`, `status`, `paid_at`, `created_at`) VALUES
(8, 1, 1, 9, 1, '1st Installment (Downpayment)', 50.00, 12500.00, '2026-10-15', 0.00, 'pending', NULL, '2026-09-15 09:03:35'),
(9, 1, 1, 9, 2, '2nd Installment', 25.00, 6250.00, '2026-11-14', 0.00, 'pending', NULL, '2026-09-15 09:03:35'),
(10, 1, 1, 9, 3, '3rd Installment', 25.00, 6250.00, '2026-12-14', 0.00, 'pending', NULL, '2026-09-15 09:03:35'),
(11, 1, 6, 10, 1, '1st Installment (Downpayment)', 40.00, 10000.00, '2026-10-15', 0.00, 'pending', NULL, '2026-09-15 09:03:42'),
(12, 1, 6, 10, 2, '2nd Installment', 20.00, 5000.00, '2026-11-14', 0.00, 'pending', NULL, '2026-09-15 09:03:42'),
(13, 1, 6, 10, 3, '3rd Installment', 20.00, 5000.00, '2026-12-14', 0.00, 'pending', NULL, '2026-09-15 09:03:42'),
(14, 1, 6, 10, 4, '4th Installment', 20.00, 5000.00, '2027-01-13', 0.00, 'pending', NULL, '2026-09-15 09:03:42'),
(15, 1, 6, 11, 1, '1st Installment (Downpayment)', 40.00, 10000.00, '2026-10-15', 0.00, 'pending', NULL, '2026-09-15 09:03:45'),
(16, 1, 6, 11, 2, '2nd Installment', 20.00, 5000.00, '2026-11-14', 0.00, 'pending', NULL, '2026-09-15 09:03:45'),
(17, 1, 6, 11, 3, '3rd Installment', 20.00, 5000.00, '2026-12-14', 0.00, 'pending', NULL, '2026-09-15 09:03:45'),
(18, 1, 6, 11, 4, '4th Installment', 20.00, 5000.00, '2027-01-13', 0.00, 'pending', NULL, '2026-09-15 09:03:45');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `charge_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_requests`
--

CREATE TABLE `maintenance_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `flat_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high','emergency') DEFAULT 'medium',
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_report` text DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  `attended_by` int(11) DEFAULT NULL,
  `attended_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_requests`
--

INSERT INTO `maintenance_requests` (`id`, `user_id`, `flat_id`, `title`, `description`, `priority`, `status`, `assigned_to`, `created_at`, `admin_report`, `estate_id`, `attended_by`, `attended_at`) VALUES
(1, 18, 5, 'Low water pressure in main bathroom', 'Water flow from the bathroom taps and shower has dropped significantly since this morning. Please send a technician to inspect.', 'high', 'open', NULL, '2026-09-12 16:14:34', NULL, 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `reference_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `estate_id`, `user_id`, `title`, `message`, `type`, `reference_id`, `is_read`, `created_at`) VALUES
(52, 1, 1, 'Contact Change Request', 'Resident Babatunde Adebayo has requested an official contact info change. Click to review in Residents.', 'resident_request', NULL, 1, '2026-09-24 13:48:25'),
(53, 1, 8, 'Contact Change Request', 'Resident Babatunde Adebayo has requested an official contact info change. Click to review in Residents.', 'resident_request', NULL, 0, '2026-09-24 13:48:25'),
(54, 1, 1, 'Contact Change Request', 'Resident Babatunde Adebayo has requested an official contact info change. Click to review in Residents.', 'resident_request', NULL, 1, '2026-09-24 13:51:11'),
(55, 1, 8, 'Contact Change Request', 'Resident Babatunde Adebayo has requested an official contact info change. Click to review in Residents.', 'resident_request', NULL, 0, '2026-09-24 13:51:11'),
(56, 1, 1, 'Contact Change Request', 'Resident Babatunde Adebayo has requested an official contact info change. Click to review in Residents.', 'resident_request', NULL, 1, '2026-09-24 13:53:01'),
(57, 1, 8, 'Contact Change Request', 'Resident Babatunde Adebayo has requested an official contact info change. Click to review in Residents.', 'resident_request', NULL, 0, '2026-09-24 13:53:01'),
(58, 1, 15, 'Zonal Contact Change Request', 'Resident Babatunde Adebayo in your zone has requested an official contact info change.', 'resident_request', NULL, 0, '2026-09-24 13:53:01'),
(59, 1, 30, 'Contact Details Updated', 'Your contact info update request has been APPROVED by Estate Central Administration.', 'contact_approved', NULL, 1, '2026-09-24 14:06:15');

-- --------------------------------------------------------

--
-- Table structure for table `owner_properties`
--

CREATE TABLE `owner_properties` (
  `id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `property_type` enum('building','flat') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `owner_properties`
--

INSERT INTO `owner_properties` (`id`, `owner_id`, `property_id`, `property_type`, `created_at`, `estate_id`) VALUES
(1, 1, 6, 'flat', '2026-09-11 17:34:53', 1);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `email` varchar(191) NOT NULL,
  `code` varchar(10) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` varchar(50) NOT NULL,
  `status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `transaction_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `invoice_id` int(11) DEFAULT NULL,
  `transaction_ref` varchar(100) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'manual',
  `estate_id` int(11) DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `property_id` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `user_id`, `amount`, `type`, `status`, `transaction_date`, `description`, `created_at`, `invoice_id`, `transaction_ref`, `payment_method`, `estate_id`, `zone_id`, `payment_reference`, `property_id`, `paid_at`) VALUES
(5, 18, 100000.00, 'Developmental levy', 'paid', NULL, 'Paystack Online Payment (CARD) for Invoice #INV-260911-784 [Remitted to Zone ABC]', '2026-09-11 19:20:38', 6, 'EST_220504247', 'paystack_card', 1, 6, 'EST_220504247', NULL, '2026-09-11 20:20:38');

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `estate_id`, `name`, `code`, `is_system`, `status`, `created_at`, `created_by`) VALUES
(1, 1, 'Cash / Counter', 'cash', 1, 'active', '2026-09-05 16:42:20', NULL),
(2, 1, 'Bank Transfer', 'bank_transfer', 1, 'active', '2026-09-05 16:42:20', NULL),
(3, 1, 'POS Terminal', 'pos', 1, 'active', '2026-09-05 16:42:20', NULL),
(4, 1, 'Cheque', 'cheque', 1, 'active', '2026-09-05 16:42:20', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `gateway` varchar(50) DEFAULT 'Paystack',
  `gateway_reference` varchar(100) NOT NULL,
  `gateway_transaction_id` varchar(100) DEFAULT NULL,
  `channel` varchar(50) DEFAULT 'card',
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'NGN',
  `gateway_response` text DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_transactions`
--

INSERT INTO `payment_transactions` (`id`, `payment_id`, `gateway`, `gateway_reference`, `gateway_transaction_id`, `channel`, `amount`, `currency`, `gateway_response`, `verified_at`, `created_at`) VALUES
(3, 5, 'Paystack', 'EST_220504247', '6548878805', 'card', 100000.00, 'NGN', '{\"id\":6548878805,\"domain\":\"test\",\"status\":\"success\",\"reference\":\"EST_220504247\",\"receipt_number\":null,\"amount\":10000000,\"message\":null,\"gateway_response\":\"Successful\",\"gateway_response_code\":\"approved\",\"response_code\":\"00\",\"paid_at\":\"2026-09-11T19:20:37.000Z\",\"created_at\":\"2026-09-11T19:20:24.000Z\",\"channel\":\"card\",\"currency\":\"NGN\",\"ip_address\":\"102.89.85.125\",\"metadata\":{\"custom_fields\":[{\"display_name\":\"Invoice ID\",\"variable_name\":\"invoice_id\",\"value\":6},{\"display_name\":\"Invoice Number\",\"variable_name\":\"invoice_number\",\"value\":\"INV-260911-784\"},{\"display_name\":\"Resident Name\",\"variable_name\":\"resident_name\",\"value\":\"Femi Oke\"},{\"display_name\":\"Charge Title\",\"variable_name\":\"charge_title\",\"value\":\"Developmental levy\"},{\"display_name\":\"Zone ID\",\"variable_name\":\"zone_id\",\"value\":6},{\"display_name\":\"Zone Remittance Account\",\"variable_name\":\"zone_account\",\"value\":\"3209812414\"}],\"referrer\":\"http:\\/\\/localhost\\/Estate\\/resident\\/finance\"},\"log\":{\"start_time\":1789154429,\"time_spent\":8,\"attempts\":1,\"errors\":0,\"success\":true,\"mobile\":false,\"input\":[],\"history\":[{\"type\":\"action\",\"message\":\"Attempted to pay with card\",\"time\":7},{\"type\":\"success\",\"message\":\"Successfully paid with card\",\"time\":8}]},\"fees\":160000,\"fees_split\":null,\"authorization\":{\"authorization_code\":\"AUTH_20vga2oimd\",\"bin\":\"408408\",\"last4\":\"4081\",\"exp_month\":\"12\",\"exp_year\":\"2030\",\"channel\":\"card\",\"card_type\":\"visa \",\"bank\":\"TEST BANK\",\"country_code\":\"NG\",\"brand\":\"visa\",\"reusable\":true,\"signature\":\"SIG_iB7b7STy6jrELg8eddZu\",\"account_name\":null,\"receiver_bank_account_number\":null,\"receiver_bank\":null},\"customer\":{\"id\":398718115,\"first_name\":\"\",\"last_name\":\"\",\"email\":\"femi@yopmail.com\",\"customer_code\":\"CUS_f1q9kq86h90hjcw\",\"phone\":\"\",\"metadata\":null,\"risk_action\":\"default\",\"international_format_phone\":null},\"plan\":null,\"split\":[],\"order_id\":null,\"paidAt\":\"2026-09-11T19:20:37.000Z\",\"createdAt\":\"2026-09-11T19:20:24.000Z\",\"requested_amount\":10000000,\"pos_transaction_data\":null,\"source\":null,\"fees_breakdown\":null,\"connect\":null,\"transaction_date\":\"2026-09-11T19:20:24.000Z\",\"plan_object\":[],\"subaccount\":[]}', '2026-09-11 20:20:38', '2026-09-11 19:20:38');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `slug`, `module`, `description`) VALUES
(1, 'View Properties', 'properties.view', 'Properties', 'View streets, buildings, and flats'),
(2, 'Manage Properties', 'properties.manage', 'Properties', 'Add, edit, or delete properties'),
(3, 'Manage Property Owners', 'owners.manage', 'Properties', 'Manage property ownership records'),
(4, 'View Residents', 'residents.view', 'Residents', 'View resident directory'),
(5, 'Register & Manage Residents', 'residents.manage', 'Residents', 'Add, edit, or register residents'),
(6, 'Manage Tenancies', 'tenancies.manage', 'Residents', 'Manage tenant move-in and leases'),
(7, 'View Invoices & Bills', 'finance.view_invoices', 'Finance', 'View estate bills, invoices, and payment status'),
(8, 'Create Charges & Bills', 'finance.create_invoice', 'Finance', 'Generate bills, charges, and invoices'),
(9, 'Record & Confirm Payments', 'finance.record_payment', 'Finance', 'Record payments and confirm collections'),
(10, 'Financial Reports', 'finance.view_reports', 'Finance', 'Access revenue and collection analytics'),
(11, 'Manage Estate Charges', 'charges.manage', 'Finance', 'Configure recurring estate service charges'),
(12, 'View Visitor Log', 'visitors.view_log', 'Visitors', 'View gate visitor entries and history'),
(13, 'Check-in / Check-out Visitors', 'visitors.check_in_out', 'Visitors', 'Verify access codes and check visitors in/out'),
(14, 'Manage Estate Staff', 'staff.manage', 'Staff', 'Manage staff records and assignments'),
(15, 'General Settings', 'settings.general', 'Settings', 'Update estate configuration and payment keys'),
(16, 'Roles & Permissions Settings', 'settings.roles_permissions', 'Settings', 'Manage roles and permission slugs'),
(46, 'Emergency SOS Alerts', 'visitors.emergency_alerts', 'Visitors', 'View and respond to security alerts'),
(48, 'Access Staff Portal', 'staff.portal_access', 'Staff', 'Access the staff portal interface'),
(49, 'View Assigned Work Orders', 'maintenance.view_assigned', 'Maintenance', 'View work orders assigned to staff'),
(50, 'Update Work Orders', 'maintenance.update_status', 'Maintenance', 'Update maintenance task status and add notes'),
(53, 'Generate & View Receipts', 'finance.view_receipts', 'Finance', 'Generate, view, and print official receipts'),
(54, 'Create & Manage Estate Zones', 'zones.manage', 'Zones', 'Create & Manage Estate Zones'),
(55, 'View Estate Zones', 'zones.view', 'Zones', 'View Estate Zones'),
(56, 'View Incident & Occurrence Log', 'incidents.view', 'Security & Incidents', 'Allows viewing estate incident history, occurrence logbook and forensics'),
(57, 'Log Incident & Strange Events', 'incidents.create', 'Security & Incidents', 'Allows guards & staff to register new occurrences and security incidents'),
(58, 'Flag Bad Behavior / Blacklist', 'incidents.flag_behavior', 'Security & Incidents', 'Allows flagging unruly residents or visitors for security review & blacklisting'),
(59, 'Manage & Resolve Incidents', 'incidents.manage', 'Security & Incidents', 'Allows updating investigation status, resolving incidents and escalating to police'),
(60, 'Export Occurrence Logbook', 'incidents.export', 'Security & Incidents', 'Allows exporting occurrence books, incident logs and analytics to CSV'),
(61, 'View Duty Rosters', 'roster.view', 'Roster & Attendance', 'Allows viewing staff duty rosters, calendar shifts and station allocations'),
(62, 'Manage & Distribute Rosters', 'roster.manage', 'Roster & Attendance', 'Allows creating shifts, configuring stations/posts and distributing recurring rosters'),
(63, 'Clock In & Out (Personal)', 'attendance.clock_in_out', 'Roster & Attendance', 'Allows staff to record clock in and clock out timestamps for assigned shifts'),
(64, 'Attendance Override & Roll Call', 'attendance.admin_override', 'Roster & Attendance', 'Allows manual clock in/out overrides, attendance verification and roll call management'),
(65, 'Flag & Blacklist Visitors', 'visitors.blacklist_flag', 'Gate & Visitor Passes', 'Allows flagging unruly visitors or blacklisting suspicious vehicles at the gate'),
(66, 'Bypass Resident Confirmation', 'visitors.bypass_confirmation', 'Gate & Visitor Passes', 'Allows staff to approve visitor gate exit without requiring resident confirmation code');

-- --------------------------------------------------------

--
-- Table structure for table `pets`
--

CREATE TABLE `pets` (
  `id` int(11) NOT NULL,
  `flat_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `property_acquisition_types`
--

CREATE TABLE `property_acquisition_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `property_acquisition_types`
--

INSERT INTO `property_acquisition_types` (`id`, `name`) VALUES
(3, 'Gift'),
(2, 'Inheritance'),
(4, 'Lease'),
(1, 'Purchase');

-- --------------------------------------------------------

--
-- Table structure for table `property_history`
--

CREATE TABLE `property_history` (
  `id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `property_type` enum('street','building','flat') NOT NULL,
  `action_type` enum('Registered','Converted','Renamed','Sold','Ownership Changed','Archived','Restored') NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `change_date` datetime DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `property_owners`
--

CREATE TABLE `property_owners` (
  `id` int(11) NOT NULL,
  `custom_id` varchar(50) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `id_type` varchar(50) DEFAULT NULL,
  `ownership_type` varchar(100) DEFAULT 'Individual',
  `owner_type` enum('building','flat') NOT NULL DEFAULT 'building',
  `registration_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `property_owners`
--

INSERT INTO `property_owners` (`id`, `custom_id`, `full_name`, `first_name`, `last_name`, `phone`, `email`, `id_type`, `ownership_type`, `owner_type`, `registration_date`, `created_at`, `estate_id`) VALUES
(1, 'OWN-00001', 'Femi Oke', 'Femi', 'Oke', '09066832352', 'timmysammy59@gmail.com', 'National ID', 'Individual', 'flat', '2026-09-11', '2026-09-11 17:34:53', 1);

-- --------------------------------------------------------

--
-- Table structure for table `property_ownership`
--

CREATE TABLE `property_ownership` (
  `id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `property_type` enum('building','flat') NOT NULL,
  `owner_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `acquisition_type` varchar(100) DEFAULT 'Purchase',
  `registration_date` datetime DEFAULT current_timestamp(),
  `supporting_docs` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `property_ownership_types`
--

CREATE TABLE `property_ownership_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `property_ownership_types`
--

INSERT INTO `property_ownership_types` (`id`, `name`) VALUES
(2, 'Company'),
(3, 'Family Trust'),
(1, 'Individual');

-- --------------------------------------------------------

--
-- Table structure for table `receipts`
--

CREATE TABLE `receipts` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `receipt_number` varchar(50) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `issued_at` datetime DEFAULT current_timestamp(),
  `receipt_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `issued_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `receipts`
--

INSERT INTO `receipts` (`id`, `estate_id`, `receipt_number`, `payment_id`, `resident_id`, `property_id`, `amount`, `issued_at`, `receipt_data`, `created_at`, `issued_by`) VALUES
(4, 1, 'REC-260911-788', 5, 18, NULL, 100000.00, '2026-09-11 20:20:38', NULL, '2026-09-11 19:20:38', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `flat_id` int(11) NOT NULL,
  `type` enum('head','dependent') DEFAULT 'head',
  `relationship` varchar(50) DEFAULT 'self',
  `lease_start` date DEFAULT NULL,
  `lease_end` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `residents`
--

INSERT INTO `residents` (`id`, `user_id`, `flat_id`, `type`, `relationship`, `lease_start`, `lease_end`, `status`, `created_at`, `custom_id`, `image_path`, `registration_date`, `estate_id`) VALUES
(11, 18, 5, 'head', 'Self', NULL, NULL, 'active', '2026-09-11 17:44:43', 'RES-00011', NULL, '2026-09-11', 1),
(13, 30, 7, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00012', NULL, '2026-09-22', 1),
(14, 31, 10, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00014', NULL, '2026-09-22', 1),
(15, 32, 13, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00015', NULL, '2026-09-22', 1),
(16, 33, 37, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00016', NULL, '2026-09-22', 1),
(17, 34, 40, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00017', NULL, '2026-09-22', 1),
(18, 35, 43, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00018', NULL, '2026-09-22', 1),
(19, 36, 46, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00019', NULL, '2026-09-22', 1),
(20, 37, 49, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00020', NULL, '2026-09-22', 1),
(21, 38, 50, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00021', NULL, '2026-09-22', 1),
(22, 39, 52, 'head', 'self', NULL, NULL, 'active', '2026-09-22 10:08:54', 'RES-00022', NULL, '2026-09-22', 1);

-- --------------------------------------------------------

--
-- Table structure for table `resident_history`
--

CREATE TABLE `resident_history` (
  `id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `flat_id` int(11) DEFAULT NULL,
  `building_id` int(11) DEFAULT NULL,
  `street_id` int(11) DEFAULT NULL,
  `action_type` enum('Moved In','Moved Out','Transferred','Role Changed') NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `reason_for_exit` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `resident_history`
--

INSERT INTO `resident_history` (`id`, `resident_id`, `flat_id`, `building_id`, `street_id`, `action_type`, `start_date`, `end_date`, `reason_for_exit`, `recorded_by`, `timestamp`, `estate_id`) VALUES
(4, 11, 5, 3, 5, 'Moved In', '2026-09-11', NULL, 'Initial zonal onboarding', 14, '2026-09-11 17:44:43', 1),
(6, 11, 5, 3, 5, 'Moved Out', '2026-09-15', NULL, 'Archived by Admin', 1, '2026-09-15 07:22:46', 1),
(9, 13, 7, 6, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(10, 14, 10, 7, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(11, 15, 13, 11, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(12, 16, 16, 12, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(13, 17, 19, 16, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(14, 18, 22, 17, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(15, 19, 25, 21, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(16, 20, 28, 22, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(17, 21, 31, 26, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1),
(18, 22, 34, 27, NULL, 'Moved In', '2026-09-22', NULL, 'Initial official move-in', NULL, '2026-09-22 10:08:54', 1);

-- --------------------------------------------------------

--
-- Table structure for table `resident_relationships`
--

CREATE TABLE `resident_relationships` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `resident_relationships`
--

INSERT INTO `resident_relationships` (`id`, `name`) VALUES
(3, 'Child'),
(6, 'Grandparent'),
(8, 'Other'),
(4, 'Parent'),
(1, 'Self (Head)'),
(5, 'Sibling'),
(2, 'Spouse'),
(7, 'Staff');

-- --------------------------------------------------------

--
-- Table structure for table `resident_statuses`
--

CREATE TABLE `resident_statuses` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `resident_statuses`
--

INSERT INTO `resident_statuses` (`id`, `name`) VALUES
(1, 'Active'),
(4, 'Archived'),
(2, 'Moved Out'),
(3, 'Temporary');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `estate_id`, `name`, `slug`, `description`, `is_system`, `created_at`) VALUES
(1, 1, 'Super Administrator', 'superadmin', 'Full platform administrative control', 1, '2026-09-01 15:28:36'),
(2, 1, 'Estate Manager', 'admin', 'Full estate administrative access', 1, '2026-09-01 15:28:36'),
(3, 1, 'Finance Manager', 'manager', 'Handles invoicing, charges, and payment verification', 1, '2026-09-01 15:28:36'),
(4, 1, 'Accountant', 'accountant', 'Manages collections, payments, and financial reporting', 0, '2026-09-01 15:28:36'),
(5, 1, 'Security Officer', 'security', 'Handles visitor verification and gate pass logs', 0, '2026-09-01 15:28:36'),
(6, 1, 'Resident', 'resident', 'Resident portal access for bills, receipts, and visitors', 1, '2026-09-01 15:28:36'),
(7, 1, 'Technician', 'technician', 'Handles estate maintenance work orders', 0, '2026-09-02 08:42:59'),
(10, 1, 'Zone Administrator', 'zone_admin', 'Administrative authority strictly scoped to an assigned zone', 1, '2026-09-11 16:07:30');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 56),
(1, 57),
(1, 58),
(1, 59),
(1, 60),
(1, 61),
(1, 62),
(1, 63),
(1, 64),
(1, 65),
(1, 66),
(2, 1),
(2, 2),
(2, 3),
(2, 4),
(2, 5),
(2, 6),
(2, 7),
(2, 8),
(2, 9),
(2, 10),
(2, 11),
(2, 12),
(2, 13),
(2, 14),
(2, 15),
(2, 16),
(2, 46),
(2, 48),
(2, 49),
(2, 50),
(2, 56),
(2, 57),
(2, 58),
(2, 59),
(2, 60),
(2, 61),
(2, 62),
(2, 63),
(2, 64),
(2, 65),
(2, 66),
(3, 56),
(3, 57),
(3, 58),
(3, 59),
(3, 60),
(3, 61),
(3, 62),
(3, 63),
(3, 64),
(3, 65),
(3, 66),
(5, 12),
(5, 13),
(5, 46),
(5, 48),
(5, 56),
(5, 57),
(5, 58),
(5, 61),
(5, 63),
(5, 65),
(7, 48),
(7, 49),
(7, 50),
(10, 1),
(10, 2),
(10, 3),
(10, 4),
(10, 5),
(10, 6),
(10, 7),
(10, 8),
(10, 9),
(10, 10),
(10, 11),
(10, 53),
(10, 55),
(10, 56),
(10, 57),
(10, 58),
(10, 59),
(10, 60),
(10, 61),
(10, 62),
(10, 63),
(10, 64),
(10, 65),
(10, 66);

-- --------------------------------------------------------

--
-- Table structure for table `security_incidents`
--

CREATE TABLE `security_incidents` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `incident_ref` varchar(50) NOT NULL,
  `reporter_id` int(11) NOT NULL,
  `reporter_name` varchar(150) DEFAULT NULL,
  `reporter_role` varchar(100) DEFAULT NULL,
  `incident_type` varchar(100) DEFAULT 'general_occurrence',
  `severity` varchar(50) DEFAULT 'medium',
  `location_or_post` varchar(150) NOT NULL,
  `incident_datetime` datetime NOT NULL,
  `entity_type` varchar(50) DEFAULT 'resident',
  `target_resident_id` int(11) DEFAULT NULL,
  `target_resident_name` varchar(150) DEFAULT NULL,
  `target_unit_or_address` varchar(150) DEFAULT NULL,
  `target_visitor_name` varchar(150) DEFAULT NULL,
  `target_visitor_phone` varchar(50) DEFAULT NULL,
  `target_pass_code` varchar(50) DEFAULT NULL,
  `vehicle_reg_plate` varchar(50) DEFAULT NULL,
  `is_flagged_bad_behavior` tinyint(1) DEFAULT 0,
  `flag_reason` varchar(255) DEFAULT NULL,
  `blacklist_recommended` tinyint(1) DEFAULT 0,
  `title` varchar(200) NOT NULL,
  `description` longtext NOT NULL,
  `immediate_action_taken` text DEFAULT NULL,
  `evidence_image_path` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'open',
  `investigated_by` int(11) DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_posts`
--

CREATE TABLE `security_posts` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `post_name` varchar(100) NOT NULL,
  `location_description` varchar(255) DEFAULT NULL,
  `phone_extension` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_posts`
--

INSERT INTO `security_posts` (`id`, `estate_id`, `post_name`, `location_description`, `phone_extension`, `status`, `created_at`) VALUES
(1, 1, 'Main Entrance Gate', 'Primary vehicle & visitor access gate', 'Ext 101', 'active', '2026-09-16 20:01:57'),
(2, 1, 'North Pedestrian Gate', 'Resident turnstiles and North exit', 'Ext 102', 'active', '2026-09-16 20:01:57'),
(3, 1, 'South Perimeter Gate', 'Heavy vehicles & service entrance', 'Ext 103', 'active', '2026-09-16 20:01:57'),
(4, 1, 'Patrol Unit Alpha', 'Mobile patrol vehicle & perimeter security', 'Ext 104', 'active', '2026-09-16 20:01:57'),
(5, 1, 'CCTV Security Command Hub', 'Central surveillance monitoring room', 'Ext 100', 'active', '2026-09-16 20:01:57');

-- --------------------------------------------------------

--
-- Table structure for table `security_roster`
--

CREATE TABLE `security_roster` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `user_id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `post_id` int(11) DEFAULT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `duty_date` date NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `status` enum('scheduled','on_duty','completed','absent','swapped','excused') DEFAULT 'scheduled',
  `clock_in_time` datetime DEFAULT NULL,
  `clock_out_time` datetime DEFAULT NULL,
  `alerted_at` datetime DEFAULT NULL,
  `handover_notes` text DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_roster`
--

INSERT INTO `security_roster` (`id`, `estate_id`, `user_id`, `staff_id`, `post_id`, `shift_id`, `duty_date`, `start_datetime`, `end_datetime`, `status`, `clock_in_time`, `clock_out_time`, `alerted_at`, `handover_notes`, `supervisor_id`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 12, 3, 1, 1, '2026-09-16', '2026-09-16 06:00:00', '2026-09-16 14:00:00', 'completed', '2026-09-16 21:01:57', '2026-09-19 11:33:21', NULL, NULL, NULL, NULL, '2026-09-16 20:01:57', '2026-09-19 10:33:21'),
(2, 1, 12, 3, 1, 2, '2026-09-18', '2026-09-18 14:00:00', '2026-09-18 22:00:00', 'completed', '2026-09-19 11:33:58', '2026-09-19 12:04:18', NULL, NULL, 1, 1, '2026-09-18 17:59:07', '2026-09-19 11:04:18'),
(3, 1, 12, 3, 1, 2, '2026-09-19', '2026-09-19 14:00:00', '2026-09-19 22:00:00', 'completed', '2026-09-19 12:00:20', '2026-09-19 12:29:38', NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 11:29:38'),
(4, 1, 12, 3, 1, 2, '2026-09-20', '2026-09-20 14:00:00', '2026-09-20 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(5, 1, 12, 3, 1, 2, '2026-09-21', '2026-09-21 14:00:00', '2026-09-21 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(6, 1, 12, 3, 1, 2, '2026-09-23', '2026-09-23 14:00:00', '2026-09-23 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(7, 1, 12, 3, 1, 2, '2026-09-25', '2026-09-25 14:00:00', '2026-09-25 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(8, 1, 12, 3, 1, 2, '2026-09-26', '2026-09-26 14:00:00', '2026-09-26 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(9, 1, 12, 3, 1, 2, '2026-09-27', '2026-09-27 14:00:00', '2026-09-27 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(10, 1, 12, 3, 1, 2, '2026-09-28', '2026-09-28 14:00:00', '2026-09-28 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(11, 1, 12, 3, 1, 2, '2026-09-30', '2026-09-30 14:00:00', '2026-09-30 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(12, 1, 12, 3, 1, 2, '2026-10-02', '2026-10-02 14:00:00', '2026-10-02 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(13, 1, 12, 3, 1, 2, '2026-10-03', '2026-10-03 14:00:00', '2026-10-03 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(14, 1, 12, 3, 1, 2, '2026-10-04', '2026-10-04 14:00:00', '2026-10-04 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(15, 1, 12, 3, 1, 2, '2026-10-05', '2026-10-05 14:00:00', '2026-10-05 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(16, 1, 12, 3, 1, 2, '2026-10-07', '2026-10-07 14:00:00', '2026-10-07 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(17, 1, 12, 3, 1, 2, '2026-10-09', '2026-10-09 14:00:00', '2026-10-09 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(18, 1, 12, 3, 1, 2, '2026-10-10', '2026-10-10 14:00:00', '2026-10-10 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(19, 1, 12, 3, 1, 2, '2026-10-11', '2026-10-11 14:00:00', '2026-10-11 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(20, 1, 12, 3, 1, 2, '2026-10-12', '2026-10-12 14:00:00', '2026-10-12 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(21, 1, 12, 3, 1, 2, '2026-10-14', '2026-10-14 14:00:00', '2026-10-14 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(22, 1, 12, 3, 1, 2, '2026-10-16', '2026-10-16 14:00:00', '2026-10-16 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(23, 1, 12, 3, 1, 2, '2026-10-17', '2026-10-17 14:00:00', '2026-10-17 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46'),
(24, 1, 12, 3, 1, 2, '2026-10-18', '2026-10-18 14:00:00', '2026-10-18 22:00:00', 'scheduled', NULL, NULL, NULL, NULL, 1, 1, '2026-09-19 10:59:46', '2026-09-19 10:59:46');

-- --------------------------------------------------------

--
-- Table structure for table `security_shifts`
--

CREATE TABLE `security_shifts` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `color_code` varchar(30) DEFAULT '#2563eb',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_shifts`
--

INSERT INTO `security_shifts` (`id`, `estate_id`, `name`, `start_time`, `end_time`, `color_code`, `is_active`, `created_at`) VALUES
(1, 1, 'Morning Shift', '06:00:00', '14:00:00', '#0ea5e9', 1, '2026-09-16 20:01:57'),
(2, 1, 'Afternoon Shift', '14:00:00', '22:00:00', '#f59e0b', 1, '2026-09-16 20:01:57'),
(3, 1, 'Night Shift', '22:00:00', '06:00:00', '#6366f1', 1, '2026-09-16 20:01:57'),
(4, 1, '24-Hour Command Duty', '08:00:00', '08:00:00', '#10b981', 1, '2026-09-16 20:01:57');

-- --------------------------------------------------------

--
-- Table structure for table `staff_details`
--

CREATE TABLE `staff_details` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `streets`
--

CREATE TABLE `streets` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','archived') DEFAULT 'active',
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `streets`
--

INSERT INTO `streets` (`id`, `name`, `description`, `created_at`, `status`, `custom_id`, `image_path`, `registration_date`, `estate_id`, `zone_id`) VALUES
(1, 'Palm Avenue', NULL, '2026-03-30 11:29:38', 'active', 'STR-00001', NULL, NULL, 1, 1),
(2, 'Orchid Road', NULL, '2026-03-30 11:29:38', 'active', 'STR-00002', NULL, NULL, 1, 1),
(3, 'Hibiscus Lane', NULL, '2026-03-30 11:29:38', 'active', 'STR-00003', NULL, NULL, 1, 1),
(4, 'West Boulevard', 'Main boulevard for West sector', '2026-09-11 16:16:05', 'active', 'STR-99999', NULL, NULL, 1, 2),
(5, 'Felix street', '', '2026-09-11 17:19:08', 'active', 'STR-100000', '', '2026-09-11', 1, 6),
(6, 'Johnn street', '', '2026-09-11 17:19:29', 'active', 'STR-100001', '', '2026-09-11', 1, 6),
(7, 'Sapphire Crescent', 'Central residential boulevard with landscaped avenues', '2026-09-22 10:08:53', 'active', 'STR-00007', NULL, '2026-09-22', 1, 1),
(8, 'Emerald Boulevard', 'Prime executive residential corridor', '2026-09-22 10:08:53', 'active', 'STR-00008', NULL, '2026-09-22', 1, 1),
(9, 'Diamond Close', 'Exclusive quiet cul-de-sac', '2026-09-22 10:08:53', 'active', 'STR-00009', NULL, '2026-09-22', 1, 1),
(10, 'Crystal Court', 'Modern high-density residential avenue', '2026-09-22 10:08:53', 'active', 'STR-00010', NULL, '2026-09-22', 1, 1),
(11, 'Silver Spring Way', 'Western green corridor near sports pavilion', '2026-09-22 10:08:53', 'active', 'STR-00011', NULL, '2026-09-22', 1, 2),
(12, 'Magnolia Drive', 'Tree-lined western avenue', '2026-09-22 10:08:53', 'active', 'STR-00012', NULL, '2026-09-22', 1, 2),
(13, 'Sunset Ridge Road', 'Scenic western hill view road', '2026-09-22 10:08:53', 'active', 'STR-00013', NULL, '2026-09-22', 1, 2),
(14, 'Canyon View Lane', 'Quiet family residential crescent', '2026-09-22 10:08:53', 'active', 'STR-00014', NULL, '2026-09-22', 1, 2),
(15, 'Lakeside Parkway', 'Western buffer avenue facing the retention lake', '2026-09-22 10:08:53', 'active', 'STR-00015', NULL, '2026-09-22', 1, 2),
(16, 'Sycamore Terrace', 'Modern paved duplex zone', '2026-09-22 10:08:53', 'active', 'STR-00016', NULL, '2026-09-22', 1, 2),
(17, 'Golden Gate Avenue', 'Eastern primary arterial access way', '2026-09-22 10:08:53', 'active', 'STR-00017', NULL, '2026-09-22', 1, 6),
(18, 'Ruby Way', 'Vibrant eastern sector street', '2026-09-22 10:08:53', 'active', 'STR-00018', NULL, '2026-09-22', 1, 6),
(19, 'Topaz Gardens Close', 'Low-traffic residential lane', '2026-09-22 10:08:53', 'active', 'STR-00019', NULL, '2026-09-22', 1, 6),
(20, 'Amber Hill Road', 'Eastern elevation residential boulevard', '2026-09-22 10:08:53', 'active', 'STR-00020', NULL, '2026-09-22', 1, 6),
(21, 'Coral Reef Drive', 'Perimeter serviced residential road', '2026-09-22 10:08:53', 'active', 'STR-00021', NULL, '2026-09-22', 1, 6),
(22, 'Northern Star Crescent', 'Northern sector main gate avenue', '2026-09-22 10:08:53', 'active', 'STR-00022', NULL, '2026-09-22', 1, 8),
(23, 'Pine Valley Way', 'Quiet northern perimeter street', '2026-09-22 10:08:53', 'active', 'STR-00023', NULL, '2026-09-22', 1, 8),
(24, 'Cedar Crest Lane', 'Eco-friendly modern villa area', '2026-09-22 10:08:53', 'active', 'STR-00024', NULL, '2026-09-22', 1, 8),
(25, 'Maple Grove Close', 'Northern sector inner circle', '2026-09-22 10:08:53', 'active', 'STR-00025', NULL, '2026-09-22', 1, 8),
(26, 'Aspen Heights Road', 'Executive residential terrace avenue', '2026-09-22 10:08:53', 'active', 'STR-00026', NULL, '2026-09-22', 1, 8),
(27, 'Willow Brook Court', 'Northern community parkway', '2026-09-22 10:08:53', 'active', 'STR-00027', NULL, '2026-09-22', 1, 8),
(28, 'Birchwood Way', 'Northern gated residential lane', '2026-09-22 10:08:53', 'active', 'STR-00028', NULL, '2026-09-22', 1, 8);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `estate_id`) VALUES
('app_company_logo', '../uploads/1789807814_134218228980314490.jpg', 1),
('app_company_name', 'NoLimitBuzz', 1),
('currency_symbol', '₦', 1),
('emergency_auto_broadcast', '1', 1),
('emergency_auto_email', '1', 1),
('emergency_countdown_seconds', '5', 1),
('emergency_stakeholder_emails', '', 1),
('emergency_stakeholder_phones', '', 1),
('estate_code_of_conduct', 'Be polite and professional.', 1),
('estate_hero_image', '', 1),
('estate_location', '', 1),
('estate_logo', '../uploads/1789807814_134218228980314490.jpg', 1),
('estate_motto', 'Excellence in Living', 1),
('estate_name', 'Main Estate', 1),
('estate_rules', '1. Respect your neighbors.\r\n2. Keep common areas clean.', 1),
('id_card_orientation', 'portrait', 1),
('id_card_template', 'modern', 1),
('kapso_api_key', '362d68eaa744aabd9d70ca1dcc190462b1bf7eebf6381951d10c974223c88e92', 1),
('kapso_business_account_id', '1157218086854130', 1),
('kapso_phone_number_id', '1314108995123770', 1),
('kapso_sender_phone', '+234 903 647 7098', 1),
('kapso_webhook_verify_token', '6e3868ca0c4edde6e108314843a373db', 1),
('notify_on_invoice', '1', 1),
('notify_on_receipt', '1', 1),
('notify_on_visitor_arrival', '1', 1),
('notify_on_visitor_pass', '1', 1),
('notify_on_welcome', '1', 1),
('office_email', 'estate@estate.nolimitbuzz.com.ng', 1),
('office_phone', '123-456-7890', 1),
('paystack_public_key', 'pk_test_ce5632627de1bf47bfd2842c8c5cb6de049f707f', 1),
('paystack_secret_key', 'sk_test_d75279eb5b5b59e5e2d39ead499e887144fb690e', 1),
('require_resident_visitor_confirmation', '0', 1),
('smtp_enabled', '1', 1),
('smtp_encryption', 'ssl', 1),
('smtp_from_email', 'estate@estate.nolimitbuzz.com.ng', 1),
('smtp_from_name', 'No limit Buzz', 1),
('smtp_host', 'estate.nolimitbuzz.com.ng', 1),
('smtp_pass', '%LH},GPb;%6f{LS@', 1),
('smtp_port', '465', 1),
('smtp_reply_to', 'estate@estate.nolimitbuzz.com.ng', 1),
('smtp_user', 'estate@estate.nolimitbuzz.com.ng', 1),
('theme_color', '#f59e0b', 1),
('whatsapp_enabled', '1', 1),
('whatsapp_from_name', 'Estate Central Admin', 1),
('whatsapp_notify_on_emergency', '1', 1),
('whatsapp_notify_on_invoice', '1', 1),
('whatsapp_notify_on_receipt', '1', 1),
('whatsapp_notify_on_visitor_arrival', '1', 1),
('whatsapp_notify_on_visitor_pass', '1', 1),
('whatsapp_notify_on_welcome', '1', 1),
('whatsapp_provider', 'kapso', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tenancies`
--

CREATE TABLE `tenancies` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `resident_id` int(11) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `flat_id` int(11) DEFAULT NULL,
  `landlord_id` int(11) DEFAULT NULL,
  `enrollment_date` date DEFAULT NULL,
  `move_in_date` date DEFAULT NULL,
  `expected_move_out_date` date DEFAULT NULL,
  `move_out_date` date DEFAULT NULL,
  `status` enum('Active','Ended','Suspended') DEFAULT 'Active',
  `termination_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tenancies`
--

INSERT INTO `tenancies` (`id`, `estate_id`, `resident_id`, `property_id`, `flat_id`, `landlord_id`, `enrollment_date`, `move_in_date`, `expected_move_out_date`, `move_out_date`, `status`, `termination_reason`, `created_at`, `updated_at`) VALUES
(4, 1, 18, NULL, 5, NULL, NULL, '2026-09-11', NULL, NULL, 'Active', NULL, '2026-09-11 17:44:43', '2026-09-11 17:44:43'),
(5, 1, 18, NULL, 5, NULL, NULL, '2026-09-11', NULL, NULL, 'Active', NULL, '2026-09-11 18:09:47', '2026-09-11 18:09:47'),
(8, 1, 30, NULL, 7, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 10:08:54'),
(9, 1, 31, NULL, 10, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 10:08:54'),
(10, 1, 32, NULL, 13, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 10:08:54'),
(11, 1, 33, NULL, 37, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 11:30:08'),
(12, 1, 34, NULL, 40, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 11:30:08'),
(13, 1, 35, NULL, 43, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 11:30:08'),
(14, 1, 36, NULL, 46, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 11:30:08'),
(15, 1, 37, NULL, 49, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 11:30:08'),
(16, 1, 38, NULL, 50, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 11:30:08'),
(17, 1, 39, NULL, 52, NULL, NULL, '2026-09-22', NULL, NULL, 'Active', NULL, '2026-09-22 10:08:54', '2026-09-22 11:30:08');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'staff',
  `status` enum('active','disabled','suspended') NOT NULL DEFAULT 'active',
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `password_changed_at` datetime DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `first_name`, `last_name`, `email`, `password`, `role`, `status`, `force_password_change`, `password_changed_at`, `phone`, `created_at`, `estate_id`, `zone_id`) VALUES
(1, 'System Admin', 'System', 'Admin', 'admin@admin.com', '$2y$10$iSGZknu9Aomndj9NwOefdeKQ32A.rAWlwbON67CD4wKF2QFoVAnPe', 'admin', 'active', 0, NULL, NULL, '2026-03-30 11:29:37', 1, NULL),
(8, 'Super Administrator', 'Super', 'Administrator', 'superadmin@admin.com', '$2y$10$kw1sa2hlemVE0bDi3SMn4eSMlHNvT100xcivWDVd.rv2RNIBUMwcG', 'superadmin', 'active', 0, NULL, NULL, '2026-04-10 15:12:34', 1, NULL),
(12, 'Femi Oke', 'Femi', 'Oke', 'femi@gmail.com', '$2y$10$ClaC0284jcBk4koMPCRHMeixngPsCbzSF07tKTS/RlrpWodl/JdJq', 'security', 'active', 0, NULL, '09066832352', '2026-09-05 17:19:18', 1, NULL),
(13, 'Zone 2 Manager', NULL, NULL, 'zone2admin@estate.com', '$2y$10$cE9YPOhY8sgQwGYKRqTCDezQpyJFrrjj8w.gtAZOhYIMVVce/Uws.', 'zone_admin', 'active', 0, NULL, NULL, '2026-09-11 16:16:05', 1, 2),
(14, 'Zone ABC Admin', NULL, NULL, 'zoneabc@gmail.com', '$2y$10$muqIRkCK.x6s8S5HEIpwYudaEi0F5C.E16fmY/QTbETs7mHNoU3eq', 'zone_admin', 'active', 0, NULL, '', '2026-09-11 16:38:00', 1, 6),
(15, 'Zone 1 Admin', NULL, NULL, 'zone1@estate.com', '$2y$10$muqIRkCK.x6s8S5HEIpwYudaEi0F5C.E16fmY/QTbETs7mHNoU3eq', 'zone_admin', 'active', 0, NULL, '0800-ZONE-01', '2026-09-11 16:38:00', 1, 1),
(18, 'Femi Oke', 'Femi', 'Oke', 'femi@yopmail.com', '$2y$10$hWpIghJ.90UuHjcfyaipxOeW9.jLQfWG2ewrpvFSZdZMPVMcmcYke', 'resident', 'active', 0, NULL, '09066832352', '2026-09-11 17:44:40', 1, NULL),
(20, 'Samuel Okon', 'Samuel', 'Okon', 'guard.samuel@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0001', '2026-09-22 10:08:54', 1, NULL),
(21, 'Musa Bello', 'Musa', 'Bello', 'guard.musa@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0002', '2026-09-22 10:08:54', 1, NULL),
(22, 'David Adeleke', 'David', 'Adeleke', 'guard.david@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0003', '2026-09-22 10:08:54', 1, NULL),
(23, 'Ibrahim Yakubu', 'Ibrahim', 'Yakubu', 'guard.ibrahim@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0004', '2026-09-22 10:08:54', 1, NULL),
(24, 'Emeka Okafor', 'Emeka', 'Okafor', 'guard.emeka@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0005', '2026-09-22 10:08:54', 1, NULL),
(25, 'Solomon Bamidele', 'Solomon', 'Bamidele', 'guard.solomon@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0006', '2026-09-22 10:08:54', 1, NULL),
(26, 'Usman Danjuma', 'Usman', 'Danjuma', 'guard.usman@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0007', '2026-09-22 10:08:54', 1, NULL),
(27, 'Victor Eze', 'Victor', 'Eze', 'guard.victor@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0008', '2026-09-22 10:08:54', 1, NULL),
(28, 'Kabir Aliyu', 'Kabir', 'Aliyu', 'guard.kabir@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0009', '2026-09-22 10:08:54', 1, NULL),
(29, 'Taiwo Oladipo', 'Taiwo', 'Oladipo', 'guard.taiwo@yopmail.com', '$2y$10$0WSAeLA0BwvuDc/j4UcgGejnxQgpEBdKAHrUd3s526MALSnvyLzeK', 'security', 'active', 0, NULL, '+234 802 300 0010', '2026-09-22 10:08:54', 1, NULL),
(30, 'Babatunde Adebayo', 'Babatunde', 'Adebayo', 'res.adebayo@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '07040352560', '2026-09-22 10:08:54', 1, NULL),
(31, 'Chioma Nnamdi', 'Chioma', 'Nnamdi', 'res.chioma@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0002', '2026-09-22 10:08:54', 1, NULL),
(32, 'Olumide Bakare', 'Olumide', 'Bakare', 'res.olumide@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0003', '2026-09-22 10:08:54', 1, NULL),
(33, 'Fatima Abdullahi', 'Fatima', 'Abdullahi', 'res.fatima@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0004', '2026-09-22 10:08:54', 1, NULL),
(34, 'Chukwuma Obi', 'Chukwuma', 'Obi', 'res.chukwuma@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0005', '2026-09-22 10:08:54', 1, NULL),
(35, 'Folashade Ajayi', 'Folashade', 'Ajayi', 'res.folashade@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0006', '2026-09-22 10:08:54', 1, NULL),
(36, 'Danladi Garba', 'Danladi', 'Garba', 'res.danladi@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0007', '2026-09-22 10:08:54', 1, NULL),
(37, 'Blessing Umeh', 'Blessing', 'Umeh', 'res.blessing@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0008', '2026-09-22 10:08:54', 1, NULL),
(38, 'Kayode Sowore', 'Kayode', 'Sowore', 'res.kayode@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0009', '2026-09-22 10:08:54', 1, NULL),
(39, 'Amina Mohammed', 'Amina', 'Mohammed', 'res.amina@yopmail.com', '$2y$10$u0yutBeRftCLVlWkf66KJ.lt7Ow6gRTactYlKY/esP2uv13AiLS9K', 'resident', 'active', 0, NULL, '+234 803 400 0010', '2026-09-22 10:08:54', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `flat_id` int(11) NOT NULL,
  `type` enum('car','motorcycle','truck','other') DEFAULT 'car',
  `reg_number` varchar(50) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `particulars_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  `color` varchar(50) DEFAULT 'Unspecified',
  `sticker_number` varchar(50) DEFAULT NULL,
  `sticker_status` enum('active','expired','revoked','pending') DEFAULT 'active',
  `sticker_expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `flat_id`, `type`, `reg_number`, `model`, `created_at`, `custom_id`, `image_path`, `particulars_path`, `registration_date`, `estate_id`, `color`, `sticker_number`, `sticker_status`, `sticker_expiry_date`) VALUES
(2, 3, 'car', 'KJA-849-XA', 'Toyota Highlander 2023', '2026-09-19 20:13:58', 'VEH-00001', NULL, NULL, '2026-01-15', 1, 'Silver Metallic', NULL, 'active', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_gate_logs`
--

CREATE TABLE `vehicle_gate_logs` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `reg_number` varchar(50) NOT NULL,
  `direction` enum('entry','exit') NOT NULL,
  `gate_name` varchar(100) NOT NULL,
  `shift_name` varchar(100) DEFAULT NULL,
  `processed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicle_gate_logs`
--

INSERT INTO `vehicle_gate_logs` (`id`, `estate_id`, `vehicle_id`, `reg_number`, `direction`, `gate_name`, `shift_name`, `processed_by`, `notes`, `logged_at`) VALUES
(1, 1, 2, 'KJA-849-XA', 'entry', 'Main Entrance Gate', 'Morning Security Shift', 1, 'Inspected and verified resident pass', '2026-09-19 20:39:36'),
(2, 1, 2, 'KJA-849-XA', 'exit', 'Main Entrance Gate', 'Morning Security Shift', 1, 'Normal resident departure', '2026-09-19 20:39:36');

-- --------------------------------------------------------

--
-- Table structure for table `visitors`
--

CREATE TABLE `visitors` (
  `id` int(11) NOT NULL,
  `flat_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `check_in_time` datetime DEFAULT current_timestamp(),
  `check_out_time` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  `resident_id` int(11) DEFAULT NULL,
  `visitor_code` varchar(20) DEFAULT NULL,
  `purpose` varchar(150) DEFAULT NULL,
  `status` enum('pre_registered','entered','confirmed','checked_out','exited_without_confirmation','cancelled') DEFAULT 'pre_registered',
  `expected_arrival` datetime DEFAULT NULL,
  `entry_time` datetime DEFAULT NULL,
  `resident_confirmed_at` datetime DEFAULT NULL,
  `exit_time` datetime DEFAULT NULL,
  `exit_reason` varchar(255) DEFAULT NULL,
  `entry_gate` varchar(100) DEFAULT 'Main Gate',
  `exit_gate` varchar(100) DEFAULT 'Main Gate',
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `guard_notes` text DEFAULT NULL,
  `entry_processed_by` int(11) DEFAULT NULL,
  `entry_shift_name` varchar(100) DEFAULT NULL,
  `entry_roster_id` int(11) DEFAULT NULL,
  `exit_processed_by` int(11) DEFAULT NULL,
  `exit_shift_name` varchar(100) DEFAULT NULL,
  `exit_roster_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_logs`
--

CREATE TABLE `whatsapp_logs` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) DEFAULT 1,
  `recipient_phone` varchar(32) NOT NULL,
  `recipient_name` varchar(191) DEFAULT NULL,
  `message_type` varchar(64) NOT NULL DEFAULT 'text',
  `template` varchar(64) NOT NULL DEFAULT 'general',
  `reference_id` varchar(64) DEFAULT NULL,
  `message_body` text NOT NULL,
  `media_url` text DEFAULT NULL,
  `status` enum('sent','failed','delivered','read') NOT NULL DEFAULT 'sent',
  `response_id` varchar(191) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `whatsapp_logs`
--

INSERT INTO `whatsapp_logs` (`id`, `estate_id`, `recipient_phone`, `recipient_name`, `message_type`, `template`, `reference_id`, `message_body`, `media_url`, `status`, `response_id`, `error_message`, `created_at`, `updated_at`) VALUES
(5, 1, '2349066832352', 'Femi Oke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Femi Oke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 15:29:24', '2026-09-24 15:29:24'),
(6, 1, '0800-ZONE-01', 'Zone 1 Admin', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Zone 1 Admin*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'Invalid phone number format: \'0800-ZONE-01\'', '2026-09-24 15:29:24', '2026-09-24 15:29:24'),
(7, 1, '2349066832352', 'Femi Oke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Femi Oke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 15:29:27', '2026-09-24 15:29:27'),
(8, 1, '2348023000001', 'Samuel Okon', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Samuel Okon*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 15:29:29', '2026-09-24 15:29:29'),
(9, 1, '2348023000002', 'Musa Bello', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Musa Bello*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 15:29:31', '2026-09-24 15:29:31'),
(10, 1, '2348023000003', 'David Adeleke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *David Adeleke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 15:29:34', '2026-09-24 15:29:34'),
(11, 1, '2348023000004', 'Ibrahim Yakubu', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Ibrahim Yakubu*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of September 2026 please let\'s make sure to be punctua;l for the activity\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 15:29:37', '2026-09-24 15:29:37'),
(12, 1, '2349066832352', 'Femi Oke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Femi Oke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:47', '2026-09-24 16:17:47'),
(13, 1, '0800-ZONE-01', 'Zone 1 Admin', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Zone 1 Admin*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'Invalid phone number format: \'0800-ZONE-01\'', '2026-09-24 16:17:47', '2026-09-24 16:17:47'),
(14, 1, '2349066832352', 'Femi Oke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Femi Oke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:48', '2026-09-24 16:17:48'),
(15, 1, '2348023000001', 'Samuel Okon', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Samuel Okon*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:49', '2026-09-24 16:17:49'),
(16, 1, '2348023000002', 'Musa Bello', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Musa Bello*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:50', '2026-09-24 16:17:50'),
(17, 1, '2348023000003', 'David Adeleke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *David Adeleke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:51', '2026-09-24 16:17:51'),
(18, 1, '2348023000004', 'Ibrahim Yakubu', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Ibrahim Yakubu*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:52', '2026-09-24 16:17:52'),
(19, 1, '2348023000005', 'Emeka Okafor', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Emeka Okafor*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:53', '2026-09-24 16:17:53'),
(20, 1, '2348023000006', 'Solomon Bamidele', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Solomon Bamidele*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:54', '2026-09-24 16:17:54'),
(21, 1, '2348023000007', 'Usman Danjuma', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Usman Danjuma*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:55', '2026-09-24 16:17:55'),
(22, 1, '2348023000008', 'Victor Eze', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Victor Eze*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:56', '2026-09-24 16:17:56'),
(23, 1, '2348023000009', 'Kabir Aliyu', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Kabir Aliyu*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:57', '2026-09-24 16:17:57'),
(24, 1, '2348023000010', 'Taiwo Oladipo', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Taiwo Oladipo*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:17:58', '2026-09-24 16:17:58'),
(25, 1, '2347040352560', 'Babatunde Adebayo', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Babatunde Adebayo*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'sent', 'wamid.HBgNMjM0NzA0MDM1MjU2MBUCABEYEjlFQzJBQ0RDMTQzODYxNjAyRgA=', NULL, '2026-09-24 16:18:01', '2026-09-24 16:18:01'),
(26, 1, '2348034000002', 'Chioma Nnamdi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Chioma Nnamdi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:02', '2026-09-24 16:18:02'),
(27, 1, '2348034000003', 'Olumide Bakare', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Olumide Bakare*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:03', '2026-09-24 16:18:03'),
(28, 1, '2348034000004', 'Fatima Abdullahi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Fatima Abdullahi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:05', '2026-09-24 16:18:05'),
(29, 1, '2348034000005', 'Chukwuma Obi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Chukwuma Obi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:06', '2026-09-24 16:18:06'),
(30, 1, '2348034000006', 'Folashade Ajayi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Folashade Ajayi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:07', '2026-09-24 16:18:07'),
(31, 1, '2348034000007', 'Danladi Garba', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Danladi Garba*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:08', '2026-09-24 16:18:08'),
(32, 1, '2348034000008', 'Blessing Umeh', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Blessing Umeh*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:10', '2026-09-24 16:18:10'),
(33, 1, '2348034000009', 'Kayode Sowore', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Kayode Sowore*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:11', '2026-09-24 16:18:11'),
(34, 1, '2348034000010', 'Amina Mohammed', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Amina Mohammed*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:12', '2026-09-24 16:18:12'),
(35, 1, '2349066832352', 'Femi Oke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Femi Oke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:18:59', '2026-09-24 16:18:59'),
(36, 1, '0800-ZONE-01', 'Zone 1 Admin', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Zone 1 Admin*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'Invalid phone number format: \'0800-ZONE-01\'', '2026-09-24 16:18:59', '2026-09-24 16:18:59'),
(37, 1, '2349066832352', 'Femi Oke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Femi Oke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:00', '2026-09-24 16:19:00'),
(38, 1, '2348023000001', 'Samuel Okon', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Samuel Okon*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:01', '2026-09-24 16:19:01'),
(39, 1, '2348023000002', 'Musa Bello', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Musa Bello*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:02', '2026-09-24 16:19:02'),
(40, 1, '2348023000003', 'David Adeleke', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *David Adeleke*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:03', '2026-09-24 16:19:03'),
(41, 1, '2348023000004', 'Ibrahim Yakubu', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Ibrahim Yakubu*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:04', '2026-09-24 16:19:04'),
(42, 1, '2348023000005', 'Emeka Okafor', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Emeka Okafor*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:05', '2026-09-24 16:19:05'),
(43, 1, '2348023000006', 'Solomon Bamidele', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Solomon Bamidele*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:06', '2026-09-24 16:19:06'),
(44, 1, '2348023000007', 'Usman Danjuma', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Usman Danjuma*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:07', '2026-09-24 16:19:07'),
(45, 1, '2348023000008', 'Victor Eze', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Victor Eze*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:08', '2026-09-24 16:19:08'),
(46, 1, '2348023000009', 'Kabir Aliyu', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Kabir Aliyu*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:09', '2026-09-24 16:19:09'),
(47, 1, '2348023000010', 'Taiwo Oladipo', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Taiwo Oladipo*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:10', '2026-09-24 16:19:10'),
(48, 1, '2347040352560', 'Babatunde Adebayo', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Babatunde Adebayo*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'sent', 'wamid.HBgNMjM0NzA0MDM1MjU2MBUCABEYEjhBNkQ3NzdEQzUyOUM2RjBBNAA=', NULL, '2026-09-24 16:19:13', '2026-09-24 16:19:13'),
(49, 1, '2348034000002', 'Chioma Nnamdi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Chioma Nnamdi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:14', '2026-09-24 16:19:14'),
(50, 1, '2348034000003', 'Olumide Bakare', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Olumide Bakare*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:15', '2026-09-24 16:19:15'),
(51, 1, '2348034000004', 'Fatima Abdullahi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Fatima Abdullahi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:16', '2026-09-24 16:19:16'),
(52, 1, '2348034000005', 'Chukwuma Obi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Chukwuma Obi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:17', '2026-09-24 16:19:17'),
(53, 1, '2348034000006', 'Folashade Ajayi', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Folashade Ajayi*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:18', '2026-09-24 16:19:18'),
(54, 1, '2348034000007', 'Danladi Garba', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Danladi Garba*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:19', '2026-09-24 16:19:19'),
(55, 1, '2348034000008', 'Blessing Umeh', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Blessing Umeh*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:20', '2026-09-24 16:19:20'),
(56, 1, '2348034000009', 'Kayode Sowore', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Kayode Sowore*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:21', '2026-09-24 16:19:21'),
(57, 1, '2348034000010', 'Amina Mohammed', 'text', 'broadcast', '', '???? *ESTATE ANNOUNCEMENT*\n--------------------------------\n????️ *Estate:* Main Estate\n???? *Subject:* *[Estate Broadcast] General cleaning*\n\nDear *Amina Mohammed*,\n\nThere would be a general cleaning across the Estate on Saturday 26th of september 2026\n\n--------------------------------\n_Official advisory from Main Estate Administration._', NULL, 'failed', NULL, 'WhatsApp 24-Hour Policy: Recipient has not messaged your estate number (+234 903 647 7098) in the past 24 hours. Send \'Hi\' to +234 903 647 7098 (https://wa.me/2349036477098?text=Hi) to open the 24-hour messaging window.', '2026-09-24 16:19:22', '2026-09-24 16:19:22');

-- --------------------------------------------------------

--
-- Table structure for table `zonal_billing_frequencies`
--

CREATE TABLE `zonal_billing_frequencies` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `interval_days` int(11) NOT NULL DEFAULT 30,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `zonal_billing_frequencies`
--

INSERT INTO `zonal_billing_frequencies` (`id`, `estate_id`, `zone_id`, `name`, `code`, `interval_days`, `description`, `status`, `sort_order`, `created_at`) VALUES
(1, 1, NULL, 'Monthly', 'monthly', 30, 'Billed every 30 days', 'Active', 1, '2026-09-12 11:19:04'),
(2, 1, NULL, 'Bi-Monthly', 'bi-monthly', 60, 'Billed every 2 months (60 days)', 'Active', 2, '2026-09-12 11:19:04'),
(3, 1, NULL, 'Quarterly', 'quarterly', 90, 'Billed every 3 months (90 days)', 'Active', 3, '2026-09-12 11:19:04'),
(4, 1, NULL, 'Semi-Annually', 'semi-annually', 180, 'Billed every 6 months (180 days)', 'Active', 4, '2026-09-12 11:19:04'),
(5, 1, NULL, 'Yearly', 'yearly', 365, 'Billed once per year (365 days)', 'Active', 5, '2026-09-12 11:19:04'),
(6, 1, NULL, 'One-Time', 'one-time', 0, 'One-off ad-hoc levy or setup charge', 'Active', 6, '2026-09-12 11:19:04'),
(7, 1, NULL, 'Weekly', 'weekly', 7, 'Billed every 7 days', 'Active', 7, '2026-09-12 11:19:04'),
(8, 1, 1, 'Bi-Weekly Cycle', 'bi-weekly', 14, 'Every two weeks billing', 'Active', 8, '2026-09-12 11:47:10');

-- --------------------------------------------------------

--
-- Table structure for table `zonal_billing_settings`
--

CREATE TABLE `zonal_billing_settings` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) NOT NULL,
  `allow_installments` tinyint(1) NOT NULL DEFAULT 1,
  `min_first_payment_percent` decimal(5,2) NOT NULL DEFAULT 40.00,
  `max_subsequent_payments` int(11) NOT NULL DEFAULT 3,
  `split_mode` enum('equal_remainder','custom_percentages','flexible') NOT NULL DEFAULT 'equal_remainder',
  `subsequent_percentages` varchar(255) DEFAULT '20,20,20',
  `installment_interval_days` int(11) NOT NULL DEFAULT 30,
  `allow_custom_amount` tinyint(1) DEFAULT 1,
  `late_penalty_percent` decimal(5,2) DEFAULT 0.00,
  `grace_period_days` int(11) DEFAULT 7,
  `instructions` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `zonal_billing_settings`
--

INSERT INTO `zonal_billing_settings` (`id`, `estate_id`, `zone_id`, `allow_installments`, `min_first_payment_percent`, `max_subsequent_payments`, `split_mode`, `subsequent_percentages`, `installment_interval_days`, `allow_custom_amount`, `late_penalty_percent`, `grace_period_days`, `instructions`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 50.00, 2, 'equal_remainder', '25,25', 30, 1, 0.00, 7, 'Installment payments are enabled for this zone. Initial downpayment is 40% with up to 3 subsequent installments every 30 days.', '2026-09-12 11:19:04', '2026-09-12 11:44:20'),
(2, 1, 2, 1, 40.00, 3, 'equal_remainder', '20,20,20', 30, 1, 0.00, 7, 'Installment payments are enabled for this zone. Initial downpayment is 40% with up to 3 subsequent installments every 30 days.', '2026-09-12 11:19:04', '2026-09-12 11:19:04'),
(3, 1, 6, 1, 40.00, 3, 'equal_remainder', '20,20,20', 30, 1, 0.00, 7, 'Installment payments are enabled for this zone. Initial downpayment is 40% with up to 3 subsequent installments every 30 days.', '2026-09-12 11:19:04', '2026-09-12 11:19:04'),
(4, 1, 8, 1, 40.00, 3, 'equal_remainder', '20,20,20', 30, 1, 0.00, 7, 'Installment payments are enabled for this zone. Initial downpayment is 40% with up to 3 subsequent installments every 30 days.', '2026-09-12 11:19:04', '2026-09-12 11:19:04'),
(5, 1, 0, 1, 40.00, 3, 'equal_remainder', '20,20,20', 30, 1, 0.00, 7, 'Estate-wide default installment billing policy. Initial downpayment is 40% with up to 3 subsequent installments spaced 30 days apart.', '2026-09-12 15:22:17', '2026-09-12 15:22:17');

-- --------------------------------------------------------

--
-- Table structure for table `zonal_charge_types`
--

CREATE TABLE `zonal_charge_types` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `icon` varchar(50) DEFAULT 'fa-shield-halved',
  `color` varchar(20) DEFAULT '#3b82f6',
  `description` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `zonal_charge_types`
--

INSERT INTO `zonal_charge_types` (`id`, `estate_id`, `zone_id`, `name`, `code`, `icon`, `color`, `description`, `status`, `created_at`) VALUES
(1, 1, NULL, 'Security & Vigilante Levy', 'security', 'fa-shield-halved', '#3b82f6', 'Zonal security patrols, gate personnel, and surveillance maintenance', 'Active', '2026-09-12 11:19:04'),
(2, 1, NULL, 'Waste Disposal & Sanitation', 'waste', 'fa-trash-can', '#10b981', 'Refuse collection, drainage cleaning, and zonal hygiene', 'Active', '2026-09-12 11:19:04'),
(3, 1, NULL, 'Power & Generator Diesel', 'power', 'fa-bolt', '#f59e0b', 'Backup central generators, diesel fuel, and transformer servicing', 'Active', '2026-09-12 11:19:04'),
(4, 1, NULL, 'Infrastructure & Road Repair', 'infrastructure', 'fa-road', '#8b5cf6', 'Pothole repairs, streetlight fixtures, and road paving dues', 'Active', '2026-09-12 11:19:04'),
(5, 1, NULL, 'Water Supply & Sewage', 'water', 'fa-faucet-drip', '#06b6d4', 'Borehole pumping, water testing, and plumbing line repairs', 'Active', '2026-09-12 11:19:04'),
(6, 1, NULL, 'Environmental & Landscaping', 'environment', 'fa-tree', '#22c55e', 'Grass trimming, hedge pruning, and recreational park upkeep', 'Active', '2026-09-12 11:19:04'),
(7, 1, NULL, 'Development & Capital Levy', 'development', 'fa-building-columns', '#ec4899', 'Long-term zone development projects and asset acquisitions', 'Active', '2026-09-12 11:19:04'),
(8, 1, NULL, 'General Administrative Dues', 'general', 'fa-file-invoice', '#64748b', 'General zonal administration and community dues', 'Active', '2026-09-12 11:19:04'),
(9, 1, 1, 'Beautification & Gardening', 'beautification', 'fa-leaf', '#10b981', 'Zone park and gardening dues', 'Active', '2026-09-12 11:47:10');

-- --------------------------------------------------------

--
-- Table structure for table `zones`
--

CREATE TABLE `zones` (
  `id` int(11) NOT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `custom_id` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `motto` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `office_address` varchar(255) DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `paystack_bank_name` varchar(100) DEFAULT NULL,
  `paystack_bank_code` varchar(20) DEFAULT NULL,
  `paystack_account_number` varchar(20) DEFAULT NULL,
  `paystack_account_name` varchar(150) DEFAULT NULL,
  `paystack_customer_code` varchar(100) DEFAULT NULL,
  `paystack_dva_id` varchar(100) DEFAULT NULL,
  `paystack_subaccount_code` varchar(100) DEFAULT NULL,
  `paystack_assigned_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `zones`
--

INSERT INTO `zones` (`id`, `estate_id`, `custom_id`, `name`, `email`, `phone`, `motto`, `registration_date`, `office_address`, `code`, `description`, `paystack_bank_name`, `paystack_bank_code`, `paystack_account_number`, `paystack_account_name`, `paystack_customer_code`, `paystack_dva_id`, `paystack_subaccount_code`, `paystack_assigned_at`, `status`, `created_at`, `created_by`) VALUES
(1, 1, 'ZN-00001', 'Zone 1 - Central Sector', 'zone1@estate.com', '0800-ZONE-01', 'Excellence, Peace & Harmony', '2026-01-15', 'Zone 1 Secretariat, Central Avenue', 'ZN-01', 'Initial central sector covering existing streets', 'Wema Bank (Paystack DVA)', NULL, '9937402783', 'Zone 1 - Central Sector / No limit Buzz', 'CUS_ZN_zn01_2783', 'DVA_934369', 'SUB_zn01_2783', '2026-09-11 19:08:53', 'active', '2026-09-11 16:07:30', NULL),
(2, 1, 'ZN-00002', 'Zone 2 - West Sector', NULL, NULL, NULL, NULL, NULL, 'ZN-02', 'West sector test zone', 'Wema Bank (Paystack DVA)', NULL, '9915029544', 'Zone 2 - West Sector / No limit Buzz', 'CUS_ZN_zn02_9544', 'DVA_248431', 'SUB_zn02_9544', '2026-09-11 19:08:54', 'active', '2026-09-11 16:16:05', NULL),
(6, 1, 'ZN-00003', 'Zone 3 - East Sector', 'zoneabc@gmail.com', '', 'Peace and progress', '2026-09-11', '', 'ZN-03', '', 'First Bank of Nigeria', '011', '3209812414', 'AKINSANYA JOHN OREOLUWA', 'CUS_v3mqpdr6q3o5kpp', '2145657', 'ACCT_nolsjwxl3r42uj0', '2026-09-11 20:09:45', 'active', '2026-09-11 16:35:28', 1),
(8, 1, 'ZN-00007', 'Zone 4 - North Sector', 'zonedef@gmail.com', '', 'Unity and Development', '2026-09-11', 'Animasaun Street', 'ZN-07', '', 'Wema Bank (Paystack DVA)', NULL, '9973940643', 'Zone DEF / No limit Buzz', 'CUS_ZN_zn07_0643', 'DVA_143289', 'SUB_zn07_0643', '2026-09-11 19:13:34', 'active', '2026-09-11 18:13:32', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_audit_logs_estate` (`estate_id`);

--
-- Indexes for table `buildings`
--
ALTER TABLE `buildings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `street_id` (`street_id`),
  ADD KEY `fk_buildings_estate` (`estate_id`);

--
-- Indexes for table `building_floor_types`
--
ALTER TABLE `building_floor_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `building_statuses`
--
ALTER TABLE `building_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `building_types`
--
ALTER TABLE `building_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `commercial_field_definitions`
--
ALTER TABLE `commercial_field_definitions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`);

--
-- Indexes for table `community_chat`
--
ALTER TABLE `community_chat`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_community_chat_estate` (`estate_id`);

--
-- Indexes for table `contact_change_requests`
--
ALTER TABLE `contact_change_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_estate` (`estate_id`),
  ADD KEY `idx_zone` (`zone_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `domestic_staff_roles`
--
ALTER TABLE `domestic_staff_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `domestic_staff_status`
--
ALTER TABLE `domestic_staff_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `recipient_email` (`recipient_email`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `estates`
--
ALTER TABLE `estates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `domain_prefix` (`domain_prefix`);

--
-- Indexes for table `estate_announcements`
--
ALTER TABLE `estate_announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_estate_announcements_estate` (`estate_id`);

--
-- Indexes for table `estate_charges`
--
ALTER TABLE `estate_charges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_estate_charges_estate` (`estate_id`),
  ADD KEY `idx_charges_zone` (`zone_id`);

--
-- Indexes for table `estate_emergency_actions`
--
ALTER TABLE `estate_emergency_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `estate_emergency_alerts`
--
ALTER TABLE `estate_emergency_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`,`status`),
  ADD KEY `sender_type` (`sender_type`,`sender_id`),
  ADD KEY `target_scope` (`target_scope`,`zone_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `estate_emergency_categories`
--
ALTER TABLE `estate_emergency_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `estate_emergency_contacts`
--
ALTER TABLE `estate_emergency_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `estate_policies`
--
ALTER TABLE `estate_policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_estate` (`estate_id`),
  ADD KEY `idx_scope` (`scope`),
  ADD KEY `idx_zone` (`zone_id`),
  ADD KEY `idx_category` (`category_slug`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `estate_policy_categories`
--
ALTER TABLE `estate_policy_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_estate_slug` (`estate_id`,`slug`),
  ADD KEY `idx_estate` (`estate_id`),
  ADD KEY `idx_slug` (`slug`);

--
-- Indexes for table `estate_settings`
--
ALTER TABLE `estate_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `estate_staff`
--
ALTER TABLE `estate_staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_estate_staff_estate` (`estate_id`);

--
-- Indexes for table `flats`
--
ALTER TABLE `flats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `building_id` (`building_id`),
  ADD KEY `fk_flats_estate` (`estate_id`);

--
-- Indexes for table `flat_statuses`
--
ALTER TABLE `flat_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `flat_types`
--
ALTER TABLE `flat_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `household_staff`
--
ALTER TABLE `household_staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `flat_id` (`flat_id`),
  ADD KEY `fk_household_staff_estate` (`estate_id`);

--
-- Indexes for table `incident_entity_types`
--
ALTER TABLE `incident_entity_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `slug` (`slug`);

--
-- Indexes for table `incident_severities`
--
ALTER TABLE `incident_severities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `slug` (`slug`);

--
-- Indexes for table `incident_statuses`
--
ALTER TABLE `incident_statuses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `slug` (`slug`);

--
-- Indexes for table `incident_types`
--
ALTER TABLE `incident_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `slug` (`slug`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `flat_id` (`flat_id`),
  ADD KEY `fk_invoices_estate` (`estate_id`),
  ADD KEY `idx_invoices_zone` (`zone_id`);

--
-- Indexes for table `invoice_installments`
--
ALTER TABLE `invoice_installments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `zone_id` (`zone_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`);

--
-- Indexes for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `flat_id` (`flat_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `fk_maintenance_requests_estate` (`estate_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_notifications_estate` (`estate_id`);

--
-- Indexes for table `owner_properties`
--
ALTER TABLE `owner_properties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `fk_owner_properties_estate` (`estate_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `token` (`token`),
  ADD KEY `code` (`code`),
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `used` (`used`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_payments_estate` (`estate_id`),
  ADD KEY `idx_payments_zone_id` (`zone_id`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payment_id` (`payment_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `pets`
--
ALTER TABLE `pets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `flat_id` (`flat_id`),
  ADD KEY `fk_pets_estate` (`estate_id`);

--
-- Indexes for table `property_acquisition_types`
--
ALTER TABLE `property_acquisition_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `property_history`
--
ALTER TABLE `property_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `changed_by` (`changed_by`),
  ADD KEY `fk_property_history_estate` (`estate_id`);

--
-- Indexes for table `property_owners`
--
ALTER TABLE `property_owners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `fk_property_owners_estate` (`estate_id`);

--
-- Indexes for table `property_ownership`
--
ALTER TABLE `property_ownership`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `fk_property_ownership_estate` (`estate_id`);

--
-- Indexes for table `property_ownership_types`
--
ALTER TABLE `property_ownership_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `receipts`
--
ALTER TABLE `receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `fk_receipts_estate` (`estate_id`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `flat_id` (`flat_id`),
  ADD KEY `fk_residents_estate` (`estate_id`);

--
-- Indexes for table `resident_history`
--
ALTER TABLE `resident_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `resident_id` (`resident_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `fk_resident_history_estate` (`estate_id`);

--
-- Indexes for table `resident_relationships`
--
ALTER TABLE `resident_relationships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `resident_statuses`
--
ALTER TABLE `resident_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_roles_estate` (`estate_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `security_incidents`
--
ALTER TABLE `security_incidents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`,`incident_datetime`),
  ADD KEY `estate_id_2` (`estate_id`,`status`),
  ADD KEY `estate_id_3` (`estate_id`,`incident_type`),
  ADD KEY `is_flagged_bad_behavior` (`is_flagged_bad_behavior`),
  ADD KEY `target_resident_id` (`target_resident_id`),
  ADD KEY `reporter_id` (`reporter_id`);

--
-- Indexes for table `security_posts`
--
ALTER TABLE `security_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `security_roster`
--
ALTER TABLE `security_roster`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`,`duty_date`),
  ADD KEY `estate_id_2` (`estate_id`,`start_datetime`,`end_datetime`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `security_shifts`
--
ALTER TABLE `security_shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`);

--
-- Indexes for table `staff_details`
--
ALTER TABLE `staff_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `fk_staff_details_estate` (`estate_id`);

--
-- Indexes for table `streets`
--
ALTER TABLE `streets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `fk_streets_estate` (`estate_id`),
  ADD KEY `idx_streets_zone` (`zone_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`estate_id`,`setting_key`);

--
-- Indexes for table `tenancies`
--
ALTER TABLE `tenancies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `resident_id` (`resident_id`),
  ADD KEY `fk_tenancies_estate` (`estate_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_estate` (`estate_id`),
  ADD KEY `idx_users_zone` (`zone_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `flat_id` (`flat_id`),
  ADD KEY `fk_vehicles_estate` (`estate_id`);

--
-- Indexes for table `vehicle_gate_logs`
--
ALTER TABLE `vehicle_gate_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `reg_number` (`reg_number`),
  ADD KEY `logged_at` (`logged_at`);

--
-- Indexes for table `visitors`
--
ALTER TABLE `visitors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `flat_id` (`flat_id`),
  ADD KEY `fk_visitors_estate` (`estate_id`);

--
-- Indexes for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `estate_id` (`estate_id`),
  ADD KEY `recipient_phone` (`recipient_phone`),
  ADD KEY `status` (`status`),
  ADD KEY `template` (`template`),
  ADD KEY `reference_id` (`reference_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `zonal_billing_frequencies`
--
ALTER TABLE `zonal_billing_frequencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `zone_id` (`zone_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `zonal_billing_settings`
--
ALTER TABLE `zonal_billing_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `zone_id` (`zone_id`),
  ADD KEY `zone_id_2` (`zone_id`),
  ADD KEY `estate_id` (`estate_id`);

--
-- Indexes for table `zonal_charge_types`
--
ALTER TABLE `zonal_charge_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `zone_id` (`zone_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `zones`
--
ALTER TABLE `zones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `custom_id` (`custom_id`),
  ADD KEY `idx_zones_estate` (`estate_id`),
  ADD KEY `idx_zones_status` (`status`),
  ADD KEY `idx_zones_paystack_account` (`paystack_account_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;

--
-- AUTO_INCREMENT for table `buildings`
--
ALTER TABLE `buildings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=281;

--
-- AUTO_INCREMENT for table `building_floor_types`
--
ALTER TABLE `building_floor_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `building_statuses`
--
ALTER TABLE `building_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `building_types`
--
ALTER TABLE `building_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `commercial_field_definitions`
--
ALTER TABLE `commercial_field_definitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `community_chat`
--
ALTER TABLE `community_chat`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `contact_change_requests`
--
ALTER TABLE `contact_change_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `domestic_staff_roles`
--
ALTER TABLE `domestic_staff_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `domestic_staff_status`
--
ALTER TABLE `domestic_staff_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=130;

--
-- AUTO_INCREMENT for table `estates`
--
ALTER TABLE `estates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `estate_announcements`
--
ALTER TABLE `estate_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `estate_charges`
--
ALTER TABLE `estate_charges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `estate_emergency_actions`
--
ALTER TABLE `estate_emergency_actions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `estate_emergency_alerts`
--
ALTER TABLE `estate_emergency_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `estate_emergency_categories`
--
ALTER TABLE `estate_emergency_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `estate_emergency_contacts`
--
ALTER TABLE `estate_emergency_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `estate_policies`
--
ALTER TABLE `estate_policies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `estate_policy_categories`
--
ALTER TABLE `estate_policy_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `estate_settings`
--
ALTER TABLE `estate_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `estate_staff`
--
ALTER TABLE `estate_staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `flats`
--
ALTER TABLE `flats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `flat_statuses`
--
ALTER TABLE `flat_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `flat_types`
--
ALTER TABLE `flat_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `household_staff`
--
ALTER TABLE `household_staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `incident_entity_types`
--
ALTER TABLE `incident_entity_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `incident_severities`
--
ALTER TABLE `incident_severities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `incident_statuses`
--
ALTER TABLE `incident_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `incident_types`
--
ALTER TABLE `incident_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `invoice_installments`
--
ALTER TABLE `invoice_installments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=168;

--
-- AUTO_INCREMENT for table `owner_properties`
--
ALTER TABLE `owner_properties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `pets`
--
ALTER TABLE `pets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `property_acquisition_types`
--
ALTER TABLE `property_acquisition_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `property_history`
--
ALTER TABLE `property_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `property_owners`
--
ALTER TABLE `property_owners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `property_ownership`
--
ALTER TABLE `property_ownership`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `property_ownership_types`
--
ALTER TABLE `property_ownership_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `receipts`
--
ALTER TABLE `receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `resident_history`
--
ALTER TABLE `resident_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `resident_relationships`
--
ALTER TABLE `resident_relationships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `resident_statuses`
--
ALTER TABLE `resident_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `security_incidents`
--
ALTER TABLE `security_incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_posts`
--
ALTER TABLE `security_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `security_roster`
--
ALTER TABLE `security_roster`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `security_shifts`
--
ALTER TABLE `security_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `staff_details`
--
ALTER TABLE `staff_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `streets`
--
ALTER TABLE `streets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `tenancies`
--
ALTER TABLE `tenancies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `vehicle_gate_logs`
--
ALTER TABLE `vehicle_gate_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `visitors`
--
ALTER TABLE `visitors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `whatsapp_logs`
--
ALTER TABLE `whatsapp_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `zonal_billing_frequencies`
--
ALTER TABLE `zonal_billing_frequencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `zonal_billing_settings`
--
ALTER TABLE `zonal_billing_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `zonal_charge_types`
--
ALTER TABLE `zonal_charge_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `zones`
--
ALTER TABLE `zones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_audit_logs_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `buildings`
--
ALTER TABLE `buildings`
  ADD CONSTRAINT `buildings_ibfk_1` FOREIGN KEY (`street_id`) REFERENCES `streets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_buildings_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `commercial_field_definitions`
--
ALTER TABLE `commercial_field_definitions`
  ADD CONSTRAINT `commercial_field_definitions_ibfk_1` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `community_chat`
--
ALTER TABLE `community_chat`
  ADD CONSTRAINT `community_chat_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_community_chat_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `estate_announcements`
--
ALTER TABLE `estate_announcements`
  ADD CONSTRAINT `fk_estate_announcements_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `estate_charges`
--
ALTER TABLE `estate_charges`
  ADD CONSTRAINT `fk_estate_charges_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `estate_staff`
--
ALTER TABLE `estate_staff`
  ADD CONSTRAINT `estate_staff_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_estate_staff_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `flats`
--
ALTER TABLE `flats`
  ADD CONSTRAINT `fk_flats_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `flats_ibfk_1` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `household_staff`
--
ALTER TABLE `household_staff`
  ADD CONSTRAINT `fk_household_staff_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `household_staff_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_requests`
--
ALTER TABLE `maintenance_requests`
  ADD CONSTRAINT `fk_maintenance_requests_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `maintenance_requests_ibfk_2` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `maintenance_requests_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `owner_properties`
--
ALTER TABLE `owner_properties`
  ADD CONSTRAINT `fk_owner_properties_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `owner_properties_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `property_owners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD CONSTRAINT `payment_transactions_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pets`
--
ALTER TABLE `pets`
  ADD CONSTRAINT `fk_pets_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pets_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `property_history`
--
ALTER TABLE `property_history`
  ADD CONSTRAINT `fk_property_history_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `property_history_ibfk_1` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `property_owners`
--
ALTER TABLE `property_owners`
  ADD CONSTRAINT `fk_property_owners_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `property_ownership`
--
ALTER TABLE `property_ownership`
  ADD CONSTRAINT `fk_property_ownership_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `property_ownership_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `property_owners` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `receipts`
--
ALTER TABLE `receipts`
  ADD CONSTRAINT `fk_receipts_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `residents`
--
ALTER TABLE `residents`
  ADD CONSTRAINT `fk_residents_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `residents_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `residents_ibfk_2` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `resident_history`
--
ALTER TABLE `resident_history`
  ADD CONSTRAINT `fk_resident_history_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `resident_history_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `resident_history_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `fk_roles_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_details`
--
ALTER TABLE `staff_details`
  ADD CONSTRAINT `fk_staff_details_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `staff_details_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `streets`
--
ALTER TABLE `streets`
  ADD CONSTRAINT `fk_streets_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tenancies`
--
ALTER TABLE `tenancies`
  ADD CONSTRAINT `fk_tenancies_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tenancies_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `visitors`
--
ALTER TABLE `visitors`
  ADD CONSTRAINT `fk_visitors_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `visitors_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
