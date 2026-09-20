# 智眸 P 系统返回接口

## 接口

```http
POST /intellisight_mo/api/p_sys_back.php
Content-Type: application/json
```

P 系统应在智眸发送的原请求数据基础上增加 `pid` 和 `p_sys_link` 后原样返回。请求可直接传对象，也可以使用 `data` 包裹：

```json
{
  "data": {
    "task_id": 26,
    "po_no": "PO-2026-001",
    "customer_name": "ABC Company",
    "customer_delivery_address": "Macau",
    "end_user_name": "End User Ltd.",
    "end_user_contact": "Chan Tai Man",
    "end_user_email": "contact@example.com",
    "vendor_part_no": "PC121231;PC321442",
    "description": "AAA;BBB",
    "qty": "2;3",
    "price_currency": "MOP;USD",
    "unit_price": "100.00;25.50",
    "pid": "PID10001;PID10002",
    "p_sys_link": "https://p-system.example/tasks/26"
  }
}
```

## 返回字段说明

| 字段 | 说明 |
|---|---|
| `task_id` | 必填，数值型任务 ID |
| 原请求的 11 个识别字段 | P 系统按收到的字段和格式原样带回 |
| `pid` | 必填，多值字段；英文分号分隔，数量必须与五个识别明细字段一致，并按位置一一对应 |
| `p_sys_link` | 必填，P 系统任务或处理结果链接 |

接口只接受状态为 `4`（匹配中）的任务。成功保存 `pid` 和 `p_sys_link` 后，系统会使用 `template/costing_sheet_v1.xlsx` 立即生成 Costing Sheet；生成成功后任务状态更新为 `6`（已建表）。如果生成失败，任务保持状态 `5`（待建表），可调用 `POST /api/costing_sheet.php` 并传入 `task_id` 重试。

### 成功响应

```json
{
  "success": true,
  "message": "P 系统匹配结果已保存，Costing Sheet 已生成。",
  "task_id": 26,
  "status": 6,
  "status_text": "已建表",
  "costing_sheet": "costing_sheet_T000026.xlsx"
}
```
