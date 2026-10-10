# 月度计划与追踪

一个自托管的个人月计划与打勾网站。PHP + MySQL（兼容 SQLite）+ 原生 HTML/CSS/JavaScript，无 Composer、npm 构建和 CDN 依赖。部署时只需上传源码、启用扩展、生成配置。

仓库仅包含程序、配置示例、迁移、测试和文档。私人配置、真实数据、部署凭证、个人截图与服务器操作记录留在本地，不纳入 Git。

0.7.3 支持 PHP 8.0+ 与 MySQL，提供网页首次初始化；已有 SQLite 配置继续可用。MySQL 部署见 docs/MYSQL.md。一次性事件与符号使用和升级见 [docs/ONCE-EVENTS.md](docs/ONCE-EVENTS.md)。输出栏目与龙虾接入见 [docs/OUTPUTS.md](docs/OUTPUTS.md)。

手机顶部收为一行，辅助功能在“⋯”菜单；布局说明见 [docs/MOBILE.md](docs/MOBILE.md)。

阅读格子可显示书名，网站可安装到安卓/Windows桌面；iPhone/iPad支持添加到主屏幕。使用和兼容规则见 [docs/READING-PWA.md](docs/READING-PWA.md)。

## 已实现

- 八个方面的月计划，可单独标记一次性事项完成；每个方面允许留白。
- 月计划按成长、健康、家庭等常用方面优先排列；先选方面，再从最近历史项目中选名称或直接新增。
- 选择哪些项目加入每日追踪；可设置目标完成天数，也可不设目标。
- 电脑端以铺满可用宽度的笔记本月表显示，日期按星期分隔。
- 手机端纵向列出项目，左侧名称固定，默认显示今天及前后约三天视野，横向滑动日期。
- 点格子打勾 / 取消；长按、右键或 Shift+点击打开备注和链接。键盘聚焦格子后按 F2 也能打开。
- 计划外记录放在表格底部，允许在多天继续打勾。
- 空白月份可沿用上月计划，不复制完成状态和计划外事项。
- 导出月表 PNG、CSV、完整月度 JSON；支持浏览器打印 / 保存 PDF。
- OpenClaw 可使用 Bearer Token 查询和操作；手动修正保护、版本冲突检查、修改日志。
- 公开 API 说明网页 `api-docs.php`，机器可读接口清单 `api.php?action=discovery`，历史候选查询 `action=suggestions`。
- 登录、CSRF 保护、登录失败限流，以及每张业务表中的 `user_id` 数据隔离。

周反思和月回顾仍可作为普通追踪项目。表格上方另设“已完成月回顾总结”开关，控制整月锁定：上个月和当前月可勾选锁定、取消解锁；再上个月及更早始终只读。未来月份可编辑，不能提前标记回顾。网站不编写正文，不直接接入佳明、Get 或学习平台；采集、归档及定时动作由 OpenClaw 负责。

## 从这里开始

1. 阅读 [宝塔部署指南](docs/DEPLOYMENT.md)，设置站点运行目录为 `public/`。
2. 在服务器项目根目录运行 `php scripts/setup.php`，设置密码并保存生成的 API Token。
3. 打开网站，新增本月计划；将需要每日打勾的项目加入追踪表。
4. 将 [OpenClaw 接口文档](docs/API.md) 与站点地址交给龙虾。

## 文档

- [产品约定与交互](docs/PRODUCT.md)
- [宝塔部署、备份与恢复](docs/DEPLOYMENT.md)
- [API 接口与调用示例](docs/API.md)
- [数据结构、升级与 MySQL 迁移](docs/ARCHITECTURE.md)
- [验证结果与复测方法](docs/TESTING.md)
- [版本变更](CHANGELOG.md)

## 目录

```text
public/       网站运行目录：页面、API、前端资源
app/          PHP 数据访问、校验、认证
migrations/   按编号执行的数据库迁移
scripts/      初始化与在线一致性备份工具（仅 CLI）
storage/      SQLite 数据库和备份（不对外提供）
docs/         部署、接口与维护文档
tests/        隔离的测试与预览样例，部署包不包含
config.php    本机/服务器私密配置，初始化生成，部署包不包含
```

正式站点初始没有任何预置项目或历史记录。文件夹里的照片作为需求参考保留，不会被导入为正式数据。

登录与排序：[功能及迁移说明](docs/LOGIN-SORTING.md)。
