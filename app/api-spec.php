<?php
declare(strict_types=1);
// Single source for the public API reference page and machine-readable discovery.
function api_spec(): array {
    $month = ['month'=>['type'=>'string','required'=>false,'description'=>'YYYY-MM，2000—2099；省略时为 Asia/Shanghai 当前月。']];
    $itemFields = [
        'month'=>['type'=>'string','required'=>true,'description'=>'项目月份 YYYY-MM。'],
        'title'=>['type'=>'string','required'=>true,'description'=>'项目名称，约 120 字符。'],
        'category'=>['type'=>'string','required'=>false,'description'=>'八个方面之一，默认成长。'],
        'tracked'=>['type'=>'boolean','required'=>false,'description'=>'是否加入每日追踪；默认 false。'],
        'once_only'=>['type'=>'boolean','required'=>false,'description'=>'一次性事件，默认 false；true 时 tracked=true、target=null，合并显示在底部，每月只能在一个日期完成。'],
        'symbol'=>['type'=>'string','required'=>false,'description'=>'显示符号，最多 32 UTF-8 字节；空字符串按名称自动选择。'],
        'unplanned'=>['type'=>'boolean','required'=>false,'description'=>'是否计划外；默认 false，true 时强制 tracked=true、target=null。'],
        'target'=>['type'=>'integer|null','required'=>false,'description'=>'目标完成天数 1—366 或 null；默认 null。'],
        'note'=>['type'=>'string','required'=>false,'description'=>'计划说明，约 4000 字符；默认空。'],
        'completed'=>['type'=>'boolean','required'=>false,'description'=>'独立月计划的完成状态，默认 false。'],
        'sort_order'=>['type'=>'integer','required'=>false,'description'=>'排序，-100000 到 100000，默认 0。'],
        'avoid_duplicate'=>['type'=>'boolean','required'=>false,'description'=>'创建时建议 true；本月同方面已有同名项目返回 409。默认 false，兼容原版。'],
    ];
    $patchFields = $itemFields;
    unset($patchFields['month'],$patchFields['avoid_duplicate']);
    foreach ($patchFields as &$field) { $field['required']=false; } unset($field);
    $patchFields = ['id'=>['type'=>'integer','required'=>true,'description'=>'项目 ID，须属于当前用户。']] + $patchFields;
    $outputFields=[
        'date'=>['type'=>'string','required'=>true,'description'=>'YYYY-MM-DD；不晚于今天，月份须可编辑。'],
        'type'=>['type'=>'string','required'=>true,'description'=>'book_note 读书笔记、article 文章、travel_note 旅行记录。'],
        'title'=>['type'=>'string','required'=>true,'description'=>'每篇内容的标题。'],
        'note'=>['type'=>'string','required'=>false,'description'=>'备注；省略保留已有内容。'],
        'links'=>['type'=>'array<string>','required'=>false,'description'=>'最多10条完整http/https链接；省略保留，[]清空。'],
        'external_id'=>['type'=>'string','required'=>false,'description'=>'1—128个UTF-8字节，当前用户唯一。推荐 agent 名称加原笔记ID；PUT必填。同一个ID重复写入不会新增。创建后不可修改。'],
        'revision'=>['type'=>'integer','required'=>false,'description'=>'乐观锁版本，PATCH建议传查询所得版本，冲突409。'],
        'force'=>['type'=>'boolean','required'=>false,'description'=>'仅明确修正意图时覆盖手动保护；无法绕过未来日期或月份锁定。'],
    ];
    $outputPatch=$outputFields; foreach($outputPatch as &$field){$field['required']=false;}unset($field);
    $outputPatch=['id'=>['type'=>'integer','required'=>true,'description'=>'输出ID（独立于项目ID）。']]+$outputPatch;
    $outputPatch['archived']=['type'=>'boolean','required'=>false,'description'=>'false恢复已移除输出。'];
    return [
        'name'=>'月度计划与追踪 API','version'=>'1.4','app_version'=>'0.7.1',
        'base_path'=>'api.php','documentation_path'=>'api-docs.php',
        'authentication'=>[
            'type'=>'Bearer','header'=>'Authorization: Bearer <API_TOKEN>',
            'description'=>'初始化时生成的私密 Token。业务接口需要鉴权；discovery 和说明网页公开，仅提供接口元数据。浏览器另用 Session + CSRF。',
        ],
        'timezone'=>'Asia/Shanghai','categories'=>categories(),
        'workflow'=>[
            '查询响应 access 给出月份编辑权限：再上个月及更早始终只读；上个月和当前月完成月回顾后锁定，可取消标记解锁。未来月份可编辑，不能提前标记月回顾。',
            '先 GET api.php?action=discovery 获取接口说明。',
            '按月查询 plans/projects/month；新增计划前可按方面查询 suggestions。',
            '历史候选只包含目标月之前的月份，去掉本月同方面已有标题；按最近使用月份、修改时间倒序。',
            '使用 POST items 添加计划，建议 avoid_duplicate=true。历史候选不自动复制备注；可按需要使用返回的 tracked/target。',
            '用 PUT records 标记明确发生的事实；优先 item_id，不把未来计划标记为完成。',
            '遇到 409 手动保护或版本冲突时保留现有内容，不常规使用 force=true。',
            '每篇输出使用 PUT outputs + external_id，例如 reading-agent:note-123；同日同类型可有多篇。先查询后纠正，不使用每日 records 来替代文章记录。',
        ],
        'endpoints'=>[
            ['action'=>'outputs','method'=>'GET','auth'=>true,'description'=>'查询当月输出。旧输出勾选单独返回legacy_outputs，不猜测类型或标题。','query'=>$month+['date'=>['type'=>'string','required'=>false,'description'=>'按日期筛选新输出。'],'type'=>['type'=>'string','required'=>false,'description'=>'按输出类型筛选新输出。'],'include_archived'=>['type'=>'string','required'=>false,'description'=>'true包含已移除输出，默认不包含。']],'response'=>'{month,access,types,outputs,legacy_outputs}'],
            ['action'=>'outputs','method'=>'PUT','auth'=>true,'description'=>'按external_id创建或更新一篇输出。相同内容重试不重复新增、不递增版本。已移除内容不会自动复活。','body'=>array_replace($outputFields,['external_id'=>array_replace($outputFields['external_id'],['required'=>true])]),'response'=>'201 {output,created:true} 或200 {output,created:false,unchanged?}；date/type/title更新已存在记录时可省略。','example_body'=>['external_id'=>'reading-agent:note-123','date'=>'2026-10-08','type'=>'book_note','title'=>'《示例书》读书笔记','links'=>['https://example.com/note/123']]],
            ['action'=>'outputs','method'=>'POST','auth'=>true,'description'=>'手动新增一篇输出。建议自动agent使用PUT和稳定external_id。POST未提供external_id时每次新增一篇。','body'=>$outputFields,'response'=>'{output,created}'],
            ['action'=>'outputs','method'=>'PATCH','auth'=>true,'description'=>'按ID修改输出或恢复已移除记录；仅传变动字段。跨月改日期需要源月与目标月均可编辑。','body'=>$outputPatch,'response'=>'{output,created:false}'],
            ['action'=>'outputs','method'=>'DELETE','auth'=>true,'description'=>'按ID移除输出，保留数据并允许PATCH archived:false恢复。','body'=>['id'=>['type'=>'integer','required'=>true,'description'=>'输出ID。'],'revision'=>['type'=>'integer','required'=>false,'description'=>'当前版本。'],'force'=>['type'=>'boolean','required'=>false,'description'=>'自动agent移除手动保护内容也需明确force意图。']],'response'=>'{output: archived=true,created:false}'],
            ['action'=>'review','method'=>'PATCH','auth'=>true,'description'=>'设置或取消上个月/当前月的月回顾标记。完成后锁定整月；取消后恢复编辑。独立于追踪表中的月回顾项目。','body'=>[
                'month'=>['type'=>'string','required'=>true,'description'=>'YYYY-MM，只允许上个月或当前月。'],
                'completed'=>['type'=>'boolean','required'=>true,'description'=>'true 锁定，false 重新开放。'],
            ],'response'=>'{month, access: {current_month,previous_month,review_completed,read_only,can_review,reason}}','example_body'=>['month'=>'2026-09','completed'=>true]],
            ['action'=>'discovery','method'=>'GET','auth'=>false,'description'=>'机器可读的完整接口清单（本 JSON）。','query'=>[],'response'=>'本接口说明对象。'],
            ['action'=>'month','method'=>'GET','auth'=>true,'description'=>'完整月计划与追踪表。','query'=>$month,'response'=>'{month,timezone,access,items,records,outputs,output_types,legacy_outputs}'],
            ['action'=>'plans','method'=>'GET','auth'=>true,'description'=>'原月计划，不含计划外事项。','query'=>$month,'response'=>'{month, access, items}'],
            ['action'=>'projects','method'=>'GET','auth'=>true,'description'=>'追踪项目，包括计划外事项。','query'=>$month,'response'=>'{month, access, items}'],
            ['action'=>'records','method'=>'GET','auth'=>true,'description'=>'当月每日格子记录。','query'=>$month,'response'=>'{month, access, records}'],
            ['action'=>'suggestions','method'=>'GET','auth'=>true,'description'=>'某方面可复用的历史项目，排除目标月同方面已有项目。','query'=>$month+['category'=>['type'=>'string','required'=>true,'description'=>'所属方面，参见 categories。']],
                'response'=>'{month, category, items: [{title,last_used_month,last_used_at,tracked,target}]}',
                'example_query'=>['month'=>'2026-10','category'=>'成长']],
            ['action'=>'audit','method'=>'GET','auth'=>true,'description'=>'项目最近 100 条修改日志。','query'=>['item_id'=>['type'=>'integer','required'=>true,'description'=>'项目 ID。']],'response'=>'{entries}'],
            ['action'=>'items','method'=>'POST','auth'=>true,'description'=>'创建月计划或计划外项目。','body'=>$itemFields,'response'=>'201 {item}；POST 不幂等，重试前查询。',
                'example_body'=>['month'=>'2026-10','title'=>'得到','category'=>'成长','tracked'=>true,'target'=>31,'avoid_duplicate'=>true]],
            ['action'=>'items','method'=>'PATCH','auth'=>true,'description'=>'修改项目，仅传需要改变的字段；不能移动月份。有记录的项目不能移出追踪表。','body'=>$patchFields,'response'=>'{item}',
                'example_body'=>['id'=>5,'completed'=>true]],
            ['action'=>'move','method'=>'PATCH','auth'=>true,'description'=>'将每日追踪项目在同一方面内上移或下移一位。保存后月计划、追踪及导出同步排序，沿用计划保留顺序。锁定月份只读。','body'=>['id'=>['type'=>'integer','required'=>true,'description'=>'项目ID。'],'direction'=>['type'=>'string','required'=>true,'description'=>'up 或 down。']],'response'=>'完整 month 数据；边界不变。'],
            ['action'=>'items','method'=>'DELETE','auth'=>true,'description'=>'删除项目及每日记录；保留审计快照。网页不能撤销，谨慎调用。','body'=>['id'=>['type'=>'integer','required'=>true,'description'=>'项目 ID。']],'response'=>'{deleted:true}'],
            ['action'=>'records','method'=>'PUT','auth'=>true,'description'=>'更新同一格子，不产生重复完成次数。日期须属于项目月份，且不能晚于服务器今天；未来日期返回403、FUTURE_DATE。','body'=>[
                'item_id'=>['type'=>'integer','required'=>false,'description'=>'优先使用项目 ID；item_id 与 title 至少提供一个。'],
                'title'=>['type'=>'string','required'=>false,'description'=>'当月唯一的追踪项目名称；同名不唯一返回 409。'],
                'date'=>['type'=>'string','required'=>true,'description'=>'有效 YYYY-MM-DD，Asia/Shanghai。'],
                'completed'=>['type'=>'boolean','required'=>false,'description'=>'省略保留已有状态，新记录默认 true。'],
                'note'=>['type'=>'string','required'=>false,'description'=>'格子备注；省略保留，空字符串清空。'],
                'links'=>['type'=>'array<string>','required'=>false,'description'=>'最多 10 条 http/https 链接；省略保留，[] 清空。'],
                'revision'=>['type'=>'integer','required'=>false,'description'=>'当前版本号，新格子传 0；冲突返回 409。'],
                'force'=>['type'=>'boolean','required'=>false,'description'=>'true 可覆盖用户手动保护；须有明确修正意图。'],
            ],'response'=>'{record}；重复 PUT 不新增格子，但递增 revision。',
                'example_body'=>['title'=>'跑步','date'=>'2026-10-08','completed'=>true]],
            ['action'=>'copy','method'=>'POST','auth'=>true,'description'=>'沿用另一月份的原计划；目标月份必须空白，不复制勾选和计划外事项。','body'=>[
                'from'=>['type'=>'string','required'=>true,'description'=>'源月份 YYYY-MM。'],
                'to'=>['type'=>'string','required'=>true,'description'=>'目标月份 YYYY-MM。'],
            ],'response'=>'完整目标月数据。','example_body'=>['from'=>'2026-10','to'=>'2026-11']],
        ],
        'item_response_fields'=>['id','user_id','month','title','category','note','target','tracked','once_only','symbol','unplanned','completed','sort_order','created_at','updated_at'],
        'output_response_fields'=>['id','user_id','date','type','title','note','links','external_id','source','manual_lock','revision','archived','created_at','updated_at'],
        'record_response_fields'=>['id','user_id','item_id','date','completed','note','links','source','manual_lock','revision','updated_at'],
        'status_codes'=>['200'=>'成功','201'=>'创建成功','400'=>'参数无效','401'=>'需要有效登录或 Token','403'=>'网页 CSRF 无效、MONTH_READ_ONLY 月份只读、FUTURE_DATE 未来日期或 REVIEW_NOT_ALLOWED 不允许设置回顾','404'=>'项目或输出不存在或不属于当前用户','405'=>'方法不支持','409'=>'重复项目、同名歧义、MANUAL_LOCK 手动保护、REVISION_CONFLICT 版本冲突或 OUTPUT_ARCHIVED 已移除输出','413'=>'JSON 超过 64 KiB','500'=>'服务器异常'],
        'notes'=>[
            'month/plans/projects/records 查询均包含 access；只读月份允许查询和作为复制来源，禁止新增、修改、删除、打勾及复制到该月。force=true 不绕过月份锁定。',
            '业务请求传 UTF-8 JSON 对象；业务响应为 JSON。错误响应为 {error:说明}。',
            'Token 决定用户；传入 user_id 不会切换操作用户。',
            'items.completed 只统计独立事项；追踪项目按 records 中 completed=true 的天数统计。',
            '没有记录不代表未完成；取消勾选保留备注、链接及 completed=false 记录。',
            '历史候选来自仍保存的历史项目，不包含已删除项目。',
            '接口不采集第三方平台数据，不直接归档到 Get，不支持批量写入或浏览器 CORS。',
            'outputs.external_id按用户唯一，不要求每天唯一；同日可有多个同类型输出。复制月计划不复制输出内容。',
            '标题以“阅读”开头的每日追踪项目，completed=true且note非空时在格子显示note作为书名；只有勾选且note为空表示当天读过。书名仍保存在原note字段，未新增数据库字段。',
        ],
    ];
}
