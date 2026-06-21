<?php
/**
 * 🪞 网恋照妖镜 · 一键安装向导
 * 
 * 浏览器访问本文件即可启动安装流程：
 * 1. 填写数据库信息
 * 2. 自动创建数据库和数据表
 * 3. 设置管理员账号
 * 4. 自动写入 config.php
 * 5. 安装完成后自动删除自身
 * 
 * 本文件仅在全新安装时使用，不会覆盖已有数据。
 */

// ========== 检测是否已安装 ==========
$configFile = __DIR__ . '/config.php';

// 如果 config.php 中已有有效数据库连接且不是默认值，判断为已安装
$alreadyInstalled = false;
if (file_exists($configFile)) {
    $cfg = file_get_contents($configFile);
    // 检查是否有非默认的数据库密码（不是空字符串也不是环境变量）
    if (preg_match("/define\('DB_PASS',\s*'([^']+)'\)/", $cfg, $m)) {
        if (!empty($m[1]) && $m[1] !== getenv('DB_PASS') . '') {
            $alreadyInstalled = true;
        }
    }
}

// ========== 处理 POST 请求 ==========
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_user = trim($_POST['db_user'] ?? 'root');
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = trim($_POST['db_name'] ?? 'mirror');
    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    $site_path = trim($_POST['site_path'] ?? '/mirror/');
    
    // 验证输入
    if (empty($db_host) || empty($db_user) || empty($db_name)) {
        $error = '请填写数据库主机、用户名和数据库名';
    } elseif (empty($admin_user) || strlen($admin_user) < 2) {
        $error = '管理员用户名至少 2 个字符';
    } elseif (empty($admin_pass) || strlen($admin_pass) < 6) {
        $error = '管理员密码至少 6 个字符';
    } elseif ($admin_pass !== $admin_pass2) {
        $error = '两次输入的密码不一致';
    } else {
        // 尝试连接数据库
        try {
            // 先连接 MySQL（不指定数据库）
            $pdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // 创建数据库（如果不存在）
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$db_name}`");
            
            // 检查是否已有表存在
            $stmt = $pdo->query("SHOW TABLES");
            $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // 如果已有表，询问是否覆盖
            if (!empty($existingTables)) {
                // 简单处理：如果已有表且用户确认覆盖，则删除重建
                if (!isset($_POST['force'])) {
                    $error = '数据库「' . htmlspecialchars($db_name) . '」中已存在数据表。如需覆盖请勾选「覆盖已有数据」';
                } else {
                    // 删除所有已有表
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    foreach ($existingTables as $table) {
                        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                    }
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                }
            }
            
            // 如果还没有错误，继续创建表
            if (empty($error)) {
                // ========== 创建数据库表 ==========
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `users` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `username` VARCHAR(50) NOT NULL UNIQUE,
                        `password` VARCHAR(255) NOT NULL,
                        `role` ENUM('admin','user') DEFAULT 'admin',
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `links` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `link_id` VARCHAR(50) NOT NULL UNIQUE,
                        `redirect_url` VARCHAR(1000) NOT NULL,
                        `user_id` INT DEFAULT NULL,
                        `tags` VARCHAR(500) DEFAULT NULL,
                        `status` ENUM('active','disabled','expired') DEFAULT 'active',
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        `expires_at` DATETIME DEFAULT NULL,
                        `views` INT DEFAULT 0,
                        `captures` INT DEFAULT 0,
                        INDEX `idx_link_id` (`link_id`),
                        INDEX `idx_status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `photos` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `link_id` VARCHAR(50) NOT NULL,
                        `file_path` VARCHAR(500) NOT NULL,
                        `ip_address` VARCHAR(45) DEFAULT NULL,
                        `lat` DECIMAL(10,7) DEFAULT NULL,
                        `lng` DECIMAL(10,7) DEFAULT NULL,
                        `screen_resolution` VARCHAR(30) DEFAULT NULL,
                        `os` VARCHAR(50) DEFAULT NULL,
                        `browser` VARCHAR(100) DEFAULT NULL,
                        `browser_lang` VARCHAR(20) DEFAULT NULL,
                        `city` VARCHAR(100) DEFAULT NULL,
                        `isp` VARCHAR(100) DEFAULT NULL,
                        `user_agent` TEXT DEFAULT NULL,
                        `file_size` INT DEFAULT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_link_id` (`link_id`),
                        INDEX `idx_created_at` (`created_at`),
                        CONSTRAINT `fk_photos_link` FOREIGN KEY (`link_id`) REFERENCES `links`(`link_id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `logs` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `link_id` VARCHAR(50) DEFAULT NULL,
                        `action` VARCHAR(50) NOT NULL,
                        `ip_address` VARCHAR(45) DEFAULT NULL,
                        `user_agent` TEXT DEFAULT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_action` (`action`),
                        INDEX `idx_ip` (`ip_address`),
                        INDEX `idx_created` (`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `banned_ips` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `ip_address` VARCHAR(45) NOT NULL UNIQUE,
                        `reason` VARCHAR(255) DEFAULT NULL,
                        `banned_by` VARCHAR(50) DEFAULT 'system',
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `settings` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `key` VARCHAR(100) NOT NULL UNIQUE,
                        `value` TEXT DEFAULT NULL,
                        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                
                // 插入管理员账户
                $stmt = $pdo->prepare("INSERT INTO `users` (`username`, `password`, `role`) VALUES (?, ?, 'admin')");
                $stmt->execute([$admin_user, $admin_pass]);
                
                // ========== 写入 config.php ==========
                $configContent = <<<EOT
<?php

// ========== 📦 数据库配置 ==========
// 本文件由安装向导自动生成，请勿手动修改
// 如需修改，请重新运行 install.php 或直接编辑下方常量

define('DB_HOST', '{$db_host}');
define('DB_USER', '{$db_user}');
define('DB_PASS', '{$db_pass}');
define('DB_NAME', '{$db_name}');
define('IMG_DIR', __DIR__ . '/img/');
define('SITE_PATH', '{$site_path}');
define('SITE_URL', (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . \$_SERVER['HTTP_HOST'] . '{$site_path}');

// ========== 🔒 安全响应头 ==========
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer');

// ========== 📁 自动创建 img 目录 ==========
if (!file_exists(IMG_DIR)) {
    mkdir(IMG_DIR, 0755, true);
}
if (!file_exists(IMG_DIR . 'index.html')) {
    file_put_contents(IMG_DIR . 'index.html', '<!DOCTYPE html><html><head><title></title></head><body></body></html>');
}
if (!file_exists(IMG_DIR . '.htaccess')) {
    file_put_contents(IMG_DIR . '.htaccess', "Options -Indexes\\n<FilesMatch \\\"\\\\.(php|php5|phtml|inc|cgi|pl|sh|py)\$\\\">\\nOrder Deny,Allow\\nDeny from all\\n</FilesMatch>");
}

/**
 * 获取数据库连接
 */
function getDB() {
    static \$pdo = null;
    if (\$pdo === null) {
        try {
            \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException \$e) {
            header('HTTP/1.1 500 Internal Server Error');
            die('系统繁忙，请稍后再试');
        }
    }
    return \$pdo;
}

EOT;
                
                // 读取原 config.php 中 getDB() 函数之后的所有内容
                $oldConfig = file_get_contents(__DIR__ . '/config.php');
                preg_match('/function getDB\(\).*/s', $oldConfig, $restMatches);
                $restContent = $restMatches[0] ?? '';
                
                // 追加剩余的函数代码（从 getDB 开始）
                if (!empty($restContent)) {
                    $configContent .= $restContent;
                } else {
                    // 如果获取失败，复制整个 config.php 中从 getDB() 之后的部分
                    $pos = strpos($oldConfig, 'function getDB()');
                    if ($pos !== false) {
                        $configContent .= substr($oldConfig, $pos);
                    }
                }
                
                // 写回 config.php
                file_put_contents($configFile, $configContent);
                chmod($configFile, 0644);
                
                $success = true;
            }
            
        } catch (PDOException $e) {
            $error = '数据库连接失败：' . $e->getMessage();
        }
    }
}

