# 当日日记（0.8.0 / API1.5）

点击日期标题打开文本日记，有内容显示小圆点。确定保存写入服务器并返回；返回、×、Esc和点弹窗外时有未保存修改会显示保存并返回/不保存/继续编辑。读取或保存失败不丢弃草稿，版本冲突保留输入；浏览器刷新或关闭使用原生离开提醒（浏览器决定是否展示）。关闭后未保存的草稿不会上传。未来日期、锁定月份及再上个月以前只读，权限由服务器复查。

数据库通用迁移006新增journals，主键(user_id,date)，纯文本content最多约10000字，revision递增，manual_lock保护手动修改，source及updated_at用于同步。空字符串代表清空内容，仍保留版本和审计，避免旧请求重建覆盖。既有项目、记录、输出不改变。scripts/backup.php包含新表；JSON整月导出自动包含journals，图片/CSV仍聚焦追踪表。

GET api.php?action=journals&date=2026-10-15 返回{date,journal:null或对象,access,read_only}。GET api.php?action=journals&month=2026-10返回{month,journals,access}，月份省略为当前月。GET month追加journals列表，兼容既有字段。

PUT api.php?action=journals，JSON为{"date":"2026-10-15","content":"今天的记录。\n第二行。","revision":0}。先GET后写：不存在revision=0，否则使用查询版本。返回201新建/200更新；相同内容重试200且unchanged=true。缺少版本400、版本冲突409 REVISION_CONFLICT（含当前journal）、自动覆盖手动内容409 MANUAL_LOCK，只有明确force:true可越过手动保护；force不能绕过版本/日期锁定。按日期天然去重，无需external_id。不得在409后盲目刷新版本覆盖全文，应合并或交用户确认。

认证沿用Bearer。龙虾自行按月或按日查询、同步Get；本项目不添加Get凭证或自动发布。API发现接口及网页说明均更新。无HTML渲染，日记只进textarea.value或JSON，不执行文本中的HTML。
