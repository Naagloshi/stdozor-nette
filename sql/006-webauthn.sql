-- Phase 4: WebAuthn credentials table (security keys + passkeys)
CREATE TABLE `user_webauthn_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `credential_id` longtext NOT NULL,
  `credential_public_key` longtext NOT NULL,
  `is_passkey` tinyint(1) NOT NULL DEFAULT 0,
  `user_handle` varchar(255) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'public-key',
  `transport` varchar(50) DEFAULT NULL,
  `transports` longtext NOT NULL DEFAULT '[]',
  `attestation_type` varchar(50) NOT NULL DEFAULT 'none',
  `trust_path` longtext NOT NULL DEFAULT '{}',
  `aaguid` varchar(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
  `counter` int(11) NOT NULL DEFAULT 0,
  `backup_eligible` tinyint(1) DEFAULT NULL,
  `backup_status` tinyint(1) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_webauthn_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
