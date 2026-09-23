# 审批订单与 ePortal 建单设计

## 1. 目标与边界

Costing Sheet 生成成功后不再直接视为业务完成，而是进入人工审批。审批人可查看待审订单并同意或退回；同意后系统向 ePortal 建单，退回后创建人可修改资料、补充附件并重新提交。

本文件同时记录已实现的审批与 ePortal 流程及其对接约定。已有数据库必须先执行 `database_migration_approval_eportal.sql`，否则新页面所需的角色、审批记录和附件字段不存在。

## 2. 建议的完整状态模型

所有面向用户显示的状态名称统一为三个汉字。不要复用旧状态值或改变旧值含义；保留 `1`～`5`，将原 `6 已建表` 更名为 `6 待审批`，新增 `7`～`10`：

| 值 | 名称 | 进入条件 | 可执行动作 / 下一状态 |
| --- | --- | --- | --- |
| `1` | 草稿中 | 创建任务的数据库事务内暂存。 | 附件保存、转 PDF 成功 → `2`；失败则事务回滚。 |
| `2` | 待识别 | 合同文件准备完成。 | 识别服务领取 → `3`。 |
| `3` | 识别中 | 识别服务已领取。 | 识别结果保存且 P 系统确认接收 → `4`；P 系统失败则保持 `3` 并重试。 |
| `4` | 匹配中 | P 系统已接收识别数据。 | P 系统回传并通过字段校验 → `5`。 |
| `5` | 待建表 | P 系统匹配结果已保存，或退回单修改后需要重建 Costing Sheet。 | Costing Sheet 生成成功 → `6`；失败保持 `5`。 |
| `6` | 待审批 | Costing Sheet 已生成，或退回单修改并重新生成成功。 | 审批同意 → `8`；审批退回（必须填写理由）→ `7`。 |
| `7` | 已退回 | 审批人退回。 | 创建人修改字段/补充附件并“重新提交” → `5`，系统重建 Costing Sheet，成功后 → `6`。 |
| `8` | 建单中 | 审批同意或对失败单发起重试；用于防止重复点击和并发建单。 | ePortal 明确成功 → `9`；网络、HTTP 或业务失败 → `10`。 |
| `9` | 已完成 | ePortal 明确返回建单成功，且本地保存 ePortal 单号/响应。 | 终态。 |
| `10` | 待重试 | ePortal 请求未获得明确成功结果，等待人工确认。 | 有审批权限的用户检查错误后重试 → `8`；不得自动重复建单。 |

主流程：

```text
1 草稿中 → 2 待识别 → 3 识别中 → 4 匹配中 → 5 待建表 → 6 待审批 → 8 建单中 → 9 已完成
                                           ↑          ↓             ↕（人工重试）
                                           └──── 7 已退回        10 待重试
```

> `8` 是必要的并发锁状态；`10` 与 `7` 必须分开。`7` 表示业务资料被审批人否决，需要创建人修改；`10` 表示审批已经通过，只是外部建单失败，不应要求创建人改资料。

### 完整用户状态流程

#### 提交用户

1. 用户上传合同并提交；页面短暂经过 `草稿中`，文件处理成功后显示 `待识别`。
2. 识别服务领取后显示 `识别中`；识别结果成功推送给 P 系统后显示 `匹配中`。
3. P 系统返回匹配资料后显示 `待建表`；Costing Sheet 生成成功后自动显示 `待审批`，用户无需再次手工提交。
4. 审批人同意后，用户依次看到 `建单中`、`已完成`；只有 `已完成` 才表示 ePortal 已经成功创建订单。
5. 审批人退回后显示 `已退回`。该订单在提交用户列表中红色高亮并置顶，同时展示退回理由；用户可以修改开放字段并补充附件。
6. 用户点击“重新提交”后，任务先进入 `待建表` 以重新生成 Costing Sheet，成功后重新进入 `待审批`。每次退回和重新提交都增加审批轮次并保留历史记录。
7. 如果 ePortal 调用失败，状态显示 `待重试`。这表示资料已经审批通过，无需提交用户修改；应由审批人或管理员处理。

