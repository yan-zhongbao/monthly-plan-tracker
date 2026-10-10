<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
try { $config = config(); session_boot(); } catch (Throwable $e) {
    http_response_code(503);
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>尚未初始化</title><p>请检查数据库配置；首次部署请打开 <a href="setup.php">网页初始化</a>。</p></html>'; exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['logout'])) { remember_forget(); $_SESSION = []; session_destroy(); setcookie(session_name(), '', ['expires'=>time()-3600,'path'=>'/','httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']); header('Location: ./'); exit; }
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
    $attempt = query('SELECT * FROM login_attempts WHERE ip_hash=?', [$key])[0] ?? ['failures'=>0,'last_attempt'=>0];
    if ($attempt['failures'] >= 8 && time() - $attempt['last_attempt'] < 900) { $error = '尝试次数较多，请 15 分钟后重试。'; }
    else {
        $id = (int)($_POST['user_id'] ?? 1);
        if (isset($config['users'][$id]) && password_verify((string)($_POST['password'] ?? ''), $config['users'][$id]['password_hash'])) {
            remember_forget();
            session_regenerate_id(true); $_SESSION['user_id'] = $id; $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['password_fingerprint'] = hash('sha256', $config['users'][$id]['password_hash']);
            if (!empty($_POST['remember'])) { remember_issue($id); }
            execute('DELETE FROM login_attempts WHERE ip_hash=?', [$key]); header('Location: ./'); exit;
        }
        $failures = time() - $attempt['last_attempt'] > 900 ? 1 : (int)$attempt['failures'] + 1;
        upsert('login_attempts', ['ip_hash','failures','last_attempt'], ['ip_hash'], [$key,$failures,time()]);
        $error = '密码不正确。';
    }
}
$logged = isset($_SESSION['user_id'], $config['users'][$_SESSION['user_id']]);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f5f2eb"><meta name="csrf-token" content="<?= h($_SESSION['csrf']) ?>">
  <meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="月度追踪"><meta name="apple-mobile-web-app-status-bar-style" content="default">
  <link rel="manifest" href="manifest.php"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png"><link rel="icon" type="image/png" href="assets/icon-192.png">
  <title>月度计划与追踪</title><link rel="stylesheet" href="assets/app.css?v=0.7.3">
