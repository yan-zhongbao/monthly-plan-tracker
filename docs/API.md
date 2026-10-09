# OpenClaw API v1.4

新增按篇保存的输出栏目与 `outputs` 接口，读书笔记、文章、旅行记录可同日多篇。接入请求、去重与旧记录兼容见 [OUTPUTS.md](OUTPUTS.md)。

0.7.0 阅读书名继续使用 `records.note`，配合 `completed:true` 显示为书名；无新增API字段。兼容规则见 [READING-PWA.md](READING-PWA.md)。

一次性事件与符号字段详见 [ONCE-EVENTS.md](ONCE-EVENTS.md)。记录写入的日期不能晚于服务器 Asia/Shanghai 的今天，未来日期返回403、`code: FUTURE_DATE`，`force`不能绕过；未来月份计划仍可编辑。已有未来记录可以查询，不会自动删除。

## 月回顾与月份权限

`GET month/plans/projects/records` 均返回 `access`：`current_month`、`previous_month`、`review_completed`、`read_only`、`can_review`、`reason`。按服务器配置时区判断月份；再上个月及更早始终只读。上个月与当前月未标记时可编辑，标记后锁定；未来月份可编辑，暂不能标记回顾。

`PATCH api.php?action=review`，请求体：

```json
{"month":"2026-09","completed":true}
```

响应 `{month,access}`。`completed:false` 取消标记、恢复上个月或当前月编辑。只能操作本用户；标记与追踪表中普通“月回顾”项目独立。龙虾应在明确完成回顾后才调用，解锁应有明确纠正意图。重复设置同一状态不重复审计动作。

只读月的所有项目新增/修改/删除、记录写入、复制目标均返回 HTTP 403：`{error,code:"MONTH_READ_ONLY",access}`。`force:true` 不能绕过月份只读。对更早月或未来月设置/取消回顾返回 403、`code:"REVIEW_NOT_ALLOWED"`。只读月份可查询、导出、作为复制来源。

基础地址：`https://你的域名/api.php`，通过查询参数 `action` 路由，不依赖 Nginx 重写规则。请求与响应均为 UTF-8 JSON。时区固定 Asia/Shanghai。

## 在线说明与接口发现

- 人读/AI 读取的说明网页：`https://你的域名/api-docs.php`。
- 机器可读清单：`GET api.php?action=discovery`；也可 `GET api-docs.php?format=json`。
- 以上说明公开，不需要 Token，也不会返回私人数据。业务接口仍需要鉴权。
- 网页和 JSON 来自同一份 `app/api-spec.php`，包括方法、action、字段类型、必填项、示例、响应与状态码。

给 OpenClaw 一个站点地址和 Token 后，可以先读取 discovery，再按返回的接口说明调用。

## 鉴权

每次调用携带：

```http
Authorization: Bearer <API_TOKEN>
Content-Type: application/json
```

Token 对应配置中的用户，API 不接受调用方指定 `user_id`。不要把 Token 放在 URL。浏览器使用 Session + CSRF；龙虾使用 Bearer Token 不需要 CSRF。

## 查询

| 方法 | URL | 返回 |
| --- | --- | --- |
| GET | `?action=month&month=2026-10` | 月份、时区、全部项目 `items`、全部格子记录 `records` |
| GET | `?action=plans&month=2026-10` | 当月原计划，不含计划外事项 |
| GET | `?action=projects&month=2026-10` | 当月追踪项目，包含计划外事项 |
| GET | `?action=records&month=2026-10` | 当月格子记录 |
| GET | `?action=audit&item_id=1` | 此用户此项目最近 100 条修改日志 |
| GET | `?action=suggestions&month=2026-10&category=成长` | 本月尚未添加的历史项目候选 |

### 历史项目候选

`suggestions` 的 `category` 必填，`month` 可省略。只查询目标月之前、当前用户同一方面的历史项目，标题去重；排除目标月同方面已有的项目（含计划外事项）。按最近使用月份倒序，同月按项目修改时间倒序，再按标题排序。不包含已删除项目。

```json
{
  "month":"2026-10",
  "category":"成长",
  "items":[
    {"title":"英语听力","last_used_month":"2026-09","last_used_at":"2026-09-01T09:00:00+08:00","tracked":true,"target":20},
    {"title":"写作练习","last_used_month":"2026-08","last_used_at":"2026-08-01T09:00:00+08:00","tracked":false,"target":null}
  ]
}
```

选择后通过 `POST action=items` 新建当月项目，建议带 `avoid_duplicate:true`；这会在同一事务内检查本月同方面同名项目，已有时返回 409。候选中的 tracked、target 供调用方参考，网站选择候选仅填名称，不自动带入历史说明、目标或完成状态。

月份可省略，默认服务器配置时区的当前月。格式 YYYY-MM，范围 2000—2099。

月查询示例（为简洁省略部分时间与用户字段）：

```json
{
  "month": "2026-10",
  "timezone": "Asia/Shanghai",
  "items": [
    {"id":1,"month":"2026-10","title":"跑步","category":"健康","note":"","target":16,"tracked":true,"unplanned":false,"completed":false,"sort_order":10}
  ],
  "records": [
    {"id":1,"item_id":1,"date":"2026-10-08","completed":true,"note":"","links":[],"source":"openclaw","manual_lock":false,"revision":1}
  ]
}
```

