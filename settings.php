<?php
/**
 * Online-Mirror v3.0 - 系统设置
 * 邮箱通知设置 + AI 人像分析设置
 */
require_once __DIR__ . '/config.php';
requireLogin();

$db = getDB();
$user = getCurrentUser();
$csrf = csrfToken();

// ========== 标签切换 ==========
$tab = $_GET['tab'] ?? 'email';

// ========== 处理邮箱设置保存 ==========
$email_saved = false;
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
    
    $email_saved = true;
}

// ========== 打赏设置保存 ==========
$donation_saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_donation') {
    requireCsrf();
    $enabled = isset($_POST['donation_enabled']) ? '1' : '0';
    setSetting('donation_enabled', $enabled);
    
    $channels = [];
    $names = $_POST['donation_name'] ?? [];
    $imgs = $_POST['donation_image'] ?? [];
    foreach ($names as $i => $name) {
        $name = trim($name);
        $img = trim($imgs[$i] ?? '');
        if (!empty($name) && !empty($img)) {
            $channels[] = ['name' => $name, 'image' => $img];
        }
    }
    setSetting('donation_channels', json_encode($channels));
    $donation_saved = true;
}

// ========== 系统维护处理 ==========
$init_success = $init_error = $self_destruct_error = $self_destruct_success = '';
$email_configured_maintenance = getSetting('email_enabled') === '1'
    && !empty(getSetting('email_send_address'))
    && !empty(getSetting('email_send_password'))
    && !empty(getSetting('email_smtp_host'));

