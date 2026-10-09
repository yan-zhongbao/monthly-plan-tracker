# 输出栏目与 OpenClaw 接入（0.6.0 / API 1.4）

月追踪底部固定显示一行“输出”。点击日期格子的＋，选择读书笔记、文章或旅行记录，填写标题，可选填备注和详情链接。同一天、同类型均可有多篇，各自显示圈读、圈文、圈旅；悬停显示类型、标题和备注，点击打开详情。已移除内容可从表格下方“已移除输出”恢复。未来日期与只读月份不能添加或修改。

## 给龙虾 agent 的说明

- 说明网页：https://month.btcsoft.net/api-docs.php#put-outputs
- 机器可读清单：https://month.btcsoft.net/api.php?action=discovery
- 业务接口：https://month.btcsoft.net/api.php?action=outputs
- 认证沿用现有 `Authorization: Bearer <API_TOKEN>`，JSON 请求头为 `Content-Type: application/json`。不要把 Token 放入 URL。
- `type` 固定代码：`book_note`（读书笔记）、`article`（文章）、`travel_note`（旅行记录）。
- 推荐 `PUT`，每篇内容用稳定且不同的 `external_id`（1—128 UTF-8 字节），例如 `reading-agent:get-note-123`。重复调用更新同一篇，完全相同的内容返回 `unchanged:true`；不要为每次重试生成新 ID。
- 新增至少提供 `external_id`、`date`、`type`、`title`。已有输出可以仅提交需更新的字段与 `external_id`；日期是实际完成日期，不能填未来。

```http
PUT /api.php?action=outputs
Authorization: Bearer <API_TOKEN>
Content-Type: application/json
```

```json
{
  "external_id": "reading-agent:get-note-123",
  "date": "2026-10-09",
  "type": "book_note",
  "title": "《书名》读书笔记",
  "note": "可选的补充说明",
  "links": ["https://example.com/note/123"]
}
```

新增返回 HTTP 201 `{output,created:true}`，更新返回 200。`output` 含 ID、日期、类型、标题、备注、链接、external_id、source、manual_lock、revision、archived 和时间戳。

查询：`GET api.php?action=outputs&month=2026-10`，可选 `date=2026-10-09`、`type=book_note`、`include_archived=true`。响应 `{month,access,types,outputs,legacy_outputs}`，默认排除已移除输出；日期/类型筛选仅作用于新输出。`GET month` 同样包含新输出和兼容记录。

手动新增可使用 POST（external_id 可选）。按 ID 修改使用 PATCH，移除使用 DELETE；JSON 中传 `id`，可传 `revision` 检查并发修改。恢复使用 PATCH `{id,revision,archived:false}`。

用户手动编辑默认保护该输出：自动覆盖返回 HTTP 409 `MANUAL_LOCK`。agent 应提示冲突，用户明确授权纠正后才传 `force:true`；该参数仍不能绕过月份锁定与未来日期只读。已移除输出不会被 PUT 自动复活，返回 `OUTPUT_ARCHIVED`，需明确恢复。`REVISION_CONFLICT` 时重新查询。HTTP 403 `MONTH_READ_ONLY` 或 `FUTURE_DATE` 时不要循环重试。不要用 `records` 接口新增按篇输出。

## 兼容、复制、升级

已有标题恰为“输出”、启用追踪且不是计划外的月计划项目仍保存在原 items/records 表中，旧格子在底部输出行显示圈出。点击仍可打开原备注/链接；不猜测旧内容类型，也不改写或删除原记录。月计划显示新输出篇数和旧完成天数。旧备注但未完成的格子明确标为未完成，不计篇数。其他追踪项目保持原方式。

沿用上月计划复制原项目设置，包括原“输出”项目，但不复制完成记录或已经完成的输出文章。输出行独立于月计划，即使当月没有“输出”项目也可以记录。

新增 migration 004 仅创建 outputs 表，不修改现有表和数据。MySQL/SQLite 都支持；PHP 最低仍为 8.0。备份脚本已包含 outputs。更新前使用旧版 backup.php 备份数据库，再覆盖程序文件，首次数据库连接自动迁移。保留 config.php 和 storage，不能用发布包覆盖配置与数据库。回退程序可以暂时隐藏新输出，务必保留 outputs 表及其备份，不要删除。

CSV、JSON 与图片导出都包含新输出；图片包含日期标记和每篇的标题/备注/链接文字。旧记录仍保留于导出数据。
