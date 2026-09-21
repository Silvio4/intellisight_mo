-- 现有安装升级脚本：执行前请备份数据库。本脚本仅新增本次接口所需字段。
USE `intellisight_mo`;
ALTER TABLE `contract_forms`
  ADD COLUMN `customer_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '客户名称' AFTER `po_no`,
  ADD COLUMN `customer_delivery_address` TEXT NULL COMMENT '客户送货地址' AFTER `customer_name`,
  ADD COLUMN `end_user_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最终用户名称' AFTER `customer_delivery_address`,
  ADD COLUMN `end_user_contact` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最终用户联系人' AFTER `end_user_name`,
  ADD COLUMN `end_user_email` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最终用户邮箱' AFTER `end_user_contact`,
  ADD COLUMN `price_currency` TEXT NULL COMMENT '价格币种，分号分隔' AFTER `qty`,
  ADD COLUMN `unit_price` TEXT NULL COMMENT '单价，分号分隔' AFTER `price_currency`,
  ADD COLUMN `pid` TEXT NULL COMMENT 'P系统物料ID，分号分隔' AFTER `unit_price`,
  ADD COLUMN `p_sys_link` TEXT NULL COMMENT 'P系统返回链接' AFTER `pid`;

-- 状态 5 在本流程中表示 P 系统已成功回传，等待创建 costing sheet。
ALTER TABLE `contract_forms`
  MODIFY COLUMN `status` TINYINT UNSIGNED NOT NULL DEFAULT 1
  COMMENT '1草稿中 2待识别 3识别中 4匹配中 5待建表 6已建表';
