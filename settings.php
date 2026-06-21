<?php
/**
 * Online-Mirror v2.0 - 邮箱通知设置
 * 首次开启通知时显示设置页，后续长按铃铛可再次进入
 */
require_once __DIR__ . '/config.php';
requireLogin();

$db = getDB();
$user = getCurrentUser();
$csrf = csrfToken();

// 处理保存
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_email') {
    requireCsrf();
    
    $enabled = isset($_POST['email_enabled']) ? '1' : '0';
    setSetting('email_enabled', $enabled);
    
    if ($enabled === '1') {
        setSetting('email_smtp_host', trim($_POST['email_smtp_host'] ?? 'smtp.qq.com'));
        setSetting('email_smtp_port', trim($_POST['email_smtp_port'] ?? '465'));
        setSetting('email_smtp_secure', trim($_POST['email_smtp_secure'] ?? 'ssl'));
        
        $send_addr = trim($_POST['email_send_address'] ?? '');
        $send_pass = trim($_POST['email_send_password'] ?? '');
        $receive_addr = trim($_POST['email_receive_address'] ?? '');
        
        if (!empty($send_addr)) setSetting('email_send_address', $send_addr);
        if (!empty($send_pass)) setSetting('email_send_password', $send_pass);
        if (!empty($receive_addr)) setSetting('email_receive_address', $receive_addr);
    }
    
    $saved = true;
}

