<?php
/**
 * Online-Mirror v2.0 - 通知检查接口
 * 当有新拍照时在dashboard顶部显示通知
 */
require_once __DIR__ . '/config.php';
requireLogin();

header('Content-Type: application/json');

$db = getDB();
$user = getCurrentUser();

// 获取上次检查时间（存在session中）
if (session_status() === PHP_SESSION_NONE) session_start();
$last_check = $_SESSION['last_notify_check'] ?? date('Y-m-d H:i:s', strtotime('-1 hour'));

// 查找新拍照
$stmt = $db->prepare("SELECT COUNT(*) FROM photos WHERE created_at >= ?");
$stmt->execute([$last_check]);
$new_photos = (int)$stmt->fetchColumn();

// 查找新访问
$stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE action='visit' AND created_at >= ?");
$stmt->execute([$last_check]);
$new_visits = (int)$stmt->fetchColumn();

// 更新检查时间
$_SESSION['last_notify_check'] = date('Y-m-d H:i:s');

// 获取最新的拍照信息
$newest = null;
if ($new_photos > 0) {
    $stmt = $db->prepare("SELECT p.*, l.tags FROM photos p LEFT JOIN links l ON p.link_id = l.link_id ORDER BY p.created_at DESC LIMIT 1");
    $stmt->execute();
    $newest = $stmt->fetch();
}

echo json_encode([
    'new_photos' => $new_photos,
    'new_visits' => $new_visits,
    'newest' => $newest ? [
        'link_id' => $newest['link_id'],
        'city' => $newest['city'],
        'time' => date('H:i', strtotime($newest['created_at'])),
    ] : null,
    'last_check' => $last_check,
]);
