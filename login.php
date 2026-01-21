<?php
/**
 * PIK Apartment Tracker - Login Page
 */

session_start();

// Check if installed
if (!file_exists(__DIR__ . '/data/.installed')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/Auth.php';

$config = require __DIR__ . '/config.php';
$db = new Database($config['db_path']);
$auth = new Auth($db->getPdo(), $config);

// Already logged in?
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? 'index.php';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = $auth->login($username, $password);

    if ($result['success']) {
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = $result['error'];
    }
}

$siteName = $config['site_name'] ?? 'PIK Tracker';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — <?= htmlspecialchars($siteName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .login-form {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #ff6b35;
            box-shadow: 0 0 0 3px rgba(255,107,53,0.1);
        }
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255,107,53,0.4);
        }
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
        .remember input {
            width: 18px;
            height: 18px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-header">
            <h1><?= htmlspecialchars($siteName) ?></h1>
            <p>Вход в систему</p>
        </div>

        <form class="login-form" method="POST">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Логин</label>
                <input type="text" id="username" name="username" required
                       autofocus autocomplete="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password">
            </div>

            <label class="remember">
                <input type="checkbox" name="remember">
                Запомнить меня
            </label>

            <button type="submit" class="btn">Войти</button>
        </form>
    </div>
</body>
</html>
