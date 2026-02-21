-- STDozor Nette: Category module tables

CREATE TABLE IF NOT EXISTS `category` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` longtext DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `status` varchar(255) NOT NULL DEFAULT 'planned',
  `estimated_amount` decimal(10,2) DEFAULT NULL,
  `actual_amount` decimal(10,2) DEFAULT NULL,
  `manual_amount_override` tinyint(4) NOT NULL DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `estimated_completion_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `project_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_project_parent` (`project_id`, `parent_id`),
  CONSTRAINT `fk_category_project` FOREIGN KEY (`project_id`) REFERENCES `project` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_category_parent` FOREIGN KEY (`parent_id`) REFERENCES `category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `category_permission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `can_view` tinyint(4) NOT NULL DEFAULT 1,
  `can_edit` tinyint(4) NOT NULL DEFAULT 0,
  `can_delete` tinyint(4) NOT NULL DEFAULT 0,
  `granted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `project_member_id` int(11) NOT NULL,
  `granted_by_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_member_category` (`project_member_id`, `category_id`),
  KEY `idx_cp_category` (`category_id`),
  CONSTRAINT `fk_cp_category` FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_member` FOREIGN KEY (`project_member_id`) REFERENCES `project_member` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_granted_by` FOREIGN KEY (`granted_by_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
