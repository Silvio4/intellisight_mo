-- 已有数据库增加 DN 识别任务功能（MySQL 5.7+）
CREATE TABLE IF NOT EXISTS `dn_task_pool` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'DN识别任务ID',
  `order_task_id` BIGINT UNSIGNED NOT NULL COMMENT '对应订单任务ID',
  `sequence_no` INT UNSIGNED NOT NULL COMMENT '同一订单的DN序号',
  `upload_file_name` VARCHAR(255) NOT NULL COMMENT '标准化后的上传PDF文件名',
  `original_file_name` VARCHAR(255) NOT NULL COMMENT '用户上传时的文件名',
  `done_file_name` VARCHAR(255) DEFAULT NULL COMMENT '识别完成后重新生成的DN文件名',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1待识别 2识别中 3已完成',
  `uploaded_by` INT UNSIGNED NOT NULL COMMENT '上传用户ID',
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '上传时间',
  `recognition_started_at` DATETIME DEFAULT NULL COMMENT '识别开始时间',
  `recognition_finished_at` DATETIME DEFAULT NULL COMMENT '识别完成时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dn_order_sequence` (`order_task_id`,`sequence_no`),
  KEY `idx_dn_status_id` (`status`,`id`),
  CONSTRAINT `fk_dn_order_task` FOREIGN KEY (`order_task_id`) REFERENCES `contract_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dn_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DN识别任务池';
