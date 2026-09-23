-- ePortal 申请人及审批链用户资料迁移（执行前请备份数据库）
USE `intellisight_mo`;

ALTER TABLE `users`
  ADD COLUMN `buyer_mail` VARCHAR(190) NOT NULL DEFAULT 'yu.y.zhang@jos.com' COMMENT 'ePortal采购人邮箱' AFTER `email`,
  ADD COLUMN `buyer` VARCHAR(120) NOT NULL DEFAULT 'Yu y Zhang' COMMENT 'ePortal采购人' AFTER `buyer_mail`,
  ADD COLUMN `buyer_boss` VARCHAR(120) NOT NULL DEFAULT 'Candice Wu' COMMENT 'ePortal采购主管' AFTER `buyer`,
  ADD COLUMN `buyer_boss_mail` VARCHAR(190) NOT NULL DEFAULT 'candice.wu@jos.com' COMMENT 'ePortal采购主管邮箱' AFTER `buyer_boss`,
  ADD COLUMN `ratifier` VARCHAR(120) NOT NULL DEFAULT 'Joan Liu' COMMENT 'ePortal批准人' AFTER `buyer_boss_mail`,
  ADD COLUMN `ratifier_mail` VARCHAR(190) NOT NULL DEFAULT 'joan.liu@jos.com' COMMENT 'ePortal批准人邮箱' AFTER `ratifier`;