// 初始化系统
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'init_system') {
    requireCsrf();
    $verify_method = trim($_POST['verify_method'] ?? 'password');
    $verified = false;
    if ($verify_method === 'password') {
        $pw = trim($_POST['verify_password'] ?? '');
        $stmt = $db->prepare("SELECT password FROM mir_users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $u = $stmt->fetch();
        if ($u && $pw === $u['password']) {
            $verified = true;
        } else {
            $init_error = '管理员密码错误';
        }
    } elseif ($verify_method === 'email' && $email_configured_maintenance) {
        $vcode_email = trim($_POST['verify_email'] ?? '');
        $vcode_input = trim($_POST['verify_code'] ?? '');
        $stored = getSetting('vcode_' . $vcode_email);
        if ($stored) {
            $parts = explode('|', $stored);
            if (count($parts) === 2 && $parts[0] === $vcode_input && (time() - intval($parts[1])) <= 300) {
                $verified = true;
                setSetting('vcode_' . $vcode_email, '');
            } else {
                $init_error = '验证码错误或已过期';
            }
        } else {
            $init_error = '请先发送验证码';
        }
    }
    if ($verified) {
        try {
            // 清空照片表
            $db->exec("DELETE FROM " . table("photos"));
            // 清空日志表
            $db->exec("DELETE FROM " . table("logs"));
            // 清空链接表
            $db->exec("DELETE FROM " . table("links"));
            // 删除图片文件（保留防护文件）
            $img_files = glob(IMG_DIR . "*.*");
            foreach ($img_files as $f) {
                $bn = basename($f);
                if ($bn !== "index.html" && $bn !== ".htaccess") {
                    @unlink($f);
                }
            }
            // 删除录音文件
            $upload_dir = __DIR__ . "/uploads/";
            if (is_dir($upload_dir)) {
                $upload_files = glob($upload_dir . "*.*");
                foreach ($upload_files as $f) {
                    @unlink($f);
                }
            }
            $init_success = "✅ 系统已初始化：照片、日志、链接数据已清空，图片和录音文件已删除";
        } catch (Exception $e) {
            $init_error = '初始化失败：' . $e->getMessage();
        }
    }
}

// 自毁系统
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'self_destruct') {
    requireCsrf();
    $verify_method = trim($_POST['sd_verify_method'] ?? 'password');
    $verified = false;
    if ($verify_method === 'password') {
        $pw = trim($_POST['sd_verify_password'] ?? '');
        $stmt = $db->prepare("SELECT password FROM mir_users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $u = $stmt->fetch();
        if ($u && $pw === $u['password']) {
            $verified = true;
        } else {
            $self_destruct_error = '管理员密码错误';
        }
    } elseif ($verify_method === 'email' && $email_configured_maintenance) {
        $vcode_email = trim($_POST['sd_verify_email'] ?? '');
        $vcode_input = trim($_POST['sd_verify_code'] ?? '');
        $stored = getSetting('vcode_' . $vcode_email);
        if ($stored) {
            $parts = explode('|', $stored);
            if (count($parts) === 2 && $parts[0] === $vcode_input && (time() - intval($parts[1])) <= 300) {
                $verified = true;
                setSetting('vcode_' . $vcode_email, '');
            } else {
                $self_destruct_error = '验证码错误或已过期';
            }
        } else {
            $self_destruct_error = '请先发送验证码';
        }
    }
    if ($verified) {
        try {
            // 退出登录
            session_destroy();
            // 递归删除目录
            $rm_func = function($dir) use (&$rm_func) {
                $files = array_diff(scandir($dir), array('.', '..'));
                foreach ($files as $f) {
                    $path = $dir . '/' . $f;
                    is_dir($path) ? $rm_func($path) : @unlink($path);
                }
                return @rmdir($dir);
            };
            $rm_func(__DIR__);
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>系统已自毁</title><style>body{background:#0f0c29;color:#e0e0e0;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:20px;}h1{font-size:28px;color:#ff6b6b;margin-bottom:8px;}p{color:#8080a0;font-size:14px;line-height:1.6;}</style></head><body><div><h1>💥 系统已自毁</h1><p>网恋照妖镜系统已完全删除，感谢使用！</p></div></body></html>';
            exit;
        } catch (Exception $e) {
            $self_destruct_success = '⚠️ 自毁完成（部分文件可能被占用无法删除）';
        }
    }
}


// ========== 处理AI设置保存 ==========
$ai_saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ai') {
    requireCsrf();
    
    $model = trim($_POST['ai_model'] ?? 'glm-4v-flash');
    $api_key = trim($_POST['ai_api_key'] ?? '');
    $prompt = trim($_POST['ai_prompt'] ?? '');
    $quota = intval($_POST['ai_quota'] ?? 3);
    $more_options = trim($_POST['ai_more_options'] ?? '');
    
    // 分析选项（多选）
    $selected_options = $_POST['ai_options'] ?? [];
    if (!is_array($selected_options)) $selected_options = [];
    
    setSetting('ai_model', $model);
    setSetting('ai_analysis_quota', strval(max(1, $quota)));
    if (!empty($api_key)) setSetting('ai_api_key', $api_key);
    if (!empty($prompt)) setSetting('ai_prompt', $prompt);
    setSetting('ai_analysis_options', json_encode($selected_options));
    setSetting('ai_analysis_more', $more_options);
    // 有key就自动启用
    setSetting('ai_enabled', !empty($api_key) ? '1' : '0');
    
    $ai_saved = true;
}

// ========== 当前设置 ==========

// 邮箱设置
$email_enabled = getSetting('email_enabled') === '1';
$smtp_host = getSetting('email_smtp_host') ?: 'smtp.qq.com';
$smtp_port = getSetting('email_smtp_port') ?: '465';
$smtp_secure = getSetting('email_smtp_secure') ?: 'ssl';
$send_address = getSetting('email_send_address') ?: '';
$receive_address = getSetting('email_receive_address') ?: '';
$has_password = !empty(getSetting('email_send_password'));

// 打赏设置
$donation_enabled = getSetting('donation_enabled') === '1';
$donation_channels = json_decode(getSetting('donation_channels') ?: '[]', true);

// AI设置
$ai_settings = getAISettings();
$ai_model = $ai_settings['model'];
$ai_api_key = getSetting('ai_api_key') ?: '';
$ai_has_key = !empty($ai_api_key);
$ai_prompt = $ai_settings['prompt'];
$ai_quota = $ai_settings['quota'];
$ai_options = $ai_settings['options'];
$ai_more_options = $ai_settings['more_options'];

$all_ai_options = [
    'has_person'   => '👤 是否有人像',
    'age'          => '📅 年龄估算',
    'gender'       => '⚧️ 性别判断',
    'expression'   => '😊 表情分析',
    'is_real'      => '🔍 真人/网图判断',
    'face_read'    => '🔮 面相性格',
    'environment'  => '🏠 环境描述',
    'scene_desc'   => '🖼️ 画面内容',
    'light_color'  => '💡 光线色调',
    'shoot_scene'  => '📷 拍摄场景',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>系统设置 · 网恋照妖镜</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh; color: #e0e0e0; padding: 20px;
}
.box {
    max-width: 600px; margin: 0 auto;
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px; padding: 28px;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}

/* 标签导航 */
.tab-nav {
    display: flex; gap: 4px; margin-bottom: 24px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.tab-nav a {
    padding: 10px 20px; border-radius: 10px 10px 0 0;
    text-decoration: none; font-size: 14px; color: #8080a0;
    transition: all 0.3s; display: flex; align-items: center; gap: 6px;
}
.tab-nav a:hover { background: rgba(255,255,255,0.04); color: #e0e0e0; }
.tab-nav a.active {
    background: rgba(102,126,234,0.12); color: #667eea;
    border-bottom: 2px solid #667eea;
}

.box h2 { text-align:center; margin-bottom:4px; }
.box .sub { text-align:center; font-size:14px; color:#8080a0; margin-bottom:24px; }

.form-group { margin-bottom: 16px; }
.form-group label { display:block; font-size:13px; color:#b0b0c8; margin-bottom:6px; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; padding:10px 14px;
    border:1px solid rgba(255,255,255,0.12);
    border-radius:10px; background:rgba(255,255,255,0.06);
    color:#e0e0e0; font-size:14px; outline:none; transition:all 0.3s;
    font-family: inherit;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#667eea; }
.form-group textarea { min-height: 80px; resize: vertical; }
.form-group select option { background: #1a1a2e; color: #e0e0e0; }

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
    margin-top: 8px;
}
.btn-save:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(102,126,234,0.4); }
/* ====== 系统维护 ====== */
.btn-danger {
    width:100%; padding:12px;
    border:none; border-radius:12px;
    background:linear-gradient(135deg, #ff6b6b, #ee5a24);
    color:white; font-size:15px; font-weight:600;
    cursor:pointer; transition:all 0.3s;
    margin-top: 8px;
}
.btn-danger:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(255,80,80,0.4); }
.btn-danger:disabled {
    opacity:0.4; cursor:not-allowed; transform:none; box-shadow:none;
}
.btn-warning {
    width:100%; padding:12px;
    border:none; border-radius:12px;
    background:linear-gradient(135deg, #ff9800, #f57c00);
    color:white; font-size:15px; font-weight:600;
    cursor:pointer; transition:all 0.3s;
    margin-top: 8px;
}
.btn-warning:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(255,152,0,0.4); }
.btn-warning:disabled {
    opacity:0.4; cursor:not-allowed; transform:none; box-shadow:none;
}
.maintenance-card {
    background:rgba(255,80,80,0.04);
    border:1px solid rgba(255,80,80,0.12);
    border-radius:16px;
    padding:20px;
    margin-bottom:16px;
}
.maintenance-card h3 {
    font-size:16px;
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:8px;
}
.maintenance-card p {
    font-size:13px;
    color:#8080a0;
    line-height:1.6;
    margin-bottom:12px;
}
.maintenance-card .countdown { font-size:36px; font-weight:700; color:#ff6b6b; text-align:center; padding:12px 0; }
.maintenance-card .confirm-area { text-align:center; margin-top:12px; }
.maintenance-card .confirm-area input {
    width:100%; padding:10px 14px; margin-bottom:8px;
    border:1px solid rgba(255,80,80,0.2);
    border-radius:10px; background:rgba(0,0,0,0.2);
    color:#e0e0e0; font-size:14px; outline:none;
}
.maintenance-card .confirm-area input:focus { border-color:#ff6b6b; }
.self-destruct-toggle {
    margin-bottom:16px;
    padding:20px;
    background:rgba(255,0,0,0.04);
    border:1px solid rgba(255,0,0,0.1);
    border-radius:16px;
}
.self-destruct-toggle .toggle-group { border-bottom:none; margin-bottom:0; padding:0; }
.maintenance-icon-danger { color:#ff6b6b; }
.maintenance-icon-warning { color:#ff9800; }

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
.hint-warn { font-size:12px; color:#ff9800; margin-top:4px; }

/* 推荐模型按钮 */
.recommend-btn {
    width:100%; padding:12px; margin-bottom:16px;
    border:1px dashed rgba(102,126,234,0.3);
    border-radius:12px; background:rgba(102,126,234,0.06);
    color:#667eea; font-size:14px; cursor:pointer;
    transition:all 0.3s; display:flex; align-items:center; justify-content:center; gap:8px;
}
.recommend-btn:hover { background:rgba(102,126,234,0.12); border-style:solid; }

/* 推荐模型弹窗 */
.recommend-modal {
    display:none;
    position:fixed; top:0; left:0; right:0; bottom:0;
    z-index:9999; background:rgba(0,0,0,0.8);
    justify-content:center; align-items:center;
}
.recommend-modal.show { display:flex; }
.recommend-modal .modal-box {
    background:#1a1a2e;
    border:1px solid rgba(255,255,255,0.12);
    border-radius:16px; overflow:hidden;
    width:90vw; max-width:500px; max-height:90vh; overflow-y:auto;
}
.recommend-modal .modal-header {
    padding:16px 20px; display:flex; justify-content:space-between; align-items:center;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.recommend-modal .modal-header h3 { font-size:16px; }
.recommend-modal .modal-header .close-btn {
    font-size:24px; color:#8080a0; cursor:pointer; border:none; background:none;
}
.recommend-modal .modal-header .close-btn:hover { color:#fff; }
.recommend-modal .modal-body { padding:20px; }
.recommend-modal .modal-body p { font-size:14px; line-height:1.8; color:#c0c0d0; margin-bottom:12px; }

/* 模型卡片 */
.model-card {
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:12px; padding:14px 16px; margin-bottom:12px;
    display:flex; align-items:center; gap:12px;
}
.model-card .mc-icon { font-size:24px; width:40px; text-align:center; }
.model-card .mc-info { flex:1; }
.model-card .mc-name { font-size:14px; font-weight:600; }
.model-card .mc-desc { font-size:12px; color:#8080a0; margin-top:2px; }
.model-card .mc-badge {
    padding:3px 10px; border-radius:6px; font-size:11px; font-weight:600;
}
.badge-free { background:rgba(76,175,80,0.15); color:#4caf50; }
.badge-paid { background:rgba(255,152,0,0.15); color:#ff9800; }

.confirm-btn {
    display:block; width:100%; padding:12px; margin-top:16px;
    border:none; border-radius:12px;
    background:linear-gradient(135deg,#667eea,#764ba2);
    color:white; font-size:15px; font-weight:600; cursor:pointer; transition:all 0.3s;
    text-decoration:none; text-align:center;
}
.confirm-btn:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(102,126,234,0.4); }

/* 分析选项checkbox */
.option-grid {
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap:8px; margin-bottom:16px;
}
.option-item {
    display:flex; align-items:center; gap:8px;
    padding:8px 12px;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:8px; cursor:pointer; transition:all 0.3s;
    font-size:13px;
}
.option-item:hover { background:rgba(255,255,255,0.08); }
.option-item.checked { border-color:#667eea; background:rgba(102,126,234,0.1); }
.option-item input[type="checkbox"] { display:none; }
.option-item .check-icon {
    width:18px; height:18px; border-radius:4px;
    border:2px solid rgba(255,255,255,0.2);
    display:flex; align-items:center; justify-content:center;
    font-size:10px; transition:all 0.3s;
}
.option-item.checked .check-icon {
    background:#667eea; border-color:#667eea;
}

@media (max-width: 480px) {
    .box { padding:20px 16px; }
    .option-grid { grid-template-columns: 1fr 1fr; }
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
<div class="box">
    <h2><i class="fas fa-cog"></i> 系统设置</h2>
    <p class="sub">配置邮箱通知与 AI 人像分析</p>
    
    <!-- 标签导航 -->
    <div class="tab-nav">
        <a href="?tab=email" class="<?php echo $tab === 'email' ? 'active' : ''; ?>"><i class="fas fa-bell"></i> 邮箱通知</a>
        <a href="?tab=donation" class="<?php echo $tab === 'donation' ? 'active' : ''; ?>"><i class="fas fa-coffee"></i> 打赏</a>
        <a href="?tab=ai" class="<?php echo $tab === 'ai' ? 'active' : ''; ?>"><i class="fas fa-robot"></i> AI 分析</a>
        <?php if ($user['role'] === 'admin'): ?>
        <a href="?tab=maintenance" class="<?php echo $tab === 'maintenance' ? 'active' : ''; ?>"><i class="fas fa-tools"></i> 系统维护</a>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'email'): ?>
    <!-- ======================== 邮箱通知设置 ======================== -->
    <?php if ($email_saved): ?>
    <div class="success-msg"><i class="fas fa-check-circle"></i> 邮箱设置已保存</div>
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
        
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> 保存邮箱设置</button>
    </form>

    <?php elseif ($tab === 'donation'): ?>
    <!-- ======================== 打赏设置 ======================== -->
    <?php if ($donation_saved): ?>
    <div class="success-msg"><i class="fas fa-check-circle"></i> 打赏设置已保存</div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_donation">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        
        <div class="toggle-group">
            <div class="label">
                打赏功能
                <small>首页底部显示打赏入口</small>
            </div>
            <button type="button" class="toggle <?php echo $donation_enabled ? 'on' : ''; ?>" id="donationToggle" onclick="toggleDonation()">
                <div class="knob"></div>
            </button>
        </div>
        <input type="hidden" name="donation_enabled" id="donationEnabled" value="<?php echo $donation_enabled ? '1' : '0'; ?>">
        
        <div id="donationFields" style="display:<?php echo $donation_enabled ? 'block' : 'none'; ?>;">
            <p style="font-size:13px;color:#8080a0;margin-bottom:12px;"><i class="fas fa-info-circle"></i> 添加收款方式，至少上传一个二维码图片</p>
            
            <div id="donationChannels">
                <?php if (empty($donation_channels)): ?>
                <div class="donation-channel" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px;margin-bottom:12px;">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:120px;">
                            <label style="font-size:12px;color:#b0b0c8;display:block;margin-bottom:4px;">名称</label>
                            <input type="text" name="donation_name[]" placeholder="微信打赏" style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:14px;outline:none;">
                        </div>
                        <div style="flex:2;min-width:200px;">
                            <label style="font-size:12px;color:#b0b0c8;display:block;margin-bottom:4px;">二维码图片URL</label>
                            <input type="text" name="donation_image[]" placeholder="https://... 或 /uploads/donation/qr.png" style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:14px;outline:none;">
                        </div>
                        <button type="button" onclick="this.parentElement.parentElement.remove()" style="align-self:flex-end;padding:10px 14px;border:none;border-radius:8px;background:rgba(255,80,80,0.1);color:#ff6b6b;cursor:pointer;font-size:13px;"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($donation_channels as $ch): ?>
                    <div class="donation-channel" style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px;margin-bottom:12px;">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:120px;">
                                <label style="font-size:12px;color:#b0b0c8;display:block;margin-bottom:4px;">名称</label>
                                <input type="text" name="donation_name[]" value="<?php echo htmlspecialchars($ch['name']); ?>" style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:14px;outline:none;">
                            </div>
                            <div style="flex:2;min-width:200px;">
                                <label style="font-size:12px;color:#b0b0c8;display:block;margin-bottom:4px;">二维码图片URL</label>
                                <input type="text" name="donation_image[]" value="<?php echo htmlspecialchars($ch['image']); ?>" placeholder="https://..." style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:14px;outline:none;">
                            </div>
                            <button type="button" onclick="this.parentElement.parentElement.remove()" style="align-self:flex-end;padding:10px 14px;border:none;border-radius:8px;background:rgba(255,80,80,0.1);color:#ff6b6b;cursor:pointer;font-size:13px;"><i class="fas fa-times"></i></button>
                        </div>
                        <?php if (!empty($ch['image'])): ?>
                        <div style="margin-top:8px;">
                            <img src="<?php echo htmlspecialchars($ch['image']); ?>" style="width:80px;height:80px;border-radius:8px;background:#fff;padding:4px;object-fit:contain;">
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <button type="button" onclick="addDonationChannel()" style="width:100%;padding:12px;border:1px dashed rgba(255,255,255,0.15);border-radius:10px;background:rgba(255,255,255,0.03);color:#8080a0;font-size:14px;cursor:pointer;transition:all 0.3s;margin-bottom:16px;" onmouseover="this.style.background='rgba(255,255,255,0.06)'" onmouseout="this.style.background='rgba(255,255,255,0.03)'">
                <i class="fas fa-plus"></i> 添加收款方式
            </button>
        </div>
        
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> 保存打赏设置</button>
    </form>
    
    <script>
    function toggleDonation() {
        var btn = document.getElementById('donationToggle');
        var fields = document.getElementById('donationFields');
        var input = document.getElementById('donationEnabled');
        btn.classList.toggle('on');
        var isOn = btn.classList.contains('on');
        fields.style.display = isOn ? 'block' : 'none';
        input.value = isOn ? '1' : '0';
    }
    function addDonationChannel() {
        var container = document.getElementById('donationChannels');
        var div = document.createElement('div');
        div.className = 'donation-channel';
        div.style.cssText = 'background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px;margin-bottom:12px;';
        div.innerHTML = '<div style="display:flex;gap:10px;flex-wrap:wrap;">' +
            '<div style="flex:1;min-width:120px;">' +
                '<label style="font-size:12px;color:#b0b0c8;display:block;margin-bottom:4px;">名称</label>' +
                '<input type="text" name="donation_name[]" placeholder="支付宝打赏" style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:14px;outline:none;">' +
            '</div>' +
            '<div style="flex:2;min-width:200px;">' +
                '<label style="font-size:12px;color:#b0b0c8;display:block;margin-bottom:4px;">二维码图片URL</label>' +
                '<input type="text" name="donation_image[]" placeholder="https://..." style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:14px;outline:none;">' +
            '</div>' +
            '<button type="button" onclick="this.parentElement.parentElement.remove()" style="align-self:flex-end;padding:10px 14px;border:none;border-radius:8px;background:rgba(255,80,80,0.1);color:#ff6b6b;cursor:pointer;font-size:13px;"><i class="fas fa-times"></i></button>' +
        '</div>';
        container.appendChild(div);
    }
    </script>

    <?php elseif ($tab === 'ai'): ?>
    <!-- ======================== AI 分析设置 ======================== -->
    <?php if ($ai_saved): ?>
    <div class="success-msg"><i class="fas fa-check-circle"></i> AI 分析设置已保存</div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="save_ai">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        
        <div style="padding:14px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:16px;display:flex;align-items:center;gap:12px;">
            <i class="fas fa-robot" style="font-size:28px;color:#667eea;"></i>
            <div>
                <div style="font-size:15px;">AI 人像分析</div>
                <div style="font-size:12px;color:#8080a0;">配置后首页及照片页将启用 AI 分析功能</div>
            </div>
            <?php if ($ai_has_key): ?>
            <span style="margin-left:auto;padding:4px 12px;border-radius:8px;background:rgba(76,175,80,0.12);color:#4caf50;font-size:12px;"><i class="fas fa-check-circle"></i> 已配置</span>
            <?php else: ?>
            <span style="margin-left:auto;padding:4px 12px;border-radius:8px;background:rgba(255,152,0,0.12);color:#ff9800;font-size:12px;"><i class="fas fa-exclamation-triangle"></i> 未配置</span>
            <?php endif; ?>
        </div>

        <!-- 推荐免费模型 -->
        <button type="button" class="recommend-btn" onclick="showRecommend()">
            <i class="fas fa-lightbulb"></i> 推荐免费模型
            <i class="fas fa-chevron-right" style="font-size:12px;"></i>
        </button>

        <div class="form-group">
            <label><i class="fas fa-cube"></i> 模型名称</label>
            <input type="text" name="ai_model" value="<?php echo htmlspecialchars($ai_model); ?>" placeholder="glm-4v-flash">
            <div class="hint">默认为智谱 GLM-4V-Flash（视觉模型），支持多模态识别</div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-key"></i> API 密钥</label>
            <input type="password" name="ai_api_key" placeholder="<?php echo $ai_has_key ? '••••••••（留空不修改）' : '请输入 API Key'; ?>">
            <div class="hint">智谱开放平台获取：<a href="https://open.bigmodel.cn/" target="_blank" style="color:#667eea;">open.bigmodel.cn</a></div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-tachometer-alt"></i> 每链接分析限额</label>
            <input type="number" name="ai_quota" value="<?php echo $ai_quota; ?>" min="1" max="100">
            <div class="hint">每个链接 ID 最多可 AI 分析次数（默认 3 次）</div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-sliders-h"></i> 分析维度（多选）</label>
            <div class="hint" style="margin-bottom:8px;">选择 AI 分析时要包含的维度</div>
            <div class="option-grid">
                <?php foreach ($all_ai_options as $opt_key => $opt_label): ?>
                <label class="option-item <?php echo in_array($opt_key, $ai_options) ? 'checked' : ''; ?>">
                    <input type="checkbox" name="ai_options[]" value="<?php echo $opt_key; ?>" <?php echo in_array($opt_key, $ai_options) ? 'checked' : ''; ?> onchange="this.parentElement.classList.toggle('checked')">
                    <span class="check-icon"><i class="fas fa-check" style="color:#fff;"></i></span>
                    <span><?php echo $opt_label; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-comment"></i> AI 提示词（Prompt）</label>
            <textarea name="ai_prompt" rows="4" placeholder="输入分析提示词…"><?php echo htmlspecialchars($ai_prompt); ?></textarea>
            <div class="hint">发送给 AI 的指令，告诉它怎么分析照片。支持用【】标记维度</div>
        </div>

        <div class="form-group">
            <label><i class="fas fa-ellipsis-h"></i> 更多选项</label>
            <textarea name="ai_more_options" rows="2" placeholder="额外的配置选项或备注…"><?php echo htmlspecialchars($ai_more_options); ?></textarea>
            <div class="hint">可选，填写额外参数或说明（仅管理员可见）</div>
        </div>

        <button type="submit" class="btn-save"><i class="fas fa-save"></i> 保存 AI 设置</button>
    </form>
    <?php elseif ($tab === 'maintenance' && $user['role'] === 'admin'): ?>
<!-- ======================== 系统维护 ======================== -->
<?php if ($init_success): ?>
<div class="success-msg"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($init_success); ?></div>
<?php endif; ?>
<?php if ($init_error): ?>
<div style="background:rgba(255,80,80,0.12);border:1px solid rgba(255,80,80,0.2);border-radius:10px;padding:10px;text-align:center;color:#ff6b6b;font-size:14px;margin-bottom:16px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($init_error); ?></div>
<?php endif; ?>
<?php if ($self_destruct_success): ?>
<div class="success-msg"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($self_destruct_success); ?></div>
<?php endif; ?>
<?php if ($self_destruct_error): ?>
<div style="background:rgba(255,80,80,0.12);border:1px solid rgba(255,80,80,0.2);border-radius:10px;padding:10px;text-align:center;color:#ff6b6b;font-size:14px;margin-bottom:16px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($self_destruct_error); ?></div>
<?php endif; ?>

<!-- 初始化系统 -->
<div class="maintenance-card">
    <h3><i class="fas fa-broom maintenance-icon-warning"></i> 初始化系统</h3>
    <p>清空所有照片、日志和链接数据。此操作不可逆，请谨慎操作！<br>初始化后将删除：<br>
    • mir_photos 表中的所有记录<br>
    • mir_logs 表中的所有记录<br>
    • mir_links 表中的所有记录<br>
    • img/ 目录下的所有图片文件<br>
    • uploads/ 目录下的所有录音文件</p>
    <div id="initCountdown" class="countdown" style="display:none;">5</div>
    <button class="btn-warning" id="initBtn" onclick="startInitCountdown()"><i class="fas fa-broom"></i> 初始化系统</button>
    <div id="initConfirmArea" class="confirm-area" style="display:none;">
        <p style="font-size:13px;color:#ff9800;margin-bottom:8px;"><i class="fas fa-exclamation-triangle"></i> 请验证身份后继续</p>
        <form method="POST" id="initForm">
            <input type="hidden" name="action" value="init_system">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <?php if ($email_configured_maintenance): ?>
            <div style="margin-bottom:8px;">
                <select name="verify_method" class="verify-method-select" data-prefix="init" style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:13px;outline:none;">
                    <option value="password">🔑 密码验证</option>
                    <option value="email">📧 邮箱验证码</option>
                </select>
            </div>
            <?php endif; ?>
            <div id="initPasswordRow" style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="password" name="verify_password" placeholder="管理员密码" style="flex:1;">
            </div>
            <div id="initEmailRow" style="display:none;flex-direction:column;gap:8px;margin-bottom:8px;">
                <?php if (!$email_configured_maintenance): ?>
                <p style="font-size:12px;color:#8080a0;"><i class="fas fa-info-circle"></i> 邮箱验证码功能需要先在「邮箱通知」TAB中配置SMTP发件服务</p>
                <?php endif; ?>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="email" name="verify_email" placeholder="接收验证码的邮箱" style="flex:1;padding:12px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.08);color:#e0e0e0;font-size:14px;outline:none;" id="initVerifyEmail">
                    <button type="button" class="btn-warning" style="width:auto;flex-shrink:0;padding:8px 12px;font-size:12px;white-space:nowrap;margin-top:0;border:none;border-radius:8px;background:rgba(255,152,0,0.15);color:#ff9800;cursor:pointer;" onclick="sendMaintenanceVcode('init')"><i class="fas fa-paper-plane"></i> 发送</button>
                </div>
                <input type="text" name="verify_code" placeholder="输入6位验证码" style="width:100%;max-width:200px;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.08);color:#e0e0e0;font-size:14px;outline:none;" id="initVerifyCode" maxlength="6">
            </div>
            <button type="submit" class="btn-danger"><i class="fas fa-broom"></i> 确认初始化</button>
        </form>
    </div>
</div>

<!-- 自毁系统 -->
<div class="self-destruct-toggle">
    <div class="toggle-group">
        <div class="label">
            <i class="fas fa-skull-crossbones maintenance-icon-danger" style="font-size:18px;"></i> 自毁系统
            <small style="color:#ff6b6b;">⚡ 高危操作，将删除整个系统</small>
        </div>
        <button type="button" class="toggle" id="selfDestructToggle" onclick="toggleSelfDestruct()" style="background:rgba(255,80,80,0.3);">
            <div class="knob"></div>
        </button>
    </div>
</div>

<div id="selfDestructBody" style="display:none;">
    <div class="maintenance-card" style="border-color:rgba(255,0,0,0.3);">
        <h3 style="color:#ff6b6b;"><i class="fas fa-skull-crossbones maintenance-icon-danger"></i> 高危操作确认</h3>
        <p style="color:#ff6b6b;">此操作将完全删除 /var/www/html/mirror/ 目录下的所有文件和数据，<strong>不可恢复</strong>！</p>
        <div id="sdCountdown" class="countdown" style="display:none;">10</div>
        <button class="btn-danger" id="sdBtn" onclick="startSDCountdown()"><i class="fas fa-skull-crossbones"></i> 开始自毁（10秒倒计时）</button>
        <div id="sdConfirmArea" class="confirm-area" style="display:none;">
            <p style="font-size:13px;color:#ff6b6b;margin-bottom:8px;"><i class="fas fa-exclamation-triangle"></i> 请验证身份后执行自毁</p>
            <form method="POST">
                <input type="hidden" name="action" value="self_destruct">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <div id="sdPasswordRow" style="display:flex;gap:8px;margin-bottom:8px;">
                    <input type="password" name="sd_verify_password" placeholder="管理员密码" style="flex:1;">
                </div>
                <?php if ($email_configured_maintenance): ?>
                <div style="margin-bottom:8px;">
                    <select name="sd_verify_method" class="verify-method-select" data-prefix="sd" style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#e0e0e0;font-size:13px;outline:none;">
                        <option value="password">🔑 密码验证</option>
                        <option value="email">📧 邮箱验证码</option>
                    </select>
                </div>
                <div id="sdEmailRow" style="display:none;flex-direction:column;gap:8px;margin-bottom:8px;">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="email" name="sd_verify_email" placeholder="接收验证码的邮箱" style="flex:1;padding:12px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.08);color:#e0e0e0;font-size:14px;outline:none;" id="sdVerifyEmail">
                        <button type="button" class="btn-danger" style="width:auto;flex-shrink:0;padding:8px 12px;font-size:12px;white-space:nowrap;margin-top:0;border:none;border-radius:8px;background:rgba(255,80,80,0.15);color:#ff6b6b;cursor:pointer;" onclick="sendMaintenanceVcode('sd')"><i class="fas fa-paper-plane"></i> 发送</button>
                    </div>
                    <input type="text" name="sd_verify_code" placeholder="输入6位验证码" style="width:100%;max-width:200px;padding:10px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.08);color:#e0e0e0;font-size:14px;outline:none;" id="sdVerifyCode" maxlength="6">
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-danger" style="background:linear-gradient(135deg,#d32f2f,#b71c1c);"><i class="fas fa-skull-crossbones"></i> 确认自毁系统</button>
            </form>
        </div>
    </div>
</div>

<?php if ($email_configured_maintenance): ?>
<script>
function sendMaintenanceVcode(prefix) {
    var emailInput = document.getElementById(prefix + 'VerifyEmail');
    var email = emailInput ? emailInput.value.trim() : '';
    if (!email) { alert('请输入邮箱地址'); return; }
    if (!/@/.test(email) || email.indexOf('.') === -1) { alert('邮箱格式不正确'); return; }
    
    var formData = new FormData();
    formData.append('action', 'send_vcode');
    formData.append('csrf_token', '<?php echo $csrf; ?>');
    formData.append('vcode_email', email);
    
    fetch('login.php', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { alert('✅ 验证码已发送到您的邮箱'); }
        else { alert('❌ ' + (data.error || '发送失败')); }
    })
    .catch(function() { alert('❌ 网络错误'); });
}
</script>
<?php endif; ?>

<script>
// 初始化系统倒计时
function startInitCountdown() {
    var btn = document.getElementById('initBtn');
    var countdown = document.getElementById('initCountdown');
    var confirmArea = document.getElementById('initConfirmArea');
    btn.disabled = true;
    btn.style.display = 'none';
    countdown.style.display = 'block';
    var sec = 5;
    countdown.textContent = sec;
    var timer = setInterval(function() {
        sec--;
        countdown.textContent = sec;
        if (sec <= 0) {
            clearInterval(timer);
            countdown.style.display = 'none';
            confirmArea.style.display = 'block';
        }
    }, 1000);
}

// 自毁系统倒计时
function startSDCountdown() {
    var btn = document.getElementById('sdBtn');
    var countdown = document.getElementById('sdCountdown');
    var confirmArea = document.getElementById('sdConfirmArea');
    btn.disabled = true;
    btn.style.display = 'none';
    countdown.style.display = 'block';
    var sec = 10;
    countdown.textContent = sec;
    var timer = setInterval(function() {
        sec--;
        countdown.textContent = sec;
        if (sec <= 0) {
            clearInterval(timer);
            countdown.style.display = 'none';
            confirmArea.style.display = 'block';
        }
    }, 1000);
}

// 自毁开关
function toggleSelfDestruct() {
    var btn = document.getElementById('selfDestructToggle');
    var body = document.getElementById('selfDestructBody');
    btn.classList.toggle('on');
    if (btn.classList.contains('on')) {
        btn.style.background = 'linear-gradient(135deg, #d32f2f, #b71c1c)';
        body.style.display = 'block';
    } else {
        btn.style.background = 'rgba(255,80,80,0.3)';
        body.style.display = 'none';
    }
}

// 验证方式切换（支持init和sd两种前缀）
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('verify-method-select')) {
        var prefix = e.target.getAttribute('data-prefix') || '';
        var passwordRow = document.getElementById(prefix + 'PasswordRow');
        var emailRow = document.getElementById(prefix + 'EmailRow');
        if (!passwordRow || !emailRow) return;
        
        if (e.target.value === 'email') {
            passwordRow.style.display = 'none';
            emailRow.style.display = 'flex';
        } else {
            passwordRow.style.display = 'flex';
            emailRow.style.display = 'none';
        }
    }
});
</script>


<?php endif; ?>
    <div class="back-link">
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> 返回控制台</a>
    </div>
</div>

<!-- ====== 推荐模型弹窗 ====== -->
<div class="recommend-modal" id="recommendModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="fas fa-lightbulb" style="color:#ffc107;"></i> 推荐免费 AI 模型</h3>
            <button class="close-btn" onclick="closeRecommend()">&times;</button>
        </div>
        <div class="modal-body">
            <p>以下模型支持<b>图片理解 + 人像分析</b>，适合本工具使用：</p>
            
            <div class="model-card">
                <div class="mc-icon">🧠</div>
                <div class="mc-info">
                    <div class="mc-name">智谱 GLM-4V-Flash</div>
                    <div class="mc-desc">免费 · 支持多模态 · 中文友好 · 推荐首选</div>
                </div>
                <span class="mc-badge badge-free">免费</span>
            </div>

            <div class="model-card">
                <div class="mc-icon">🌐</div>
                <div class="mc-info">
                    <div class="mc-name">阿里通义千问 Qwen-VL-Plus</div>
                    <div class="mc-desc">免费额度 · 百炼平台获取 Key</div>
                </div>
                <span class="mc-badge badge-free">免费</span>
            </div>

            <div class="model-card">
                <div class="mc-icon">🔮</div>
                <div class="mc-info">
                    <div class="mc-name">百度千帆 ERNIE-Bot</div>
                    <div class="mc-desc">有免费额度 · 千帆大模型平台</div>
                </div>
                <span class="mc-badge badge-free">免费</span>
            </div>

            <div class="model-card">
                <div class="mc-icon">☁️</div>
                <div class="mc-info">
                    <div class="mc-name">DeepSeek-V3 / Janus</div>
                    <div class="mc-desc">多模态理解 · 新秀模型</div>
                </div>
                <span class="mc-badge badge-free">免费</span>
            </div>

            <p style="margin-top:16px;padding:12px;background:rgba(102,126,234,0.08);border-radius:10px;font-size:13px;color:#c0c0d0;line-height:1.8;">
                <i class="fas fa-info-circle" style="color:#667eea;"></i> 
                <b>配置指引：</b><br>
                ① 前往 <a href="https://open.bigmodel.cn/" target="_blank" style="color:#667eea;">智谱开放平台</a> 注册账号<br>
                ② 进入「API 密钥」页面创建新的 API Key<br>
                ③ 将密钥复制到上方「API 密钥」输入框中<br>
                ④ 模型名称保持默认 <code>glm-4v-flash</code> 即可
            </p>

            <a href="https://open.bigmodel.cn/usercenter/apikeys" target="_blank" class="confirm-btn" onclick="closeRecommend()">
                <i class="fas fa-external-link-alt"></i> 前往智谱获取 Key
            </a>
        </div>
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

function showRecommend() {
    document.getElementById('recommendModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeRecommend() {
    document.getElementById('recommendModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeRecommend();
});
</script>
</body>
</html>
