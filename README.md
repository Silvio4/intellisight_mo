# 智眸 - 澳门（intellisight_mo）

PHP + MySQL 5.7 合同识别系统，部署路径默认为 `/intellisight_mo`。

## 数据结构

`database.sql` 是破坏性全量重建脚本，仅保留三张表：

- `users`：登录用户；
- `contract_forms`：任务、Sales Person、附件、识别结果及所有流程时间。自增 `id` 直接作为任务号；
- `logs`：任务操作记录，包含操作人、任务 ID、操作时间、操作内容和操作后状态。

任务状态为：`1` 草稿中、`2` 待识别、`3` 识别中、`4` 匹配中、`5` 待传输、`6` 已完成。
执行脚本前请备份旧数据；脚本会删除旧版 `contract_tasks`、`contract_task_files` 和 `contract_form`。

## 部署

1. 使用 MySQL 5.7+ 执行 `database.sql`。
2. 修改 `config/config.php` 中的数据库连接与 `app.base_path`。
3. 确保 Web 服务账号可写 `files/contract_forms/`、`logs/` 和 `api/logs/`。
4. 初始账号为 `admin` / `admin123`，首次登录会自动升级密码散列。

应用需要 PHP 7.1+、`pdo_mysql` 和 `fileinfo`。Office 转 PDF 需要 LibreOffice；图片转换可使用 Imagick、ImageMagick 或 LibreOffice。

## 接口

### 获取任务

`GET|POST /api/get_task.php`

响应与智眸既有系统保持一致：无任务时返回 `success`、`has_task=false`、`message`；成功时 `task` 包含 `id`、数值 `status`、`status_text`、`created_at`、`created_by_mail`、`contract_quote_epo_file` 和 `contract_quote_epo_url`。系统同时最多派发一条状态 `3` 的任务。

### 回传识别结果

`POST /api/returndata.php`，JSON 请求使用 `id`（兼容 `task_id` / `task_no`）标识任务，并可包含：`po_no`、`delivery_address`、`no`、`vendor_part_no`、`description`、`qty`、`unit_cost`、`discount`。明细字段使用英文分号分隔。保存后任务更新为状态 `6`。

每个接口独立写入 `api/logs/<接口名>_YYYY-MM-DD.log`；网页日志写入根目录 `logs/`。

`api/download_file.php` 根据任务 `id` 从受保护的存储目录读取识别用 PDF，并以 `application/pdf` 二进制响应返回给识别端。`get_task.php` 返回的 `contract_quote_epo_url` 会指向该接口。
