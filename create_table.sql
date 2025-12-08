CREATE TABLE `participants_routes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `experiment_id` varchar(255) NOT NULL,
  `browser_id` varchar(255) NOT NULL,
  `condition_group` varchar(255) NOT NULL,
  `current_step_index` int(11) NOT NULL DEFAULT '0',
  `status` varchar(255) NOT NULL DEFAULT 'assigned',
  `last_heartbeat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participants_browser_id_unique` (`browser_id`),
  KEY `participants_experiment_id_index` (`experiment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;