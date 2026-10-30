--
-- Table structure for table `transactions`
--
-- DB-backed transaction log written by avisitor/db-transaction-log.
-- One row per recorded DB operation, including failed ones (status/error).
-- Retention is enforced by DbTransactionLog\TransactionLogger::deleteOlderThan()
-- (configurable, default 1 year) — keep the `ts` index for that delete.
--

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ts` datetime NOT NULL,
  `username` varchar(64) DEFAULT NULL,
  `class` varchar(64) NOT NULL DEFAULT '',
  `operation` varchar(64) NOT NULL DEFAULT '',
  `email` varchar(128) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'ok',
  `error` text DEFAULT NULL,
  `params` mediumtext DEFAULT NULL,
  `request` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ts` (`ts`),
  KEY `idx_operation` (`operation`),
  KEY `idx_class` (`class`),
  KEY `idx_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
