-- Existing installations: run once before enabling the P-system callbacks.
USE `intellisight_mo`;
ALTER TABLE `contract_forms`
  ADD COLUMN `upc_code` TEXT NULL COMMENT 'P系统匹配UPC码，分号分隔' AFTER `discount`;
