# 智眸 DN 任务获取和返回文件接口

本文档供 DN 识别系统对接使用。接口流程如下：

1. 用户在订单识别完成后，于任务详情页上传 DN PDF；系统创建“待识别”任务。
2. 识别系统调用 `get_task_dn.php` 领取任务；领取成功后任务变为“识别中”。
3. 识别系统通过响应中的下载地址获取原始 DN PDF。
4. 识别完成后，调用 `returndata_dn.php` 回传结果；可选上传重新生成的 DN PDF，任务变为“已完成”。

接口 JSON 响应均使用 UTF-8 编码。下面示例假设系统部署地址为：

```text
https://example.com/intellisight_mo
```

## 1. DN 任务状态

| 状态值 | 状态文字 | 说明 |
|---:|---|---|
| `1` | 待识别 | 用户已上传，等待识别系统领取 |
| `2` | 识别中 | 任务已被 `get_task_dn.php` 领取 |
| `3` | 已完成 | 重新生成的 DN PDF 已成功回传 |

## 2. 获取待识别 DN 任务

```http
GET /intellisight_mo/api/get_task_dn.php
```

接口也支持 `POST`，不需要请求体。任务按 DN 任务 ID 从小到大领取。为避免识别系统并行处理导致状态混乱，同一时间最多只派发一条“识别中”的 DN 任务。

### 2.1 请求示例

```bash
curl -X GET "https://example.com/intellisight_mo/api/get_task_dn.php"
```

### 2.2 有任务响应

```json
{
  "success": true,
  "has_task": true,
  "dn_task_id": 12,
  "task": {
    "dn_task_id": 12,
    "order_task_id": 26,
    "order_task_no": "T000026",
    "sequence_no": 1,
    "status": 2,
    "status_text": "识别中",
    "uploaded_at": "2026-09-22 14:30:00",
    "original_file_name": "customer-dn.pdf",
    "dn_file": "dn_T000026_20260922_1.pdf",
    "dn_file_url": "https://example.com/intellisight_mo/api/download_dn.php?id=12"
  }
}
```

### 2.3 响应字段

| 字段 | 类型 | 说明 |
|---|---|---|
| `success` | boolean | 接口是否正常执行 |
| `has_task` | boolean | 本次是否成功领取任务；调用方必须先判断此字段 |
| `dn_task_id` | integer | DN 任务 ID；回传文件时使用此 ID |
| `task.order_task_id` | integer | DN 所属的订单任务数据库 ID |
| `task.order_task_no` | string | 页面显示的订单任务号 |
| `task.sequence_no` | integer | 该订单下 DN 的上传序号 |
| `task.status` | integer | 领取后的状态，固定为 `2` |
| `task.status_text` | string | 领取后的状态文字，固定为“识别中” |
| `task.uploaded_at` | string | DN 上传时间，格式为 `YYYY-MM-DD HH:mm:ss` |
| `task.original_file_name` | string | 用户上传时的原始文件名，仅用于展示或追溯 |
| `task.dn_file` | string | 系统标准化后的原始 DN 文件名 |
| `task.dn_file_url` | string | 原始 DN PDF 下载地址 |

### 2.4 暂无任务响应

```json
{
  "success": true,
  "has_task": false,
  "message": "暂无待识别 DN 任务"
}
```

如果已有其他 DN 任务处于“识别中”，接口不会继续派发任务：

```json
{
  "success": true,
  "has_task": false,
  "message": "已有识别中 DN 任务，暂不派发新任务",
  "recognizing_dn_task_id": 12
}
```

> `success=true` 只表示接口正常执行，不代表一定领取到任务。对接程序必须通过 `has_task` 判断是否需要下载和处理文件。

## 3. 下载原始 DN PDF

领取成功后，直接使用 `task.dn_file_url` 下载文件：

```http
GET /intellisight_mo/api/download_dn.php?id={dn_task_id}
```

### 3.1 请求示例

```bash
curl -L \
  "https://example.com/intellisight_mo/api/download_dn.php?id=12" \
  --output dn_T000026_20260922_1.pdf
```

成功时响应正文为 PDF 二进制内容，响应头 `Content-Type` 为 `application/pdf`。文件或任务不存在时返回 HTTP `404` 和 JSON 错误信息。

## 4. 返回 DN 处理结果

```http
POST /intellisight_mo/api/returndata_dn.php
Content-Type: application/json 或 multipart/form-data
```

过渡期间，接口允许只返回任务 ID 而不上传文件，也允许 `dn_file` 是普通文本等其他内容。没有收到有效 PDF 时，任务仍会完成，但不会生成可下载的完成文件。

