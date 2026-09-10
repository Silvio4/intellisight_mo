# 智眸 - 澳门 API 获取任务及返回结果对接说明

文档版本：v1.1  
适用系统：智眸 - 澳门 `/intellisight_mo`  
接口格式：HTTP + JSON  
字符编码：UTF-8

---

## 1. 对接目的

外部识别系统通过本接口完成以下流程：

1. 调用 `api/get_task.php` 获取当前需要识别的合同任务。
2. 下载该任务下已经转换好的一个或多个 PDF 文件。
3. 对 PDF 执行识别。
4. 调用 `api/returndata.php` 返回结构化识别结果。
5. 智眸 - 澳门保存识别结果，并把任务状态更新为“已完成”。

任务状态流转：

```text
待识别 pending
    ↓ 调用 get_task.php 成功领取
识别中 recognizing
    ↓ 调用 returndata.php 成功保存结果
已完成 completed
```

当前版本在整个系统范围内同一时间只允许存在一条“识别中”任务。只要仍有任务处于 `recognizing`，获取任务接口就不会派发下一条待识别任务。

---

## 2. 基础地址与访问方式

假设系统地址为：

```text
https://example.com/intellisight_mo
```

实际接口地址：

```text
获取任务：
https://example.com/intellisight_mo/api/get_task.php

返回结果：
https://example.com/intellisight_mo/api/returndata.php
```

当前版本已经取消 API Key 鉴权：

- 获取任务接口无需登录 Cookie，也无需发送 Key。
- 返回结果接口无需登录 Cookie，也无需发送 Key。
- 调用方可直接请求接口地址。
- PDF 文件仍使用获取任务时自动生成的临时 `claim_token` 下载；调用方只需使用接口返回的 `download_url`，无需自行配置令牌。

由于接口不再验证调用方身份，建议在内部网络使用，并通过防火墙或 Nginx 来源 IP 白名单限制访问范围。

---

# 第一部分：获取识别任务

## 3. 获取任务接口

### 3.1 接口信息

| 项目 | 内容 |
| --- | --- |
| 接口路径 | `/intellisight_mo/api/get_task.php` |
| 请求方法 | `GET` 或 `POST` |
| 返回格式 | `application/json; charset=utf-8` |
| 是否需要登录 Cookie | 不需要 |
| 是否需要 API Key | 不需要 |

推荐使用 `GET`。

### 3.2 基本请求示例

#### cURL

```bash
curl -X GET "https://example.com/intellisight_mo/api/get_task.php"
```

#### Python

```python
import requests

url = "https://example.com/intellisight_mo/api/get_task.php"
response = requests.get(url, timeout=60)
response.raise_for_status()
result = response.json()

print(result)
```

#### PHP

```php
<?php
$url = 'https://example.com/intellisight_mo/api/get_task.php';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
]);

$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($body === false) {
    throw new RuntimeException(curl_error($ch));
}
curl_close($ch);

$result = json_decode($body, true);
var_dump($httpCode, $result);
```

---

## 4. 获取任务的派发规则

接口每次被调用时按以下顺序执行：

| 顺序 | 当前情况 | 接口处理 | 返回 code | dispatched |
| --- | --- | --- | --- | --- |
| 1 | 已有“识别中”任务 | 返回当前识别中任务，不领取新任务 | `recognition_in_progress` | `false` |
| 2 | 没有识别中任务，但有待识别任务 | 领取最早创建的待识别任务，并转为识别中 | `task_dispatched` | `true` |
| 3 | 没有识别中任务，也没有待识别任务 | 不派发任务 | `no_pending_task` | `false` |

接口使用 MySQL 命名锁和事务保护派发过程。即使多个程序同时调用，也不会同时领取多条任务。

---

## 5. 获取任务返回字段

### 5.1 顶层字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `success` | boolean | 请求是否成功 |
| `code` | string | 本次处理结果代码 |
| `message` | string | 中文说明 |
| `dispatched` | boolean | 本次请求是否新领取了一条任务 |
| `task` | object/null | 任务信息；无任务时为 `null` |

### 5.2 task 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `task_no` | string | `T` 加至少 6 位任务序号（如 `T000001`），返回识别结果时必须原样带回 |
| `status` | string | 当前状态，正常为 `recognizing` |
| `status_text` | string | 中文状态名称 |
| `sales_person` | string | 创建表单时填写的 Sales Person |
| `created_at` | string | 任务创建时间，格式 `YYYY-MM-DD HH:mm:ss` |
| `claimed_at` | string | 任务领取时间 |
| `claim_token` | string | 本次任务的 PDF 下载令牌 |
| `pdf_count` | integer | PDF 文件数量 |
| `files` | array | PDF 文件列表 |

