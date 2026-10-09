# 阅读显示和桌面安装（0.7.0）

## 阅读

名称以“阅读”开头且属于每日追踪的项目（包括阅读En）使用书名显示规则：已完成且 note 为空显示原图标/勾；已完成且 note 非空显示书名文字。书名直接使用现有备注，不修改历史记录、不新增字段，不自动统计读完本数。未完成的备注仍保留，作为备注标记，不显示为读完书籍。

长按阅读格子可填写书名。输入非空书名时勾选完成，保存前仍可手动取消。已显示的书名单击打开详情，不直接取消完成；可在详情中取消勾选并保存，原书名仍保留。其他项目继续原有打勾与备注操作。窄格子最多显示三行（手机两行），长书名省略，悬停或点击查看完整备注；原行只有需要时增加高度。

原 API 不变，agent 写书名示例：

```json
{"title":"阅读","date":"2026-10-09","completed":true,"note":"《书名》"}
```

使用 `PUT api.php?action=records`，照常遵守日期/月权限和手动保护。不提供 note 时保留原书名，清空书名需明确 `note:""`。API 不从备注推测并修改完成状态。CSV/JSON保留原备注，图片显示书名缩略并在详情列出完整备注。

## 安装

安装入口位于电脑顶部或手机“⋯”菜单，未登录页也提供入口。

- 安卓 Chrome：点“安装到桌面”，浏览器准备好时点击“安装应用”；也可从浏览器菜单选“安装应用/添加到主屏幕”。
- Windows Chrome / Edge：同上，或用地址栏安装图标，Edge 也可从“应用”菜单安装。
- iPhone / iPad Safari：分享 → 添加到主屏幕，若出现“作为网页 App 打开”则保持开启。
- Mac Safari（支持网页 App 的版本）：文件 → 添加到程序坞。
- 内置浏览器或不支持安装的浏览器：说明入口仍可用，请转到系统浏览器。

安装确认由用户在浏览器完成，不自动触发、不请求通知权限。安装提示可能需重新访问或短暂等待；是否弹出由浏览器决定。部分平台可能需要重新登录。实际安卓/Windows/iOS安装需在对应设备浏览器确认；响应式预览不能代替系统安装验证。

参考：[MDN 安装条件](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)、[Apple 主屏幕网页 App](https://support.apple.com/en-lamr/guide/iphone/iphea86e5236/ios)。

## 升级与缓存

仅新增静态程序资源；数据库迁移仍为004。更新包必须包含 manifest.php、manifest.webmanifest、sw.js、pwa.js、tracking-display.js 与3张图标。manifest.php提供正确的application/manifest+json响应，避免改动服务器Nginx MIME配置。页面静态资源查询版本与 Service Worker 的 CACHE/FILES 版本一同更新，首次安装依赖HTTPS。相对路径支持站点根目录及子目录部署。

Service Worker 只缓存明确列出的公开 CSS、JS、图标；用户 HTML、登录响应、API JSON、备注及 Token 都不进入 Cache Storage。离线无法打开/填写正式记录，没有排队上传。恢复联网后重新打开或刷新。旧缓存只清理本应用当前作用域的版本，不删除同域其他应用缓存。图标以既有“月”品牌生成，PNG已提交；开发维护可使用 Pillow 和中文字体运行 scripts/build-icons.py，服务器无需Python。
