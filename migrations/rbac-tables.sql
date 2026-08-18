-- Migration: Create RBAC tables for role-based access control
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/rbac-tables.sql
--
-- Phase 0: Schema foundation. Creates 5 new tables. Zero behavioral changes.

-- ============================================================
-- 1. ork_permission — Registry of all atomic permissions (~54)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ork_permission` (
  `permission_id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(80) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `scope_type` enum('global','kingdom','park','event','unit') NOT NULL,
  `category` varchar(40) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `uk_permission_key` (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. ork_role — Named roles (system defaults + kingdom-custom)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ork_role` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `scope_type` enum('global','kingdom','park','event','unit') NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `kingdom_id` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `modified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uk_role_name_kingdom` (`name`, `kingdom_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. ork_role_permission — Maps permissions to roles (M:N)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ork_role_permission` (
  `role_permission_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_permission_id`),
  UNIQUE KEY `uk_role_permission` (`role_id`, `permission_id`),
  KEY `idx_rp_role` (`role_id`),
  KEY `idx_rp_permission` (`permission_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. ork_user_role — Assigns roles to users at scopes
-- ============================================================
CREATE TABLE IF NOT EXISTS `ork_user_role` (
  `user_role_id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `kingdom_id` int(11) NOT NULL DEFAULT 0,
  `park_id` int(11) NOT NULL DEFAULT 0,
  `event_id` int(11) NOT NULL DEFAULT 0,
  `unit_id` int(11) NOT NULL DEFAULT 0,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_role_id`),
  UNIQUE KEY `uk_user_role_scope` (`mundane_id`, `role_id`, `kingdom_id`, `park_id`, `event_id`, `unit_id`),
  KEY `idx_ur_mundane` (`mundane_id`),
  KEY `idx_ur_role` (`role_id`),
  KEY `idx_ur_kingdom` (`kingdom_id`),
  KEY `idx_ur_park` (`park_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. ork_rbac_audit — Audit trail for all RBAC changes
-- ============================================================
CREATE TABLE IF NOT EXISTS `ork_rbac_audit` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `actor_mundane_id` int(11) NOT NULL,
  `action` enum('grant_role','revoke_role','create_role','edit_role','delete_role','add_permission','remove_permission') NOT NULL,
  `target_mundane_id` int(11) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `permission_id` int(11) DEFAULT NULL,
  `scope_kingdom_id` int(11) NOT NULL DEFAULT 0,
  `scope_park_id` int(11) NOT NULL DEFAULT 0,
  `scope_event_id` int(11) NOT NULL DEFAULT 0,
  `scope_unit_id` int(11) NOT NULL DEFAULT 0,
  `detail` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_actor` (`actor_mundane_id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_target` (`target_mundane_id`),
  KEY `idx_audit_role` (`role_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
