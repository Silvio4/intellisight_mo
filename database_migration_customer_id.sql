-- 已有数据库增加 Customer ID（MySQL 5.7+）
USE `intellisight_mo`;

ALTER TABLE `contract_forms`
  ADD COLUMN `customer_id` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '客户ID' AFTER `po_no`;