### 5.3 files 数组字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `file_id` | integer | 文件记录ID |
| `original_name` | string | 用户上传时的原文件名 |
| `pdf_name` | string | 系统转换后的 PDF 文件名 |
| `pdf_size` | integer | PDF 字节数 |
| `sha256` | string | PDF 文件 SHA-256，可用于校验下载完整性 |
| `download_url` | string | 带领取令牌的 PDF 下载地址 |
| `content_type` | string | 仅 Base64 模式返回，固定为 `application/pdf` |
| `content_base64` | string | 仅 Base64 模式返回，PDF 的 Base64 内容 |

---

## 6. 三种正常返回结果

### 6.1 新派发了一条任务

```json
{
  "success": true,
  "code": "task_dispatched",
  "message": "已派发一条待识别任务，并更新为识别中。",
  "dispatched": true,
  "task": {
    "task_no": "T000001",
    "status": "recognizing",
    "status_text": "识别中",
    "sales_person": "Silvio",
    "created_at": "2026-09-10 10:20:30",
    "claimed_at": "2026-09-10 10:25:00",
    "claim_token": "随机下载令牌",
    "pdf_count": 2,
    "files": [
      {
        "file_id": 1,
        "original_name": "PO.pdf",
        "pdf_name": "document_01_PO.pdf",
        "pdf_size": 215400,
        "sha256": "PDF文件的SHA256值",
        "download_url": "https://example.com/intellisight_mo/api/download_file.php?file_id=1&token=随机下载令牌"
      },
      {
        "file_id": 2,
        "original_name": "quotation.xlsx",
        "pdf_name": "document_02_quotation.pdf",
        "pdf_size": 180230,
        "sha256": "PDF文件的SHA256值",
        "download_url": "https://example.com/intellisight_mo/api/download_file.php?file_id=2&token=随机下载令牌"
      }
    ]
  }
}
```

识别端收到 `task_dispatched` 后，应保存 `task_no`，下载 `files` 中的全部 PDF，然后开始识别。

### 6.2 已有识别中的任务

```json
{
  "success": true,
  "code": "recognition_in_progress",
  "message": "当前已有识别中的任务，本次不派发新任务。",
  "dispatched": false,
  "task": {
    "task_no": "T000001",
    "status": "recognizing",
    "status_text": "识别中",
    "sales_person": "Silvio",
    "created_at": "2026-09-10 10:20:30",
    "claimed_at": "2026-09-10 10:25:00",
    "claim_token": "原任务下载令牌",
    "pdf_count": 2,
    "files": []
  }
}
```

注意：实际返回的 `files` 会包含任务的完整 PDF 文件信息。此状态代表接口没有领取下一条任务，而是把尚未完成的原任务再次返回，方便识别端在异常重启后继续处理。

识别端处理建议：

- 如果本地正在处理同一个 `task_no`，继续原处理流程。
- 如果识别程序刚刚重启，可重新下载该任务 PDF 并重新识别。
- 不要因为 `dispatched=false` 就忽略 `task`；应根据 `code` 和 `task_no` 判断。

### 6.3 没有待识别任务

```json
{
  "success": true,
  "code": "no_pending_task",
  "message": "目前没有待识别任务，本次不派发任务。",
  "dispatched": false,
  "task": null
}
```

识别端收到此结果后无需报错，可间隔一段时间后再次轮询。

建议轮询间隔为 10–30 秒，不建议无间隔连续请求。

---

## 7. 下载任务 PDF

获取任务成功后，遍历：

```text
task.files
```

并请求每个文件的：

```text
download_url
```

`download_url` 已经携带 `claim_token`，调用方无需配置或添加 API Key。

### Python 下载示例

```python
from pathlib import Path
import requests

task = result["task"]
task_dir = Path("downloads") / task["task_no"]
task_dir.mkdir(parents=True, exist_ok=True)

for item in task["files"]:
    response = requests.get(item["download_url"], timeout=120)
    response.raise_for_status()

    target = task_dir / item["pdf_name"]
    target.write_bytes(response.content)
    print("已下载：", target)
```

下载成功时：

- HTTP 状态码为 `200`。
- `Content-Type` 为 `application/pdf`。
- 响应体是 PDF 二进制内容，不是 JSON。

下载令牌无效时返回 HTTP `401`；文件不存在时返回 HTTP `404`。

建议下载完成后计算本地文件 SHA-256，并与接口返回的 `sha256` 比较。

---

## 8. Base64 获取模式

