# 🐛 修复记录

## v4.0 — 2026-07-23

### 🔧 Bug 7：连拍模式N+1 - 表单重复提交多拍一张
- **问题等级：** ⚡ 中等 — 开启连拍模式时，实际保存照片数量比设定多1张
- **原因：** `capture.php` 连拍逻辑中，每张照片通过 fetch 逐一保存到 `save.php`，但全部连拍完成后又调用 `markPhotoDone()` → `trySubmit()` → 表单提交，最后一张照片被重复保存
- **修复：**
  - `trySubmit()` 中判断 `burstTotal > 1` 时直接 `window.location.href` 跳转，不提交表单
  - 连拍改用 XMLHttpRequest（兼容性更好），调整间隔和时序
- **涉及文件：** `capture.php`
- **影响范围：** 所有开启连拍模式的链接

### 🔧 Bug 8：安装程序缺失辅助函数导致项目不可用
- **问题等级：** ⚠️ 严重 — 全新安装后项目无法运行
- **原因：** `install.php` 生成 `config.php` 时通过模板写入，但 `config.template.php` 只含 `getDB()` 函数，其余30个辅助函数（登录验证、IP检测、邮件发送等）依赖读取旧 `config.php` 追加，全新安装时旧文件不存在，导致函数缺失
- **修复：** 抽取所有辅助函数到独立文件 `functions.php`，`config.template.php` 通过 `require_once` 自动引用
- **涉及文件：** `functions.php`（新建）、`config.template.php`、`config.php`、`install.php`
- **影响范围：** 任何通过安装向导进行全新安装的部署

### 🔧 首页底部布局优化 & 新增修复日志按钮
- **问题：** 5个底部按钮（GitHub、Gitee、开发者、打赏、修复日志）挤在一行
- **修复：** 分为两排：第一排 GitHub + Gitee（Gitee图标改为内嵌SVG），第二排 开发者 + 打赏 + 修复日志
- **新增：** 修复日志按钮，点击弹窗从本地读取 BUGFIX_LOG.md 内容并渲染
- **涉及文件：** `index.php`

### 🔧 Bug 6：封禁IP页面空白 - 未闭合HTML注释
- **问题等级：** ⚡ 中等 — 被封禁IP访问首页时页面完全空白，无任何提示
- **原因：** `index.php` 第54行 `die()` 中的 HTML 字符串内嵌了 `<!-- ====== 🎬` 未闭合的HTML注释，浏览器将 `</head>` 之后的所有内容都视为注释，导致页面渲染为空
- **修复：** 移除该行未闭合注释，`</style>` 后直接接 `</head><body>`
- **涉及文件：** `index.php`、`BUGFIX_LOG.md`
- **影响范围：** 所有被封禁IP访问首页的用户（管理后台手动封禁 + 系统自动封禁）
- **附注：** 同步修复 `settings.php` 中硬编码绝对路径 `/var/www/html/mirror/` 改为动态描述

## v3.2 — 2026-07-22

### 🛡️ Bug 5（严重安全漏洞）：邮箱验证码登录 - 任意邮箱可获取验证码
- **漏洞等级：** ⚠️ 严重 — 任意邮箱即可获取验证码登录后台
- **问题：** `login.php` 中发送邮箱验证码时，未校验输入的邮箱是否为系统设置的发件邮箱（管理员邮箱）。攻击者只需输入任意邮箱即可获取验证码，若该邮箱在攻击者控制下，即可直接登录后台
- **修复步骤：**
  - `发送验证码` 环节：在 `send_vcode` 处理中，比对 `$_POST['vcode_email']` 与数据库中 `email_send_address` 是否一致，不一致则返回 `❌ 非管理员邮箱，无法发送验证码` 并记录日志 `vcode_email_mismatch`
  - `验证码登录` 环节：在登录处理中，二次比对邮箱与 `email_send_address`，不一致则拒绝登录
- **涉及文件：** `login.php`
- **影响范围：** 所有开启邮箱验证码登录的后台

---

## v3.1.2 — 2026-07-04

### 🔧 Bug 1：未配置邮箱时用户仍可打开通知开关
- **问题：** 管理员未配置邮箱 SMTP 时，用户在前端点击邮箱通知开关仍然可以展开输入框
- **修复：** 在 `index.php` 的 `toggleField` 函数中增加邮箱配置检查，未配置时弹出提示条「管理员尚未配置邮箱通知，请联系管理员在后台设置」并自动关闭开关
- **影响范围：** 前端用户生成链接页面

### 🔧 Bug 2：系统设置邮箱通知开关无法开启
- **问题：** `settings.php` 中 `isset($_POST['email_enabled'])` 始终为 true（hidden input 始终提交），导致邮箱通知开关永远无法保存为关闭状态
- **修复：** 将 `isset($_POST['email_enabled'])` 改为 `($_POST['email_enabled'] ?? '') === '1'`，真正判断值是 '1' 还是 '0'
- **影响范围：** 后台系统设置 - 邮箱通知 Tab

### 🔧 Bug 3：系统设置开屏开关无法关闭
- **问题：** 同上，`isset($_POST['splash_enabled'])` 始终为 true，导致开屏闪屏开关永远无法关闭
- **修复：** 将 `isset($_POST['splash_enabled'])` 改为 `($_POST['splash_enabled'] ?? '') === '1'`
- **影响范围：** 后台系统设置 - 开屏闪屏 Tab

### 🔧 Bug 4：管理员退出后台后需重新登录
- **问题：** PHP 默认 `session.cookie_lifetime=0`（浏览器关闭即过期）+ `session.gc_maxlifetime=1440`（24分钟无操作即失效），导致管理员频繁被踢出登录
- **修复：** 
  - `config.php` 加入 `ini_set('session.cookie_lifetime', 2592000)` 和 `ini_set('session.gc_maxlifetime', 2592000)`（30天）
  - `login.php` 登录成功处调用 `session_set_cookie_params(2592000)` 确保 regenerated 的会话 cookie 也有30天有效期
- **影响范围：** 全局登录状态

### 🛡️ 滑块验证安全增强 (Token 方案)
- **问题：** 滑块验证纯前端实现，`slider_pass=1` 可被 curl 等工具直接伪造绕过
- **方案：** 自建服务端 Token 验证
  - `config.php` 新增 `generateCaptchaToken()` / `markCaptchaVerified()` / `checkCaptchaToken()` 三个函数
  - 每次页面加载生成随机 32 位 hex token 存入 session
  - 滑块拖动完成时发 AJAX 请求到服务端标记 token 已验证
  - 表单提交时服务端双重校验：`slider_pass === '1'` + `checkCaptchaToken()`
- **涉及页面：** `index.php`（链接生成）、`login.php`（密码/邮箱登录）
- **安全性：** 攻击者无法通过直接 POST `slider_pass=1` 绕过，必须完成真实的滑动操作

---

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