</head>
<body>
<?php if (!$logged): ?>
<main class="login-page"><section class="login-card">
  <div class="eyebrow">MY MONTHLY NOTEBOOK</div><div class="brand-mark">月</div>
  <h1>月度计划与追踪</h1><p class="muted">把计划写下来，把日子慢慢过好。</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <?php if (count($config['users']) > 1): ?><label>用户<select name="user_id"><?php foreach ($config['users'] as $id=>$user): ?><option value="<?= (int)$id ?>"><?= h($user['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>登录密码<input type="password" name="password" autocomplete="current-password" required autofocus></label>
    <label class="remember-login"><input type="checkbox" name="remember" value="1" checked>记住这台设备 90 天</label>
    <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
    <button class="primary full" type="submit">打开我的笔记本 →</button>
  </form><p class="login-foot">一个月，一页记录。 · <a href="api-docs.php">API 说明</a> · <button type="button" class="quiet" data-install-app>安装到桌面</button></p>
</section></main>
<?php else: ?>
<div class="app-shell">
<div class="mobile-topbar"><span>月度计划与追踪</span><span id="mobile-month-title">本月</span><button id="mobile-menu-open" class="icon-button" aria-label="打开月份与功能菜单" aria-haspopup="dialog" aria-controls="mobile-menu">⋯</button></div>
<main>
<div id="primary-controls">
<header class="app-header">
  <div class="brand"><span class="brand-mark">月</span><div><h1>月度计划与追踪</h1><span class="eyebrow">MY MONTHLY NOTEBOOK</span></div></div>
  <div class="header-right"><button type="button" class="quiet" data-install-app>安装到桌面</button><a class="quiet" href="api-docs.php" target="_blank" rel="noopener">API 说明</a><span id="save-state" role="status">正在打开…</span><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><button class="quiet" name="logout" value="1">退出</button></form></div>
</header>
  <section class="month-bar">
    <div class="month-heading"><span class="eyebrow" id="month-subtitle">记录每一天</span><div class="month-controls"><button id="prev-month" class="icon-button" aria-label="上个月">‹</button><h2 id="month-title">本月</h2><button id="next-month" class="icon-button" aria-label="下个月">›</button><input id="month-picker" type="month" aria-label="选择月份" min="2000-01" max="2099-12"></div></div>
    <div class="month-actions"><button id="copy-month" class="secondary">沿用上月计划</button><button id="export-open" class="secondary">导出本月 ↗</button><button id="add-item" class="primary">＋ 添加项目</button></div>
  </section>
  <section class="view-bar"><nav class="tabs" aria-label="查看方式"><button id="tab-tracker" class="active" aria-selected="true">月度追踪</button><button id="tab-plan" aria-selected="false">月计划</button></nav><div class="view-hint" id="view-hint">轻点打勾 · 长按 / 右键添加备注</div></section>
  <section class="review-bar" aria-label="月回顾状态"><label class="check-label"><input id="month-review" type="checkbox">已完成月回顾总结</label><span id="month-access" role="status"></span></section>
</div>
  <section id="tracker-view" class="notebook">
    <div class="notebook-top"><div><span class="green-dot"></span><span id="tracker-summary">本月追踪</span></div><div class="table-tools"><button id="go-today" class="quiet">回到今天</button><span class="legend">✓ 完成 <span class="note-dot">●</span> 备注 / 链接</span></div></div>
    <div class="table-scroll" id="table-scroll" tabindex="0" aria-label="月度追踪表，可左右滑动"><table id="tracker-table"></table></div>
    <div class="notebook-bottom"><div><button id="add-unplanned" class="quiet">＋ 添加计划外事项</button><button id="output-archives" class="quiet">已移除输出</button></div><span class="muted">空白表示尚未标记，完成天数只统计勾选。</span></div>
  </section>
  <section id="plan-view" hidden><div class="plan-summary" id="plan-summary"></div><div class="plan-grid" id="plan-grid"></div></section>
  <footer class="page-footer"><span>留一点空白，也是一种安排。</span><span id="mobile-hint">左右滑动日期 · 项目名称保持在左侧</span></footer>
</main></div>
<dialog id="mobile-menu" aria-labelledby="mobile-menu-title"><div class="dialog-head"><h3 id="mobile-menu-title">月份与功能</h3><button type="button" class="icon-button close-dialog" aria-label="关闭菜单">×</button></div><div id="mobile-controls-host"></div><button id="mobile-go-today" type="button" class="secondary full">回到今天的追踪表</button></dialog>
<dialog id="item-dialog"><form id="item-form">
  <div class="dialog-head"><div><span class="eyebrow" id="item-kicker">MONTHLY PLAN</span><h3 id="item-dialog-title">添加项目</h3></div><button type="button" class="icon-button close-dialog" aria-label="关闭">×</button></div>
  <input type="hidden" id="item-id"><label for="item-category">所属方面</label><select id="item-category" required></select>
  <label for="item-title">项目名称</label><div class="title-combo"><input id="item-title" maxlength="120" placeholder="先选择所属方面" autocomplete="off" required aria-controls="item-suggestions"><button id="suggestion-toggle" type="button" aria-label="展开历史项目" aria-expanded="false">⌄</button></div>
  <div id="item-suggestions" class="suggestion-list" aria-label="历史项目" hidden></div><p id="suggestion-status" class="field-help" role="status"></p>
  <fieldset class="mode-options"><legend>记录方式</legend><input id="item-mode" type="hidden" value="plan">
    <label><input type="radio" name="record-mode" value="plan">仅月计划<small>在计划中完成</small></label>
    <label><input type="radio" name="record-mode" value="daily">每日追踪<small>单独占一行</small></label>
    <label><input type="radio" name="record-mode" value="once">一次性事件<small>底部合并一行</small></label>
  </fieldset>
  <label id="target-label">目标天数（选填）<input id="item-target" type="number" min="1" max="366" placeholder="例如：16；填写后加入每日追踪"></label>
  <label>标记符号（选填）<span class="symbol-input"><input id="item-symbol" maxlength="8" placeholder="留空自动选择"><button id="choose-symbol" type="button" class="secondary" aria-expanded="false" aria-controls="symbol-picker">选择图标</button></span></label>
  <div id="symbol-picker" class="symbol-picker" hidden><div class="symbol-picker-head"><span>选择适合的标记</span><div class="table-tools"><button id="more-symbols" type="button" class="quiet" aria-expanded="false" aria-controls="symbol-options">更多图标</button><button id="symbol-auto" type="button" class="quiet">恢复自动</button></div></div><div id="symbol-options" class="symbol-options"></div><p class="field-help">选择后自动收起；也可在输入框填写文字。</p></div>
  <label>计划说明（选填）<textarea id="item-note" rows="3" maxlength="4000" placeholder="写下本月的安排或期待"></textarea></label>
  <label class="check-label" id="tracked-label" hidden><input id="item-tracked" type="checkbox">加入月度追踪表，每天打勾</label>
  <p class="field-help" id="item-help">不加入追踪表的事项，留在月计划中单独标记完成。</p>
  <div class="dialog-actions"><button id="delete-item" class="danger" type="button" hidden>删除项目</button><button id="item-save" class="primary" type="submit">保存项目</button></div>
</form></dialog>
<dialog id="event-dialog"><div class="dialog-head"><div><span id="event-date" class="eyebrow"></span><h3>一次性事件</h3></div><button type="button" class="icon-button close-dialog" aria-label="关闭">×</button></div><p class="field-help">点击事项标记完成；点击已完成事项可取消。详情可填写备注和链接。</p><div id="event-list"></div></dialog>
<dialog id="output-dialog"><form id="output-form">
  <div class="dialog-head"><div><span class="eyebrow">OUTPUTS</span><h3 id="output-heading">添加输出</h3></div><button type="button" class="icon-button close-dialog" aria-label="关闭">×</button></div>
  <label>输出日期<input id="output-date" type="date" required></label>
  <label>输出类型<select id="output-type"><option value="book_note">读书笔记 · 圈读</option><option value="article">文章 · 圈文</option><option value="travel_note">旅行记录 · 圈旅</option></select></label>
  <label>输出标题<input id="output-title" maxlength="120" required placeholder="书名、文章标题或旅行名称"></label>
  <label>输出备注<textarea id="output-note" rows="3" maxlength="4000"></textarea></label>
  <label>输出链接（每行一个）<textarea id="output-links" rows="2" placeholder="https://…"></textarea></label>
  <div id="output-link-list" class="record-link-list"></div>
  <label class="check-label"><input id="output-lock" type="checkbox" checked>保留我的修改，自动填写时不覆盖</label>
  <p id="output-info" class="field-help">同一天可添加多篇，同类型也可重复添加。</p>
  <div class="dialog-actions"><button id="output-remove" class="danger" type="button" hidden>移除输出</button><button id="output-save" class="primary" type="submit">保存输出</button></div>
</form></dialog>
<dialog id="output-archive-dialog"><div class="dialog-head"><h3>已移除输出</h3><button type="button" class="icon-button close-dialog" aria-label="关闭">×</button></div><p class="field-help">仅显示当前月。恢复后重新出现在输出行。</p><div id="output-archive-list"></div></dialog>
<dialog id="record-dialog"><form id="record-form">
  <div class="dialog-head"><div><span class="eyebrow" id="record-date"></span><h3 id="record-title">记录详情</h3></div><button type="button" class="icon-button close-dialog" aria-label="关闭">×</button></div>
  <label class="check-label"><input id="record-completed" type="checkbox">这一天已完成</label>
  <label><span id="record-note-label">补充备注</span><textarea id="record-note" rows="4" maxlength="4000" placeholder="书名、朋友名字，或想留住的一点细节…"></textarea></label>
  <label>详情链接（每行一个）<textarea id="record-links" rows="2" placeholder="https://…"></textarea></label>
  <div id="record-link-list" class="record-link-list"></div>
  <label class="check-label"><input id="record-lock" type="checkbox">保留我的修改，自动填写时不覆盖</label>
  <p id="record-source" class="field-help"></p>
  <div class="dialog-actions"><button id="record-save" class="primary" type="submit">保存记录</button></div>
</form></dialog>
<dialog id="export-dialog"><div class="dialog-head"><div><span class="eyebrow">MONTHLY ARCHIVE</span><h3>保存这个月</h3></div><button class="icon-button close-dialog" aria-label="关闭">×</button></div><p class="muted">导出后可交给 OpenClaw 归档到 Get 笔记。</p><div class="export-options"><button data-export="png">月度表格图片 <span>PNG · 适合放进笔记</span></button><button data-export="csv">表格文件 <span>CSV · 可用 Excel 打开</span></button><button data-export="json">完整月度数据 <span>JSON · 含计划、备注和链接</span></button><button data-export="print">打印 / 保存 PDF <span>浏览器打印当前月表</span></button></div></dialog>
<div id="toast" role="status" hidden></div><script src="assets/tracking-display.js?v=0.7.3" defer></script><script src="assets/app.js?v=0.7.3" defer></script>
<?php endif; ?>
<dialog id="install-dialog" aria-labelledby="install-title"><div class="dialog-head"><h3 id="install-title">安装到桌面</h3><button type="button" class="icon-button" id="install-close" aria-label="关闭安装说明">×</button></div><p id="install-status" class="field-help" role="status">安装后可从桌面直接打开，需要联网同步记录。</p><button id="install-confirm" type="button" class="primary full" hidden>安装应用</button><ul class="install-guide"><li><strong>安卓 Chrome：</strong>浏览器菜单 → 安装应用 / 添加到主屏幕。</li><li><strong>Windows Chrome / Edge：</strong>地址栏安装图标，或浏览器菜单 → 安装应用（Edge 中在“应用”菜单）。</li><li><strong>iPhone / iPad：</strong>用 Safari 打开 → 分享 → 添加到主屏幕；如显示“作为网页 App 打开”，保持开启。</li><li><strong>Mac Safari：</strong>文件 → 添加到程序坞。</li></ul><p class="field-help">安装按钮是否出现由浏览器决定；微信等内置浏览器请先在系统浏览器打开。</p></dialog>
<script src="assets/pwa.js?v=0.7.3" defer></script>
</body></html>