// 当前设置
$email_enabled = getSetting('email_enabled') === '1';
$smtp_host = getSetting('email_smtp_host') ?: 'smtp.qq.com';
$smtp_port = getSetting('email_smtp_port') ?: '465';
$smtp_secure = getSetting('email_smtp_secure') ?: 'ssl';
$send_address = getSetting('email_send_address') ?: '';
$receive_address = getSetting('email_receive_address') ?: '';
$has_password = !empty(getSetting('email_send_password'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>通知设置 · 网恋照妖镜</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh; color: #e0e0e0; padding: 20px;
}
.box {
    max-width: 520px; margin: 0 auto;
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px; padding: 32px;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
.box h2 { text-align:center; margin-bottom:4px; }
.box .sub { text-align:center; font-size:14px; color:#8080a0; margin-bottom:24px; }

.form-group { margin-bottom: 16px; }
.form-group label { display:block; font-size:13px; color:#b0b0c8; margin-bottom:6px; }
.form-group input, .form-group select {
    width:100%; padding:10px 14px;
    border:1px solid rgba(255,255,255,0.12);
    border-radius:10px; background:rgba(255,255,255,0.06);
    color:#e0e0e0; font-size:14px; outline:none; transition:all 0.3s;
}
.form-group input:focus, .form-group select:focus { border-color:#667eea; }

.toggle-group {
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 0; margin-bottom:16px;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.toggle-group .label { font-size:15px; }
.toggle-group .label small { display:block; font-size:12px; color:#8080a0; font-weight:normal; }

.toggle {
    width:50px; height:26px; border-radius:13px;
    background:rgba(255,255,255,0.15); cursor:pointer;
    position:relative; transition:all 0.3s; border: none; padding:0;
}
.toggle.on { background: linear-gradient(135deg, #667eea, #764ba2); }
.toggle .knob {
    width:22px; height:22px; border-radius:50%;
    background:#fff; position:absolute; top:2px; left:2px;
    transition:all 0.3s; box-shadow:0 1px 3px rgba(0,0,0,0.3);
}
.toggle.on .knob { left:26px; }

.btn-save {
    width:100%; padding:12px;
    border:none; border-radius:12px;
    background:linear-gradient(135deg, #667eea, #764ba2);
    color:white; font-size:15px; font-weight:600;
    cursor:pointer; transition:all 0.3s;
}
.btn-save:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(102,126,234,0.4); }
.back-link { text-align:center; margin-top:16px; }
.back-link a { color:#667eea; text-decoration:none; font-size:14px; }
.back-link a:hover { text-decoration:underline; }

.success-msg {
    background:rgba(76,175,80,0.12);
    border:1px solid rgba(76,175,80,0.2);
    border-radius:10px; padding:10px;
    text-align:center; color:#4caf50; font-size:14px; margin-bottom:16px;
}

.email-fields { display: <?php echo $email_enabled ? 'block' : 'none'; ?>; }
.hint { font-size:12px; color:#8080a0; margin-top:4px; }
</style>
</head>
<body>
<div class="box">
    <h2><i class="fas fa-bell"></i> 通知设置</h2>
    <p class="sub">配置邮箱通知，新拍照时自动发送邮件</p>
    
    <?php if ($saved): ?>
    <div class="success-msg"><i class="fas fa-check-circle"></i> 设置已保存</div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_email">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        
        <div class="toggle-group">
            <div class="label">
                邮箱通知
                <small>新拍照时自动发送邮件</small>
            </div>
            <button type="button" class="toggle <?php echo $email_enabled ? 'on' : ''; ?>" id="toggleBtn" onclick="toggleEmail()">
                <div class="knob"></div>
            </button>
        </div>
        <input type="hidden" name="email_enabled" id="emailEnabled" value="<?php echo $email_enabled ? '1' : '0'; ?>">
        
        <div class="email-fields" id="emailFields">
            <div class="form-group">
                <label><i class="fas fa-server"></i> SMTP 服务器</label>
                <input type="text" name="email_smtp_host" value="<?php echo htmlspecialchars($smtp_host); ?>" placeholder="smtp.qq.com">
                <div class="hint">QQ邮箱: smtp.qq.com · 163邮箱: smtp.163.com · Gmail: smtp.gmail.com</div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-plug"></i> SMTP 端口</label>
                <select name="email_smtp_port">
                    <option value="465" <?php echo $smtp_port === '465' ? 'selected' : ''; ?>>465 (SSL)</option>
                    <option value="587" <?php echo $smtp_port === '587' ? 'selected' : ''; ?>>587 (TLS)</option>
                    <option value="25" <?php echo $smtp_port === '25' ? 'selected' : ''; ?>>25</option>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-shield-alt"></i> 加密方式</label>
                <select name="email_smtp_secure">
                    <option value="ssl" <?php echo $smtp_secure === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    <option value="tls" <?php echo $smtp_secure === 'tls' ? 'selected' : ''; ?>>TLS</option>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> 发件邮箱地址</label>
                <input type="email" name="email_send_address" value="<?php echo htmlspecialchars($send_address); ?>" placeholder="your@qq.com">
            </div>
            <div class="form-group">
                <label><i class="fas fa-key"></i> SMTP 授权码</label>
                <input type="password" name="email_send_password" placeholder="<?php echo $has_password ? '••••••••（留空不修改）' : '请输入授权码'; ?>">
                <div class="hint">QQ邮箱需开启SMTP服务获取授权码，不是登录密码</div>
            </div>
            <div class="form-group">
                <label><i class="fas fa-inbox"></i> 接收通知邮箱</label>
                <input type="email" name="email_receive_address" value="<?php echo htmlspecialchars($receive_address); ?>" placeholder="receive@example.com">
            </div>
        </div>
        
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> 保存设置</button>
    </form>
    
    <div class="back-link">
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> 返回控制台</a>
    </div>
</div>

<script>
function toggleEmail() {
    var btn = document.getElementById('toggleBtn');
    var fields = document.getElementById('emailFields');
    var input = document.getElementById('emailEnabled');
    var isOn = btn.classList.contains('on');
    
    if (isOn) {
        btn.classList.remove('on');
        fields.style.display = 'none';
        input.value = '0';
    } else {
        btn.classList.add('on');
        fields.style.display = 'block';
        input.value = '1';
    }
}
</script>
</body>
</html>
