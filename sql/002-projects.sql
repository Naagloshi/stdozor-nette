-- STDozor Nette: Project module tables

CREATE TABLE IF NOT EXISTS `role` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `code` varchar(30) NOT NULL,
  `description` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `role` (`name`, `code`, `description`) VALUES
  ('Vlastník projektu', 'owner', 'Plný přístup k projektu, správa členů a kategorií'),
  ('Technický dozor investor (TDI)', 'supervisor', 'Přístup ke všem kategoriím, kontrolní dny, stavební deník'),
  ('Zhotovitel', 'contractor', 'Přístup k přiděleným kategoriím, zápisy do stavebního deníku'),
  ('Investor', 'investor', 'Přístup k přiděleným kategoriím, náhled rozpočtu');

CREATE TABLE IF NOT EXISTS `project` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` longtext DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'planning',
  `is_public` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'CZK',
  `owner_id` int(11) NOT NULL,
  `estimated_amount_cents` bigint(20) DEFAULT NULL,
  `total_amount_cents` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project_owner` (`owner_id`),
  CONSTRAINT `fk_project_owner` FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `project_member` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `roles` longtext NOT NULL CHECK (json_valid(`roles`)),
  `invited_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` datetime DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `invited_by_id` int(11) DEFAULT NULL,
  `has_global_category_access` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_project_member` (`user_id`, `project_id`),
  KEY `idx_pm_project` (`project_id`),
  CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`),
  CONSTRAINT `fk_pm_project` FOREIGN KEY (`project_id`) REFERENCES `project` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_invited_by` FOREIGN KEY (`invited_by_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
