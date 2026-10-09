<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/api-spec.php';
$spec = api_spec();
if (($_GET['format'] ?? '') === 'json') { respond($spec); }
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'");
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function fields_table(array $fields): void {
    echo '<div class="api-table-wrap"><table class="api-fields"><thead><tr><th>参数</th><th>类型</th><th>必填</th><th>说明</th></tr></thead><tbody>';
    foreach ($fields as $name=>$field) { echo '<tr><td><code>'.h($name).'</code></td><td>'.h($field['type']).'</td><td>'.($field['required']?'是':'否').'</td><td>'.h($field['description']).'</td></tr>'; }
    echo '</tbody></table></div>';
}
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>API 说明 · 月度计划与追踪</title><link rel="stylesheet" href="assets/app.css"></head><body>
<main class="api-page">
<header class="api-intro"><span class="eyebrow">FOR OPENCLAW & OTHER AGENTS</span><h1>API 使用说明</h1><p>查询月计划、发现历史项目、标记每日完成。接口元数据公开，个人数据需要 Token。</p><div class="api-links"><a href="./">← 返回笔记本</a><a href="api.php?action=discovery">机器可读接口清单 JSON ↗</a></div></header>
<section class="api-section"><h2>访问与鉴权</h2><p>本站 API 路径为 <code>api.php</code>，使用查询参数 <code>action</code> 选择接口，不需要伪静态。发现接口公开，无需 Token：</p><pre>GET api.php?action=discovery</pre><p>业务请求携带以下请求头：</p><pre>Authorization: Bearer &lt;API_TOKEN&gt;
Content-Type: application/json</pre><p><?= h($spec['authentication']['description']) ?></p><p>Token 由初始化程序生成，请放在 OpenClaw 的私密配置中，不要写入 URL。业务日期使用 Asia/Shanghai。</p><h3>建议调用顺序</h3><ol><?php foreach($spec['workflow'] as $step): ?><li><?= h($step) ?></li><?php endforeach; ?></ol><p>方面顺序：<?= h(implode(' → ',$spec['categories'])) ?>。</p></section>
<nav class="api-index" aria-label="接口目录"><?php foreach($spec['endpoints'] as $e): ?><a href="#<?= h(strtolower($e['method']).'-'.$e['action']) ?>"><?= h($e['method'].' '.$e['action']) ?></a><?php endforeach; ?></nav>
<?php foreach($spec['endpoints'] as $e): ?>
<section class="api-section" id="<?= h(strtolower($e['method']).'-'.$e['action']) ?>"><h2><span class="method-badge"><?= h($e['method']) ?></span> <?= h($e['action']) ?></h2><p><?= h($e['description']) ?></p><code class="endpoint-url">api.php?action=<?= h($e['action']) ?></code><p class="muted"><?= $e['auth']?'需要 Bearer Token':'公开，无需 Token' ?></p>
<?php if (!empty($e['query'])): ?><h3>查询参数</h3><?php fields_table($e['query']); endif; ?>
<?php if (!empty($e['body'])): ?><h3>JSON 请求体</h3><?php fields_table($e['body']); endif; ?>
<?php if(isset($e['example_query'])): ?><h3>示例请求</h3><pre><?= h($e['method'].' api.php?'.http_build_query(['action'=>$e['action']]+$e['example_query'])) ?></pre><?php endif; ?>
<?php if(isset($e['example_body'])): ?><h3>示例请求体</h3><pre><?= h(json_encode($e['example_body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
<p><strong>响应：</strong><?= h($e['response']) ?></p></section>
<?php endforeach; ?>
<section class="api-section"><h2>响应字段</h2><p>项目：<code><?= h(implode(', ',$spec['item_response_fields'])) ?></code></p><p>格子：<code><?= h(implode(', ',$spec['record_response_fields'])) ?></code></p><p>输出：<code><?= h(implode(', ',$spec['output_response_fields'])) ?></code></p><p>输出类型：<code>book_note</code> 读书笔记（圈读）、<code>article</code> 文章（圈文）、<code>travel_note</code> 旅行记录（圈旅）。同一天可以保存多篇相同类型的输出。</p><h2>状态码</h2><dl class="api-status"><?php foreach($spec['status_codes'] as $status=>$description): ?><dt><?= h((string)$status) ?></dt><dd><?= h($description) ?></dd><?php endforeach; ?></dl><h2>使用约定</h2><ul><?php foreach($spec['notes'] as $note): ?><li><?= h($note) ?></li><?php endforeach; ?></ul></section>
<footer class="page-footer">API <?= h($spec['version']) ?> · 应用 <?= h($spec['app_version']) ?> · 网页与 JSON 说明来自同一份接口定义</footer>
</main></body></html>
