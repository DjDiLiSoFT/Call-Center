-- CallStats own schema (MariaDB 5.5 compatible). Idempotent.

CREATE TABLE IF NOT EXISTS `qlog_events` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ts` INT NOT NULL,
  `callid` VARCHAR(40) NOT NULL DEFAULT '',
  `queue` VARCHAR(40) NOT NULL DEFAULT '',
  `agent` VARCHAR(80) NOT NULL DEFAULT '',
  `event` VARCHAR(32) NOT NULL DEFAULT '',
  `data` VARCHAR(255) NOT NULL DEFAULT '',
  `raw` VARCHAR(512) NOT NULL DEFAULT '',
  `line_hash` CHAR(40) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `line_hash` (`line_hash`),
  KEY `callid` (`callid`),
  KEY `ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `calls` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `call_key` VARCHAR(40) NOT NULL,
  `kind` ENUM('queue','direct','outbound','internal') NOT NULL,
  `queue` VARCHAR(40) NULL,
  `started_at` DATETIME NOT NULL,
  `answered_at` DATETIME NULL,
  `ended_at` DATETIME NOT NULL,
  `client_number` VARCHAR(80) NOT NULL DEFAULT '',
  `answered_by` VARCHAR(80) NULL,
  `wait_sec` INT NOT NULL DEFAULT 0,
  `talk_sec` INT NOT NULL DEFAULT 0,
  `recording_file` VARCHAR(255) NULL,
  `approx` TINYINT NOT NULL DEFAULT 0,
  `sources` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `call_key` (`call_key`),
  KEY `started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `call_agents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `call_id` INT NOT NULL,
  `extension` VARCHAR(80) NOT NULL,
  `direction` ENUM('in','out') NOT NULL,
  `outcome` ENUM('answered','missed','other_answered','caller_hangup','offline','not_connected') NOT NULL,
  `ring_sec` INT NOT NULL DEFAULT 0,
  `talk_sec` INT NOT NULL DEFAULT 0,
  `first_ring_at` DATETIME NOT NULL,
  `answered_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `extension` (`extension`, `first_ring_at`),
  KEY `call_id` (`call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `transfers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `call_id` INT NOT NULL,
  `from_ext` VARCHAR(80) NOT NULL,
  `to_ext` VARCHAR(80) NOT NULL,
  `at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `call_id` (`call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `agent_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `extension` VARCHAR(80) NOT NULL,
  `queue` VARCHAR(40) NOT NULL,
  `start_at` DATETIME NOT NULL,
  `end_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `extension` (`extension`, `start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `agent_pauses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `extension` VARCHAR(80) NOT NULL,
  `queue` VARCHAR(40) NOT NULL,
  `start_at` DATETIME NOT NULL,
  `end_at` DATETIME NULL,
  `reason` VARCHAR(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `extension` (`extension`, `start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `operators` (
  `extension` VARCHAR(80) NOT NULL,
  `fpbx_name` VARCHAR(80) NOT NULL DEFAULT '',
  `custom_name` VARCHAR(80) NULL,
  PRIMARY KEY (`extension`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `queues` (
  `queue` VARCHAR(40) NOT NULL,
  `name` VARCHAR(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `collector_state` (
  `k` VARCHAR(64) NOT NULL,
  `v` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