提交用户看到的正常路径：

```text
草稿中 → 待识别 → 识别中 → 匹配中 → 待建表 → 待审批 → 建单中 → 已完成
                                  ↑          ↓
                                  └──── 已退回（修改后重新生成表格）
```

#### 审批用户

1. “审批订单”菜单只把 `待审批` 纳入待办数量，按最早提交时间优先显示。
2. 审批用户查看合同、识别结果、产品明细、Costing Sheet 和补充附件后，可以“同意”或“退回”。
3. 选择“同意”时，系统原子地把 `待审批` 改为 `建单中`，锁定该订单，防止多人重复审批或重复建单。
4. ePortal 明确建单成功后显示 `已完成`；调用失败或结果不明确时显示 `待重试`。
5. `待重试` 由审批用户或管理员核对 ePortal 是否已经产生订单。确认未建单后才能点击“重试建单”，状态变回 `建单中`。
   `待重试` 任务的通用详情页也会展示最后一次 ePortal 错误，并向审批用户和管理员提供“提交eportal”按钮；该按钮仍统一调用 `api/submit_eportal.php`。
6. 选择“退回”时必须填写理由，任务变为 `已退回`；提交用户完成修改并重新提交后，该任务再次出现在 `待审批` 队列。

审批用户的处理路径：

```text
                   ┌→ 已退回 →（用户修改、重建表格）→ 待审批
待审批 → 审批决定 ┤
                   └→ 建单中 → 已完成
                          ↕
                       待重试
```

### “完成时间”的新定义

- `costing_sheet_generated_at`：Costing Sheet 生成时间；
- `submitted_approval_at`：进入待审批时间；
- `approved_at` / `rejected_at`：最后一次审批时间；
- `eportal_submitted_at`：最近一次发起 ePortal 请求时间；
- 原 `completed_at`：仅在状态 `9`、ePortal 建单成功后填写。

## 3. 页面与权限

### 左侧菜单

新增“审批订单”菜单，显示状态 `6` 的待审批数量徽标。仅 `role IN ('approver', 'admin')` 可见、可访问；不能只隐藏菜单，服务端也必须校验角色。

### 审批订单列表

默认只列出 `6 待审批`，按 `submitted_approval_at ASC, id ASC` 排序（最早提交优先），支持任务号、创建人、Sales Person 和状态筛选。列表至少展示：任务号、客户、PO、金额、创建人、提交审批时间、当前状态、详情入口。可切换查看 `7/9/10` 作为审批历史或异常队列。

### 审批详情

展示合同字段、产品明细、原始合同、识别 PDF、Costing Sheet、补充附件、历史退回理由和完整操作日志。

- **同意并建单**：二次确认；后端用条件更新 `WHERE id=:id AND status=6` 抢占任务并置 `8`，然后请求 ePortal。
- **退回**：理由必填，建议 5～1000 字；写审批记录后置 `7`。
- **重试建单**：只允许状态 `10`；显示上次错误，二次确认后置 `8`。

### 创建人的合同列表

普通创建人默认只能看到自己创建的任务（管理员可看全部）。状态 `7` 的行置顶：

```sql
ORDER BY (created_by = :current_user AND status = 7) DESC,
         CASE WHEN status = 7 THEN rejected_at END DESC,
         id DESC
```

退回行使用红色背景、红色状态徽标和醒目“退回原因”，详情页显示“修改并重新提交”。服务端必须校验 `created_by = 当前用户`，避免修改他人的订单。

重新提交时只允许修改明确开放的业务字段，并允许追加附件；提交后先置 `5` 并重新生成 Costing Sheet，生成成功才置 `6`。不能直接把旧表格对应的任务改成 `6`，否则审批人看到的是过期文件。

## 4. 数据库设计

### `users`

新增：

```sql
role ENUM('submitter','approver','admin') NOT NULL DEFAULT 'submitter'
```