如果识别系统不方便再次请求 `download_url`，可让获取任务接口直接在 JSON 中携带 PDF Base64：

```text
GET /intellisight_mo/api/get_task.php?include_base64=1
```

示例：

```bash
curl -X GET "https://example.com/intellisight_mo/api/get_task.php?include_base64=1"
```

此时每个文件额外返回：

```json
{
  "content_type": "application/pdf",
  "content_base64": "JVBERi0xLjQKJ..."
}
```

注意：Base64 会使响应体积增加约三分之一，而且 PHP 和识别端需要同时在内存中保存整个 JSON。文件较大或文件较多时，应优先使用 `download_url`。

---

# 第二部分：返回识别结果

## 9. 返回结果接口

### 9.1 接口信息

| 项目 | 内容 |
| --- | --- |
| 接口路径 | `/intellisight_mo/api/returndata.php` |
| 请求方法 | `POST` |
| 推荐 Content-Type | `application/json` |
| 返回格式 | `application/json; charset=utf-8` |
| 是否需要登录 Cookie | 不需要 |
| 是否需要 API Key | 不需要 |

只允许以下状态的任务接收结果：

- `recognizing`：第一次正常返回。
- `completed`：识别端重试或修正结果，覆盖原有结果。

`pending` 状态任务尚未被领取，不能直接返回结果。

---

## 10. 返回结果请求字段

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `task_no` | string | 是 | 获取任务接口返回的任务号，必须原样返回 |
| `po_no` | string | 否 | PO 编号 |
| `delivery_address` | string | 否 | 送货地址 |
| `no` | string/array | 否 | 行号；多个值以分号分隔 |
| `vendor_part_no` | string/array | 否 | Vendor Part No.；多个值以分号分隔 |
| `description` | string/array | 否 | 产品描述；多个值以分号分隔 |
| `qty` | string/array | 否 | 数量；多个值以分号分隔 |
| `unit_cost` | string/array | 否 | 单位成本；多个值以分号分隔 |
| `unit_price` | string/array | 否 | `unit_cost` 的兼容别名；两者都有时优先使用 `unit_cost` |
| `discount` | number/null | 否 | 折扣，允许正数、0、负数或 `null` |

推荐所有明细字段都传字符串，并使用英文半角分号 `;` 分隔。

---

## 11. 多行明细对应规则

以下五个字段属于同一组明细：

```text
no
vendor_part_no
description
qty
unit_cost
```

相同位置表示同一条明细。例如：

```json
{
  "no": "1;2",
  "vendor_part_no": "PN-001;PN-002",
  "description": "Notebook;Docking Station",
  "qty": "10;5",
  "unit_cost": "12938.94;1888.00"
}
```

系统会显示为：

| no | vendor_part_no | description | qty | unit_cost |
| --- | --- | --- | --- | --- |
| 1 | PN-001 | Notebook | 10 | 12938.94 |
| 2 | PN-002 | Docking Station | 5 | 1888.00 |

五个字段的分号数量应一致。如果数量不一致，接口仍会保存数据，但成功响应的 `warnings` 会提示人工核对。

如果字段内容本身可能包含分号，请在识别结果生成阶段先替换该分号，避免被系统误认为下一条明细。

接口也接受数组：

```json
{
  "no": ["1", "2"],
  "vendor_part_no": ["PN-001", "PN-002"],
  "description": ["Notebook", "Docking Station"],
  "qty": ["10", "5"],
  "unit_cost": ["12938.94", "1888.00"]
}
```

数组会在服务器端自动转换为分号格式保存。

---

## 12. 返回结果请求示例

### 12.1 标准 JSON

```json
{
  "task_no": "T000001",
  "po_no": "PO-2026-001",
  "delivery_address": "Avenida de Almeida Ribeiro, Macau",
  "no": "1;2",
  "vendor_part_no": "PN-001;PN-002",
  "description": "Notebook;Docking Station",
  "qty": "10;5",
  "unit_cost": "12938.94;1888.00",
  "discount": -100
}
```

### 12.2 cURL

```bash
curl -X POST "https://example.com/intellisight_mo/api/returndata.php" \
  -H "Content-Type: application/json" \
  -d '{
    "task_no": "T000001",
    "po_no": "PO-2026-001",
    "delivery_address": "Avenida de Almeida Ribeiro, Macau",
    "no": "1;2",
    "vendor_part_no": "PN-001;PN-002",
    "description": "Notebook;Docking Station",
    "qty": "10;5",
    "unit_cost": "12938.94;1888.00",
    "discount": -100
  }'
```

### 12.3 Python

