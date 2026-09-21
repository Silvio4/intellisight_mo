# 智眸 - 澳门（intellisight_mo）

PHP + MySQL 5.7 合同识别系统，部署路径默认为 `/intellisight_mo`。

## 数据结构

`database.sql` 是破坏性全量重建脚本，仅保留三张表：

- `users`：登录用户；
- `contract_forms`：任务、Sales Person、附件、识别结果、UPC 匹配结果及所有流程时间。数据库使用自增 `id`，界面显示为 `T` + 6 位数字；
- `logs`：任务操作记录，包含操作人、任务 ID、操作时间、操作内容和操作后状态。

### 任务状态与流转条件

任务状态为：`1` 草稿中、`2` 待识别、`3` 识别中、`4` 匹配中、`5` 待建表、`6` 待审批、`7` 已退回、`8` 建单中、`9` 已完成、`10` 待重试。完整的状态流转、页面权限、失败重试策略以及 ePortal 全字段映射见 [审批订单与 ePortal 建单设计](docs/approval-eportal-design.md)。

> `contract_forms.status` 是任务状态。`users.status` 是独立的账号状态：`1` 表示启用、`0` 表示停用；它不参与任务流转。

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

无任务时返回 `success`、`has_task=false`、`message`；成功时顶层返回数值型 `task_id`，`task` 中也保留 `task_id`，并包含 `status`、`status_text`、`created_at`、`created_by_mail`、`contract_quote_epo_file` 和 `contract_quote_epo_url`。调用方应在读取 `task_id` 前判断 `has_task`。系统同时最多派发一条状态 `3` 的任务。

### 回传识别结果

`POST /api/returndata.php` 使用数值型 `task_id` 标识任务，支持 11 个识别字段：`po_no`、`customer_name`、`customer_delivery_address`、`end_user_name`、`end_user_contact`、`end_user_email`、`vendor_part_no`、`description`、`qty`、`price_currency`、`unit_price`。后五项是数量一致、按位置对应的英文分号分隔多值字段。保存后立即以相同格式请求 P 系统，请求成功后状态更新为 `4`（匹配中）。

### P 系统

- 识别结果保存后，系统自动向 `POST http://10.106.4.46:12332/api/tasks` 推送任务；P 系统确认接收后任务更新为 `4`（匹配中）。端口可用 `P_SYS_PORT` 覆盖，完整地址可用 `P_SYS_ENDPOINT` 覆盖。
- `POST /api/submit_p_sys.php`：按 `task_id` 手动重试向 P 系统推送状态仍为 `3` 的任务。
- `POST /api/p_sys_back.php`：接收 P 系统以 `data` 包裹的原任务字段、`pid` 和 `p_sys_link`；保存匹配结果后会立即生成 Costing Sheet，成功时任务更新为 `6`（待审批）。
- `POST /api/costing_sheet.php`：接收 `task_id`，可对状态 `5` 的任务重试生成 Costing Sheet。生成前需将模板放在 `template/costing_sheet_v1.xlsx`，输出保存于 `files/costing_sheet/costing_sheet_Txxxxxx.xlsx`，并会显示在任务详情的“任务文件”中。

已有数据库应先执行 `database_migration_p_system.sql`（如尚未执行），再执行 `database_migration_approval_eportal.sql`；全新安装直接使用 `database.sql`。

完整格式参见 `智眸任务获取和返回数据接口.md` 与 `智眸P系统返回接口.md`。

每个接口独立写入 `api/logs/<接口名>_YYYY-MM-DD.log`；网页日志写入根目录 `logs/`。

`api/download_file.php` 根据任务 `id` 从受保护的存储目录读取识别用 PDF，并以 `application/pdf` 二进制响应返回给识别端。`get_task.php` 返回的 `contract_quote_epo_url` 会指向该接口。
