<?php
/**
 * PIK Apartment Tracker - Installer
 *
 * Run this once to set up the system
 */

session_start();

// Check if already installed
$configFile = __DIR__ . '/config.php';
$lockFile = __DIR__ . '/data/.installed';

if (file_exists($lockFile) && !isset($_GET['force'])) {
    header('Location: index.php');
    exit;
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2:
            // Save configuration
            $config = [
                'site_name' => trim($_POST['site_name'] ?? 'PIK Tracker'),
                'timezone' => $_POST['timezone'] ?? 'Europe/Moscow',
                'email_enabled' => !empty($_POST['email_enabled']),
                'email_to' => trim($_POST['email_to'] ?? ''),
                'email_from' => trim($_POST['email_from'] ?? ''),
                'smtp_host' => trim($_POST['smtp_host'] ?? ''),
                'smtp_port' => (int) ($_POST['smtp_port'] ?? 587),
                'smtp_user' => trim($_POST['smtp_user'] ?? ''),
                'smtp_pass' => $_POST['smtp_pass'] ?? '',
            ];
            $_SESSION['install_config'] = $config;
            header('Location: install.php?step=3');
            exit;

        case 3:
            // Create admin user
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            if (strlen($username) < 3) {
                $error = 'Логин должен быть минимум 3 символа';
            } elseif (strlen($password) < 6) {
                $error = 'Пароль должен быть минимум 6 символов';
            } elseif ($password !== $password2) {
                $error = 'Пароли не совпадают';
            } else {
                $_SESSION['install_admin'] = [
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                ];
                header('Location: install.php?step=4');
                exit;
            }
            break;

        case 4:
            // Finalize installation
            try {
                $config = $_SESSION['install_config'] ?? [];
                $admin = $_SESSION['install_admin'] ?? [];

                if (empty($config) || empty($admin)) {
                    throw new Exception('Данные установки потеряны. Начните заново.');
                }

                // Create config file
                $configContent = generateConfig($config);
                file_put_contents($configFile, $configContent);

                // Create data directory
                $dataDir = __DIR__ . '/data';
                if (!is_dir($dataDir)) {
                    mkdir($dataDir, 0755, true);
                }

                // Initialize database with admin user
                require_once __DIR__ . '/api/Database.php';
                $db = new Database($dataDir . '/apartments.db');

                // Add users table
                $db->getPdo()->exec("
                    CREATE TABLE IF NOT EXISTS users (
                        id INTEGER PRIMARY KEY,
                        username TEXT UNIQUE NOT NULL,
                        password TEXT NOT NULL,
                        email TEXT,
                        is_admin INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        last_login DATETIME
                    )
                ");

                // Insert admin user
                $stmt = $db->getPdo()->prepare("
                    INSERT INTO users (username, password, email, is_admin)
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->execute([
                    $admin['username'],
                    $admin['password'],
                    $config['email_to'] ?? null,
                ]);

                // Create lock file
                file_put_contents($lockFile, date('Y-m-d H:i:s'));

                // Clear session
                unset($_SESSION['install_config'], $_SESSION['install_admin']);

                $success = true;
            } catch (Exception $e) {
                $error = 'Ошибка установки: ' . $e->getMessage();
            }
            break;
    }
}

function generateConfig(array $config): string
{
    $emailEnabled = $config['email_enabled'] ? 'true' : 'false';

    return <<<PHP
<?php
/**
 * PIK Apartment Tracker - Configuration
 * Generated: {$_SERVER['REQUEST_TIME']}
 */

return [
    // Site
    'site_name' => '{$config['site_name']}',

    // Database
    'db_path' => __DIR__ . '/data/apartments.db',

    // PIK API
    'pik_api_base' => 'https://api.pik.ru',
    'pik_api_version' => 'v2',
    'pik_site_url' => 'https://www.pik.ru',

    // Request settings
    'request_timeout' => 30,
    'request_delay' => 2,

    // Email notifications
    'email' => [
        'enabled' => {$emailEnabled},
        'from' => '{$config['email_from']}',
        'from_name' => '{$config['site_name']}',
        'to' => '{$config['email_to']}',
        'smtp' => [
            'host' => '{$config['smtp_host']}',
            'port' => {$config['smtp_port']},
            'username' => '{$config['smtp_user']}',
            'password' => '{$config['smtp_pass']}',
            'encryption' => 'tls',
        ],
    ],

    // Security
    'session_lifetime' => 86400, // 24 hours
    'auth_required' => true,

    // Settings
    'timezone' => '{$config['timezone']}',
    'locale' => 'ru_RU',
    'items_per_page' => 50,
    'check_interval_hours' => 6,
];
PHP;
}

function checkRequirements(): array
{
    $checks = [];

    // PHP version
    $checks['php_version'] = [
        'name' => 'PHP версия',
        'required' => '7.4+',
        'current' => PHP_VERSION,
        'ok' => version_compare(PHP_VERSION, '7.4.0', '>='),
    ];

    // Extensions
    $extensions = ['pdo', 'pdo_sqlite', 'curl', 'json', 'mbstring'];
    foreach ($extensions as $ext) {
        $checks["ext_$ext"] = [
            'name' => "Расширение $ext",
            'required' => 'Установлено',
            'current' => extension_loaded($ext) ? 'Да' : 'Нет',
            'ok' => extension_loaded($ext),
        ];
    }

    // Writable directories
    $dirs = [
        __DIR__ . '/data' => 'data/',
        __DIR__ => 'Корневая папка',
    ];
    foreach ($dirs as $path => $name) {
        $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
        $checks["dir_" . md5($path)] = [
            'name' => "Запись в $name",
            'required' => 'Доступно',
            'current' => $writable ? 'Да' : 'Нет',
            'ok' => $writable,
        ];
    }

    return $checks;
}

$requirements = checkRequirements();
$allOk = !in_array(false, array_column($requirements, 'ok'));

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка PIK Tracker</title>
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
        .installer {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 600px;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { opacity: 0.9; }
        .steps {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        .step {
            flex: 1;
            padding: 15px;
            text-align: center;
            font-size: 14px;
            color: #6c757d;
            position: relative;
        }
        .step.active { color: #ff6b35; font-weight: 600; }
        .step.done { color: #28a745; }
        .step.done::after {
            content: '✓';
            margin-left: 5px;
        }
        .content { padding: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #6c757d;
            font-size: 13px;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #ff6b35;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input { width: auto; }
        .btn {
            display: inline-block;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
            color: white;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(255,107,53,0.4); }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-block { width: 100%; }
        .buttons { display: flex; gap: 15px; margin-top: 30px; }
        .buttons .btn { flex: 1; }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .alert-success { background: #d4edda; color: #155724; }
        .requirements { margin: 20px 0; }
        .req-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
        }
        .req-item:last-child { border-bottom: none; }
        .req-name { font-weight: 500; }
        .req-status { font-weight: 600; }
        .req-status.ok { color: #28a745; }
        .req-status.fail { color: #dc3545; }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin: 25px 0 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        .section-title:first-child { margin-top: 0; }
        .success-icon {
            font-size: 80px;
            text-align: center;
            margin: 20px 0;
        }
        .row { display: flex; gap: 15px; }
        .row .form-group { flex: 1; }
    </style>
</head>
<body>
    <div class="installer">
        <div class="header">
            <h1>PIK Apartment Tracker</h1>
            <p>Мастер установки</p>
        </div>

        <div class="steps">
            <div class="step <?= $step == 1 ? 'active' : ($step > 1 ? 'done' : '') ?>">1. Проверка</div>
            <div class="step <?= $step == 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">2. Настройки</div>
            <div class="step <?= $step == 3 ? 'active' : ($step > 3 ? 'done' : '') ?>">3. Админ</div>
            <div class="step <?= $step == 4 ? 'active' : '' ?>">4. Готово</div>
        </div>

        <div class="content">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($step == 1): ?>
                <!-- Step 1: Requirements -->
                <h2 class="section-title">Проверка требований</h2>

                <div class="requirements">
                    <?php foreach ($requirements as $req): ?>
                        <div class="req-item">
                            <span class="req-name"><?= $req['name'] ?></span>
                            <span class="req-status <?= $req['ok'] ? 'ok' : 'fail' ?>">
                                <?= $req['current'] ?>
                                <?= $req['ok'] ? '✓' : '✗' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($allOk): ?>
                    <div class="alert alert-success">
                        Все требования выполнены. Можно продолжить установку.
                    </div>
                    <a href="?step=2" class="btn btn-primary btn-block">Продолжить →</a>
                <?php else: ?>
                    <div class="alert alert-danger">
                        Не все требования выполнены. Исправьте проблемы и обновите страницу.
                    </div>
                    <a href="?step=1" class="btn btn-secondary btn-block">Проверить снова</a>
                <?php endif; ?>

            <?php elseif ($step == 2): ?>
                <!-- Step 2: Configuration -->
                <form method="POST">
                    <h2 class="section-title">Основные настройки</h2>

                    <div class="form-group">
                        <label>Название сайта</label>
                        <input type="text" name="site_name" value="PIK Tracker" required>
                    </div>

                    <div class="form-group">
                        <label>Часовой пояс</label>
                        <select name="timezone">
                            <option value="Europe/Moscow">Москва (UTC+3)</option>
                            <option value="Europe/Samara">Самара (UTC+4)</option>
                            <option value="Asia/Yekaterinburg">Екатеринбург (UTC+5)</option>
                            <option value="Asia/Novosibirsk">Новосибирск (UTC+7)</option>
                            <option value="Asia/Vladivostok">Владивосток (UTC+10)</option>
                        </select>
                    </div>

                    <h2 class="section-title">Email уведомления (опционально)</h2>

                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="email_enabled" id="email_enabled" onchange="toggleEmail()">
                            <label for="email_enabled" style="margin:0;">Включить email уведомления</label>
                        </div>
                    </div>

                    <div id="email-settings" style="display:none;">
                        <div class="form-group">
                            <label>Ваш Email (куда присылать)</label>
                            <input type="email" name="email_to" placeholder="your@email.com">
                        </div>

                        <div class="form-group">
                            <label>Email отправителя</label>
                            <input type="email" name="email_from" placeholder="noreply@yourdomain.com">
                        </div>

                        <h2 class="section-title">SMTP сервер</h2>
                        <small style="display:block;margin:-10px 0 15px;color:#6c757d;">
                            Оставьте пустым для использования встроенной функции mail()
                        </small>

                        <div class="row">
                            <div class="form-group">
                                <label>SMTP хост</label>
                                <input type="text" name="smtp_host" placeholder="smtp.gmail.com">
                            </div>
                            <div class="form-group">
                                <label>Порт</label>
                                <input type="number" name="smtp_port" value="587">
                            </div>
                        </div>

                        <div class="row">
                            <div class="form-group">
                                <label>SMTP логин</label>
                                <input type="text" name="smtp_user">
                            </div>
                            <div class="form-group">
                                <label>SMTP пароль</label>
                                <input type="password" name="smtp_pass">
                            </div>
                        </div>
                    </div>

                    <div class="buttons">
                        <a href="?step=1" class="btn btn-secondary">← Назад</a>
                        <button type="submit" class="btn btn-primary">Продолжить →</button>
                    </div>
                </form>

                <script>
                function toggleEmail() {
                    document.getElementById('email-settings').style.display =
                        document.getElementById('email_enabled').checked ? 'block' : 'none';
                }
                </script>

            <?php elseif ($step == 3): ?>
                <!-- Step 3: Admin Account -->
                <form method="POST">
                    <h2 class="section-title">Создание администратора</h2>
                    <p style="margin-bottom:20px;color:#6c757d;">
                        Эти данные будут использоваться для входа в систему.
                    </p>

                    <div class="form-group">
                        <label>Логин</label>
                        <input type="text" name="username" required minlength="3"
                               placeholder="admin" autocomplete="username">
                        <small>Минимум 3 символа</small>
                    </div>

                    <div class="form-group">
                        <label>Пароль</label>
                        <input type="password" name="password" required minlength="6"
                               placeholder="••••••••" autocomplete="new-password">
                        <small>Минимум 6 символов</small>
                    </div>

                    <div class="form-group">
                        <label>Повторите пароль</label>
                        <input type="password" name="password2" required minlength="6"
                               placeholder="••••••••" autocomplete="new-password">
                    </div>

                    <div class="buttons">
                        <a href="?step=2" class="btn btn-secondary">← Назад</a>
                        <button type="submit" class="btn btn-primary">Создать →</button>
                    </div>
                </form>

            <?php elseif ($step == 4): ?>
                <!-- Step 4: Complete -->
                <?php if ($success): ?>
                    <div class="success-icon">🎉</div>
                    <h2 style="text-align:center;margin-bottom:15px;">Установка завершена!</h2>
                    <p style="text-align:center;color:#6c757d;margin-bottom:30px;">
                        PIK Tracker успешно установлен и готов к работе.
                    </p>

                    <div class="alert alert-success">
                        <strong>Не забудьте настроить cron для автоматических проверок:</strong><br>
                        <code style="display:block;margin-top:10px;background:#c3e6cb;padding:10px;border-radius:4px;font-size:13px;">
                            0 */6 * * * php <?= __DIR__ ?>/cron.php
                        </code>
                    </div>

                    <a href="index.php" class="btn btn-primary btn-block">Войти в систему →</a>
                <?php else: ?>
                    <form method="POST">
                        <h2 class="section-title">Завершение установки</h2>
                        <p style="margin-bottom:20px;">
                            Нажмите кнопку для завершения установки. Будут созданы:
                        </p>
                        <ul style="margin-bottom:20px;padding-left:20px;color:#6c757d;">
                            <li>Конфигурационный файл</li>
                            <li>База данных</li>
                            <li>Учетная запись администратора</li>
                        </ul>

                        <div class="buttons">
                            <a href="?step=3" class="btn btn-secondary">← Назад</a>
                            <button type="submit" class="btn btn-primary">Установить</button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