```python
import requests

url = "https://example.com/intellisight_mo/api/returndata.php"
payload = {
    "task_no": "T000001",
    "po_no": "PO-2026-001",
    "delivery_address": "Avenida de Almeida Ribeiro, Macau",
    "no": "1;2",
    "vendor_part_no": "PN-001;PN-002",
    "description": "Notebook;Docking Station",
    "qty": "10;5",
    "unit_cost": "12938.94;1888.00",
    "discount": -100
}

response = requests.post(url, json=payload, timeout=60)
print(response.status_code)
print(response.json())
response.raise_for_status()
```

### 12.4 PHP

```php
<?php
$url = 'https://example.com/intellisight_mo/api/returndata.php';

$payload = [
    'task_no' => 'T000001',
    'po_no' => 'PO-2026-001',
    'delivery_address' => 'Avenida de Almeida Ribeiro, Macau',
    'no' => '1;2',
    'vendor_part_no' => 'PN-001;PN-002',
    'description' => 'Notebook;Docking Station',
    'qty' => '10;5',
    'unit_cost' => '12938.94;1888.00',
    'discount' => -100,
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
]);

$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($body === false) {
    throw new RuntimeException(curl_error($ch));
}
curl_close($ch);

$result = json_decode($body, true);
var_dump($httpCode, $result);
```

### 12.5 data 包装格式

接口也接受外层使用 `data` 包装：

```json
{
  "data": {
    "task_no": "T000001",
    "po_no": "PO-2026-001",
    "delivery_address": "Macau",
    "no": "1;2",
    "vendor_part_no": "PN-001;PN-002",
    "description": "Notebook;Docking Station",
    "qty": "10;5",
    "unit_cost": "12938.94;1888.00",
    "discount": -100
  }
}
```

---

## 13. 返回结果成功响应

### 13.1 字段数量一致

```json
{
  "success": true,
  "code": "result_saved",
  "message": "识别结果已保存，任务状态已更新为已完成。",
  "task_no": "T000001",
  "status": "completed",
  "status_text": "已完成",
  "line_counts": {
    "no": 2,
    "vendor_part_no": 2,
    "description": 2,
    "qty": 2,
    "unit_cost": 2
  },
  "warnings": []
}
```

### 13.2 字段数量不一致

```json
{
  "success": true,
  "code": "result_saved",
  "message": "识别结果已保存，任务状态已更新为已完成。",
  "task_no": "T000001",
  "status": "completed",
  "status_text": "已完成",
  "line_counts": {
    "no": 2,
    "vendor_part_no": 2,
    "description": 2,
    "qty": 1,
    "unit_cost": 2
  },
  "warnings": [
    "明细字段的分号分隔数量不一致，请在任务详情中核对行对应关系。"
  ]
}
```

即使 `warnings` 不为空，只要 `success=true` 和 `code=result_saved`，就表示数据库保存成功。识别端可记录警告，由业务人员在任务详情页核对。

---

## 14. 返回结果错误响应

| HTTP状态码 | code | 原因 | 处理建议 |
| --- | --- | --- | --- |
| 400 | `invalid_json` | JSON 格式错误 | 检查 JSON 引号、逗号和编码 |
| 404 | `task_not_found` | 任务号不存在 | 确认原样返回 get_task 的 `task_no` |
| 405 | `method_not_allowed` | 未使用 POST | 改用 POST |
| 409 | `invalid_task_status` | 任务不是识别中或已完成 | 先通过 get_task 正常领取任务 |
| 422 | `missing_task_no` | 未提供任务号 | 添加 `task_no` |
| 422 | `invalid_discount` | discount 不是数字 | 传数字、负数、0 或 null |
| 500 | `save_error` | 数据库或服务器异常 | 记录响应，稍后重试并查看服务端日志 |

任务不存在示例：

```json
{
  "success": false,
  "code": "task_not_found",
  "message": "找不到对应任务号。"
}
```

状态不允许示例：

```json
{
  "success": false,
  "code": "invalid_task_status",
  "message": "当前任务状态为“待识别”，不能接收识别结果。"
}
```

---

## 15. 重试与幂等性

### 获取任务

- 获取任务请求可以安全重试。
- 任务领取成功后，再次请求会返回同一个 `recognizing` 任务，不会派发下一条。
- 识别程序重启后，可以通过再次调用获取任务接口恢复当前任务。

### 返回结果

- 同一 `task_no` 可以重复返回。
- 第一次返回会创建 `contract_form` 结果记录。
- 后续重复返回会更新该任务原有记录，不会产生重复结果行。
- 网络超时但无法确定服务器是否保存成功时，可以使用同一份数据重试。

