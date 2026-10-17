# 本月结构化备份（0.8.2 / API1.6）

网页：导出本月 → 本月结构化备份，下载YYYY-MM-月度计划与追踪-结构化备份.json。PNG/CSV保持原用途。JSON导出从服务器读最新数据，不依赖页面上次加载的副本；数据库事务提供一致性快照，不写入或锁定月份，不要求月份可编辑。

API：GET api.php?action=export&month=2026-10。month省略为当前月，认证沿用Bearer或网页登录。返回application/json，Content-Disposition提供下载文件名。登录与用户隔离与其他业务API一致；API发现和说明网页同步提供接口。

文件结构：
- format固定monthly-plan-tracker/month-backup，format_version=1，app_version记录程序版本；exported_at为UTC ISO时间，timezone用于解释当月日期。
- month/categories/calendar保存月份、八方面显示顺序、每日日期及ISO星期（周一1到周日7）、周起点及周末标记。
- rendering保存导出当日日期、实际解析后的item_symbols（项目ID、符号、plain/circled/flower显示方式）、阅读备注显示书名规则和输出圈字类型。默认图标也保存，避免以后默认规则改变影响旧图。
- data.items为本月所有计划及计划外项目，包含id/category/title/note/target/tracked/once_only/unplanned/completed/symbol/sort_order等原字段。依数组顺序或sort_order,id排列。
- data.records包含全部已存每日标注，按item_id及date关联；completed=false、备注、链接、版本与手动保护均保留。没有记录的日期格为空白，不能直接视为明确未完成。
- data.journals包含逐日日记全文、版本、来源、手动保护、修改时间；空内容保留版本；没有日记的日期通过calendar可确定。
- data.outputs为当前输出；archived_outputs单独保留已移除输出用于恢复，重建当前图表时不显示它们；legacy_outputs保留旧输出勾选。output_types冻结类型名称。
- data.month_review保存已存在的月回顾数据库行，未设置时null；data.access仅记录导出时权限参考，恢复时应重新计算。

重建图表：普通tracked且非once_only、非旧“输出”项目逐行显示，计划外在底部；按date关联records。once_only项目合并成每日符号列表，输出按日期/type显示（同一天可多篇）；阅读项目已完成且note非空时显示书名。用journals生成日期日记标记及详情。所有数值和备注都在文件中，不依赖外部页面内容。字体/系统emoji的外观可能因设备不同而不同。

格式版本1为月度数据备份，不是整站SQL备份，不包含密码、Token、会话、其他月份、账号配置或已永久删除项目；不自动抓取外链内容。此次不提供一键导入。以后编写恢复器时应校验format_version、重映射用户/项目ID并同时更新records引用、在事务中写入，避免覆盖已有数据。原基础JSON（无format头）可识别为旧month响应，不应当作新格式直接导入。
