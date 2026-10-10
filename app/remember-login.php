<?php
declare(strict_types=1);
function remember_cookie(?string $value, int $expires = 0): void {
    setcookie('month_tracker_remember', $value ?? '', ['expires'=>$value === null ? time()-3600 : $expires, 'path'=>'/', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly'=>true, 'samesite'=>'Lax']);
}
function remember_restore(): void {
    $cookie = $_COOKIE['month_tracker_remember'] ?? '';
    if (!is_string($cookie) || !preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D', $cookie, $parts)) {
        if ($cookie !== '') { remember_cookie(null); } return;
    }
    $row = query('SELECT * FROM remembered_devices WHERE selector=?', [$parts[1]])[0] ?? null;
    $user = $row ? (config()['users'][(int)$row['user_id']] ?? null) : null;
    if (!$row || !$user || (int)$row['expires_at'] <= time() || !hash_equals($row['token_hash'], hash('sha256', $parts[2])) || !hash_equals($row['password_fingerprint'], hash('sha256', $user['password_hash']))) {
        remember_cookie(null); return;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['user_id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['remember_selector'] = $parts[1];
    $_SESSION['remember_until'] = (int)$row['expires_at'];
    $_SESSION['password_fingerprint'] = $row['password_fingerprint'];
}
function remember_forget(): void {
    if (isset($_SESSION['remember_selector'])) { execute('DELETE FROM remembered_devices WHERE selector=? AND user_id=?', [$_SESSION['remember_selector'], $_SESSION['user_id']]); }
    unset($_SESSION['remember_selector'], $_SESSION['remember_until']);
    remember_cookie(null);
}
function remember_issue(int $user): void {
    remember_forget();
    execute('DELETE FROM remembered_devices WHERE expires_at<=?', [time()]);
    $selector = bin2hex(random_bytes(16)); $token = bin2hex(random_bytes(32)); $expires = time() + 90*86400;
    $fingerprint = hash('sha256', config()['users'][$user]['password_hash']);
    execute('INSERT INTO remembered_devices (selector,user_id,token_hash,password_fingerprint,expires_at) VALUES (?,?,?,?,?)', [$selector,$user,hash('sha256',$token),$fingerprint,$expires]);
    $_SESSION['remember_selector'] = $selector; $_SESSION['remember_until'] = $expires; $_SESSION['password_fingerprint'] = $fingerprint;
    remember_cookie($selector . '.' . $token, $expires);
}