建议识别端只在收到以下响应后将本地任务标记为成功：

```text
HTTP 200
success = true
code = result_saved
```

---

## 16. 推荐的完整识别端流程

```python
import time
from pathlib import Path
import requests

BASE_URL = "https://example.com/intellisight_mo"

def get_task():
    response = requests.get(
        f"{BASE_URL}/api/get_task.php",
        timeout=60,
    )
    response.raise_for_status()
    return response.json()

def download_task_files(task):
    target_dir = Path("downloads") / task["task_no"]
    target_dir.mkdir(parents=True, exist_ok=True)
    paths = []

    for item in task["files"]:
        response = requests.get(item["download_url"], timeout=120)
        response.raise_for_status()
        path = target_dir / item["pdf_name"]
        path.write_bytes(response.content)
        paths.append(path)

    return paths

def recognize_pdf_files(paths):
    # 在这里调用实际 OCR / 大模型识别程序。
    # 以下仅为返回格式示例。
    return {
        "po_no": "PO-2026-001",
        "delivery_address": "Macau",
        "no": "1;2",
        "vendor_part_no": "PN-001;PN-002",
        "description": "Notebook;Docking Station",
        "qty": "10;5",
        "unit_cost": "12938.94;1888.00",
        "discount": -100,
    }

def return_result(task_no, recognition):
    payload = {"task_no": task_no, **recognition}
    response = requests.post(
        f"{BASE_URL}/api/returndata.php",
        json=payload,
        timeout=60,
    )
    response.raise_for_status()
    result = response.json()
    if not result.get("success") or result.get("code") != "result_saved":
        raise RuntimeError(result)
    return result

while True:
    try:
        result = get_task()
        code = result.get("code")

        if code == "no_pending_task":
            time.sleep(20)
            continue

        if code not in ("task_dispatched", "recognition_in_progress"):
            raise RuntimeError(result)

        task = result["task"]
        pdf_paths = download_task_files(task)
        recognition = recognize_pdf_files(pdf_paths)
        saved = return_result(task["task_no"], recognition)
        print("任务完成：", saved["task_no"])

    except Exception as exc:
        print("处理失败：", exc)
        time.sleep(30)
```

实际生产程序应增加：

- 本地任务日志。
- 下载文件 SHA-256 校验。
- OCR 调用超时和有限次数重试。
- 返回结果失败时保留本地识别结果。
- 告警通知，避免任务长期停留在识别中。

---

## 17. 联调步骤

1. 登录智眸 - 澳门。
2. 进入“合同表单”。
3. 点击“新建表单”。
4. 填写 Sales Person，上传 1–10 份文件并提交。
5. 确认任务列表状态为“待识别”。
6. 调用 `get_task.php`。
7. 确认返回 `code=task_dispatched`，页面状态变成“识别中”。
8. 逐一请求 `download_url`，确认全部返回 PDF。
9. 使用返回的同一 `task_no` 调用 `returndata.php`。
10. 确认返回 `code=result_saved`。
11. 刷新任务详情，确认状态为“已完成”，PO、地址、折扣和明细行显示正确。
12. 再次调用 `get_task.php`，确认开始领取下一条待识别任务；若无任务则返回 `no_pending_task`。

---

## 18. 反向代理注意事项

如果系统通过 Nginx 反向代理访问，建议传递以下请求头：

```nginx
proxy_set_header Host $host;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
```

系统会读取 `X-Forwarded-Host` 和 `X-Forwarded-Proto` 生成正确的 PDF 下载地址。

---

## 19. 安全要求

- 获取任务和返回结果接口不验证 API Key，建议只允许可信内网主机访问。
- 正式环境建议在防火墙或 Nginx 中配置调用方 IP 白名单。
- 建议仅通过 HTTPS 调用接口。
- 不要开放 `files/` 目录直接访问；应只通过 `api/download_file.php` 下载。
- 建议在防火墙或 Nginx 中限制接口来源 IP。
- 不建议长期使用 `include_base64=1` 处理大文件。
- `logs/` 和 `files/` 目录应禁止执行 PHP 文件。

---

## 20. 数据库存储关系

| 数据表 | 用途 |
| --- | --- |
| `contract_tasks` | 任务号、Sales Person、任务状态、领取时间、完成时间及原始返回数据 |
| `contract_task_files` | 原文件名、服务器文件名、PDF 文件名、大小及 SHA-256 |
| `contract_form` | PO、地址、明细字段和 discount 等结构化识别结果 |

`contract_form.task_id` 对一条任务保持唯一，因此重复返回同一任务时执行更新，不会产生重复记录。
