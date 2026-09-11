# 智眸 - 澳门 API 对接说明

## 获取待识别任务

```http
GET /intellisight_mo/api/get_task.php
```

接口只派发状态 `2`（待识别）的 `contract_forms`，领取后更新为状态 `3`（识别中）。系统已有识别中任务时不会派发下一条。

有任务响应：

```json
{
  "success": true,
  "has_task": true,
  "task": {
    "id": 1,
    "status": 3,
    "status_text": "识别中",
    "created_at": "2026-09-11 10:00:00",
    "created_by_mail": "user@example.com",
    "contract_quote_epo_file": "document_01_PO.pdf",
    "contract_quote_epo_url": "https://example.com/intellisight_mo/api/download_file.php?id=1"
  }
}
```

无任务响应：

```json
{"success":true,"has_task":false,"message":"暂无待识别任务"}
```

已有识别中任务响应会额外包含 `recognizing_task_id`。

## 回传识别结果

```http
POST /intellisight_mo/api/returndata.php
Content-Type: application/json
```

```json
{
  "id": 1,
  "po_no": "PO-001",
  "delivery_address": "Macau",
  "no": "1;2",
  "vendor_part_no": "PN-1;PN-2",
  "description": "Item A;Item B",
  "qty": "2;3",
  "unit_cost": "10.50;20.00",
  "discount": -5
}
```

- `id` 是 `contract_forms.id`，即获取任务响应中的任务号；接口也兼容旧调用方传 `task_id` 或 `task_no`。
- `no`、`vendor_part_no`、`description`、`qty`、`unit_cost` 可以传分号分隔字符串或数组。
- `unit_price` 可作为 `unit_cost` 的兼容别名。
- 成功保存后状态更新为 `6`（已完成），并记录识别、匹配和完成时间。

## 日志

接口日志按接口文件独立保存：

```text
api/logs/get_task_YYYY-MM-DD.log
api/logs/returndata_YYYY-MM-DD.log
api/logs/download_file_YYYY-MM-DD.log
```
