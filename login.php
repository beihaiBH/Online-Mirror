<?php
/**
 * Online-Mirror v3.0 - 管理员登录
 * 支持密码登录 + 邮箱验证码登录 + 滑动验证
 */
session_start();
require_once __DIR__ . '/config.php';

// 退出
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// ========== 检查邮箱配置是否完整 ==========
function isEmailConfigured() {
    return getSetting('email_enabled') === '1'
        && !empty(getSetting('email_send_address'))
        && !empty(getSetting('email_send_password'))
        && !empty(getSetting('email_smtp_host'));
}

$email_configured = isEmailConfigured();

// ========== 发送邮箱验证码 AJAX ==========
$vcode_sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_vcode') {
    header('Content-Type: application/json; charset=utf-8');
    requireCsrf();
    
    if (!$email_configured) {
        echo json_encode(['success' => false, 'error' => '系统未配置邮箱发件服务，请联系管理员']);
        exit;
    }
    
    $email = trim($_POST['vcode_email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => '邮箱格式不正确']);
        exit;
    }
    
    // 检查30秒内是否已发送
    $existing = getSetting('vcode_' . $email);
    if ($existing) {
        $parts = explode('|', $existing);
        if (count($parts) === 2) {
            $last_time = intval($parts[1]);
            if (time() - $last_time < 30) {
                $remaining = 30 - (time() - $last_time);
                echo json_encode(['success' => false, 'error' => "发送过于频繁，请{$remaining}秒后再试"]);
                exit;
            }
        }
    }
    
    // 生成6位验证码
    $code = strval(random_int(100000, 999999));
    $store_value = $code . '|' . time();
    setSetting('vcode_' . $email, $store_value);
    
    // 发送验证码邮件
    $subject = '🔐 网恋照妖镜 - 登录验证码';
    $body = <<<EOT
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0f0c29;font-family:'Segoe UI',-apple-system,sans-serif;">
<center>
<div style="max-width:480px;margin:40px auto;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:20px;overflow:hidden;">
<div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:30px 20px;text-align:center;">
<div style="font-size:48px;margin-bottom:8px;">🔐</div>
<h1 style="color:#fff;margin:0;font-size:20px;">登录验证码</h1>
</div>
<div style="padding:28px;">
<p style="color:#c0c0d0;font-size:14px;margin:0 0 16px;">您正在登录网恋照妖镜后台管理系统，请输入以下验证码：</p>
<div style="text-align:center;padding:20px;background:rgba(102,126,234,0.08);border-radius:12px;margin-bottom:16px;">
<span style="font-size:36px;font-weight:700;letter-spacing:8px;color:#667eea;font-family:monospace;">{$code}</span>
</div>
<p style="color:#8080a0;font-size:12px;margin:0;">验证码有效期为5分钟，请勿泄露给他人。</p>
</div>
<div style="padding:12px 20px;text-align:center;border-top:1px solid rgba(255,255,255,0.04);">
<p style="margin:0;font-size:11px;color:#606080;">网恋照妖镜 v3.0 · 自动发送</p>
</div>
</div>
</center>
</body>
</html>
EOT;
    
    $from = getSetting('email_send_address');
    $smtp_host = getSetting('email_smtp_host');
    $smtp_port = getSetting('email_smtp_port') ?: '465';
    $smtp_secure = getSetting('email_smtp_secure') ?: 'ssl';
    $password = getSetting('email_send_password');
    
    $send_result = sendSmtpMail($email, $subject, $body, $from, $smtp_host, $smtp_port, $smtp_secure, $password);
    
    if ($send_result['success']) {
        echo json_encode(['success' => true, 'message' => '验证码已发送到您的邮箱，请查收']);
    } else {
        echo json_encode(['success' => false, 'error' => '邮件发送失败：' . $send_result['error']]);
    }
    exit;
}

