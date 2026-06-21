<?php
/**
 * Online-Mirror - 配置文件
 * 
 * 首次使用请访问 install.php 进行安装配置
 * 安装完成后此文件会被自动写入真实配置
 * 
 * 手动配置请修改以下常量：
 */

// ========== 数据库配置 ==========
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'mirror');

// ========== 网站配置 ==========
define('IMG_DIR', __DIR__ . '/img/');
define('SITE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/');

// ========== 安全配置 ==========
// 图片上传最大大小（字节）
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// ========== 自动初始化 ==========
// 自动创建图片目录
if (!file_exists(IMG_DIR)) {
    @mkdir(IMG_DIR, 0755, true);
    @file_put_contents(IMG_DIR . 'index.html', '<!DOCTYPE html><html><head><title></title></head><body></body></html>');
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
            return null; // 连接失败返回 null，由调用方处理
        }
    }
    return $pdo;
}

/**
 * 检查是否已安装
 */
function isInstalled() {
    $db = getDB();
    if ($db === null) return false;
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'users'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 获取客户端IP
 */
function getClientIP() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return filter_var(trim($ip), FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * 记录日志
 */
function addLog($link_id, $action) {
    try {
        $db = getDB();
        if ($db === null) return;
        $stmt = $db->prepare("INSERT INTO logs (link_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$link_id, $action, getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '']);
    } catch (Exception $e) {}
}

/**
 * 生成随机ID
 */
function generateID($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    try {
        $db = getDB();
        if ($db === null) return null;
        $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

function requireLogin() {
    session_start();
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function formatSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ========== 安装检测：未安装时重定向到安装页面 ==========
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($current_script !== 'install.php' && !isInstalled()) {
    header('Location: install.php');
    exit;
}
