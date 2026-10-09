# MySQL 部署、升级与迁移

0.4.0 新增 MySQL 5.7+ / MariaDB 10.2+ 支持，继续支持已有 SQLite 配置。PHP 8.0+ 需要 pdo_mysql、session、json、filter；不需要升级 SQLite。所有业务表使用 InnoDB 与 utf8mb4。

## 首次部署到宝塔

1. 在宝塔创建专用数据库，例如 month_tracker；选择 utf8mb4、仅本地服务器访问，并记录对应用户名和密码。应用不能使用 MySQL root 账号。
2. 上传并解压发布包到 `/www/wwwroot/month.btcsoft.net`，网站运行目录设为 `/public`，PHP 版本选择 8.0 或以上，开启 HTTPS。
3. 项目目录须允许 PHP-FPM 的 www 用户创建 config.php。storage 归 www 所有、权限 700。
4. 在宝塔终端运行：

```sh
/www/server/php/80/bin/php /www/wwwroot/month.btcsoft.net/scripts/prepare-web-setup.php
chown www:www /www/wwwroot/month.btcsoft.net/storage/setup.key
```

5. 在宝塔文件管理中私下打开 `storage/setup.key`，复制初始化码。访问 `https://month.btcsoft.net/setup.php`，填写初始化码、数据库连接信息及两次网站登录密码，提交。
6. 初始化自动测试 MySQL 连接、执行建表、保存 config.php，展示一次性 API Token。请立即保存 Token 到 OpenClaw 私密配置，不要发到聊天或写入普通日志。
7. config.php 已存在时，setup.php 返回 403，不允许修改或重置配置；初始化码会被删除。数据库配置以后只能由服务器维护者修改私有 config.php。

初始化码不是数据库密码。网页初始化仅允许 HTTPS 和本机数据库；使用 POST 与 CSRF 校验，数据库错误不给出密码、DSN 或堆栈。输入失败后密码字段不会回填。

连接或建表失败时，可以打开 `database-check.php`，使用初始化码与已有数据库凭证独立检测。它不设置网站登录密码，不创建 config.php，给出失败阶段及 MySQL 驱动错误码。初始化完成后此入口自动关闭。1045 表示账号校验失败，1064 表示 SQL 语法不兼容，不能把所有错误都归为密码问题。

## 事务与升级

MySQL 迁移位于 migrations/mysql；旧 SQLite 迁移文件保持原样。MySQL DDL 隐式提交，建表迁移可重复执行，并以数据库命名锁防止并发初始化。应用所有业务写事务先锁定该用户的 user_write_locks 行，因此创建项目、每日记录与月回顾关闭/解锁互相串行；不同用户独立。

升级时先备份数据库及 config.php，停止 OpenClaw 写入，覆盖 app、public、scripts、migrations 和文档，保留 config.php 与 storage。相同后端的数据库迁移自动执行。

## 备份与恢复

```sh
cd /www/wwwroot/month.btcsoft.net
/www/server/php/80/bin/php scripts/backup.php
```

MySQL 配置会生成 storage/backup-时间.sql。备份在 InnoDB REPEATABLE READ 事务中读取一致快照，包含建表语句、业务记录、月回顾、修改日志与迁移版本，不包含 config.php 中的数据库密码或 API Token。备份仍包含私人记录，存放于 Web 根目录之外。备份期间不要执行数据库结构升级。

恢复时创建一个新的空数据库，在宝塔导入 SQL，修改私有 config.php 的连接信息，再检查历史计划、记录、权限和接口。不直接向非空生产库导入此备份，不包含 DROP TABLE 语句。另行备份 config.php。

## 已有 SQLite 数据迁移

本次服务器尚未初始化，没有需要迁移的个人记录。已有 SQLite 用户不能直接把 database 字段改成 MySQL 后就认为历史数据已迁移：应先导出备份，在空 MySQL 库建表，再按原 ID 复制 items、records、month_reviews、audit_log 等数据，保留用户 ID 与哈希配置，核对各表行数、外键、记录内容与 API 结果后切换。0.4.0 尚未提供自动跨后端迁移工具。