`items.completed` 用于不进每日表的独立事项；追踪项目的实际完成量请统计 `records` 中同一 `item_id` 且 `completed=true` 的数量。目标单位是完成天数。没有记录的日期表示未标记，不能推断为没做；已有记录 `completed=false` 表示取消勾选或只记备注。

## 标记每日完成

`PUT ?action=records`

```json
{"title":"跑步","date":"2026-10-08","completed":true}
```

优先用查询得到的稳定 ID：

```json
{"item_id":1,"date":"2026-10-08","completed":true}
```

名称匹配只查日期所属月份的追踪项目。同名不唯一或没有对应项目，返回 409，不会自动创建项目。日期必须属于项目月份，并且项目必须在追踪表中。

重复写入同一天同一项目，更新同一条记录，不增加完成次数。每次成功写入递增版本号并留下审计日志。龙虾重试时可先查现状，避免不必要的重复写入。

可选字段：

| 字段 | 说明 |
| --- | --- |
| `completed` | 布尔值；省略时保留已有值，新记录默认 true |
| `note` | 备注；省略保留，空字符串清空，限制约 4000 字符 |
| `links` | http/https 地址数组，最多 10 条；省略保留，空数组清空 |
| `revision` | 乐观锁：须匹配当前版本，新记录传 0；不匹配返回 409 |
| `force` | true 时允许覆盖手动保护的记录；仅在明确要求修正时使用 |

示例：

```json
{
  "item_id":12,
  "date":"2026-10-08",
  "completed":true,
  "note":"读完《悉达多》",
  "links":["https://example.com/notes/123"],
  "revision":0
}
```

成功响应为 `{"record":{...}}`。来源由鉴权方式决定：网页登录为 `manual`，Bearer Token 为 `openclaw`，不能伪造来源。

### 手动修改保护

手动修改默认 `manual_lock=true`，API 再写返回 409 和现有 `record`。龙虾应保留现状并报告冲突；不要常规使用 `force=true` 绕过。

网页详情可以取消保护；网页请求额外支持 `manual_lock` 布尔值，Bearer 请求不能改变该锁。如果龙虾强制修正，仍保留原保护状态，未来自动写入继续被保护。

## 创建 / 编辑月计划

`POST ?action=items` 创建：

```json
{
  "month":"2026-10",
  "title":"得到",
  "category":"成长",
  "tracked":true,
  "target":31,
  "note":"每天读一点",
  "sort_order":20,
  "avoid_duplicate":true
}
```

返回 201 与 `{"item":{...}}`。`month`、`title` 必填；其他默认：category=成长、tracked=false、unplanned=false、completed=false、target=null、note=""、sort_order=0。

`avoid_duplicate` 是创建时的可选布尔值，默认 false 以兼容旧调用；true 时拒绝本月同方面同名项目，适合龙虾重试与历史候选复用。不同方面可有同名项目。

分类须为：财务、社交、自我实现、职业、健康、家庭、成长、娱乐。目标为 1—366 整数或 null，不是公里数。`title` 限制约 120 字符；`sort_order` 整数，越小越靠前。追踪表按此排序连续显示，计划外事项放在底部；月计划页按方面组织。

独立事项：`tracked=false`，例如旅行；打勾通过 PATCH 修改 `completed`：

```json
{"id":5,"completed":true}
```

`PATCH ?action=items` 更新，必须提供 `id`，其余字段只传需要修改的。不能修改月份。有每日记录的项目不能移出追踪表。

`DELETE ?action=items` 请求 `{"id":5}`：删除项目及其每日记录，保留删除前的审计快照。不可在网页中恢复，应谨慎调用。

## 计划外事项

先创建：

```json
{"month":"2026-10","title":"临时见朋友","category":"社交","unplanned":true}
```

系统会强制 tracked=true、target=null。再用返回的 `item.id` 写记录：

```json
{"item_id":21,"date":"2026-10-08","completed":true,"note":"和小王一起吃饭"}
```

创建项目的 POST 不幂等，重试前先查询，以免生成重复项目。记录的 PUT 幂等，不会新增重复格子。

## 沿用计划

`POST ?action=copy`

```json
{"from":"2026-10","to":"2026-11"}
```

仅允许目标月份完全空白。复制原月计划的名称、分类、说明、目标与是否追踪，清空独立事项完成状态，不复制每日记录和计划外事项。目标已有项目返回 409。

## 错误与限制

| 状态码 | 含义 |
| --- | --- |
| 400 | 参数无效、日期不属于月份、链接协议不支持 |
| 401 | 没有有效登录或 Token |
| 403 | 网页 CSRF 无效 |
| 404 | 项目不存在或不属于当前用户 |
| 405 | 未支持的接口/方法组合 |
| 409 | 版本冲突、手动保护、同名项目或复制目标不为空 |
| 413 | JSON 请求超过 64 KiB |
| 500 | 服务器错误，检查日志 |

格式：`{"error":"可读错误说明"}`。调用方应检查 HTTP 状态，成功后才确认已记录。当前不支持批量请求及 CORS 浏览器跨站访问。

## 龙虾执行约定

1. 先查 `action=month` 或 `action=projects`，确定日期、月份与项目 ID。
2. 只写明确发生的完成事实；“明天准备跑步”不能标记今天完成。
3. 有手动保护时保持现有记录，必要时询问用户。
4. 不承担公里数计算，不自动把所有月计划变成追踪项目。
5. 月末查询完整月数据，自行生成或获取图片/表格，然后存到 Get。网页提供手动图片、CSV、JSON 导出；API 本身返回结构化 JSON，不生成图片文件。
