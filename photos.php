<?php
/**
 * Online-Mirror v3.0 - 照片查看页
 * 支持：灯箱大图预览、GPS地图、浏览器指纹、IP归属地
 */
session_start();
require_once __DIR__ . '/config.php';

// ========== 封禁IP拦截：禁止查看图片 ==========
if (isIPBanned()) {
    $ban_reason = getBanReason();
    die('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>访问被拒绝</title><style>body{background:#0f0c29;color:#e0e0e0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}.card{background:rgba(255,255,255,0.05);backdrop-filter:blur(20px);border:1px solid rgba(255,80,80,0.2);border-radius:24px;padding:40px;max-width:420px;width:100%;text-align:center}.card .icon{font-size:64px;margin-bottom:16px}.card h1{font-size:24px;color:#ff6b6b;margin:0 0 4px}.card .sub{color:#8080a0;font-size:13px;line-height:1.6;margin:0}.card .reason{color:#a0a0b8;font-size:15px;line-height:1.6;margin:16px 0 0;padding:12px 16px;background:rgba(255,80,80,0.08);border-radius:12px;border:1px solid rgba(255,80,80,0.12)}/* ========== 🎬 动画 ========== */
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
</style></head><body><div class="card"><div class="icon">🚫</div><h1>拒绝访问</h1><p class="sub">您的请求已被系统拒绝</p><p class="reason">' . $ban_reason . '</p></div></body></html>');
}

$id = trim($_GET['id'] ?? '');
$type = trim($_GET['type'] ?? '');
$page = max(0, intval($_GET['page'] ?? 0));
$per_page = 6;

if (empty($id)) {
    die('<h2 style="text-align:center;margin-top:50px;color:#888;">缺少ID参数</h2>');
}

$db = getDB();

// 删除单张照片
if ($type === 'delete' && isset($_GET['photo_id']) && isLoggedIn()) {
    requireCsrf();
    $photo_id = intval($_GET['photo_id']);
    $stmt = $db->prepare("SELECT file_path FROM mir_photos WHERE id = ? AND link_id = ?");
    $stmt->execute([$photo_id, $id]);
    $photo = $stmt->fetch();
    if ($photo) {
        $filepath = IMG_DIR . $photo['file_path'];
        if (file_exists($filepath)) unlink($filepath);
        $stmt = $db->prepare("DELETE FROM mir_photos WHERE id = ?");
        $stmt->execute([$photo_id]);
        $stmt = $db->prepare("UPDATE mir_links SET captures = GREATEST(captures - 1, 0) WHERE link_id = ?");
        $stmt->execute([$id]);
        addLog($id, 'delete_photo');
    }
    header("Location: photos.php?id=" . urlencode($id));
    exit;
}

// 清空所有照片
if ($type === 'clear' && isLoggedIn()) {
    requireCsrf();
    $stmt = $db->prepare("SELECT file_path FROM mir_photos WHERE link_id = ?");
    $stmt->execute([$id]);
    $photos = $stmt->fetchAll();
    foreach ($photos as $p) {
        $fp = IMG_DIR . $p['file_path'];
        if (file_exists($fp)) unlink($fp);
    }
    $stmt = $db->prepare("DELETE FROM mir_photos WHERE link_id = ?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("UPDATE mir_links SET captures = 0 WHERE link_id = ?");
    $stmt->execute([$id]);
    addLog($id, 'clear_photos');
    header("Location: photos.php?id=" . urlencode($id));
    exit;
}

// 获取照片
$stmt = $db->prepare("SELECT * FROM mir_photos WHERE link_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$all_photos = $stmt->fetchAll();
$total = count($all_photos);
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages - 1);
$photos = array_slice($all_photos, $page * $per_page, $per_page);

// 获取链接信息
$stmt = $db->prepare("SELECT * FROM mir_links WHERE link_id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

$csrf = csrfToken();
$ai_settings = getAISettings();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>照片查看 · <?php echo htmlspecialchars($id); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- Spotlight.js 灯箱 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/spotlight.js@0.7.8/dist/css/spotlight.min.css">
<script src="https://cdn.jsdelivr.net/npm/spotlight.js@0.7.8/dist/js/spotlight.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Spotlight !== 'undefined') {
        new Spotlight(document.querySelectorAll('.spotlight'));
    }
});
</script>
<!-- Leaflet.js 地图（国内CDN） -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0f0c29;
    color: #e0e0e0;
    min-height: 100vh;
}
.header {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding: 20px;
    position: sticky;
    top: 0;
    z-index: 100;
}
.header-inner {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.header h2 { font-size: 20px; }
.header h2 small { font-size: 14px; color: #8080a0; font-weight: normal; }
.header .actions { display: flex; gap: 8px; flex-wrap: wrap; }
.header .actions a {
    padding: 8px 16px; border-radius: 8px; text-decoration: none;
    font-size: 13px; transition: all 0.3s;
}
.btn-back { background: rgba(255,255,255,0.08); color: #a0a0b8; }
.btn-back:hover { background: rgba(255,255,255,0.12); }
.btn-clear { background: rgba(255,80,80,0.15); color: #ff6b6b; }
.btn-clear:hover { background: rgba(255,80,80,0.25); }
.btn-dash { background: rgba(102,126,234,0.15); color: #667eea; }
.btn-dash:hover { background: rgba(102,126,234,0.25); }

.content { max-width: 1200px; margin: 0 auto; padding: 20px; }

/* 统计面板 */
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px; margin-bottom: 24px;
}
.stat-card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px;
    padding: 16px; text-align: center;
}
.stat-card .num {
    font-size: 28px; font-weight: 700;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.stat-card .label { font-size: 12px; color: #8080a0; margin-top: 4px; }

/* 照片卡片 + 灯箱 */
.photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}
.photo-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s;
}
.photo-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
.photo-card .img-wrap {
    width: 100%;
    aspect-ratio: 3/4;
    background: #1a1a2e;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    cursor: zoom-in;
}
.photo-card .img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #111;
}
.photo-card .info {
    padding: 12px 14px;
    font-size: 12px;
    color: #8080a0;
}
.photo-card .info .row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}
.photo-card .info .row:last-child { margin-bottom: 0; }
.photo-card .info .del-btn {
    color: #ff6b6b; text-decoration: none;
    padding: 4px 8px; border-radius: 6px;
    background: rgba(255,80,80,0.1);
    font-size: 11px; transition: all 0.3s;
}
.photo-card .info .del-btn:hover { background: rgba(255,80,80,0.2); }
.photo-card .info .download-btn {
    color: #4caf50; text-decoration: none;
    padding: 4px 8px; margin-right: 6px;
    border-radius: 6px; background: rgba(76,175,80,0.1);
    font-size: 11px; transition: all 0.3s;
}
.photo-card .info .download-btn:hover { background: rgba(76,175,80,0.2); }
.photo-card .fingerprint {
    padding: 0 14px 10px;
    font-size: 11px;
    color: #606080;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.photo-card .fingerprint .tag {
    background: rgba(102,126,234,0.1);
    padding: 2px 8px;
    border-radius: 4px;
    color: #8080c0;
}

/* v3.0 AI分析与反向图搜 */
.ai-btn, .reverse-btn {
    display:inline-flex; align-items:center; gap:4px;
    padding:5px 12px; border-radius:6px;
    font-size:11px; cursor:pointer; transition:all 0.3s; border:none;
}
.ai-btn {
    background:rgba(102,126,234,0.12); color:#667eea;
}
.ai-btn:hover { background:rgba(102,126,234,0.25); }
.ai-btn.loading {
    background:rgba(255,152,0,0.15); color:#ff9800;
    pointer-events:none; opacity:0.7;
}
.ai-btn.disabled {
    background:rgba(255,80,80,0.1); color:#ff6b6b;
    cursor:not-allowed; opacity:0.6;
}
.reverse-btn {
    background:rgba(76,175,80,0.1); color:#4caf50;
}
.reverse-btn:hover { background:rgba(76,175,80,0.2); }

.ai-result {
    display:none;
    padding:10px 14px 14px;
    background:rgba(102,126,234,0.06);
    border-top:1px solid rgba(102,126,234,0.1);
    font-size:12px; line-height:1.7;
}
.ai-result.show { display:block; }
.ai-result .ai-toggle-bar {
    display:flex; align-items:center; justify-content:space-between;
    padding:0 0 6px; margin-bottom:6px;
    border-bottom:1px solid rgba(255,255,255,0.04);
}
.ai-result .ai-toggle-bar .ai-toggle-btn {
    background:none; border:none; color:#667eea; font-size:11px; cursor:pointer;
    display:flex; align-items:center; gap:4px; padding:2px 6px;
    border-radius:4px; transition:all 0.2s;
}
.ai-result .ai-toggle-bar .ai-toggle-btn:hover { background:rgba(102,126,234,0.1); }
.ai-result .ai-loading {
    text-align:center; padding:12px 0; color:#8080a0;
}
.ai-result .ai-loading i { font-size:18px; margin-bottom:6px; display:block; }
.ai-result .ai-error {
    text-align:center; padding:8px; color:#ff6b6b; font-size:12px;
}
.ai-result .ai-quota {
    font-size:10px; color:#606080; margin-top:6px; text-align:right;
}
.ai-result-inner { }
.ai-dimension {
    display:flex; padding:2px 0;
    border-bottom:1px solid rgba(255,255,255,0.04);
}
.ai-dim-key {
    color:#667eea; font-weight:600; min-width:60px; flex-shrink:0;
}
.ai-dim-val { color:#c0c0d0; }
.ai-line { padding:2px 0; color:#b0b0c0; }

/* 反向图搜弹窗 */
.reverse-modal {
    display:none;
    position:fixed; top:0; left:0; right:0; bottom:0;
    z-index:9999; background:rgba(0,0,0,0.8);
    justify-content:center; align-items:center;
}
.reverse-modal.show { display:flex; }
.reverse-modal .modal-box {
    background:#1a1a2e;
    border:1px solid rgba(255,255,255,0.12);
    border-radius:16px; overflow:hidden;
    width:90vw; max-width:440px;
}
.reverse-modal .modal-header {
    padding:14px 20px; display:flex; justify-content:space-between; align-items:center;
    border-bottom:1px solid rgba(255,255,255,0.06);
}
.reverse-modal .modal-header h3 { font-size:15px; }
.reverse-modal .modal-header .close-btn {
    font-size:22px; color:#8080a0; cursor:pointer; border:none; background:none;
}
.reverse-modal .modal-header .close-btn:hover { color:#fff; }
.reverse-modal .modal-body { padding:16px 20px 20px; }
.reverse-modal .search-option {
    display:flex; align-items:center; gap:12px;
    padding:12px 14px; margin-bottom:8px;
    background:rgba(255,255,255,0.04);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:10px; cursor:pointer;
    text-decoration:none; transition:all 0.3s;
}
.reverse-modal .search-option:hover {
    background:rgba(255,255,255,0.08);
    border-color:rgba(102,126,234,0.3);
    transform:translateX(4px);
}
.reverse-modal .search-option .so-icon { font-size:20px; width:32px; text-align:center; }
.reverse-modal .search-option .so-name { font-size:14px; color:#e0e0e0; }
.reverse-modal .search-option .so-desc { font-size:11px; color:#8080a0; }

/* 标签 */
.tags-display {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 4px;
}
.tags-display .tag {
    background: rgba(102,126,234,0.15);
    padding: 2px 10px;
    border-radius: 6px;
    font-size: 11px;
    color: #667eea;
}

/* 地图按钮 */
.map-btn {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 6px;
    background: rgba(76,175,80,0.12);
    color: #4caf50;
    font-size: 11px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s;
    border: none;
}
.map-btn:hover { background: rgba(76,175,80,0.25); }

/* 地图弹窗遮罩 */
.map-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 9999;
    background: rgba(0,0,0,0.85);
    justify-content: center;
    align-items: center;
}
.map-modal.show { display: flex; }
.map-modal .modal-box {
    background: #1a1a2e;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 16px;
    overflow: hidden;
    width: 90vw;
    max-width: 600px;
}
.map-modal .modal-header {
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.map-modal .modal-header h3 { font-size: 16px; }
.map-modal .modal-header .close-btn {
    font-size: 24px; color: #8080a0;
    cursor: pointer; border: none;
    background: none;
}
.map-modal .modal-header .close-btn:hover { color: #fff; }
.map-modal .modal-body {
    height: 60vh;
    max-height: 500px;
}

/* 翻页 */
.pagination {
    display: flex; justify-content: center; gap: 8px;
    margin-top: 24px; padding-bottom: 40px;
}
.pagination a {
    padding: 8px 16px; border-radius: 8px;
    background: rgba(255,255,255,0.06);
    color: #a0a0b8; text-decoration: none; font-size: 14px; transition: all 0.3s;
}
.pagination a:hover { background: rgba(255,255,255,0.12); }
.pagination a.active {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}
.empty-state {
    text-align: center; padding: 80px 20px; color: #606080;
}
.empty-state i { font-size: 64px; margin-bottom: 16px; }
.empty-state p { font-size: 16px; }

@media (max-width: 640px) {
    .photo-grid { grid-template-columns: 1fr; }
    .header-inner { flex-direction: column; align-items: flex-start; }
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
<div class="header">
    <div class="header-inner">
        <h2><i class="fas fa-images"></i> 照片查看 <small>ID: <?php echo htmlspecialchars($id); ?></small></h2>
        <div class="actions">
            <?php if ($link && $link['tags']): ?>
            <div class="tags-display">
                <?php foreach (explode(',', $link['tags']) as $t): ?>
                <span class="tag"><?php echo htmlspecialchars(trim($t)); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <a href="dashboard.php" class="btn-dash"><i class="fas fa-tachometer-alt"></i> 控制台</a>
            <?php if ($total > 0 && isLoggedIn()): ?>
                <a href="?id=<?php echo urlencode($id); ?>&type=clear&csrf_token=<?php echo $csrf; ?>" class="btn-clear" onclick="return confirm('确定清空所有照片？')"><i class="fas fa-trash"></i> 清空</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="content">
    <?php if ($link): ?>
    <div class="stats">
        <div class="stat-card">
            <div class="num"><?php echo $total; ?></div>
            <div class="label">📸 照片总数</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo $link['views']; ?></div>
            <div class="label">👁️ 访问次数</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo date('m/d', strtotime($link['created_at'])); ?></div>
            <div class="label">📅 创建日期</div>
        </div>
        <?php if ($link['expires_at']): ?>
        <div class="stat-card">
            <div class="num" style="font-size:20px;"><?php echo date('m/d', strtotime($link['expires_at'])); ?></div>
            <div class="label">⏳ 过期时间</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($photos)): ?>
    <div class="empty-state">
        <i class="fas fa-camera-slash"></i>
        <p>暂无照片，等待对方打开链接...</p>
    </div>
    <?php else: ?>
    <div class="photo-grid">
        <?php foreach ($photos as $photo): ?>
        <div class="photo-card">
            <div class="img-wrap">
                <a href="img/<?php echo htmlspecialchars($photo['file_path']); ?>" class="spotlight" data-spotlight="photos">
                    <img src="img/<?php echo htmlspecialchars($photo['file_path']); ?>" alt="照片" loading="lazy">
                </a>
            </div>
            <div class="info">
                <div class="row">
                    <span><i class="far fa-clock"></i> <?php echo date('m-d H:i', strtotime($photo['created_at'])); ?></span>
                    <span>
                        <a href="img/<?php echo htmlspecialchars($photo['file_path']); ?>" download class="download-btn"><i class="fas fa-download"></i></a>
                        <?php if (isLoggedIn()): ?>
                        <a href="?id=<?php echo urlencode($id); ?>&type=delete&photo_id=<?php echo $photo['id']; ?>&csrf_token=<?php echo $csrf; ?>" class="del-btn" onclick="return confirm('删除这张照片？')"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="row">
                    <span><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($photo['ip_address'] ?? '-'); ?></span>
                    <?php if ($photo['file_size']): ?>
                    <span><?php echo formatSize($photo['file_size']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($photo['city'] || $photo['isp']): ?>
                <div class="row" style="color:#667eea;">
                    <span><i class="fas fa-globe"></i> <?php echo htmlspecialchars($photo['city'] ?? ''); ?></span>
                    <span><?php echo htmlspecialchars($photo['isp'] ?? ''); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($photo['screen_resolution'] || $photo['os'] || $photo['browser'] || $photo['recording_seconds']): ?>
            <div class="fingerprint">
                <?php if ($photo['os']): ?><span class="tag"><i class="fas fa-laptop"></i> <?php echo htmlspecialchars($photo['os']); ?></span><?php endif; ?>
                <?php if ($photo['browser']): ?><span class="tag"><i class="fas fa-globe"></i> <?php echo htmlspecialchars($photo['browser']); ?></span><?php endif; ?>
                <?php if ($photo['screen_resolution']): ?><span class="tag"><i class="fas fa-expand"></i> <?php echo htmlspecialchars($photo['screen_resolution']); ?></span><?php endif; ?>
                <?php if ($photo['browser_lang']): ?><span class="tag"><i class="fas fa-language"></i> <?php echo htmlspecialchars($photo['browser_lang']); ?></span><?php endif; ?>
                <?php if ($photo['recording_seconds'] > 0): ?><span class="tag"><i class="fas fa-microphone"></i> 录音 <?php echo intval($photo['recording_seconds']); ?>秒</span><?php endif; ?>
                <?php if ($photo['recording_file_path']): ?>
                <span class="tag" style="background:rgba(156,39,176,0.15);border-color:rgba(156,39,176,0.25);padding:2px 6px;"><i class="fas fa-play-circle" style="color:#ce93d8;"></i>
                <audio controls preload="none" style="height:32px;width:170px;vertical-align:middle;border-radius:6px;" src="uploads/recordings/<?php echo htmlspecialchars($photo['recording_file_path']); ?>"></audio></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- v3.0 AI分析 & 反向图搜 & 地图按钮（合并一行） -->
            <div style="padding:0 14px 14px;display:flex;gap:6px;flex-wrap:wrap;">
                <button class="ai-btn" onclick="analyzePhoto('<?php echo htmlspecialchars($photo['file_path']); ?>', this)" title="AI人像分析">
                    <i class="fas fa-robot"></i> AI分析
                </button>
                <button class="reverse-btn" onclick="reverseSearch('<?php echo htmlspecialchars($photo['file_path']); ?>')" title="以图搜图">
                    <i class="fas fa-search"></i> 反向图搜
                </button>
                <?php if ($photo['lat'] && $photo['lng']): ?>
                <button class="map-btn" onclick="openMap(<?php echo $photo['lat']; ?>, <?php echo $photo['lng']; ?>, '<?php echo htmlspecialchars($photo['city'] ?? '未知位置'); ?>')">
                    <i class="fas fa-map-marked-alt"></i> 查看地图
                </button>
                <?php endif; ?>
            </div>
            <?php 
            $has_ai_result = !empty($photo['ai_result']);
            $ai_result_html = $has_ai_result ? formatAIResult($photo['ai_result'], $ai_settings['options'] ?? []) : '';
            ?>
            <div class="ai-result <?php echo $has_ai_result ? 'show' : ''; ?>" id="aiResult_<?php echo $photo['id']; ?>">
                <?php if ($has_ai_result): ?>
                <div class="ai-toggle-bar">
                    <span style="font-size:11px;color:#8080a0;"><i class="fas fa-robot" style="color:#667eea;"></i> AI分析结果</span>
                    <button class="ai-toggle-btn" onclick="toggleAIResult('<?php echo $photo['id']; ?>')">
                        <i class="fas fa-chevron-up"></i> 收起
                    </button>
                </div>
                <?php echo $ai_result_html; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 地图弹窗 -->
    <div class="map-modal" id="mapModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3><i class="fas fa-map-marked-alt" style="color:#4caf50;"></i> <span id="mapTitle">地图位置</span></h3>
                <button class="close-btn" onclick="closeMap()">&times;</button>
            </div>
            <div class="modal-body" id="mapContainer"></div>
        </div>
    </div>

    <script>
    var mapInstance = null;
    
    function openMap(lat, lng, title) {
        document.getElementById('mapTitle').textContent = title || '地图位置';
        document.getElementById('mapModal').classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // 先让浏览器渲染 modal，再初始化地图
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                if (mapInstance) {
                    mapInstance.remove();
                    mapInstance = null;
                }
                
                mapInstance = L.map('mapContainer', {
                    zoomControl: true,
                    scrollWheelZoom: true,
                    center: [lat, lng],
                    zoom: 15
                });
                
                // 使用高德地图瓦片（国内可访问）
                L.tileLayer('https://{s}.is.autonavi.com/appmaptile?lang=zh_cn&size=1&scale=1&style=8&x={x}&y={y}&z={z}', {
                    maxZoom: 18,
                    subdomains: ['webrd01','webrd02','webrd03','webrd04'],
                    attribution: '&copy; AutoNavi 高德地图'
                }).addTo(mapInstance);
                
                L.marker([lat, lng]).addTo(mapInstance)
                    .bindPopup(title || ('📍 ' + lat.toFixed(4) + ', ' + lng.toFixed(4)))
                    .openPopup();
                
                // 地图容器尺寸调整
                setTimeout(function() { 
                    try { mapInstance.invalidateSize(); } catch(e) {}
                }, 200);
            });
        });
    }
    
    function closeMap() {
        document.getElementById('mapModal').classList.remove('show');
        document.body.style.overflow = '';
        if (mapInstance) {
            mapInstance.remove();
            mapInstance = null;
        }
    }
    
    // v3.0 AI分析功能
    var analyzingPhotos = {};
    
    function analyzePhoto(photoPath, btn) {
        // 查找对应的结果容器
        var card = btn.closest('.photo-card');
        var resultDiv = card.querySelector('.ai-result');
        var photoId = resultDiv.id.replace('aiResult_', '');
        
        // 防止重复点击
        if (analyzingPhotos[photoId]) return;
        
        // 检查是否已有结果
        if (resultDiv.classList.contains('show') && !resultDiv.querySelector('.ai-loading')) {
            // 已有结果，收起
            resultDiv.classList.remove('show');
            btn.classList.remove('loading');
            return;
        }
        
        analyzingPhotos[photoId] = true;
        btn.classList.add('loading');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 分析中...';
        
        resultDiv.classList.add('show');
        resultDiv.innerHTML = '<div class="ai-loading"><i class="fas fa-spinner fa-pulse" style="color:#667eea;"></i>🤔 AI 正在分析照片特征...</div>';
        
        var formData = new FormData();
        formData.append('action', 'analyze');
        formData.append('link_id', '<?php echo htmlspecialchars($id); ?>');
        formData.append('photo_path', photoPath);
        
        fetch('ajax_ai_analyze.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            analyzingPhotos[photoId] = false;
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="fas fa-robot"></i> AI分析';
            
            if (data.error) {
                resultDiv.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-circle"></i> ' + data.error + '</div>';
                btn.classList.add('disabled');
                return;
            }
            
            if (data.success && data.formatted) {
                resultDiv.innerHTML = '<div class="ai-toggle-bar">' +
                    '<span style="font-size:11px;color:#8080a0;"><i class="fas fa-robot" style="color:#667eea;"></i> AI分析结果</span>' +
                    '<button class="ai-toggle-btn" onclick="toggleAIResult(\'' + photoId + '\')">' +
                        '<i class="fas fa-chevron-up"></i> 收起' +
                    '</button>' +
                '</div>' + data.formatted;
                if (data.quota) {
                    resultDiv.innerHTML += '<div class="ai-quota">剩余分析次数: ' + data.quota.remaining + '/' + data.quota.quota + '</div>';
                }
            } else {
                resultDiv.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-circle"></i> 分析失败，请重试</div>';
            }
        })
        .catch(function(err) {
            analyzingPhotos[photoId] = false;
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="fas fa-robot"></i> AI分析';
            resultDiv.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-circle"></i> 网络错误，请重试</div>';
        });
    }
    
    // v3.0 AI结果收起展开
    function toggleAIResult(photoId) {
        var div = document.getElementById('aiResult_' + photoId);
        if (!div) return;
        var btn = div.querySelector('.ai-toggle-btn');
        var icon = btn ? btn.querySelector('i') : null;
        if (div.classList.contains('show')) {
            div.classList.remove('show');
            if (icon) { icon.className = 'fas fa-chevron-down'; }
            if (btn) { btn.innerHTML = '<i class="fas fa-chevron-down"></i> 展开'; }
        } else {
            div.classList.add('show');
            if (icon) { icon.className = 'fas fa-chevron-up'; }
            if (btn) { btn.innerHTML = '<i class="fas fa-chevron-up"></i> 收起'; }
        }
    }
    
    // v3.0 反向图搜功能
    function reverseSearch(photoPath) {
        var siteUrl = '<?php echo SITE_URL; ?>';
        var imgUrl = siteUrl + 'img/' + photoPath;
        
        // 创建弹窗
        var modal = document.createElement('div');
        modal.className = 'reverse-modal show';
        modal.id = 'reverseModal';
        modal.innerHTML = '<div class="modal-box">' +
            '<div class="modal-header">' +
                '<h3><i class="fas fa-search" style="color:#4caf50;"></i> 以图搜图</h3>' +
                '<button class="close-btn" onclick="closeReverse()">&times;</button>' +
            '</div>' +
            '<div class="modal-body">' +
                '<p style="font-size:13px;color:#8080a0;margin-bottom:12px;">选择搜索引擎进行反向图片搜索：</p>' +
                '<a class="search-option" href="https://www.google.com/searchbyimage?image_url=' + encodeURIComponent(imgUrl) + '" target="_blank" rel="noopener">' +
                    '<div class="so-icon">🔍</div>' +
                    '<div><div class="so-name">Google 图片搜索</div><div class="so-desc">搜索相似图片，查来源</div></div>' +
                '</a>' +
                '<a class="search-option" href="https://image.baidu.com/n/pc_search?queryImageUrl=' + encodeURIComponent(imgUrl) + '&from=pc" target="_blank" rel="noopener">' +
                    '<div class="so-icon">🌐</div>' +
                    '<div><div class="so-name">百度识图</div><div class="so-desc">百度以图搜图，适合国内网络</div></div>' +
                '</a>' +
                '<a class="search-option" href="https://saucenao.com/search.php?url=' + encodeURIComponent(imgUrl) + '" target="_blank" rel="noopener">' +
                    '<div class="so-icon">🎨</div>' +
                    '<div><div class="so-name">SauceNAO</div><div class="so-desc">二次元/动漫图片搜索利器</div></div>' +
                '</a>' +
                '<a class="search-option" href="https://yandex.com/images/search?rpt=imageview&url=' + encodeURIComponent(imgUrl) + '" target="_blank" rel="noopener">' +
                    '<div class="so-icon">🇷🇺</div>' +
                    '<div><div class="so-name">Yandex 图片搜索</div><div class="so-desc">俄罗斯搜索引擎，效果不错</div></div>' +
                '</a>' +
            '</div>' +
        '</div>';
        
        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';
    }
    
    function closeReverse() {
        var modal = document.getElementById('reverseModal');
        if (modal) {
            modal.remove();
            document.body.style.overflow = '';
        }
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMap();
            closeReverse();
        }
    });
    
    // 点击遮罩关闭
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('reverse-modal')) {
            closeReverse();
        }
    });
    </script>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 0; $i < $total_pages; $i++): ?>
        <a href="?id=<?php echo urlencode($id); ?>&page=<?php echo $i; ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i + 1; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
