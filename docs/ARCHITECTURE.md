# 架构、数据库与迁移

## 选择

首版用 SQLite：一个用户、短记录、低写入并发，单文件部署与备份简单。数据不会存在浏览器 localStorage；PHP API 和网页操作写同一数据库。静态资源本地提供，不依赖外部 CDN。

预留多用户不是只有增加字段：当前所有查询/修改已经限定 Token 或登录对应的用户，测试覆盖跨用户读取和写入隔离。用户配置在 `config.php` 中，若增设账户，需要新的密码哈希、Token 哈希与独立整数 ID。前端支持多账户选择，但没有注册与用户管理页面。

## 结构

| 表 | 作用与约束 |
| --- | --- |
| `schema_migrations` | 已应用迁移编号与时间 |
| `month_reviews` | 唯一键 (user_id,month)，存回顾完成状态、来源、修改时间；迁移 002 新增 |
| `items` | 每月计划/追踪/计划外项目；含 user_id、month、分类、标题、说明、target、tracked、unplanned、completed、排序、时间 |
| `records` | 每格一条；唯一键 (user_id,item_id,date)，含完成状态、备注、JSON 链接、来源、人工保护、revision |
| `audit_log` | 修改动作、项目 ID、来源、前后 JSON 快照与时间；删除后仍保留 |
| `login_attempts` | 登录 IP SHA-256 与短期限流计数 |

数据库外键由每次 PDO 连接启用。应用验证 item 和 record 属于同一用户、月份，禁止非法日期。所有 SQL 参数使用预处理语句。时间戳存带时区 ISO 8601 文本；日期与月份存定长文本，不根据 UTC 日期猜测用户哪天运动。

月项目 ID 不跨月复用，复制计划生成新 ID。OpenClaw 不应沿用上个月的 item_id，而应按月查询。标题不设唯一键；通过名字写记录只有匹配唯一追踪项目时才成功。

实际完成量由 records 的 completed=true 个数统计；不是“备注数”或 AI 估计值。`items.completed` 对每日追踪项目没有统计意义，只用于独立事项。

### 写入一致性

迁移 002 增加独立 month_reviews；`month_access()` 统一计算上个月/当前月的回顾锁定和更早月份强制只读。计划、格子、复制目标的写入在 `BEGIN IMMEDIATE` 事务内重新验证权限；review 同样在写事务中更新状态并记录审计，避免并发封存与补录交叉。回顾开关不由普通项目完成率推算。月份越界/滚动后的旧页面仍会被服务端拒绝，force 仅用于单条手动保护，不影响月份权限。

格子更新使用 `BEGIN IMMEDIATE`，读取当前版本/手动保护后更新，记录及审计在同一事务中提交。重复写入唯一格子不会产生多条记录。网页提供 revision 乐观锁，调用方可选择传版本号；自动来源不能伪装为手动来源。

Item 编辑与删除采用事务；月计划复制使用写事务，防止检查空白后同时复制。数据库使用默认 journal 模式和 5 秒 busy_timeout；暂未使用 WAL，便于低并发单文件运维。

## 代码分工

- `app/bootstrap.php`：配置、连接、迁移、用户认证、参数校验、数据归一化、审计。
- `public/api.php`：HTTP action 分发与月计划/格子事务。
- `public/index.php`：登录、会话、页面 HTML。
- `public/assets/app.js`：交互、按月取数、格子编辑、图片/CSV/JSON 导出。
- `public/assets/app.css`：桌面和手机响应式笔记本布局。
- `app/api-spec.php`：共享接口元数据，生成公开说明网页与 discovery JSON；不包含个人数据。
- `public/api-docs.php`：公开 API 说明网页，也支持 `format=json`。

历史候选按月份、更新时间与 ID 查询排序，在 PHP 中按标题去重，并排除本月同方面已有标题，查询始终限定当前用户。SQLite 备份要求 3.27+；新部署可使用 MySQL，不依赖 SQLite 库。新增项目可传 `avoid_duplicate=true`，在写事务内检查重复，旧调用默认维持兼容。

用户数据仅插入经过转义的 HTML/SVG，链接只接受 http/https。网页采用 CSP 与同源资源；Token 不下发到前端。网页会话与 API Token 是两个独立入口。

## 升级流程

1. 维护窗口中执行一致性备份，并保存旧版源码与配置。
2. 新版本只增加 `migrations/002_*.sql` 等递增迁移；不要修改已发布的 001 内容。
3. 上传新代码与迁移，保留 `config.php`、`storage/`，不上传本地 tests/.tools。
4. 首次 API/数据访问自动按编号应用新迁移。SQLite 迁移使用事务；MySQL DDL 隐式提交，因此使用可重试建表与命名锁。
5. 验证登录、历史月份、记录写入、备注链接和 OpenClaw 调用。

失败时暂停写入、恢复旧代码，并根据迁移兼容性恢复升级前数据库。不能只回退 PHP 文件而保留不兼容的新数据库。每个版本应明确是否需要数据库回退。

## MySQL 实现与跨数据库迁移

0.4.0 已实现 PDO MySQL 驱动、独立迁移文件、InnoDB 表、utf8mb4 内容、每用户写事务行锁、兼容 upsert 与一致快照 SQL 备份。相同用户的所有写操作先锁定 user_write_locks 行，保证不存在格子的并发更新及月回顾锁定不会竞态；不同用户可以独立写入。

新部署使用网页首次配置。原 SQLite 配置仍然有效，升级不会自动迁移或删除旧数据库。跨数据库的历史迁移需要专门导入与核对，尚未提供自动迁移脚本；详见 MYSQL.md。不能只修改连接配置就视为数据迁移完成。月度 JSON 不包含全部审计与用户配置，不替代完整数据库备份。
