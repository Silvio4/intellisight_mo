-- 智眸 - 澳门数据库初始化脚本（MySQL 5.7+）
-- 警告：这是全量重建脚本，会删除现有业务表及其数据。
CREATE DATABASE IF NOT EXISTS `intellisight_mo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `intellisight_mo`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `contract_task_files`;
DROP TABLE IF EXISTS `contract_form`;
DROP TABLE IF EXISTS `contract_tasks`;
DROP TABLE IF EXISTS `contract_forms`;
DROP TABLE IF EXISTS `logs`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(80) NOT NULL COMMENT '登录用户名',
  `password` VARCHAR(255) NOT NULL COMMENT 'password_hash 密码',
  `name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '显示名称',
  `email` VARCHAR(190) NOT NULL DEFAULT '' COMMENT '邮箱',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1启用，0停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户';

CREATE TABLE `contract_forms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务号',
  `created_by` INT UNSIGNED NOT NULL COMMENT '任务创建人ID',
  `created_by_name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '创建人名字快照',
  `created_by_mail` VARCHAR(190) NOT NULL DEFAULT '' COMMENT '创建人邮箱快照',
  `sales_person` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '销售人员',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1草稿中 2待识别 3识别中 4匹配中 5待传输 6已完成',
  `attachment_original_name` VARCHAR(255) DEFAULT NULL COMMENT '上传时文件名',
  `attachment_source_file` VARCHAR(255) DEFAULT NULL COMMENT '保存的原文件名',
  `attachment_contract_quote_epo` VARCHAR(255) DEFAULT NULL COMMENT '识别用PDF文件名',
  `attachment_extension` VARCHAR(20) DEFAULT NULL,
  `attachment_file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `attachment_sha256` CHAR(64) NOT NULL DEFAULT '',
  `po_no` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'PO编号',
  `delivery_address` TEXT NULL COMMENT '送货地址',
  `no` TEXT NULL COMMENT '行号，分号分隔',
  `vendor_part_no` TEXT NULL COMMENT '供应商物料号，分号分隔',
  `description` LONGTEXT NULL COMMENT '描述，分号分隔',
  `qty` TEXT NULL COMMENT '数量，分号分隔',
  `unit_cost` TEXT NULL COMMENT '单位成本，分号分隔',
  `discount` DECIMAL(18,4) DEFAULT NULL COMMENT '折扣，可为负数',
  `raw_result` JSON NULL COMMENT '接口原始回传数据',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `submitted_recognition_at` DATETIME DEFAULT NULL COMMENT '提交识别时间',
  `recognition_started_at` DATETIME DEFAULT NULL COMMENT '识别开始时间',
  `recognition_finished_at` DATETIME DEFAULT NULL COMMENT '识别结束时间',
  `matching_started_at` DATETIME DEFAULT NULL COMMENT '匹配开始时间',
  `matching_finished_at` DATETIME DEFAULT NULL COMMENT '匹配结束时间',
  `completed_at` DATETIME DEFAULT NULL COMMENT '完成时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`), KEY `idx_contract_forms_status_id` (`status`,`id`), KEY `idx_contract_forms_created_at` (`created_at`),
  KEY `idx_contract_forms_created_by` (`created_by`), CONSTRAINT `fk_contract_forms_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合同表单及任务';

CREATE TABLE `logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operator` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '操作人',
  `task_id` BIGINT UNSIGNED NOT NULL COMMENT '任务ID',
  `operation_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
  `operation_content` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '操作内容',
  `task_status` TINYINT UNSIGNED NOT NULL COMMENT '操作后任务状态',
  PRIMARY KEY (`id`),
  KEY `idx_logs_task_time` (`task_id`,`operation_time`),
  KEY `idx_logs_operation_time` (`operation_time`),
  CONSTRAINT `fk_logs_contract_form` FOREIGN KEY (`task_id`) REFERENCES `contract_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任务操作日志';

INSERT INTO `users` (`username`,`password`,`name`,`email`,`status`)
VALUES ('admin', CONCAT('sha256:', SHA2('admin123', 256)), '系统管理员', '', 1);
