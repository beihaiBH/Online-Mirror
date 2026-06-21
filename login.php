<?php
/**
 * Online-Mirror 升级版 - 登录页面
 */
session_start();
require_once __DIR__ . '/config.php';

// 退出
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if ($username && $password) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && $password === $user['password']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                header('Location: dashboard.php');
                exit;
            } else {
                $error = '用户名或密码错误！';
            }
        } catch (Exception $e) {
            $error = '系统错误，请稍后再试';
        }
    } else {
        $error = '请输入用户名和密码';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 · 网恋照妖镜</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(145deg, #0f0c29, #302b63, #24243e);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    color: #e0e0e0;
}
.login-box {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 24px;
    padding: 40px;
    max-width: 400px;
    width: 100%;
    text-align: center;
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
.login-box .icon {
    font-size: 48px;
    color: #667eea;
    margin-bottom: 10px;
}
.login-box h2 {
    font-size: 24px;
    margin-bottom: 4px;
}
.login-box p.sub {
    font-size: 14px;
    color: #8080a0;
    margin-bottom: 24px;
}
.form-group {
    margin-bottom: 16px;
}
.form-group input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 12px;
    background: rgba(255,255,255,0.06);
    color: #e0e0e0;
    font-size: 15px;
    outline: none;
    transition: all 0.3s;
}
.form-group input:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
}
.btn-login {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102,126,234,0.4);
}
.error {
    background: rgba(255,80,80,0.1);
    border: 1px solid rgba(255,80,80,0.2);
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 16px;
    color: #ff6b6b;
    font-size: 14px;
}
.back-link {
    margin-top: 20px;
    font-size: 14px;
}
.back-link a { color: #667eea; text-decoration: none; }
.back-link a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="login-box">
    <div class="icon"><i class="fas fa-shield-alt"></i></div>
    <h2>管理员登录</h2>
    <p class="sub">Online Mirror 后台管理系统</p>
    
    <?php if ($error): ?>
    <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <input type="text" name="username" placeholder="用户名" required autocomplete="username">
        </div>
        <div class="form-group">
            <input type="password" name="password" placeholder="密码" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> 登 录</button>
    </form>
    
    <div class="back-link">
        <a href="index.php"><i class="fas fa-arrow-left"></i> 返回首页</a>
    </div>
</div>
</body>
</html>
