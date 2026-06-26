<?php
/**
 * Online-Mirror v3.0 - AI分析 AJAX 接口
 * 接收图片路径和链接ID，调用智谱API分析人像
 */
session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== 'analyze') {
    echo json_encode(['error' => '无效操作']);
    exit;
}

// 检查AI是否已配置
$ai_settings = getAISettings();
if (empty($ai_settings['api_key'])) {
    echo json_encode(['error' => '管理员未配置 AI 模型，请先联系管理员在后台设置']);
    exit;
}

$link_id = trim($_POST['link_id'] ?? '');
$photo_path = trim($_POST['photo_path'] ?? '');

if (empty($link_id) || empty($photo_path)) {
    echo json_encode(['error' => '参数不完整']);
    exit;
}

// 安全检查：防止路径遍历
$photo_path = basename($photo_path);
$full_path = IMG_DIR . $photo_path;
if (!file_exists($full_path)) {
    echo json_encode(['error' => '图片文件不存在']);
    exit;
}

// 检查配额
$quota = checkAIQuota($link_id);
if (!$quota['allowed']) {
    if ($quota['quota'] > 0) {
        echo json_encode(['error' => "此链接的 AI 分析次数已用完（{$quota['used']}/{$quota['quota']}）"]);
    } else {
        echo json_encode(['error' => 'AI 分析未启用']);
    }
    exit;
}

// 调用AI分析
$result = callZhipuAI($photo_path, $ai_settings);

if (isset($result['error'])) {
    echo json_encode(['error' => $result['error']]);
    exit;
}

// 记录分析日志
addLog($link_id, 'ai_analysis');

// 💾 持久化保存AI分析结果到数据库
$db = getDB();
$stmt = $db->prepare("UPDATE mir_photos SET ai_result = ? WHERE link_id = ? AND file_path = ?");
$stmt->execute([$result['result'], $link_id, $photo_path]);

// 返回分析选项的格式化结果
$analysis_options = $ai_settings['options'];
$formatted = formatAIResult($result['result'], $analysis_options);

echo json_encode([
    'success' => true,
    'result' => $result['result'],
    'formatted' => $formatted,
    'quota' => $quota
]);
