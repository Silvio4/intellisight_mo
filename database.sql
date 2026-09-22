-- 智眸 - 澳门数据库初始化脚本（MySQL 5.7+）
-- 警告：这是全量重建脚本，会删除现有业务表及其数据。
CREATE DATABASE IF NOT EXISTS `intellisight_mo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `intellisight_mo`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `contract_task_files`;
DROP TABLE IF EXISTS `dn_task_pool`;
DROP TABLE IF EXISTS `contract_form_files`;
DROP TABLE IF EXISTS `contract_approvals`;
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
  `role` ENUM('submitter','approver','admin') NOT NULL DEFAULT 'submitter' COMMENT '系统角色',
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
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1草稿中 2待识别 3识别中 4匹配中 5待建表 6待审批 7已退回 8建单中 9已完成 10待重试',
  `attachment_original_name` VARCHAR(255) DEFAULT NULL COMMENT '上传时文件名',
  `attachment_source_file` VARCHAR(255) DEFAULT NULL COMMENT '保存的原文件名',
  `attachment_contract_quote_epo` VARCHAR(255) DEFAULT NULL COMMENT '识别用PDF文件名',
  `attachment_extension` VARCHAR(20) DEFAULT NULL,
  `attachment_file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `attachment_sha256` CHAR(64) NOT NULL DEFAULT '',
  `po_no` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'PO编号',
  `customer_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '客户名称',
  `customer_delivery_address` TEXT NULL COMMENT '客户送货地址',
  `end_user_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最终用户名称',
  `end_user_contact` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最终用户联系人',
  `end_user_email` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最终用户邮箱',
  `vendor_part_no` TEXT NULL COMMENT '供应商物料号，分号分隔',
  `description` LONGTEXT NULL COMMENT '描述，分号分隔',
  `qty` TEXT NULL COMMENT '数量，分号分隔',
  `price_currency` TEXT NULL COMMENT '价格币种，分号分隔',
  `unit_price` TEXT NULL COMMENT '单价，分号分隔',
  `pid` TEXT NULL COMMENT 'P系统物料ID，分号分隔',
  `p_sys_link` TEXT NULL COMMENT 'P系统返回链接',
  `raw_result` JSON NULL COMMENT '接口原始回传数据',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `submitted_recognition_at` DATETIME DEFAULT NULL COMMENT '提交识别时间',
  `recognition_started_at` DATETIME DEFAULT NULL COMMENT '识别开始时间',
  `recognition_finished_at` DATETIME DEFAULT NULL COMMENT '识别结束时间',
  `matching_started_at` DATETIME DEFAULT NULL COMMENT '匹配开始时间',
  `matching_finished_at` DATETIME DEFAULT NULL COMMENT '匹配结束时间',
  `completed_at` DATETIME DEFAULT NULL COMMENT '完成时间',
  `costing_sheet_generated_at` DATETIME DEFAULT NULL COMMENT 'Costing Sheet生成时间',
  `approval_round` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批轮次',
  `submitted_approval_at` DATETIME DEFAULT NULL COMMENT '提交审批时间',
  `approved_at` DATETIME DEFAULT NULL COMMENT '审批通过时间',
  `approved_by` INT UNSIGNED DEFAULT NULL COMMENT '审批人ID',
  `rejected_at` DATETIME DEFAULT NULL COMMENT '退回时间',
  `rejected_by` INT UNSIGNED DEFAULT NULL COMMENT '退回人ID',
  `rejection_reason` VARCHAR(1000) DEFAULT NULL COMMENT '最近退回理由',
  `eportal_submitted_at` DATETIME DEFAULT NULL COMMENT 'ePortal最近提交时间',
  `eportal_completed_at` DATETIME DEFAULT NULL COMMENT 'ePortal完成时间',
  `eportal_ticket_no` VARCHAR(190) DEFAULT NULL COMMENT 'ePortal单号',
  `eportal_last_error` TEXT NULL COMMENT 'ePortal最近错误',
  `eportal_response` LONGTEXT NULL COMMENT 'ePortal原始响应',
  `eportal_request_key` VARCHAR(100) DEFAULT NULL COMMENT 'ePortal幂等请求标识',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`), KEY `idx_contract_forms_status_id` (`status`,`id`), KEY `idx_contract_forms_approval` (`status`,`submitted_approval_at`,`id`), KEY `idx_contract_forms_created_at` (`created_at`),
  KEY `idx_contract_forms_created_by` (`created_by`), CONSTRAINT `fk_contract_forms_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合同表单及任务';

CREATE TABLE `contract_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `approval_round` INT UNSIGNED NOT NULL,
  `action` ENUM('submit','approve','reject','eportal_retry') NOT NULL,
  `operator_id` INT UNSIGNED NOT NULL,
  `operator_name` VARCHAR(120) NOT NULL DEFAULT '',
  `reason` VARCHAR(1000) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_approvals_task` (`task_id`,`approval_round`,`id`),
  CONSTRAINT `fk_approvals_task` FOREIGN KEY (`task_id`) REFERENCES `contract_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单审批历史';

CREATE TABLE `contract_form_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` BIGINT UNSIGNED NOT NULL,
  `approval_round` INT UNSIGNED NOT NULL DEFAULT 0,
  `category` ENUM('supplement') NOT NULL DEFAULT 'supplement',
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_form_files_task` (`task_id`,`id`),
  CONSTRAINT `fk_form_files_task` FOREIGN KEY (`task_id`) REFERENCES `contract_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='合同补充附件';

CREATE TABLE `dn_task_pool` (
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

INSERT INTO `users` (`username`,`password`,`name`,`email`,`role`,`status`)
VALUES ('admin', CONCAT('sha256:', SHA2('admin123', 256)), '系统管理员', '', 'admin', 1);
