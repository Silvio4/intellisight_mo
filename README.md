# 智眸 - 澳门（intellisight_mo）

这是参照旧智眸系统界面构建的 PHP + MySQL 5.7 系统雏形，部署路径为 `/intellisight_mo`。

当前版本：v0.3。此版本取消了获取任务和返回结果接口的 API Key 鉴权；对接系统可直接请求接口，不再返回因缺少 Key 导致的 HTTP 401。

完整接口字段、返回代码、PDF 下载、Python/PHP/cURL 示例和联调步骤请查看：

```text
API_INTEGRATION_GUIDE.md
```

## 已实现

- 使用系统自己的 `users` 表登录，不连接 WTMS。
- 左侧菜单为“合同表单”，包含任务列表、状态统计、查询和详情。
- 新建表单可填写 Sales Person，并上传 1–10 份文件。
- 支持 PDF、常见图片、Word、Excel、PowerPoint、RTF、TXT、CSV。
- 上传文件保存在 `files/任务号/`；原文件以 `source_` 开头，识别用 PDF 以 `document_` 开头。
- 任务号直接使用数据库自增序号，并统一格式化为 `T` 加至少 6 位数字，例如 `T000001`、`T000002`。
- 提交成功后生成“待识别”任务。
- `api/get_task.php`：派发任务，并保证全系统同时最多只有一条“识别中”任务。
- `api/download_file.php`：供识别端下载派发任务下的 PDF。
- `api/returndata.php`：接收识别结果，保存至 `contract_form`，任务转为“已完成”。
- 详情页按分号位置把 `no/vendor_part_no/description/qty/unit_cost` 对齐为明细表。

## 1. 环境要求

- PHP 7.1 或更高版本（已兼容 PHP 7.1–7.3，不使用 PHP 7.4 类型化类属性）。
- MySQL 5.7 或更高版本。
- PHP 扩展：`pdo_mysql`、`fileinfo`；建议启用 `mbstring`。
- PHP 必须允许 `file_uploads`。
- Word/Excel/PPT 等转 PDF：安装 LibreOffice，并允许 PHP 使用 `exec()`。
- 图片转 PDF：优先使用 PHP Imagick 或 ImageMagick；若没有，会继续尝试 LibreOffice。

推荐 `php.ini`：

```ini
file_uploads = On
upload_max_filesize = 30M
post_max_size = 310M
max_file_uploads = 10
max_execution_time = 300
```

修改后重启 php-fpm、Apache 或 phpstudy。

## 2. 部署

1. 将整个 `intellisight_mo` 文件夹放到站点目录，使访问地址为：

   `https://你的域名/intellisight_mo/`

2. 在 MySQL 执行 `database.sql`。

3. 修改 `config/config.php`：

   - `db`：数据库地址、库名、账号和密码。
   - `app.base_path`：保持 `/intellisight_mo`；若反向代理路径不同则同步修改。
   - 如果 LibreOffice 安装位置不同，把实际 `soffice.exe` 路径加入 `libreoffice_paths`。

4. 确保 Web 服务账号对 `files/` 和 `logs/` 有写入权限。

5. 初始登录账号：

   - 用户名：`admin`
   - 密码：`admin123`

   首次登录成功后，程序会自动将初始密码值升级为 PHP `password_hash`。

## 3. Nginx 文件保护

Apache 已通过 `files/.htaccess` 禁止直接访问。若使用 Nginx，请在对应 `server` 中增加：

```nginx
location ^~ /intellisight_mo/files/ {
    deny all;
    return 403;
}

location ^~ /intellisight_mo/logs/ {
    deny all;
    return 403;
}
```

PDF 仍可通过有凭证的 `api/download_file.php` 下载。

## 4. 任务获取接口

地址：

```text
GET /intellisight_mo/api/get_task.php
```

获取任务接口不需要登录 Cookie，也不需要 API Key：

```text
http://服务器地址/intellisight_mo/api/get_task.php
```

逻辑：

1. 若已有“识别中”任务，返回该任务，`code=recognition_in_progress`，不派发新任务。
2. 若没有“识别中”任务但有“待识别”任务，领取最早一条并改为“识别中”，`code=task_dispatched`。
3. 若没有待识别任务，返回 `code=no_pending_task` 和 `task=null`。

任务内的每个 PDF 都包含 `download_url`。识别系统直接请求该 URL 即可下载；URL 自带本次任务的随机领取令牌。

默认不把 PDF 放入 JSON，以免大文件导致内存问题。若对接端必须一次获得 Base64，可调用：

```text
/intellisight_mo/api/get_task.php?include_base64=1
```

成功派发示例：

```json
{
  "success": true,
  "code": "task_dispatched",
  "message": "已派发一条待识别任务，并更新为识别中。",
  "dispatched": true,
  "task": {
    "task_no": "T000001",
    "status": "recognizing",
    "sales_person": "Silvio",
    "pdf_count": 2,
    "files": [
      {
        "file_id": 1,
        "original_name": "PO.pdf",
        "pdf_name": "document_01_PO.pdf",
        "download_url": "https://example.com/intellisight_mo/api/download_file.php?..."
      }
    ]
  }
}
```

## 5. 识别结果回传接口

地址：

```text
POST /intellisight_mo/api/returndata.php
Content-Type: application/json
```

返回结果接口同样不需要登录 Cookie或 API Key。

请求示例：

```json
{
  "task_no": "T000001",
  "po_no": "PO-2026-001",
  "delivery_address": "Macau ...",
  "no": "1;2",
  "vendor_part_no": "PN-001;PN-002",
  "description": "Notebook;Docking Station",
  "qty": "10;10",
  "unit_cost": "12938.94;1888.00",
  "discount": -100
}
```

注意：

- `no`、`vendor_part_no`、`description`、`qty`、`unit_cost` 用分号分隔。
- 相同位置表示同一行，例如五个字段的第一个值属于第一条明细。
- 为兼容部分识别端，接口也接受 `unit_price`，但数据库统一保存到 `unit_cost`。
- `discount` 可为正数、0、负数或 `null`。
- 只有“识别中”或已经“已完成”的任务允许回传；重复回传同一任务会更新已有结果，不会新增重复记录。

## 6. 目录说明

```text
intellisight_mo/
├─ api/                       对接接口
├─ assets/                    样式和脚本
├─ config/config.php          系统配置
├─ includes/                  公共逻辑与文件转换
├─ files/任务号/              原文件和识别用 PDF
├─ logs/                      运行日志
├─ contract_forms.php         任务列表
├─ contract_form_create.php   新建任务
├─ contract_form_detail.php   任务详情
└─ database.sql               数据库初始化脚本
```

每次请求都会写入开始、数据库连接、业务阶段、响应及结束日志。API 请求统一写入
`logs/api_YYYY-MM-DD.log`，并通过 `X-Request-ID` 响应头返回关联编号，便于串联同一次请求的各环节；
其他页面请求写入 `logs/app_YYYY-MM-DD.log`。日志不会记录下载令牌、文件 Base64 或完整业务请求体。

## 7. 当前雏形的边界

- 当前同时只允许一个全局“识别中”任务，完全按本次需求实现。
- 当前没有自动超时重置。若识别端领取后永久中断，需要管理员在数据库确认后手动把任务改回 `pending`。
- 当前没有用户管理页面；新用户可先通过数据库新增，密码建议用 PHP `password_hash()` 生成。