// ========== 安装成功后自动删除自身 ==========
if ($success) {
    // 先输出页面再删除
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装完成 · 网恋照妖镜</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #e0e0e0;
}
.card {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px;
    padding: 40px;
    max-width: 480px;
    width: 100%;
    text-align: center;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
.check { font-size: 64px; color: #4caf50; margin-bottom: 12px; }
h2 { font-size: 24px; margin-bottom: 8px; }
p { color: #aaa; margin-bottom: 24px; line-height: 1.6; }
.btn {
    display: inline-block;
    padding: 14px 36px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s;
}
.btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(102,126,234,0.4); }
.warn {
    background: rgba(255,193,7,0.1);
    border: 1px solid rgba(255,193,7,0.2);
    border-radius: 10px;
    padding: 12px;
    margin: 16px 0;
    color: #ffc107;
    font-size: 13px;
}
</style>
</head>
<body>
<div class="card">
    <div class="check">✅</div>
    <h2>🎉 安装成功！</h2>
    <p>
        网恋照妖镜 v2.0 已安装完成。<br>
        数据库表已创建，管理员账号已就绪。
    </p>
    <div class="warn">
        ⚠️ 本安装文件将在 3 秒后自动删除<br>
        如未自动删除，请手动删除 <code>install.php</code>
    </div>
    <br>
    <a href="login.php" class="btn">🔐 前往登录后台</a>
    <br><br>
    <a href="index.php" style="color:#667eea;font-size:14px;text-decoration:none;">🏠 返回首页</a>
</div>
<script>
setTimeout(function() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'install.php?action=cleanup', true);
    xhr.send();
}, 3000);
</script>
</body>
</html>
    <?php
    // 清理自身
    if (isset($_GET['action']) && $_GET['action'] === 'cleanup') {
        @unlink(__FILE__);
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>一键安装 · 网恋照妖镜 v2.0</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #e0e0e0;
}
.wrapper {
    max-width: 560px;
    width: 100%;
}
.card {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px;
    padding: 36px;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
.header { text-align: center; margin-bottom: 28px; }
.header .icon { font-size: 48px; }
.header h1 { font-size: 22px; margin: 8px 0 4px; }
.header p { color: #8080a0; font-size: 14px; }
.section { margin-bottom: 24px; }
.section h3 {
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #667eea;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.form-group { margin-bottom: 14px; }
.form-group label {
    display: block;
    font-size: 13px;
    color: #aaa;
    margin-bottom: 5px;
}
.form-group input, .form-group select {
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
.form-group input::placeholder { color: #555; }
.form-row {
    display: flex;
    gap: 12px;
}
.form-row .form-group { flex: 1; }
.form-group.checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-group.checkbox input { width: auto; }
.form-group.checkbox label { margin-bottom: 0; cursor: pointer; }
.error {
    background: rgba(255,80,80,0.1);
    border: 1px solid rgba(255,80,80,0.2);
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 16px;
    color: #ff6b6b;
    font-size: 14px;
}
.btn-install {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-install:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102,126,234,0.4);
}
.note {
    text-align: center;
    margin-top: 16px;
    font-size: 12px;
    color: #606080;
    line-height: 1.6;
}
</style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <div class="icon">🪞</div>
            <h1>网恋照妖镜 · 一键安装</h1>
            <p>填写数据库信息，快速完成部署设置</p>
        </div>
        
        <?php if ($error): ?>
        <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($alreadyInstalled): ?>
        <div class="error" style="border-color:rgba(255,193,7,0.3);background:rgba(255,193,7,0.08);color:#ffc107;">
            <i class="fas fa-info-circle"></i> 检测到系统已安装。如需重新安装，请先清空数据库或删除旧表。
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="section">
                <h3>📦 数据库配置</h3>
                <div class="form-group">
                    <label>数据库主机</label>
                    <input type="text" name="db_host" value="localhost" placeholder="通常为 localhost" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text" name="db_user" value="root" placeholder="数据库用户名" required>
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" name="db_pass" placeholder="数据库密码">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>数据库名</label>
                        <input type="text" name="db_name" value="mirror" placeholder="数据库名称" required>
                    </div>
                    <div class="form-group">
                        <label>网站路径</label>
                        <input type="text" name="site_path" value="/mirror/" placeholder="/mirror/" required>
                    </div>
                </div>
                <div class="form-group checkbox">
                    <input type="checkbox" name="force" id="force">
                    <label for="force">☑️ 覆盖已有数据（删除数据库中所有旧表）</label>
                </div>
            </div>
            
            <div class="section">
                <h3>🔐 管理员账号</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>用户名</label>
                        <input type="text" name="admin_user" value="admin" placeholder="admin" required>
                    </div>
                    <div class="form-group">
                        <label>密码</label>
                        <input type="password" name="admin_pass" placeholder="至少 6 位" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>确认密码</label>
                    <input type="password" name="admin_pass2" placeholder="再次输入密码" required>
                </div>
            </div>
            
            <button type="submit" class="btn-install" <?php echo $alreadyInstalled ? 'disabled' : ''; ?>>
                <i class="fas fa-rocket"></i> 开始安装
            </button>
        </form>
        
        <div class="note">
            ⚡ 安装程序会自动创建数据库和数据表<br>
            安装成功后本文件将自动删除
        </div>
    </div>
</div>
</body>
</html>