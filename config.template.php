<?php

// ========== 📦 数据库配置 ==========
// 本文件由安装向导自动生成，请勿手动修改
// 如需修改，请重新运行 install.php 或直接编辑下方常量

define('DB_HOST', '{{DB_HOST}}');
define('DB_USER', '{{DB_USER}}');
define('DB_PASS', '{{DB_PASS}}');
define('DB_NAME', '{{DB_NAME}}');
define('IMG_DIR', __DIR__ . '/img/');
define('SITE_PATH', '{{SITE_PATH}}');
define('SITE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '{{SITE_PATH}}');

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
    file_put_contents(IMG_DIR . '.htaccess', 'Options -Indexes' . "\n" . '<FilesMatch "\.(php|php5|phtml|inc|cgi|pl|sh|py)$">' . "\n" . 'Order Deny,Allow' . "\n" . 'Deny from all' . "\n" . '</FilesMatch>');
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
