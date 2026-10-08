
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `artisan_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `maintenance_id` int(11) NOT NULL,
  `artisan_id` int(11) NOT NULL,
  `resident_user_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL CHECK (`rating` between 1 and 5),
  `review_comment` text DEFAULT NULL,
  `punctuality_score` tinyint(1) DEFAULT 5,
  `workmanship_score` tinyint(1) DEFAULT 5,
  `politeness_score` tinyint(1) DEFAULT 5,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `artisan_id` (`artisan_id`),
  KEY `maintenance_id` (`maintenance_id`),
  KEY `resident_user_id` (`resident_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `artisans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `artisan_code` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `phone` varchar(25) NOT NULL,
  `alt_phone` varchar(25) DEFAULT NULL,
  `trade_category` varchar(60) NOT NULL,
  `specialties` text DEFAULT NULL,
  `years_experience` int(3) DEFAULT 1,
  `business_name` varchar(150) DEFAULT NULL,
  `residential_address` text NOT NULL,
  `id_type` enum('nin','drivers_license','voters_card','national_id','passport') NOT NULL,
  `id_number` varchar(80) NOT NULL,
  `id_document_path` varchar(255) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `guarantor_name` varchar(150) DEFAULT NULL,
  `guarantor_phone` varchar(25) DEFAULT NULL,
  `guarantor_address` text DEFAULT NULL,
  `base_inspection_fee` decimal(10,2) DEFAULT 0.00,
  `availability_status` enum('available','busy','off_duty') DEFAULT 'available',
  `is_emergency_ready` tinyint(1) DEFAULT 0,
  `referred_by_type` enum('public','resident','admin','staff') DEFAULT 'public',
  `referred_by_id` int(11) DEFAULT NULL,
  `verification_status` enum('pending','verified','rejected','suspended') DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `average_rating` decimal(3,2) DEFAULT 5.00,
  `total_jobs_completed` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `artisan_code` (`artisan_code`),
  KEY `estate_id` (`estate_id`),
  KEY `trade_category` (`trade_category`),
  KEY `verification_status` (`verification_status`),
  KEY `phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_audit_logs_estate` (`estate_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_logs_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `building_floor_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `building_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `building_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `buildings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `commercial_data` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `street_id` (`street_id`),
  KEY `fk_buildings_estate` (`estate_id`),
  CONSTRAINT `buildings_ibfk_1` FOREIGN KEY (`street_id`) REFERENCES `streets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_buildings_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `commercial_field_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `field_label` varchar(100) NOT NULL,
  `field_type` enum('text','number','dropdown','date') DEFAULT 'text',
  `field_options` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  CONSTRAINT `commercial_field_definitions_ibfk_1` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `community_chat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_community_chat_estate` (`estate_id`),
  CONSTRAINT `community_chat_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_community_chat_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `contact_change_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estate` (`estate_id`),
  KEY `idx_zone` (`zone_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `domestic_staff_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `domestic_staff_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `recipient_email` varchar(191) NOT NULL,
  `recipient_name` varchar(191) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `template` varchar(64) NOT NULL,
  `reference_id` varchar(64) DEFAULT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `recipient_email` (`recipient_email`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_estate_announcements_estate` (`estate_id`),
  CONSTRAINT `fk_estate_announcements_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_estate_charges_estate` (`estate_id`),
  KEY `idx_charges_zone` (`zone_id`),
  CONSTRAINT `fk_estate_charges_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_emergency_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `action_title` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `target_status` enum('resolved','dispatched','acknowledged','false_alarm') DEFAULT 'resolved',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_emergency_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`,`status`),
  KEY `sender_type` (`sender_type`,`sender_id`),
  KEY `target_scope` (`target_scope`,`zone_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_emergency_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_emergency_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `label` varchar(100) NOT NULL,
  `phone_number` varchar(50) NOT NULL,
  `contact_type` enum('internal_security','medical','fire','police','management','general') DEFAULT 'internal_security',
  `is_primary` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL,
  `module_key` varchar(60) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `disabled_reason` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estate_module` (`estate_id`,`module_key`),
  CONSTRAINT `fk_mod_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_penalties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `penalty_ref` varchar(50) NOT NULL,
  `policy_id` int(11) DEFAULT NULL,
  `incident_id` int(11) DEFAULT NULL,
  `recipient_type` enum('resident','artisan','visitor','staff','other') NOT NULL DEFAULT 'resident',
  `user_id` int(11) DEFAULT NULL,
  `artisan_id` int(11) DEFAULT NULL,
  `visitor_id` int(11) DEFAULT NULL,
  `recipient_name` varchar(150) NOT NULL,
  `recipient_phone` varchar(50) DEFAULT NULL,
  `recipient_email` varchar(191) DEFAULT NULL,
  `property_or_unit` varchar(150) DEFAULT NULL,
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `violation_title` varchar(200) NOT NULL,
  `violation_code` varchar(50) DEFAULT NULL,
  `violation_details` text NOT NULL,
  `offence_date` datetime NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `punishment_type` varchar(50) NOT NULL DEFAULT 'fine',
  `fine_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `status` enum('pending','paid','waived','disputed','escalated') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `issued_by_name` varchar(150) DEFAULT NULL,
  `email_dispatched` tinyint(1) DEFAULT 0,
  `whatsapp_dispatched` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `penalty_ref` (`penalty_ref`),
  KEY `user_id` (`user_id`),
  KEY `artisan_id` (`artisan_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_policies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estate` (`estate_id`),
  KEY `idx_scope` (`scope`),
  KEY `idx_zone` (`zone_id`),
  KEY `idx_category` (`category_slug`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_policy_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `slug` varchar(100) NOT NULL,
  `name` varchar(150) NOT NULL,
  `icon` varchar(60) DEFAULT 'fa-gavel',
  `color` varchar(30) DEFAULT '#3b82f6',
  `description` varchar(255) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estate_slug` (`estate_id`,`slug`),
  KEY `idx_estate` (`estate_id`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_name` varchar(255) DEFAULT 'Estate Management System',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estate_staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `custom_id` varchar(50) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `role` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `user_id` (`user_id`),
  KEY `fk_estate_staff_estate` (`estate_id`),
  CONSTRAINT `estate_staff_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_estate_staff_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `estates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `domain_prefix` varchar(50) DEFAULT NULL,
  `status` enum('active','suspended','trial','maintenance','expired') DEFAULT 'active',
  `plan` enum('starter','growth','enterprise') DEFAULT 'starter',
  `subscription_expires_at` datetime DEFAULT NULL,
  `billing_cycle` enum('monthly','annual') DEFAULT 'monthly',
  `contact_email` varchar(150) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `max_residents` int(11) DEFAULT 250,
  `max_guards` int(11) DEFAULT 15,
  `custom_domain` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_prefix` (`domain_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `flat_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `flat_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `flats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `building_id` (`building_id`),
  KEY `fk_flats_estate` (`estate_id`),
  CONSTRAINT `fk_flats_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `flats_ibfk_1` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `household_staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `flat_id` (`flat_id`),
  KEY `fk_household_staff_estate` (`estate_id`),
  CONSTRAINT `fk_household_staff_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `household_staff_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `incident_entity_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `incident_severities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `incident_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `incident_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `invoice_installments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `zone_id` (`zone_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `charge_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `issue_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `flat_id` (`flat_id`),
  KEY `fk_invoices_estate` (`estate_id`),
  KEY `idx_invoices_zone` (`zone_id`),
  CONSTRAINT `fk_invoices_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `maintenance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `flat_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high','emergency') DEFAULT 'medium',
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `assigned_to` int(11) DEFAULT NULL,
  `artisan_id` int(11) DEFAULT NULL,
  `service_type` varchar(60) DEFAULT NULL,
  `scheduled_date` datetime DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `resident_rated` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_report` text DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  `attended_by` int(11) DEFAULT NULL,
  `attended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `flat_id` (`flat_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `fk_maintenance_requests_estate` (`estate_id`),
  CONSTRAINT `fk_maintenance_requests_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_requests_ibfk_2` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_requests_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `reference_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_notifications_estate` (`estate_id`),
  CONSTRAINT `fk_notifications_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `offline_sync_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `terminal_id` varchar(64) NOT NULL DEFAULT 'gate_terminal_1',
  `guard_user_id` int(11) DEFAULT NULL,
  `action_type` varchar(64) NOT NULL,
  `visitor_id` int(11) DEFAULT NULL,
  `visitor_code` varchar(32) DEFAULT NULL,
  `offline_timestamp` datetime NOT NULL,
  `synced_at` datetime DEFAULT current_timestamp(),
  `sync_status` enum('synced','conflict','ignored') NOT NULL DEFAULT 'synced',
  `details` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `visitor_code` (`visitor_code`),
  KEY `synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `owner_properties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `property_type` enum('building','flat') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`),
  KEY `fk_owner_properties_estate` (`estate_id`),
  CONSTRAINT `fk_owner_properties_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `owner_properties_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `property_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `email` varchar(191) NOT NULL,
  `code` varchar(10) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `token` (`token`),
  KEY `code` (`code`),
  KEY `expires_at` (`expires_at`),
  KEY `used` (`used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `gateway` varchar(50) DEFAULT 'Paystack',
  `gateway_reference` varchar(100) NOT NULL,
  `gateway_transaction_id` varchar(100) DEFAULT NULL,
  `channel` varchar(50) DEFAULT 'card',
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'NGN',
  `gateway_response` text DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payment_id` (`payment_id`),
  CONSTRAINT `payment_transactions_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_payments_estate` (`estate_id`),
  KEY `idx_payments_zone_id` (`zone_id`),
  CONSTRAINT `fk_payments_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `pets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `flat_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `flat_id` (`flat_id`),
  KEY `fk_pets_estate` (`estate_id`),
  CONSTRAINT `fk_pets_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pets_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `property_acquisition_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `property_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `property_type` enum('street','building','flat') NOT NULL,
  `action_type` enum('Registered','Converted','Renamed','Sold','Ownership Changed','Archived','Restored') NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `change_date` datetime DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `changed_by` (`changed_by`),
  KEY `fk_property_history_estate` (`estate_id`),
  CONSTRAINT `fk_property_history_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_history_ibfk_1` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `property_owners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `fk_property_owners_estate` (`estate_id`),
  CONSTRAINT `fk_property_owners_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `property_ownership` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `property_type` enum('building','flat') NOT NULL,
  `owner_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `acquisition_type` varchar(100) DEFAULT 'Purchase',
  `registration_date` datetime DEFAULT current_timestamp(),
  `supporting_docs` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`),
  KEY `fk_property_ownership_estate` (`estate_id`),
  CONSTRAINT `fk_property_ownership_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_ownership_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `property_owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `property_ownership_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `receipt_number` varchar(50) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `issued_at` datetime DEFAULT current_timestamp(),
  `receipt_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `issued_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  KEY `payment_id` (`payment_id`),
  KEY `fk_receipts_estate` (`estate_id`),
  CONSTRAINT `fk_receipts_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `resident_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `resident_id` (`resident_id`),
  KEY `recorded_by` (`recorded_by`),
  KEY `fk_resident_history_estate` (`estate_id`),
  CONSTRAINT `fk_resident_history_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resident_history_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resident_history_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `resident_relationships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `resident_statuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `residents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `user_id` (`user_id`),
  KEY `flat_id` (`flat_id`),
  KEY `fk_residents_estate` (`estate_id`),
  CONSTRAINT `fk_residents_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `residents_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `residents_ibfk_2` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_roles_estate` (`estate_id`),
  CONSTRAINT `fk_roles_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `security_incidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`,`incident_datetime`),
  KEY `estate_id_2` (`estate_id`,`status`),
  KEY `estate_id_3` (`estate_id`,`incident_type`),
  KEY `is_flagged_bad_behavior` (`is_flagged_bad_behavior`),
  KEY `target_resident_id` (`target_resident_id`),
  KEY `reporter_id` (`reporter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `security_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `post_name` varchar(100) NOT NULL,
  `location_description` varchar(255) DEFAULT NULL,
  `phone_extension` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `security_roster` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`,`duty_date`),
  KEY `estate_id_2` (`estate_id`,`start_datetime`,`end_datetime`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `security_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `color_code` varchar(30) DEFAULT '#2563eb',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `staff_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `estate_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_staff_details_estate` (`estate_id`),
  CONSTRAINT `fk_staff_details_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_details_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `streets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','archived') DEFAULT 'active',
  `custom_id` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `estate_id` int(11) DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `fk_streets_estate` (`estate_id`),
  KEY `idx_streets_zone` (`zone_id`),
  CONSTRAINT `fk_streets_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`estate_id`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `tenancies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `resident_id` (`resident_id`),
  KEY `fk_tenancies_estate` (`estate_id`),
  CONSTRAINT `fk_tenancies_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tenancies_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `zone_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_estate` (`estate_id`),
  KEY `idx_users_zone` (`zone_id`),
  CONSTRAINT `fk_users_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ussd_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `session_id` varchar(100) NOT NULL,
  `phone_number` varchar(32) NOT NULL,
  `service_code` varchar(32) DEFAULT NULL,
  `network_code` varchar(32) DEFAULT NULL,
  `user_input` text DEFAULT NULL,
  `response_type` enum('CON','END') NOT NULL DEFAULT 'CON',
  `response_text` text NOT NULL,
  `status` enum('success','failed','timeout') NOT NULL DEFAULT 'success',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `phone_number` (`phone_number`),
  KEY `session_id` (`session_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `ussd_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) DEFAULT 1,
  `session_id` varchar(100) NOT NULL,
  `phone_number` varchar(32) NOT NULL,
  `service_code` varchar(32) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_role` varchar(32) DEFAULT NULL,
  `current_step` varchar(64) NOT NULL DEFAULT 'menu',
  `temp_data` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `phone_number` (`phone_number`),
  KEY `estate_id` (`estate_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `vehicle_gate_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `reg_number` varchar(50) NOT NULL,
  `direction` enum('entry','exit') NOT NULL,
  `gate_name` varchar(100) NOT NULL,
  `shift_name` varchar(100) DEFAULT NULL,
  `processed_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `vehicle_id` (`vehicle_id`),
  KEY `reg_number` (`reg_number`),
  KEY `logged_at` (`logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `sticker_expiry_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `flat_id` (`flat_id`),
  KEY `fk_vehicles_estate` (`estate_id`),
  CONSTRAINT `fk_vehicles_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `visitors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `exit_roster_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `flat_id` (`flat_id`),
  KEY `fk_visitors_estate` (`estate_id`),
  CONSTRAINT `fk_visitors_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visitors_ibfk_1` FOREIGN KEY (`flat_id`) REFERENCES `flats` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estate_id` (`estate_id`),
  KEY `recipient_phone` (`recipient_phone`),
  KEY `status` (`status`),
  KEY `template` (`template`),
  KEY `reference_id` (`reference_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `zonal_billing_frequencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `interval_days` int(11) NOT NULL DEFAULT 30,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `zone_id` (`zone_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `zonal_billing_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `zone_id` (`zone_id`),
  KEY `zone_id_2` (`zone_id`),
  KEY `estate_id` (`estate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `zonal_charge_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estate_id` int(11) NOT NULL DEFAULT 1,
  `zone_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `icon` varchar(50) DEFAULT 'fa-shield-halved',
  `color` varchar(20) DEFAULT '#3b82f6',
  `description` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `zone_id` (`zone_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `zones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `custom_id` (`custom_id`),
  KEY `idx_zones_estate` (`estate_id`),
  KEY `idx_zones_status` (`status`),
  KEY `idx_zones_paystack_account` (`paystack_account_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