// ========== 邮箱验证码登录处理 ==========
$error = '';
$login_tab = 'password';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    requireCsrf();
    
    $login_tab = trim($_POST['login_tab'] ?? 'password');
    
    // 登录频率限制
    list($login_allowed, $login_msg) = checkLoginRateLimit();
    if (!$login_allowed) {
        $error = $login_msg;
    } elseif ($login_tab === 'email') {
        // 邮箱验证码登录
        $vcode_email = trim($_POST['vcode_email'] ?? '');
        $vcode_input = trim($_POST['vcode_code'] ?? '');
        
        if (empty($vcode_email) || empty($vcode_input)) {
            $error = '请输入邮箱和验证码';
        } else {
            $stored = getSetting('vcode_' . $vcode_email);
            if (empty($stored)) {
                $error = '验证码已过期或不存在，请重新发送';
                addLog('', 'login_fail');
            } else {
                $parts = explode('|', $stored);
                if (count($parts) !== 2) {
                    $error = '验证码数据异常，请重新发送';
                } else {
                    $stored_code = $parts[0];
                    $stored_time = intval($parts[1]);
                    
                    // 检查5分钟有效期
                    if (time() - $stored_time > 300) {
                        $error = '验证码已过期（5分钟有效），请重新发送';
                        setSetting('vcode_' . $vcode_email, '');
                    } elseif ($vcode_input !== $stored_code) {
                        $error = '验证码错误，请重试';
                        addLog('', 'login_fail');
                    } else {
                        // 验证成功 - 查找匹配的管理员
                        try {
                            $db = getDB();
                            $stmt = $db->query("SELECT * FROM mir_users WHERE role='admin' LIMIT 1");
                            $user = $stmt->fetch();
                            
                            if ($user) {
                                session_regenerate_id(true);
                                $_SESSION['user_id'] = $user['id'];
                                $_SESSION['username'] = $user['username'];
                                $_SESSION['role'] = $user['role'];
                                $_SESSION['login_ip'] = getClientIP();
                                $_SESSION['login_time'] = time();
                                // 清除已使用的验证码
                                setSetting('vcode_' . $vcode_email, '');
                                addLog('', 'login_success');
                                header('Location: dashboard.php');
                                exit;
                            } else {
                                $error = '未找到管理员账号，请先通过密码登录创建';
                            }
                        } catch (Exception $e) {
                            $error = '系统错误，请稍后再试';
                        }
                    }
                }
            }
        }
    } else {
        // 密码登录
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if ($username && $password) {
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT * FROM mir_users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user && $password === $user['password']) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_ip'] = getClientIP();
                    $_SESSION['login_time'] = time();
                    addLog('', 'login_success');
                    header('Location: dashboard.php');
                    exit;
                } else {
                    addLog('', 'login_fail');
                    $error = '用户名或密码错误！';
                }
            } catch (Exception $e) {
                $error = '系统错误，请稍后再试';
            }
        } else {
            $error = '请输入用户名和密码';
        }
    }
}
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 · 网恋照妖镜</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #e0e0e0;
}
.login-box {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px;
    padding: 40px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
.login-box .icon {
    font-size: 48px;
    color: #667eea;
    margin-bottom: 6px;
}
.login-box h2 {
    font-size: 24px;
    margin-bottom: 2px;
}
.login-box p.sub {
    font-size: 14px;
    color: #8080a0;
    margin-bottom: 20px;
}
/* Tab切换 */
.login-tabs {
    display: flex;
    margin-bottom: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.login-tab {
    flex: 1;
    padding: 10px 0;
    font-size: 14px;
    color: #8080a0;
    cursor: pointer;
    transition: all 0.3s;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
}
.login-tab:hover { color: #e0e0e0; }
.login-tab.active {
    color: #667eea;
    border-bottom-color: #667eea;
}
.login-tab:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.tab-content { display: none; }
.tab-content.active { display: block; }

.form-group {
    margin-bottom: 14px;
    text-align: left;
}
.form-group label {
    display: block;
    font-size: 13px;
    color: #b0b0c8;
    margin-bottom: 4px;
}
.form-group input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    background: rgba(255,255,255,0.06);
    color: #e0e0e0;
    font-size: 15px;
    outline: none;
    transition: all 0.3s;
}
.form-group input:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
}
.form-group .input-row {
    display: flex;
    gap: 8px;
}
.form-group .input-row input { flex: 1; }
.form-group .input-row button {
    padding: 12px 16px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    white-space: nowrap;
}
.form-group .input-row button:hover { opacity: 0.85; }
.form-group .input-row button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.btn-login {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    margin-top: 4px;
}
.btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102,126,234,0.4);
}
.btn-login:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}
.error {
    background: rgba(255,80,80,0.1);
    border: 1px solid rgba(255,80,80,0.2);
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 14px;
    color: #ff6b6b;
    font-size: 14px;
    text-align: left;
}
.success-msg {
    background: rgba(76,175,80,0.1);
    border: 1px solid rgba(76,175,80,0.2);
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 14px;
    color: #4caf50;
    font-size: 14px;
    text-align: left;
}
.vcode-tip {
    font-size: 11px;
    color: #606080;
    margin-top: 4px;
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

.back-link {
    margin-top: 20px;
    font-size: 14px;
}
.back-link a { color: #667eea; text-decoration: none; }
.back-link a:hover { text-decoration: underline; }
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
@keyframes glow {
    0%, 100% { box-shadow: 0 0 5px rgba(102,126,234,0.3); }
    50% { box-shadow: 0 0 20px rgba(102,126,234,0.6); }
}

.container, .box, .chart-wrap, .table-wrap, .login-box, .photo-card,
.stats-grid, .section-title, .tab-nav { animation: fadeInUp 0.5s ease-out; }
.stat-card { animation: fadeInUp 0.5s ease-out backwards; }
.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(2) { animation-delay: 0.1s; }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(4) { animation-delay: 0.2s; }
.stat-card:nth-child(5) { animation-delay: 0.25s; }
.stat-card:nth-child(6) { animation-delay: 0.3s; }
.stat-card { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.stat-card:hover { transform: translateY(-4px); border-color: rgba(102,126,234,0.4); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
.photo-mini { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.photo-mini:hover { transform: translateY(-4px) scale(1.02); border-color: rgba(102,126,234,0.4); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
.btn-save, .btn-login, .btn-danger, .btn-warning,
.load-more-btn, .export-btn, .refresh-btn {
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.btn-save:hover, .btn-login:hover, .btn-danger:hover, .btn-warning:hover,
.load-more-btn:hover, .export-btn:hover, .refresh-btn:hover {
    transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102,126,234,0.3);
}
.toggle { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.toggle:hover { transform: scale(1.05); }
.toggle .knob { transition: all 0.3s cubic-bezier(0.68,-0.55,0.27,1.55); }
.toggle-field { animation: slideDown 0.3s ease-out; }
.modal-box, .edit-box { animation: fadeInUp 0.3s ease-out; }
table tr { transition: background 0.2s; }
table tr:hover td { background: rgba(102,126,234,0.05) !important; }
.tag { transition: all 0.2s; }
.tag:hover { transform: translateY(-1px); }
.footer .social-links a { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
.footer .social-links a:hover { transform: translateY(-2px); }
.toast { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
</style>
</head>
<body>
<div class="login-box">
    <div class="icon"><i class="fas fa-shield-alt"></i></div>
    <h2>管理员登录</h2>
    <p class="sub">Online Mirror 后台管理系统 v3.0</p>
    
    <?php if ($error): ?>
    <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- 登录Tab切换 -->
    <div class="login-tabs">
        <button class="login-tab <?php echo $login_tab === 'password' ? 'active' : ''; ?>" onclick="switchLoginTab('password')" id="tabPassword"><i class="fas fa-key"></i> 密码登录</button>
        <button class="login-tab <?php echo $login_tab === 'email' ? 'active' : ''; ?>" onclick="switchLoginTab('email')" id="tabEmail" <?php echo $email_configured ? '' : 'disabled title="邮箱发件未配置"'; ?>><i class="fas fa-envelope"></i> 邮箱验证码<?php echo $email_configured ? '' : ' (未配置)'; ?></button>
    </div>
    
    <!-- 密码登录表单 -->
    <div class="tab-content <?php echo $login_tab === 'password' ? 'active' : ''; ?>" id="tabContentPassword">
        <form method="POST" onsubmit="return checkCaptchaPassword()">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="login_tab" value="password">
            <div class="form-group">
                <input type="text" name="username" placeholder="用户名" required autocomplete="username">
            </div>
            <div class="form-group">
                <input type="password" name="password" placeholder="密码" required autocomplete="current-password">
            </div>
            <!-- 滑动验证 -->
            <div class="slide-captcha">
                <label class="sc-label"><i class="fas fa-shield-alt"></i> 安全验证</label>
                <div class="sc-track" id="scTrackPassword">
                    <div class="sc-fill" id="scFillPassword"></div>
                    <div class="sc-thumb" id="scThumbPassword" onmousedown="startDrag(event, 'Password')" ontouchstart="startDragTouch(event, 'Password')">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="sc-text" id="scTextPassword">请按住滑块拖动到最右侧</div>
                </div>
                <input type="hidden" name="slider_pass" id="sliderPassPassword" value="0">
            </div>
            <button type="submit" class="btn-login" onclick="resetSliderOnSubmit();"><i class="fas fa-sign-in-alt"></i> 登 录</button>
        </form>
    </div>
    
    <!-- 邮箱验证码登录表单 -->
    <div class="tab-content <?php echo $login_tab === 'email' ? 'active' : ''; ?>" id="tabContentEmail">
        <form method="POST" id="vcodeForm" onsubmit="return checkCaptchaVcode()">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="login_tab" value="email">
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> 邮箱地址</label>
                <div class="input-row">
                    <input type="email" name="vcode_email" id="vcodeEmail" placeholder="your@email.com" required>
                    <button type="button" id="sendVcodeBtn" onclick="sendVerificationCode()">发送验证码</button>
                </div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-hashtag"></i> 验证码</label>
                <input type="text" name="vcode_code" id="vcodeCode" placeholder="请输入6位验证码" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required>
                <div class="vcode-tip">验证码发送至您的邮箱，5分钟内有效</div>
            </div>
            <div id="vcodeStatus"></div>
            <!-- 滑动验证 -->
            <div class="slide-captcha">
                <label class="sc-label"><i class="fas fa-shield-alt"></i> 安全验证</label>
                <div class="sc-track" id="scTrackEmail">
                    <div class="sc-fill" id="scFillEmail"></div>
                    <div class="sc-thumb" id="scThumbEmail" onmousedown="startDrag(event, 'Email')" ontouchstart="startDragTouch(event, 'Email')">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="sc-text" id="scTextEmail">请按住滑块拖动到最右侧</div>
                </div>
                <input type="hidden" name="slider_pass" id="sliderPassEmail" value="0">
            </div>
            <button type="submit" class="btn-login" id="vcodeLoginBtn"><i class="fas fa-sign-in-alt"></i> 验证并登录</button>
        </form>
    </div>
    
    <div class="back-link">
        <a href="index.php"><i class="fas fa-arrow-left"></i> 返回首页</a>
    </div>
</div>

<script>
// Tab切换
function switchLoginTab(tab) {
    document.querySelectorAll('.login-tab').forEach(function(el) {
        el.classList.remove('active');
    });
    document.querySelectorAll('.tab-content').forEach(function(el) {
        el.classList.remove('active');
    });
    document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
    document.getElementById('tabContent' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
}

// 发送验证码
var vcodeTimer = null;
var vcodeCountdown = 0;

function sendVerificationCode() {
    var btn = document.getElementById('sendVcodeBtn');
    var email = document.getElementById('vcodeEmail').value.trim();
    var statusDiv = document.getElementById('vcodeStatus');
    
    if (!email) {
        statusDiv.innerHTML = '<div class="error"><i class="fas fa-exclamation-circle"></i> 请输入邮箱地址</div>';
        return;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        statusDiv.innerHTML = '<div class="error"><i class="fas fa-exclamation-circle"></i> 邮箱格式不正确</div>';
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 发送中...';
    statusDiv.innerHTML = '';
    
    var formData = new FormData();
    formData.append('action', 'send_vcode');
    formData.append('csrf_token', '<?php echo $csrf; ?>');
    formData.append('vcode_email', email);
    
    fetch('login.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            statusDiv.innerHTML = '<div class="success-msg"><i class="fas fa-check-circle"></i> ' + data.message + '</div>';
            // 开始30秒倒计时
            vcodeCountdown = 30;
            btn.innerHTML = '重新发送 (' + vcodeCountdown + 's)';
            vcodeTimer = setInterval(function() {
                vcodeCountdown--;
                if (vcodeCountdown <= 0) {
                    clearInterval(vcodeTimer);
                    btn.disabled = false;
                    btn.innerHTML = '发送验证码';
                } else {
                    btn.innerHTML = '重新发送 (' + vcodeCountdown + 's)';
                }
            }, 1000);
        } else {
            statusDiv.innerHTML = '<div class="error"><i class="fas fa-exclamation-circle"></i> ' + (data.error || '发送失败') + '</div>';
            btn.disabled = false;
            btn.innerHTML = '发送验证码';
        }
    })
    .catch(function() {
        statusDiv.innerHTML = '<div class="error"><i class="fas fa-exclamation-circle"></i> 网络错误，请重试</div>';
        btn.disabled = false;
        btn.innerHTML = '发送验证码';
    });
}

// ====== 滑动验证码 ======
var isDragging = false;
var dragStartX = 0;
var dragCurrentX = 0;
var dragTrackWidth = 0;
var dragSuffix = '';

function startDrag(e, suffix) {
    e.preventDefault();
    var track = document.getElementById('scTrack' + suffix);
    if (track.classList.contains('verified')) return;
    
    isDragging = true;
    dragSuffix = suffix;
    dragStartX = e.clientX;
    var trackRect = track.getBoundingClientRect();
    dragTrackWidth = trackRect.width - 42;
    dragCurrentX = 0;
    
    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup', onDragEnd);
}

function startDragTouch(e, suffix) {
    e.preventDefault();
    var track = document.getElementById('scTrack' + suffix);
    if (track.classList.contains('verified')) return;
    
    isDragging = true;
    dragSuffix = suffix;
    var touch = e.touches[0];
    dragStartX = touch.clientX;
    var trackRect = track.getBoundingClientRect();
    dragTrackWidth = trackRect.width - 42;
    dragCurrentX = 0;
    
    document.addEventListener('touchmove', onDragMoveTouch, { passive: false });
    document.addEventListener('touchend', onDragEndTouch);
}

function onDragMove(e) {
    if (!isDragging) return;
    e.preventDefault();
    var dx = e.clientX - dragStartX;
    updateDragPosition(dx);
}

function onDragMoveTouch(e) {
    if (!isDragging) return;
    e.preventDefault();
    var touch = e.touches[0];
    var dx = touch.clientX - dragStartX;
    updateDragPosition(dx);
}

function updateDragPosition(dx) {
    var pos = Math.max(0, Math.min(dragTrackWidth, dx));
    dragCurrentX = pos;
    
    var suffix = dragSuffix;
    var thumb = document.getElementById('scThumb' + suffix);
    var fill = document.getElementById('scFill' + suffix);
    var text = document.getElementById('scText' + suffix);
    
    thumb.style.left = (2 + pos) + 'px';
    fill.style.width = (pos + 20) + 'px';
    
    var percent = pos / dragTrackWidth;
    if (percent > 0.9) {
        text.textContent = '松开完成验证';
    } else if (percent > 0.5) {
        text.textContent = '继续向右拖动';
    } else {
        text.textContent = '请按住滑块拖动到最右侧';
    }
}

function onDragEnd() {
    if (!isDragging) return;
    isDragging = false;
    document.removeEventListener('mousemove', onDragMove);
    document.removeEventListener('mouseup', onDragEnd);
    checkDragComplete();
}

function onDragEndTouch() {
    if (!isDragging) return;
    isDragging = false;
    document.removeEventListener('touchmove', onDragMoveTouch);
    document.removeEventListener('touchend', onDragEndTouch);
    checkDragComplete();
}

function checkDragComplete() {
    var suffix = dragSuffix;
    var threshold = dragTrackWidth * 0.9;
    
    if (dragCurrentX >= threshold) {
        // 验证通过
        var track = document.getElementById('scTrack' + suffix);
        var thumb = document.getElementById('scThumb' + suffix);
        var fill = document.getElementById('scFill' + suffix);
        var text = document.getElementById('scText' + suffix);
        var input = document.getElementById('sliderPass' + suffix);
        
        track.classList.add('verified');
        thumb.classList.add('verified');
        thumb.innerHTML = '<i class="fas fa-check"></i>';
        text.textContent = '✓ 验证通过';
        input.value = '1';
    } else {
        // 重置
        var thumb = document.getElementById('scThumb' + suffix);
        var fill = document.getElementById('scFill' + suffix);
        var text = document.getElementById('scText' + suffix);
        
        thumb.style.left = '2px';
        fill.style.width = '0';
        text.textContent = '请按住滑块拖动到最右侧';
    }
}

// 表单提交验证
function checkCaptchaVcode() {
    var sliderPass = document.getElementById('sliderPassEmail').value;
    if (sliderPass !== '1') {
        alert('请先完成滑块验证！');
        return false;
    }
    return true;
}
function checkCaptchaPassword() {
    var sliderPass = document.getElementById('sliderPassPassword').value;
    if (sliderPass !== '1') {
        alert('请先完成滑块验证！');
        return false;
    }
    return true;
}
</script>
</body>
</html>