迁移时应将现有 `admin` 设为 `admin`，其他用户默认 `submitter`。

### `contract_forms`

新增：

```sql
approval_round INT UNSIGNED NOT NULL DEFAULT 0,
submitted_approval_at DATETIME NULL,
approved_at DATETIME NULL,
approved_by INT UNSIGNED NULL,
rejected_at DATETIME NULL,
rejected_by INT UNSIGNED NULL,
rejection_reason VARCHAR(1000) NULL,
costing_sheet_generated_at DATETIME NULL,
eportal_submitted_at DATETIME NULL,
eportal_completed_at DATETIME NULL,
eportal_ticket_no VARCHAR(190) NULL,
eportal_last_error TEXT NULL,
eportal_response LONGTEXT NULL,
eportal_request_key VARCHAR(100) NULL
```

状态注释更新为 `1草稿中 2待识别 3识别中 4匹配中 5待建表 6待审批 7已退回 8建单中 9已完成 10待重试`。索引建议增加 `(status, submitted_approval_at, id)` 与 `(created_by, status, id)`。

迁移现有 `6 已建表` 数据时，应保持状态值 `6`（迁移后解释为待审批），把旧 `completed_at` 复制到 `costing_sheet_generated_at`，设置 `submitted_approval_at = COALESCE(completed_at, updated_at)`、`approval_round = 1`，再清空 `completed_at`。这样已有 Costing Sheet 会进入审批队列，而不会被误记为已经在 ePortal 完成。

### 审批历史表 `contract_approvals`

每次同意、退回、重提都必须追加记录，不能只覆盖 `contract_forms.rejection_reason`：

```text
id, task_id, approval_round, action(submit/approve/reject/eportal_retry),
operator_id, operator_name, reason, created_at
```

### 附件表 `contract_form_files`

补充附件不能继续塞入当前单文件字段，建议字段：

```text
id, task_id, approval_round, category(source/supplement/costing_sheet),
original_name, stored_name, mime_type, file_size, sha256,
uploaded_by, created_at
```

文件保存在任务隔离目录，不把磁盘路径交给客户端；下载仍经鉴权接口输出。

## 5. ePortal 请求协议

### 地址与传输方式

- 地址：`POST http://10.106.4.174/mo.php/api/createTicket`
- 建议配置项：`EPORTAL_ENDPOINT`、`EPORTAL_CONNECT_TIMEOUT_SECONDS`、`EPORTAL_TIMEOUT_SECONDS`，禁止把地址散落在业务代码中。默认连接超时为 30 秒，整个请求超时为 60 秒；整个请求超时配置小于连接超时时，程序会自动采用连接超时值。
- 请求使用 `multipart/form-data`：表单字段 `data` 是下表对象序列化后的 JSON 字符串，文件字段 `att2` 是本审批轮次生成的 XLSX；补充附件使用 `files[0]`、`files[1]` 依次上传。
- JSON 中仍保留 `att2: null` 与 `files: []`，实际二进制文件通过同名 multipart 文件字段传送。
- 所有审批及重试建单统一 POST 到 `api/submit_eportal.php`，由该接口独占处理状态变更、ePortal 请求和结果入库。
- 每次发送前，API 日志会以 `【请求eportal原格式】` 记录完整 ePortal JSON 字段层级，并另行记录 multipart 文件元数据、请求头和超时设置；收到的 HTTP 状态与原始响应体以 `【eportal响应】` 记录。文件二进制内容不写入日志，以免日志无限膨胀。

### 顶层字段映射

“固定值”应放在配置中；“新增字段”表示当前数据库没有可靠来源，必须在创建/退回编辑表单中补录，不能用猜测值上线。

