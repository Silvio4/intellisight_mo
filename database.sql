-- 智眸 - 澳门 数据库初始化脚本（MySQL 5.7+）
CREATE DATABASE IF NOT EXISTS `intellisight_mo`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `intellisight_mo`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(80) NOT NULL COMMENT '登录用户名',
  `password` VARCHAR(255) NOT NULL COMMENT 'password_hash 密码',
  `name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '显示名称',
  `email` VARCHAR(190) NOT NULL DEFAULT '' COMMENT '邮箱',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用，0停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='本地登录用户';

CREATE TABLE IF NOT EXISTS `contract_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_no` VARCHAR(32) DEFAULT NULL COMMENT '任务号',
  `sales_person` VARCHAR(120) NOT NULL COMMENT '销售人员',
  `status` ENUM('pending','recognizing','completed','failed') NOT NULL DEFAULT 'pending' COMMENT '任务状态',
  `created_by` INT UNSIGNED NOT NULL COMMENT '创建用户ID',
  `claim_token` VARCHAR(96) DEFAULT NULL COMMENT '本次识别下载令牌',
  `claimed_at` DATETIME DEFAULT NULL COMMENT '领取/开始识别时间',
  `completed_at` DATETIME DEFAULT NULL COMMENT '识别完成时间',
  `error_message` TEXT NULL COMMENT '错误信息',
  `raw_result` JSON NULL COMMENT '接口原始回传数据',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contract_tasks_task_no` (`task_no`),
  KEY `idx_contract_tasks_status_created` (`status`, `created_at`),
  KEY `idx_contract_tasks_created_by` (`created_by`),
  CONSTRAINT `fk_contract_tasks_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合同识别任务';

CREATE TABLE IF NOT EXISTS `contract_task_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL COMMENT '用户上传文件名',
  `stored_name` VARCHAR(255) NOT NULL COMMENT '保存的原文件名',
  `pdf_name` VARCHAR(255) NOT NULL COMMENT '转换后的PDF文件名',
  `extension` VARCHAR(20) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL DEFAULT '',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NOT NULL DEFAULT '',
  `conversion_status` ENUM('success','failed') NOT NULL DEFAULT 'success',
  `conversion_method` VARCHAR(40) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contract_task_files_task` (`task_id`),
  CONSTRAINT `fk_contract_task_files_task` FOREIGN KEY (`task_id`) REFERENCES `contract_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务上传文件';

CREATE TABLE IF NOT EXISTS `contract_form` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `po_no` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'PO编号',
  `delivery_address` TEXT NULL COMMENT '送货地址',
  `no` TEXT NULL COMMENT '行号，分号分隔',
  `vendor_part_no` TEXT NULL COMMENT '供应商物料号，分号分隔',
  `description` LONGTEXT NULL COMMENT '描述，分号分隔',
  `qty` TEXT NULL COMMENT '数量，分号分隔',
  `unit_cost` TEXT NULL COMMENT '单位成本，分号分隔',
  `discount` DECIMAL(18,4) DEFAULT NULL COMMENT '折扣，可为负数',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contract_form_task` (`task_id`),
  CONSTRAINT `fk_contract_form_task` FOREIGN KEY (`task_id`) REFERENCES `contract_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合同结构化识别结果';

-- 初始账号：admin / admin123
-- 首次成功登录后，程序会自动把下面的 SHA-256 初始值升级为 password_hash。
INSERT INTO `users` (`username`, `password`, `name`, `email`, `status`)
SELECT 'admin', CONCAT('sha256:', SHA2('admin123', 256)), '系统管理员', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');