如需保存生成后的 PDF，应使用 `multipart/form-data`，并把 `dn_file` 作为文件 part 上传。仅回传任务状态时，也可以使用 JSON；JSON 顶层或 `data` 对象内的 `dn_task_id` 均可识别。

### 4.1 请求字段

| 字段 | 类型 | 必填 | 说明 |
|---|---|:---:|---|
| `dn_task_id` | integer | 是 | `get_task_dn.php` 返回的 DN 任务 ID |
| `dn_file` | file / 任意 | 否 | 有效 PDF（最大 30MB）会被保存；缺失或其他内容会被忽略 |

为了兼容旧调用方，接口也接受 `task_id` 作为 `dn_task_id` 的别名，并接受 `file` 作为 `dn_file` 的别名；新对接应优先使用标准字段名 `dn_task_id` 和 `dn_file`。

### 4.2 请求示例

```bash
curl -X POST \
  -F "dn_task_id=12" \
  -F "dn_file=@/path/to/generated-dn.pdf;type=application/pdf" \
  "https://example.com/intellisight_mo/api/returndata_dn.php"
```

Python `requests` 示例：

```python
with open("/path/to/generated-dn.pdf", "rb") as pdf:
    response = requests.post(
        "https://example.com/intellisight_mo/api/returndata_dn.php",
        data={"dn_task_id": 12},
        files={"dn_file": ("generated-dn.pdf", pdf, "application/pdf")},
    )
response.raise_for_status()
```

上传文件时不要自行设置 `Content-Type` 请求头；由 HTTP 客户端生成包含 boundary 的 `multipart/form-data` 请求头。

不返回文件时可以直接发送 JSON：

```json
{
  "data": {
    "dn_task_id": 12
  },
  "state": 0
}
```

### 4.3 成功响应

```json
{
  "success": true,
  "message": "DN 返回文件已保存。",
  "dn_task_id": 12,
  "order_task_id": 26,
  "status": 3,
  "status_text": "已完成",
  "file_saved": true,
  "done_file_name": "dn_T000026_20260922_1_done.pdf"
}
```

未上传有效 PDF 时，`file_saved` 为 `false`、`done_file_name` 为 `null`，任务状态仍为 `3`（已完成）。

返回成功后，生成文件保存在系统的 `files/dn_done/` 目录，并可在订单任务详情页的“DN 文件”区域下载。

## 5. HTTP 状态码与错误响应

| HTTP 状态码 | 常见场景 |
|---:|---|
| `200` | 请求成功，或正常查询但当前没有可领取任务 |
| `404` | DN 任务或待下载文件不存在 |
| `405` | 使用了接口不支持的 HTTP 方法 |
| `409` | 尝试向非“识别中”状态的任务回传文件 |
| `422` | 缺少或无法识别任务 ID |
| `500` | 服务端领取或文件存储处理失败 |

错误响应格式示例：

```json
{
  "success": false,
  "message": "仅识别中的 DN 任务可接收返回文件。"
}
```

调用方应同时检查 HTTP 状态码和响应中的 `success` 字段。回传接口成功前不要删除本地生成文件；发生网络错误或 `5xx` 错误时，可先查询运维日志并谨慎重试。同一个已完成任务不能重复回传，重复请求会返回 HTTP `409`。

### 5.1 过渡期文件处理规则

1. 不传 `dn_file`：完成任务，不保存文件；
2. `dn_file` 为 `test` 等普通字段：忽略该字段，完成任务，不保存文件；
3. `dn_file` 为无效、超限或非 PDF 上传文件：忽略该文件，完成任务，不保存文件；
4. `dn_file` 为 30MB 以内的有效 PDF：保存文件并完成任务；
5. DN 结果应提交到 `returndata_dn.php`，不要提交到订单识别结果接口 `returndata.php`。

## 6. 文件命名及存储规则

| 文件类型 | 存储目录 | 文件名示例 |
|---|---|---|
| 用户上传的原始 DN | `files/dn/` | `dn_T000026_20260922_1.pdf` |
| 识别后重新生成的 DN | `files/dn_done/` | `dn_T000026_20260922_1_done.pdf` |

同一订单在同一天或不同日期上传多个 DN 时，末尾序号依次递增，例如 `_1.pdf`、`_2.pdf`。序号按订单任务维度计算，不会因日期变化而重置。

## 7. 日志与问题排查

每个 API 使用独立日志文件，默认位于 `api/logs/`：

```text
api/logs/get_task_dn_YYYY-MM-DD.log
api/logs/download_dn_YYYY-MM-DD.log
api/logs/returndata_dn_YYYY-MM-DD.log
```

响应头会包含 `X-Request-ID`。排查问题时建议同时提供调用时间、接口地址、HTTP 状态码、`dn_task_id` 和 `X-Request-ID`，以便在对应接口日志中定位请求。