| ePortal 字段 | 本系统来源 | 转换/说明 |
| --- | --- | --- |
| `products` | 产品行映射，见下表 | 数组；由分号字段按相同下标组装。 |
| `presales` | 固定值 `"0"` | 当前接口实现直接传字符串 `0`。 |
| `quotation_ref` | `contract_forms.po_no` | 暂按 PO No. 映射；需业务确认是否另有报价单号。 |
| `customer_name` | `contract_forms.customer_name` | 直接传。 |
| `customer_id` | 空字符串 | 当前数据表无客户 ID；接口实现传空字符串。 |
| `user_name` | `contract_forms.end_user_name` | 直接传。 |
| `so` | `contract_forms.po_no` | 暂按 PO No. 映射；如 SO 与 PO 不同，应新增 `so`。 |
| `so1` | 空字符串 | 当前接口实现传空字符串。 |
| `customer_payment_term` | 空字符串 | 当前接口实现传空字符串。 |
| `sales_person` | `contract_forms.sales_person` | 示例看似登录名（如 `agnes.lao`）；当前是自由文本，建议改为销售账号选择器。 |
| `customer_address` | `contract_forms.customer_delivery_address` | 直接传。 |
| `user_contact` | `contract_forms.end_user_contact` | 直接传。 |
| `user_mail` | `contract_forms.end_user_email` | 直接传。 |
| `delivery_date` | 建单当天 | 澳门时区 `Y-m-d`。 |
| `tax_structure` | 空字符串 | 当前接口实现传空字符串。 |
| `exchange_rate` | 固定值 `"1"` | 当前接口实现使用字符串 `1`。 |
| `sales_bundling` | 固定值 `Yes` | 与提供的接口样例一致。 |
| `date` | 审批同意当天 | 澳门时区 `Y-m-d`；若应为订单日期则新增字段。 |
| `att1` | 原合同文件或 `null` | 是否传原文件由业务及 ePortal 编码协议确认。 |
| `att2` | `files/costing_sheet/costing_sheet_Txxxxxx.xlsx` | 必须是该审批轮次最新生成文件。 |
| `files` | `contract_form_files.category=supplement` | 其他附件数组；元素结构仍需 ePortal 确认。 |
| `stage` | 配置固定值 `0` | 整数。 |
| `salesman` | `contract_forms.sales_person` | 当前与 `sales_person` 传相同值。 |
| `product_amount` | 产品类行金额合计 | 若 ePortal 接受 `null` 可传 `null`；建议由行项目计算。 |
| `service_amount` | 服务类行金额合计 | 当前只有 Product，暂为 `null`/`0`，需确认。 |
| `total_price` | `sum(products.total_price)` | 数值。 |
| `prior` | 固定值 `否` | 与提供的接口样例一致。 |
| `grand_total_total_cost` | `sum(products.total_cost)` | 数值。 |
| `grand_total_total_price` | `sum(products.total_price)` | 数值。 |
| `grand_total_gst_payable` | `sum(products.gst_payable)` | 数值；示例虽为字符串，建议先确认类型。 |
| `grand_total_GP` | `grand_total_total_price - grand_total_total_cost` | 数值。 |
| `grand_total_GP_rate` | `grand_total_GP / grand_total_total_price * 100%` | 分母为 0 时传 `0.0%`。 |
| `total_price_exclude_sst` | `grand_total_total_price` | 若单价未含税；计税口径需财务确认。 |
| `sst_payable` | 按确认税率计算 | 示例 `0.07` 与 `gst_payable_rate=7` 含义可能冲突，不能据示例猜测。 |
| `total_price_inclusive_sst` | `total_price_exclude_sst + sst_payable` | 数值。 |
| `gst_payable_rate` | `eportal.gst_rate` | 默认数值 `7`，可在配置中调整。 |
| `total_amount` | 含税总额 | `total_price + sst_payable`。 |
| `buyer_mail` | `null` | 当前数据表无来源。 |
| `buyer` | `null` | 当前数据表无来源。 |

### `products[]` 字段映射

