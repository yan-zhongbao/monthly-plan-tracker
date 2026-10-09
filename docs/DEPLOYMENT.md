# 宝塔部署与维护

0.4.0 新部署推荐使用 MySQL 与网页初始化，详见 [MySQL 部署](MYSQL.md)。以下 SQLite 说明继续适用于已有 SQLite 用户。

## 环境与站点目录

- 应用兼容 PHP 8.0 或以上；启用 `PDO`、`pdo_sqlite`，以及通常默认启用的 `session`、`json`、`filter`。建议后续使用仍受官方维护的 PHP 版本。
- PHP-FPM 和命令行 PHP 都要启用 `pdo_sqlite`。宝塔可能为二者使用不同配置。
- SQLite 数据文件应位于本地磁盘，不放在 NAS/NFS 共享目录。
- SQLite 库需支持 `VACUUM INTO`（3.27+），用于一致性备份。

例如上传部署包到：

```text
/www/wwwroot/month-tracker/
  app/
  docs/
  migrations/
  public/
  scripts/
  storage/
  config.example.php
```

宝塔建立 PHP 网站，网站根目录选择 `/www/wwwroot/month-tracker`，**运行目录设置为 `/public`**，默认首页为 `index.php`，选择相应 PHP 版本。该项目不需要伪静态规则。

浏览器只能访问 `public/`。不要把整个项目根目录设为实际 Web 运行目录，否则数据库、配置和备份可能暴露。站点也可部署在子路径，前端全部使用相对地址。

启用 HTTPS。站点不需要开放跨域 API，OpenClaw 从服务端直接发 HTTP 请求即可。

## 初始化

在宝塔终端进入项目根目录，按实际 PHP 版本执行，例如：

```sh
cd /www/wwwroot/month-tracker
/www/server/php/84/bin/php scripts/setup.php
```

程序询问网页登录密码，至少 12 个字符。终端会显示输入，请在私人终端操作。初始化生成 `config.php` 与空数据库；随机 API Token 只显示一次，复制到 OpenClaw 私密配置中。

若使用宝塔 PHP 8.0，上面的 PHP 命令替换为 `/www/server/php/80/bin/php scripts/setup.php`，备份命令同样使用 `/www/server/php/80/bin/php`。CLI 和 FPM 都需启用 pdo_sqlite；运行目录仍是 `/public`。

程序不会覆盖已有 `config.php`。密码只保存哈希，Token 只保存 SHA-256 哈希，原始 Token 不在数据库或配置中。不要把 Token 写入网页代码、URL、Git 或普通日志。

运行目录配置完成后，在浏览器登录，新建月计划。无需 npm、Composer 或额外任务调度。

### 文件权限

PHP-FPM 用户通常是 `www`，可在宝塔文件管理设置：

- `storage/`：所有者为 PHP-FPM 用户，可写，权限建议 700。
- `config.php`：PHP-FPM 用户可读，权限建议 600。
- 代码目录：PHP-FPM 用户可读，不需要写权限。

如果终端以 root 运行初始化，需要调整 `config.php`、`storage/` 及其文件的所有者，否则网页登录可能无法读取配置或写数据库。不要用 777 解决权限问题。

## 给 OpenClaw 的信息

站点根地址，例如 `https://tracker.example.com/`；API 为同一地址下的 `api.php`。传 `Authorization: Bearer <API_TOKEN>`。

将 `docs/API.md` 发给龙虾。先查询当月项目，再按 `item_id` 或唯一项目名称写入。计划外事项须先创建，再记录完成。月度图片可以手动导出，或由龙虾查询月数据后自行生成、存入 Get。

也可以只给龙虾本站 `api-docs.php` 或 `api.php?action=discovery` 地址，让它直接读取最新接口说明。历史计划复用先查 suggestions，再 POST items，建议 `avoid_duplicate=true`。

### 从 0.1.x 升级到 0.2.0

备份后覆盖 `app/`、`public/` 与文档，保留 `config.php`、`storage/`。此次无需新数据库迁移，旧 API 调用继续有效。浏览器刷新页面以载入新资源。

## 备份

### 升级到 0.3.0

先用旧版备份工具备份数据库，并另行备份 `config.php`。短暂停止 OpenClaw 写入后，覆盖 `app/`、`public/`、`migrations/`、`scripts/` 与文档；保留自己的 `config.php` 和整个 `storage/`。首次访问数据库自动执行迁移 002，新增 `month_reviews` 表，原计划及记录保留。刷新浏览器后检查上个月可编辑、勾选锁定、取消解锁及更早月只读，再恢复自动填写。

新表默认没有完成标记；普通追踪项目中的旧“月回顾”勾选不会自动转换为锁定。此次新增字段兼容查询，但写入旧月会按新规则返回 403。需要回退时恢复升级前的一致性数据库备份和对应源码，避免只删除迁移文件。

在宝塔计划任务中，每天运行：

```sh
cd /www/wwwroot/month-tracker
/www/server/php/84/bin/php scripts/backup.php
```

默认在 `storage/` 生成时间戳备份；也可指定新的绝对文件路径：

```sh
/www/server/php/84/bin/php scripts/backup.php /your/private-backups/month-tracker-20261008.sqlite
```

父目录应事先创建且 PHP 用户可写。工具使用 `VACUUM INTO` 产生一致快照，不应在站点正在写入时直接复制活跃数据库。备份文件包含完整个人记录与修改日志，应保存到私有存储。另行备份 `config.php`；图片和月度 JSON 是月快照，不代替完整数据库备份。

### 恢复 / 换服务器

1. 在新服务器准备同样的 PHP 扩展及 `public/` 运行目录，暂时关闭站点写入。
2. 上传源码、数据库备份和私密配置。
3. 在 `config.php` 中将 `database` 改为新服务器数据库文件的绝对路径。
4. 检查所有者与权限，启用站点；数据库迁移会自动运行。
5. 登录并确认历史月份、备注与 API 查询正确，再启用龙虾同步。

恢复会替换到备份时的状态；恢复前对现有数据另做一份一致性备份。

## 更新密码与 Token

当前没有网页设置页面。维护人员可用命令行 PHP 的 `password_hash()` 生成新密码哈希，替换配置中对应值；Token 应用 `random_bytes(32)` 生成新随机值，把其 `hash('sha256', $token)` 写入配置，将原值安全交给 OpenClaw。修改前备份配置，勿输出到公网日志。数据库数据不受影响。

## 排错

| 现象 | 检查 |
| --- | --- |
| 尚未初始化 / 503 | 是否存在项目根目录的 `config.php`，FPM 是否有读取权限 |
| `could not find driver` | FPM 或 CLI 的 `pdo_sqlite` 没启用 |
| `unable to open database` | 数据库路径、storage 所有者和目录写权限 |
| API 401 | Token 是否正确；Nginx/FastCGI 是否传递 Authorization 请求头 |
| API 409 | 手动修正保护、版本冲突、同名项目或复制到已有月份，详见返回信息 |
| 频繁要求登录 | HTTPS/Session 配置，反向代理时请正确传递 HTTPS 状态 |
| 15 分钟不能登录 | 同一 IP 失败 8 次触发临时限制 |

API 的 500 错误只给出通用信息，详情看 PHP 错误日志。生产环境关闭 `display_errors`、开启 `log_errors`。浏览器登录会话未设置持久“记住我”，连续 7 天未使用会话会失效；会话文件保存在数据库同目录的私有 `sessions/` 下，避免被其他 PHP 应用的会话清理影响。
