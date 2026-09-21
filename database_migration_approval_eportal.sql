-- 审批订单及 ePortal 流程迁移（执行前请备份数据库）
USE `intellisight_mo`;

ALTER TABLE `users`
  ADD COLUMN `role` ENUM('submitter','approver','admin') NOT NULL DEFAULT 'submitter' COMMENT '系统角色' AFTER `email`;
UPDATE `users` SET `role`='admin' WHERE `username`='admin';

ALTER TABLE `contract_forms`
  MODIFY COLUMN `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1草稿中 2待识别 3识别中 4匹配中 5待建表 6待审批 7已退回 8建单中 9已完成 10待重试',
  ADD COLUMN `costing_sheet_generated_at` DATETIME NULL COMMENT 'Costing Sheet生成时间' AFTER `completed_at`,
  ADD COLUMN `approval_round` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批轮次' AFTER `costing_sheet_generated_at`,
  ADD COLUMN `submitted_approval_at` DATETIME NULL COMMENT '提交审批时间' AFTER `approval_round`,
  ADD COLUMN `approved_at` DATETIME NULL COMMENT '审批通过时间' AFTER `submitted_approval_at`,
  ADD COLUMN `approved_by` INT UNSIGNED NULL COMMENT '审批人ID' AFTER `approved_at`,
  ADD COLUMN `rejected_at` DATETIME NULL COMMENT '退回时间' AFTER `approved_by`,
  ADD COLUMN `rejected_by` INT UNSIGNED NULL COMMENT '退回人ID' AFTER `rejected_at`,
  ADD COLUMN `rejection_reason` VARCHAR(1000) NULL COMMENT '最近退回理由' AFTER `rejected_by`,
  ADD COLUMN `eportal_submitted_at` DATETIME NULL COMMENT 'ePortal最近提交时间' AFTER `rejection_reason`,
  ADD COLUMN `eportal_completed_at` DATETIME NULL COMMENT 'ePortal完成时间' AFTER `eportal_submitted_at`,
  ADD COLUMN `eportal_ticket_no` VARCHAR(190) NULL COMMENT 'ePortal单号' AFTER `eportal_completed_at`,
  ADD COLUMN `eportal_last_error` TEXT NULL COMMENT 'ePortal最近错误' AFTER `eportal_ticket_no`,
  ADD COLUMN `eportal_response` LONGTEXT NULL COMMENT 'ePortal原始响应' AFTER `eportal_last_error`,
  ADD COLUMN `eportal_request_key` VARCHAR(100) NULL COMMENT 'ePortal幂等请求标识' AFTER `eportal_response`,
  ADD KEY `idx_contract_forms_approval` (`status`,`submitted_approval_at`,`id`);

-- 原状态6代表已生成Costing Sheet，迁移后进入待审批而不是已完成。
UPDATE `contract_forms`
SET `costing_sheet_generated_at`=`completed_at`,
    `submitted_approval_at`=COALESCE(`completed_at`,`updated_at`),
    `approval_round`=1,
    `completed_at`=NULL
WHERE `status`=6;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `contract_approvals` (`task_id`,`approval_round`,`action`,`operator_id`,`operator_name`,`created_at`)
SELECT `id`,`approval_round`,'submit',`created_by`,`created_by_name`,COALESCE(`submitted_approval_at`,`updated_at`)
FROM `contract_forms` WHERE `status`=6;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
