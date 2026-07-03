-- ============================================
-- 🎬 开屏闪屏功能 - 数据库更新
-- 版本：v3.1
-- 说明：为 mir_settings 表添加默认闪屏配置
-- ============================================

-- 闪屏功能开关（默认开启）
INSERT INTO `mir_settings` (`key`, `value`) VALUES ('splash_enabled', '1')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 闪屏图片地址（默认使用本地图片）
INSERT INTO `mir_settings` (`key`, `value`) VALUES ('splash_image', '/mirror/splash_default.png')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 闪屏显示时长（默认3000毫秒=3秒）
INSERT INTO `mir_settings` (`key`, `value`) VALUES ('splash_duration', '3000')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 完成！
-- 如果 install.php 已存在则无需手动执行，登录后台即可自动初始化
