# 更新记录

## v0.3

- 取消 `api/get_task.php` 的 API Key 鉴权，解决未携带 Key 时返回 HTTP 401 的问题。
- 取消 `api/returndata.php` 的 API Key 鉴权。
- PDF 下载继续使用获取任务时自动生成的 `claim_token`，对接程序无需配置 Key。
- 同步更新完整 API 对接文档和全部调用示例。

## v0.2

- 修复 PHP 7.1–7.3 无法解析 `private array $uploadConfig` 的问题。
- 将文件转换器类属性改为兼容旧 PHP 的普通属性，并保留 PHPDoc 数组类型说明。
- 增加完整的 `API_INTEGRATION_GUIDE.md`，覆盖获取任务、PDF 下载、结果回传、错误码、重试和联调流程。
- 项目最低 PHP 版本说明调整为 PHP 7.1。

## v0.1

- 完成本地用户登录、合同任务创建、多文件上传、PDF 转换、任务派发、结果回传及任务详情。
