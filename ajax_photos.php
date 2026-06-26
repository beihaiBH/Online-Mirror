<?php
/**
 * AJAX 接口 - 异步加载最新照片（供控制台首页使用）
 */
require_once __DIR__ . '/config.php';
requireLogin();

header('Content-Type: application/json');

$db = getDB();

$page = max(0, intval($_GET['page'] ?? 0));
$per_page = 12;
$offset = $page * $per_page;

// 获取照片总数
$total = $db->query("SELECT COUNT(*) FROM mir_photos")->fetchColumn();

// 获取分页照片
$stmt = $db->prepare("SELECT p.*, l.redirect_url FROM mir_photos p LEFT JOIN mir_links l ON p.link_id = l.link_id ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$per_page, $offset]);
$photos = $stmt->fetchAll();

$data = [];
foreach ($photos as $p) {
    $data[] = [
        'id' => $p['id'],
        'link_id' => $p['link_id'],
        'file_path' => $p['file_path'],
        'created_at' => date('m-d H:i', strtotime($p['created_at'])),
        'ip_address' => $p['ip_address'],
    ];
}

echo json_encode([
    'total' => (int)$total,
    'page' => $page,
    'has_more' => ($offset + $per_page) < $total,
    'photos' => $data
]);
