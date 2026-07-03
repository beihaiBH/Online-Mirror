# 🐛 修复记录

## v3.1.1 — 2026-07-04

### 🔧 安装程序大修
- **问题：** `config.php` 中出现 `syntax error, unexpected '\' (T_NS_SEPARATOR)`
- **原因：** Heredoc 中的 `\$` 转义在不同 PHP 版本上兼容性不一致
- **修复：** 改用 `config.template.php` 模板文件 + `str_replace` 占位符替换（绕开所有 `\$` 转义）
- **影响范围：** 跨服务器安装时不再因 PHP 版本差异报错

### 🔧 getDB() 重复声明
- **问题：** `Fatal error: Cannot redeclare getDB()`
- **原因：** 模板生成已包含 `getDB()`，但 install.php 又把旧 config 中的 `getDB()` 追加了一遍
- **修复：** 追加时数花括号跳过 `getDB()` 函数体，只保留后面的辅助函数
- **影响范围：** 覆盖安装（即已有 config.php 时）不再报重复声明

### 🔧 跳过 getDB 代码重复
- **问题：** 同一段「跳过 getDB 追加辅助函数」的代码出现了两遍
- **原因：** 替换 heredoc 时新代码和旧代码各保留了一份
- **修复：** 删除第二份重复块

### 🔧 安装检测缺失
- **问题：** 未安装时首页显示「系统繁忙」而非跳转安装向导
- **原因：** `index.php` 被 `git checkout` 恢复后丢失了安装检测代码
- **修复：** 重新添加安装检测：检查 `installed.lock` 是否存在，否则跳转 `install.php`

### 🔧 FilesMatch 缺少 $ 锚点
- **问题：** fallback 路径的 `.htaccess` 生成代码漏了正则 `$` 锚点
- **修复：** 补上 `\$`，确保只匹配 `.php` 结尾

---

## v3.1 — 2026-07-03

### 🎬 开屏闪屏功能
- 新增网站开屏闪屏（LOGO + 进度条 + 渐入动画）
- 新增后台「开屏闪屏」设置 Tab（开关/图片/时长/预览）
- 默认图片路径：`/mirror/splash_default.png`

### 🎨 UI 优化
- Tab 导航栏支持触摸滑动
- 开屏开关改为与邮箱通知一致的 toggle 按钮样式
- 设置输入框统一样式

### 🐞 问题修复
- AI 人像分析 / 反向图搜开关不可用 → 加入 `toggleField` 的 map
- 反斜杠 `\$` 残留 → 全部清空