| ePortal 字段 | 本系统来源 | 转换/说明 |
| --- | --- | --- |
| `product_id` | `contract_forms.pid[i]` | P 系统返回的产品 ID。 |
| `description` | `contract_forms.description[i]` | 直接传。 |
| `PN` | `contract_forms.vendor_part_no[i]` | 供应商料号。 |
| `node_id` | 配置固定值 `JOSM` | 应配置化。 |
| `biz_category` | 配置固定值 `Product` | 若未来支持服务行，需逐行存储类别。 |
| `qty` | `contract_forms.qty[i]` | 请求前验证为正数。 |
| `currency` | `contract_forms.price_currency[i]` | ISO 币种代码。 |
| `unit_cost` | `contract_forms.unit_price[i]` | 当前没有独立成本字段，暂以单价作为成本基准，因此 GP 为 0。 |
| `price` | `contract_forms.price_currency[i]` | 按示例映射为币种；字段名含义需 ePortal 确认。 |
| `unit_price` | `contract_forms.unit_price[i]` | 请求前验证为非负数。 |
| `total_cost` | `qty × unit_cost` | 数值。 |
| `total_price` | `qty × unit_price` | 数值。 |
| `gst_payable` | `total_price × eportal.gst_rate / 100` | 默认税率为 7。 |
| `tax_pyable` | 空字符串 | 按接口原拼写保留。 |
| `supplier` | 空字符串 | 当前数据表无来源。 |
| `jas_cost` | 固定值 `0` | 整数。 |
| `warehouse` | 空字符串 | 当前接口实现传空字符串。 |
| `dropship` | 固定值 `Y` | 与提供的接口样例一致。 |
| `remarks` | 空字符串 | 当前接口实现传空字符串。 |
| `notes` | 空字符串 | 当前接口实现传空字符串。 |
| `pass` | 固定值 `1` | 整数。 |
| `GP` | `total_price - total_cost` | 数值。 |
| `GP_percent` | `GP / total_price × 100` | 分母为 0 时为 `0`；确认 ePortal 要数值还是百分数字符串。 |

现有产品数据使用分号拼接，不适合继续扩充。实施审批编辑时建议迁移成 `contract_form_items` 明细表，逐行保存上述成本、供应商、税务及交付字段，并在过渡期兼容读取旧列。

## 6. ePortal 调用与一致性

1. 在数据库事务中锁定任务，仅允许 `6 → 8` 或 `10 → 8`；生成唯一 `eportal_request_key` 并提交事务。
2. 从数据库重新读取快照，验证最新 Costing Sheet 存在，校验所有必填字段、金额和附件。
3. 发送请求并记录脱敏后的请求摘要、HTTP 状态与响应正文。禁止把文件内容和个人资料写入普通日志。
4. **只有 HTTP 2xx 且 JSON 响应 `success === true`**才执行 `8 → 9`；外部单号依次读取 `ticket_no`、`id`，并填写 `completed_at`。
5. 超时或响应不明确时执行 `8 → 10`。因为服务端可能已建单，人工重试前必须能用 `eportal_request_key` 或查询接口核对，避免重复订单。

当前实现以 `X-Idempotency-Key` 请求头发送本地幂等键。ePortal 方仍需确认是否支持该请求头，并补充认证方式、完整成功/失败响应样例、字段必填性/长度/类型及超时后的查单接口；在这些协议确认前，`待重试` 状态必须由人工核对后再操作。

## 7. 实施顺序与验收

1. 确认第 5 节所有“待确认”项及接口响应协议。
2. 编写数据库迁移、状态常量和集中式状态机，禁止页面直接写任意状态。
3. 实现角色权限、审批列表/详情、审批历史和退回原因。
4. 实现创建人退回高亮、编辑、补充附件、重建 Costing Sheet 与重新提交。
5. 实现 ePortal 客户端、请求校验、幂等/审计、失败重试。
6. 使用 ePortal 测试环境完成成功、业务失败、HTTP 失败、超时、重复点击和并发审批测试后再启用生产地址。

验收重点：未授权用户无法审批；同一任务不能被重复审批或重复建单；退回理由可追溯；修改后一定使用新 Costing Sheet；只有 ePortal 明确成功才显示“已完成”；失败订单可安全定位和人工重试。
