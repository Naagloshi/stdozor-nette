-- STDozor Nette: Project invitation table

CREATE TABLE IF NOT EXISTS `project_invitation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(180) NOT NULL,
  `roles` longtext NOT NULL CHECK (json_valid(`roles`)),
  `token` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `used` tinyint(4) NOT NULL DEFAULT 0,
  `project_id` int(11) NOT NULL,
  `invited_by_id` int(11) DEFAULT NULL,
  `project_member_id` int(11) DEFAULT NULL,
  `category_ids` longtext NOT NULL DEFAULT '[]' CHECK (json_valid(`category_ids`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invitation_token` (`token`),
  KEY `idx_invitation_email_project` (`email`, `project_id`),
  CONSTRAINT `fk_invitation_project` FOREIGN KEY (`project_id`) REFERENCES `project` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invitation_member` FOREIGN KEY (`project_member_id`) REFERENCES `project_member` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invitation_invited_by` FOREIGN KEY (`invited_by_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
