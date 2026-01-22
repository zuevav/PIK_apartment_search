<?php
/**
 * PIK Apartment Tracker - Main Page (Wizard Interface)
 */

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

// Check authentication
$auth->requireAuth();

$user = $auth->getUser();
$siteName = $config['site_name'] ?? 'PIK Tracker';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Wizard styles */
        .wizard-progress {
            display: flex;
            justify-content: center;
            gap: 0;
            margin-bottom: 2rem;
            background: #fff;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .wizard-step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            color: #999;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 8px;
        }
        .wizard-step:hover {
            background: #f5f5f5;
        }
        .wizard-step.active {
            color: var(--primary);
            font-weight: 600;
        }
        .wizard-step.completed {
            color: var(--success);
        }
        .wizard-step-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .wizard-step.active .wizard-step-number {
            background: var(--primary);
            color: #fff;
        }
        .wizard-step.completed .wizard-step-number {
            background: var(--success);
            color: #fff;
        }
        .wizard-step-arrow {
            color: #ccc;
            margin: 0 0.5rem;
        }
        .wizard-content {
            display: none;
        }
        .wizard-content.active {
            display: block;
        }
        .wizard-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }
        .wizard-actions .btn {
            min-width: 150px;
        }
        .selected-count {
            background: var(--primary);
            color: #fff;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }
        .criteria-grid {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .filter-card {
            background: #fafafa;
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid #eee;
        }
        .filter-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-weight: 600;
            color: var(--pik-dark);
        }
        .filter-card-icon {
            width: 36px;
            height: 36px;
            background: var(--pik-orange);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
        }
        .price-presets {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
        }
        .price-preset {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 20px;
            background: white;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .price-preset:hover {
            border-color: var(--pik-orange);
            color: var(--pik-orange);
        }
        .price-preset.active {
            background: var(--pik-orange);
            border-color: var(--pik-orange);
            color: white;
        }
        .range-inputs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .range-inputs input {
            flex: 1;
            text-align: center;
        }
        .range-inputs .separator {
            color: #999;
            font-weight: 500;
        }
        @media (max-width: 768px) {
            .price-presets {
                justify-content: center;
            }
        }
        .subscription-box {
            background: linear-gradient(135deg, #fff5f0 0%, #fff 100%);
            border: 2px solid var(--primary);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        .subscription-box h4 {
            color: var(--primary);
            margin-bottom: 1rem;
        }
        .settings-icon {
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .settings-icon:hover {
            background: rgba(0,0,0,0.05);
        }
        .my-filters-link {
            color: var(--primary);
            cursor: pointer;
            font-size: 0.9rem;
        }
        .my-filters-link:hover {
            text-decoration: underline;
        }

        /* Room buttons */
        .rooms-buttons {
            display: flex;
            gap: 0.75rem;
        }
        .room-btn {
            flex: 1;
            padding: 1rem 0.5rem;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            background: white;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.2s;
            text-align: center;
        }
        .room-btn:hover {
            border-color: var(--pik-orange);
            background: #fff5f0;
        }
        .room-btn.active {
            background: var(--pik-orange);
            border-color: var(--pik-orange);
            color: white;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(255, 87, 34, 0.3);
        }

        /* Sort chips */
        .sort-chips-container {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .sort-chips {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .sort-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.4rem 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 20px;
            background: #f8f8f8;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }
        .sort-chip:hover {
            border-color: var(--pik-orange);
            background: #fff5f0;
        }
        .sort-chip.asc {
            background: #e3f2fd;
            border-color: #2196f3;
            color: #1565c0;
        }
        .sort-chip.desc {
            background: #fff3e0;
            border-color: var(--pik-orange);
            color: #e65100;
        }
        .sort-chip .sort-dir {
            font-weight: bold;
            min-width: 1rem;
        }
        .sort-chip .sort-order {
            display: none;
            background: #333;
            color: white;
            font-size: 0.7rem;
            width: 1.1rem;
            height: 1.1rem;
            border-radius: 50%;
            text-align: center;
            line-height: 1.1rem;
            margin-left: 0.25rem;
        }
        .sort-chip.asc .sort-order,
        .sort-chip.desc .sort-order {
            display: inline-block;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .loading-overlay .spinner {
            width: 50px;
            height: 50px;
            border-width: 4px;
        }
        .loading-overlay .loading-text {
            margin-top: 1rem;
            font-size: 1.1rem;
            color: var(--pik-dark);
            font-weight: 500;
        }
        .loading-overlay .loading-progress {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: var(--pik-gray);
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <?= htmlspecialchars($siteName) ?> <span>| Поиск квартир</span>
            </div>
            <nav class="nav">
                <span class="my-filters-link" onclick="showMyFilters()">Мои подписки</span>
                <div class="nav-separator"></div>
                <span class="settings-icon" onclick="showSettings()" title="Настройки">⚙️</span>
                <div class="user-menu">
                    <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                    <a href="logout.php" class="nav-btn logout-btn">Выйти</a>
                </div>
            </nav>
        </div>
    </header>

    <main class="container">
        <!-- Wizard Progress -->
        <div class="wizard-progress">
            <div class="wizard-step active" data-step="1" onclick="goToStep(1)">
                <span class="wizard-step-number">1</span>
                <span>Выбор ЖК</span>
            </div>
            <span class="wizard-step-arrow">→</span>
            <div class="wizard-step" data-step="2" onclick="goToStep(2)">
                <span class="wizard-step-number">2</span>
                <span>Параметры</span>
            </div>
            <span class="wizard-step-arrow">→</span>
            <div class="wizard-step" data-step="3" onclick="goToStep(3)">
                <span class="wizard-step-number">3</span>
                <span>Результаты</span>
            </div>
        </div>

        <!-- Step 1: Select Projects -->
        <div class="wizard-content active" id="step-1">
            <div class="card">
                <div class="card-header">
                    <div>
                        Шаг 1: Выберите жилые комплексы
                        <span id="selected-projects-count" class="selected-count" style="display:none;">0</span>
                    </div>
                    <button class="btn btn-outline btn-sm" onclick="syncProjects()">
                        Обновить список
                    </button>
                </div>
                <div class="card-body">
                    <p class="mb-2">Выберите ЖК, в которых хотите искать квартиры:</p>

                    <div class="filter-group mb-2" style="display: flex; gap: 0.5rem;">
                        <input type="text" id="projects-search" placeholder="Поиск по названию..." style="flex: 1;">
                        <button class="btn btn-outline btn-sm" onclick="selectAllVisibleProjects()">Выбрать все</button>
                        <button class="btn btn-outline btn-sm" onclick="deselectAllProjects()">Снять все</button>
                    </div>

                    <div id="projects-list" class="projects-list" style="max-height: 400px; overflow-y: auto;">
                        <div class="loading">
                            <div class="spinner"></div>
                            Загрузка проектов...
                        </div>
                    </div>
                </div>

                <div class="wizard-actions">
                    <div></div>
                    <button class="btn btn-primary" onclick="goToStep(2)" id="btn-to-step-2">
                        Далее: Параметры →
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Set Criteria -->
        <div class="wizard-content" id="step-2">
            <div class="card">
                <div class="card-header">
                    Шаг 2: Укажите параметры квартиры
                </div>
                <div class="card-body">
                    <div class="criteria-grid">
                        <!-- Комнаты -->
                        <div class="filter-card">
                            <div class="filter-card-header">
                                <div class="filter-card-icon">&#x1f6cb;</div>
                                <span>Количество комнат</span>
                            </div>
                            <div class="rooms-buttons" id="rooms-buttons">
                                <button type="button" class="room-btn" data-rooms="0">Студия</button>
                                <button type="button" class="room-btn" data-rooms="1">1</button>
                                <button type="button" class="room-btn" data-rooms="2">2</button>
                                <button type="button" class="room-btn" data-rooms="3">3+</button>
                            </div>
                        </div>

                        <!-- Цена -->
                        <div class="filter-card">
                            <div class="filter-card-header">
                                <div class="filter-card-icon">&#x20bd;</div>
                                <span>Бюджет</span>
                            </div>
                            <div class="price-presets">
                                <button type="button" class="price-preset" onclick="setPriceRange(0, 10)">до 10 млн</button>
                                <button type="button" class="price-preset" onclick="setPriceRange(10, 15)">10-15 млн</button>
                                <button type="button" class="price-preset" onclick="setPriceRange(15, 20)">15-20 млн</button>
                                <button type="button" class="price-preset" onclick="setPriceRange(20, 30)">20-30 млн</button>
                                <button type="button" class="price-preset" onclick="setPriceRange(30, 0)">от 30 млн</button>
                            </div>
                            <div class="range-inputs">
                                <input type="number" id="filter-price-min" placeholder="от" min="0" step="0.5">
                                <span class="separator">—</span>
                                <input type="number" id="filter-price-max" placeholder="до" min="0" step="0.5">
                                <span style="color:#888;margin-left:0.25rem;">млн ₽</span>
                            </div>
                        </div>

                        <!-- Площадь и Этаж в одну строку -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="filter-card">
                                <div class="filter-card-header">
                                    <div class="filter-card-icon">&#x25a2;</div>
                                    <span>Площадь</span>
                                </div>
                                <div class="range-inputs">
                                    <input type="number" id="filter-area-min" placeholder="от" min="0" step="5">
                                    <span class="separator">—</span>
                                    <input type="number" id="filter-area-max" placeholder="до" min="0" step="5">
                                    <span style="color:#888;margin-left:0.25rem;">м²</span>
                                </div>
                            </div>

                            <div class="filter-card">
                                <div class="filter-card-header">
                                    <div class="filter-card-icon">&#x2191;</div>
                                    <span>Этаж</span>
                                </div>
                                <div class="range-inputs">
                                    <input type="number" id="filter-floor-min" placeholder="от" min="1">
                                    <span class="separator">—</span>
                                    <input type="number" id="filter-floor-max" placeholder="до" min="1">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wizard-actions">
                    <button class="btn btn-outline" onclick="goToStep(1)">
                        ← Назад
                    </button>
                    <button class="btn btn-primary" onclick="searchApartments()">
                        Найти квартиры →
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 3: Results -->
        <div class="wizard-content" id="step-3">
            <div class="card">
                <div class="card-header" style="flex-direction: column; align-items: stretch; gap: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Результаты поиска: <strong id="apartments-count">0</strong> квартир</span>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-outline btn-sm" onclick="fetchFromPik()" id="btn-fetch-pik">
                                Обновить с PIK
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="goToStep(2)">Изменить параметры</button>
                        </div>
                    </div>
                    <div class="sort-chips-container">
                        <span style="font-size: 0.85rem; color: #666; margin-right: 0.5rem;">Сортировка:</span>
                        <div class="sort-chips" id="sort-chips">
                            <button class="sort-chip" data-field="price" onclick="toggleSort('price')">
                                <span class="sort-label">Цена</span>
                                <span class="sort-dir"></span>
                                <span class="sort-order"></span>
                            </button>
                            <button class="sort-chip" data-field="area" onclick="toggleSort('area')">
                                <span class="sort-label">Площадь</span>
                                <span class="sort-dir"></span>
                                <span class="sort-order"></span>
                            </button>
                            <button class="sort-chip" data-field="floor" onclick="toggleSort('floor')">
                                <span class="sort-label">Этаж</span>
                                <span class="sort-dir"></span>
                                <span class="sort-order"></span>
                            </button>
                        </div>
                        <button class="btn btn-outline btn-sm" onclick="resetSort()" style="margin-left: 0.5rem; font-size: 0.75rem;">Сброс</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="apartments-list" class="apartments-grid">
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <p>Сначала выберите ЖК и задайте параметры поиска</p>
                        </div>
                    </div>
                    <div id="pagination" class="pagination hidden">
                        <button class="btn btn-outline btn-sm" onclick="prevPage()">← Назад</button>
                        <span class="pagination-info" id="pagination-info"></span>
                        <button class="btn btn-outline btn-sm" onclick="nextPage()">Вперед →</button>
                    </div>
                </div>
            </div>

            <!-- Subscription Box -->
            <div class="subscription-box">
                <h4>🔔 Подписаться на обновления</h4>
                <p style="margin-bottom: 1rem; color: #666;">
                    Получайте уведомления о новых квартирах и изменениях цен по этим параметрам:
                </p>
                <div style="display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
                    <div class="filter-group" style="flex: 1; min-width: 200px; margin: 0;">
                        <label>Название подписки</label>
                        <input type="text" id="subscription-name" placeholder="Например: 2-комн. до 15 млн">
                    </div>
                    <div class="filter-group" style="flex: 1; min-width: 200px; margin: 0;">
                        <label>Email для уведомлений</label>
                        <input type="email" id="subscription-email" placeholder="your@email.com">
                    </div>
                    <button class="btn btn-primary" onclick="createSubscription()" style="height: 42px;">
                        Подписаться
                    </button>
                </div>
            </div>
        </div>

        <!-- My Filters/Subscriptions (hidden by default) -->
        <div class="wizard-content" id="my-filters">
            <div class="card">
                <div class="card-header">
                    <span>Мои подписки</span>
                    <button class="btn btn-outline btn-sm" onclick="hideMyFilters()">← Назад к поиску</button>
                </div>
                <div class="card-body">
                    <div id="saved-filters" class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Название</th>
                                    <th>Параметры</th>
                                    <th>Email</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody id="filters-table-body">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings (hidden by default) -->
        <div class="wizard-content" id="settings-panel">
            <div class="card">
                <div class="card-header">
                    <span>Настройки</span>
                    <button class="btn btn-outline btn-sm" onclick="hideSettings()">← Назад к поиску</button>
                </div>
                <div class="card-body">
                    <h4 style="margin-bottom: 1rem;">Уведомления</h4>
                    <div class="filter-group">
                        <label>
                            <input type="checkbox" id="setting-email-enabled">
                            Включить email-уведомления
                        </label>
                    </div>
                    <div class="filter-group">
                        <label>Почтовый сервис</label>
                        <select id="setting-email-provider" onchange="onEmailProviderChange()">
                            <option value="">Выберите сервис</option>
                            <option value="yandex">Яндекс Почта</option>
                            <option value="mailru">Mail.ru</option>
                            <option value="gmail">Gmail</option>
                            <option value="custom">Свой SMTP сервер</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Ваш Email (для отправки и получения)</label>
                        <input type="email" id="setting-email" placeholder="your@yandex.ru">
                    </div>
                    <div class="filter-group">
                        <label>Пароль приложения</label>
                        <input type="password" id="setting-email-password" placeholder="Пароль приложения">
                        <small id="password-hint" style="color:#888;margin-top:0.5rem;display:block;"></small>
                    </div>
                    <div id="custom-smtp-settings" style="display:none;">
                        <div class="filter-group">
                            <label>SMTP сервер</label>
                            <input type="text" id="setting-smtp-host" placeholder="smtp.example.com">
                        </div>
                        <div class="filter-group">
                            <label>Порт</label>
                            <input type="number" id="setting-smtp-port" placeholder="465" value="465">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label>Интервал проверки</label>
                        <select id="setting-interval">
                            <option value="1">Каждый час</option>
                            <option value="3">Каждые 3 часа</option>
                            <option value="6">Каждые 6 часов</option>
                            <option value="12">Каждые 12 часов</option>
                            <option value="24">Раз в день</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" onclick="saveSettings()">
                        Сохранить
                    </button>
                    <button class="btn btn-outline" onclick="testEmail()" style="margin-left:0.5rem;">
                        Тест письма
                    </button>
                </div>
            </div>

            <div class="card mt-2">
                <div class="card-header">Смена пароля</div>
                <div class="card-body">
                    <div class="filter-group">
                        <label>Текущий пароль</label>
                        <input type="password" id="current-password">
                    </div>
                    <div class="filter-group">
                        <label>Новый пароль</label>
                        <input type="password" id="new-password">
                    </div>
                    <div class="filter-group">
                        <label>Повторите новый пароль</label>
                        <input type="password" id="new-password2">
                    </div>
                    <button class="btn btn-secondary" onclick="changePassword()">
                        Сменить пароль
                    </button>
                </div>
            </div>

            <div class="card mt-2">
                <div class="card-header">API и Cron</div>
                <div class="card-body">
                    <button class="btn btn-outline" onclick="testApi()">
                        Проверить подключение к PIK API
                    </button>
                    <div id="api-status" class="mt-1"></div>

                    <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid #eee;">

                    <p>Для автоматической проверки добавьте в crontab:</p>
                    <code style="display:block;background:#f5f5f5;padding:1rem;border-radius:4px;margin-top:0.5rem;word-break:break-all;font-size:0.85rem;">
                        0 */6 * * * php <?= __DIR__ ?>/cron.php
                    </code>
                </div>
            </div>

            <div class="card mt-2">
                <div class="card-header">Обновление системы</div>
                <div class="card-body">
                    <p style="margin-bottom: 1rem; color: #666;">
                        Обновить систему до последней версии из Git репозитория:
                    </p>
                    <button class="btn btn-primary" onclick="updateFromGit()" id="btn-update-git">
                        🔄 Обновить из Git
                    </button>
                    <div id="git-update-status" class="mt-1"></div>
                </div>
            </div>
        </div>
    </main>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="spinner"></div>
        <div class="loading-text">Загрузка квартир...</div>
        <div class="loading-progress" id="loading-progress"></div>
    </div>

    <!-- Apartment Detail Modal -->
    <div class="modal-overlay" id="apartment-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Детали квартиры</h3>
                <button class="modal-close" onclick="closeModal('apartment-modal')">&times;</button>
            </div>
            <div class="modal-body" id="apartment-modal-body">
            </div>
        </div>
    </div>

    <script src="assets/js/app.js?v=<?= time() ?>"></script>
    <script>
        // Wizard navigation
        let currentStep = 1;

        function goToStep(step) {
            // Validate before moving forward
            if (step > currentStep) {
                if (currentStep === 1) {
                    const selected = projects.filter(p => p.is_tracked).length;
                    if (selected === 0) {
                        showAlert('Выберите хотя бы один ЖК', 'warning');
                        return;
                    }
                }
            }

            currentStep = step;

            // Update progress
            document.querySelectorAll('.wizard-step').forEach(el => {
                const s = parseInt(el.dataset.step);
                el.classList.remove('active', 'completed');
                if (s === step) el.classList.add('active');
                else if (s < step) el.classList.add('completed');
            });

            // Show content
            document.querySelectorAll('.wizard-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`step-${step}`).classList.add('active');

            // If going to step 3 via navigation (not search), just load from local DB
            if (step === 3) {
                window.loadApartments();
            }
        }

        async function searchApartments() {
            // Show step 3 first
            currentStep = 3;
            document.querySelectorAll('.wizard-step').forEach(el => {
                const s = parseInt(el.dataset.step);
                el.classList.remove('active', 'completed');
                if (s === 3) el.classList.add('active');
                else if (s < 3) el.classList.add('completed');
            });
            document.querySelectorAll('.wizard-content').forEach(el => el.classList.remove('active'));
            document.getElementById('step-3').classList.add('active');

            // Fetch from PIK
            await fetchFromPik();
        }

        async function fetchFromPik() {
            const btn = document.getElementById('btn-fetch-pik');
            if (btn) {
                btn.textContent = 'Загрузка...';
                btn.disabled = true;
            }

            showLoadingOverlay('Загрузка квартир с PIK...');
            updateLoadingProgress('Подключение к API...');

            try {
                // Get current filters and pass them to the API
                const filters = window.getFilters();
                const params = {
                    rooms: filters.rooms || '',
                    price_min: filters.price_min || '',
                    price_max: filters.price_max || '',
                    area_min: filters.area_min || '',
                    area_max: filters.area_max || ''
                };

                const data = await window.api('fetch_apartments', params);
                console.log('Fetch result:', data);
                updateLoadingProgress(`Загружено: ${data.fetched}, новых: ${data.new}`);

                // Small delay to show the result
                await new Promise(r => setTimeout(r, 500));

                // Show debug info if available
                let msg = `Загружено: ${data.fetched}, новых: ${data.new}, обновлено: ${data.updated}`;
                if (data.debug) {
                    msg += ` (API вернул: ${data.debug.api_returned || '?'})`;
                    console.log('Debug info:', data.debug);
                }
                window.showAlert(msg, 'success');
                window.loadApartments();
            } catch (e) {
                window.showAlert('Ошибка загрузки: ' + e.message, 'danger');
            } finally {
                hideLoadingOverlay();
                if (btn) {
                    btn.textContent = 'Обновить с PIK';
                    btn.disabled = false;
                }
            }
        }

        function updateSelectedCount() {
            const count = projects.filter(p => p.is_tracked).length;
            const el = document.getElementById('selected-projects-count');
            if (count > 0) {
                el.textContent = count;
                el.style.display = 'inline';
            } else {
                el.style.display = 'none';
            }
        }

        function selectAllVisibleProjects() {
            const query = document.getElementById('projects-search').value.toLowerCase().trim();
            const toSelect = projects.filter(p => !query || (p.name && p.name.toLowerCase().includes(query)));
            toSelect.forEach(p => {
                if (!p.is_tracked) {
                    toggleProject(p.id, true);
                }
            });
        }

        function deselectAllProjects() {
            projects.filter(p => p.is_tracked).forEach(p => {
                toggleProject(p.id, false);
            });
        }

        function createSubscription() {
            const name = document.getElementById('subscription-name').value.trim();
            const email = document.getElementById('subscription-email').value.trim();

            if (!name) {
                showAlert('Введите название подписки', 'warning');
                return;
            }

            doSaveFilterWithData(name, email);
        }

        async function doSaveFilterWithData(name, email) {
            const filters = getFilters();
            const trackedProjects = projects.filter(p => p.is_tracked);

            try {
                await api('save_filter', {
                    name,
                    notify_email: email || null,
                    project_ids: trackedProjects.map(p => p.id),
                    rooms_min: filters.rooms_min || null,
                    rooms_max: filters.rooms_max || null,
                    price_min: filters.price_min || null,
                    price_max: filters.price_max || null,
                    area_min: filters.area_min || null,
                    area_max: filters.area_max || null,
                    floor_min: filters.floor_min || null,
                    floor_max: filters.floor_max || null,
                    is_active: true
                }, 'POST');

                showAlert('Подписка создана! Вы будете получать уведомления.', 'success');
                document.getElementById('subscription-name').value = '';
                document.getElementById('subscription-email').value = '';
                loadFilters();
            } catch (e) {
                showAlert('Ошибка создания подписки', 'danger');
            }
        }

        function showMyFilters() {
            document.querySelectorAll('.wizard-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
            document.getElementById('my-filters').classList.add('active');
            loadFilters();
        }

        function hideMyFilters() {
            document.getElementById('my-filters').classList.remove('active');
            goToStep(currentStep);
        }

        function showSettings() {
            document.querySelectorAll('.wizard-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
            document.getElementById('settings-panel').classList.add('active');
        }

        function hideSettings() {
            document.getElementById('settings-panel').classList.remove('active');
            goToStep(currentStep);
        }

        // Email provider settings
        const emailProviders = {
            yandex: {
                host: 'smtp.yandex.ru',
                port: 465,
                hint: 'Создайте пароль приложения: Яндекс ID → Безопасность → Пароли приложений'
            },
            mailru: {
                host: 'smtp.mail.ru',
                port: 465,
                hint: 'Создайте пароль приложения: Mail.ru → Настройки → Пароль и безопасность → Пароли для приложений'
            },
            gmail: {
                host: 'smtp.gmail.com',
                port: 587,
                hint: 'Создайте пароль приложения: Google → Безопасность → Двухэтапная аутентификация → Пароли приложений'
            }
        };

        function onEmailProviderChange() {
            const provider = document.getElementById('setting-email-provider').value;
            const hint = document.getElementById('password-hint');
            const customSettings = document.getElementById('custom-smtp-settings');
            const emailInput = document.getElementById('setting-email');

            if (provider === 'custom') {
                customSettings.style.display = 'block';
                hint.textContent = '';
            } else {
                customSettings.style.display = 'none';
                if (emailProviders[provider]) {
                    hint.innerHTML = emailProviders[provider].hint;
                    // Update email placeholder
                    const domains = {yandex: 'yandex.ru', mailru: 'mail.ru', gmail: 'gmail.com'};
                    emailInput.placeholder = 'your@' + (domains[provider] || 'email.com');
                } else {
                    hint.textContent = '';
                }
            }
        }

        async function testEmail() {
            const email = document.getElementById('setting-email').value;
            if (!email) {
                showAlert('Введите email', 'warning');
                return;
            }

            try {
                showAlert('Отправка тестового письма...', 'info');
                const result = await window.api('test_email', { email }, 'POST');
                if (result.success) {
                    showAlert('Тестовое письмо отправлено! Проверьте почту.', 'success');
                } else {
                    showAlert('Ошибка: ' + (result.error || 'Не удалось отправить'), 'danger');
                }
            } catch (e) {
                showAlert('Ошибка отправки: ' + e.message, 'danger');
            }
        }

        async function updateFromGit() {
            const btn = document.getElementById('btn-update-git');
            const statusDiv = document.getElementById('git-update-status');

            btn.disabled = true;
            btn.textContent = '⏳ Обновление...';
            statusDiv.innerHTML = '<div class="loading"><div class="spinner"></div>Получение обновлений...</div>';

            try {
                const result = await window.api('git_update', {}, 'POST');

                if (result.success) {
                    statusDiv.innerHTML = `
                        <div class="alert alert-success">
                            ✅ Обновлено успешно!<br>
                            <small>${result.message || ''}</small>
                            ${result.changes ? '<br><small>Изменения: ' + result.changes + '</small>' : ''}
                        </div>
                    `;
                    // Reload page after successful update
                    setTimeout(() => {
                        if (confirm('Система обновлена. Перезагрузить страницу?')) {
                            window.location.reload();
                        }
                    }, 1000);
                } else {
                    statusDiv.innerHTML = `
                        <div class="alert alert-danger">
                            ❌ Ошибка: ${result.error || 'Не удалось обновить'}
                        </div>
                    `;
                }
            } catch (e) {
                statusDiv.innerHTML = `
                    <div class="alert alert-danger">
                        ❌ Ошибка: ${e.message}
                    </div>
                `;
            } finally {
                btn.disabled = false;
                btn.textContent = '🔄 Обновить из Git';
            }
        }

        // Override toggleProject to update counter
        const originalToggleProject = window.toggleProject;
        window.toggleProject = async function(projectId, tracked) {
            await originalToggleProject(projectId, tracked);
            updateSelectedCount();
        };

        // Override loadProjects to update counter after load
        const originalLoadProjects = window.loadProjects;
        window.loadProjects = async function() {
            await originalLoadProjects();
            updateSelectedCount();
        };

        // Room buttons
        function initRoomButtons() {
            const buttons = document.querySelectorAll('.room-btn');
            buttons.forEach(btn => {
                btn.addEventListener('click', () => {
                    btn.classList.toggle('active');
                });
            });
        }

        // Price range presets
        function setPriceRange(min, max) {
            const minInput = document.getElementById('filter-price-min');
            const maxInput = document.getElementById('filter-price-max');

            minInput.value = min || '';
            maxInput.value = max || '';

            // Update preset buttons
            document.querySelectorAll('.price-preset').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
        }

        // Clear price presets when manually editing
        function initPriceInputs() {
            const minInput = document.getElementById('filter-price-min');
            const maxInput = document.getElementById('filter-price-max');

            [minInput, maxInput].forEach(input => {
                if (input) {
                    input.addEventListener('input', () => {
                        document.querySelectorAll('.price-preset').forEach(btn => {
                            btn.classList.remove('active');
                        });
                    });
                }
            });
        }

        function getSelectedRooms() {
            const selected = [];
            document.querySelectorAll('.room-btn.active').forEach(btn => {
                selected.push(parseInt(btn.dataset.rooms));
            });
            return selected;
        }

        // Loading overlay
        function showLoadingOverlay(text = 'Загрузка квартир...') {
            const overlay = document.getElementById('loading-overlay');
            overlay.querySelector('.loading-text').textContent = text;
            overlay.querySelector('.loading-progress').textContent = '';
            overlay.classList.add('active');
        }

        function updateLoadingProgress(text) {
            document.getElementById('loading-progress').textContent = text;
        }

        function hideLoadingOverlay() {
            document.getElementById('loading-overlay').classList.remove('active');
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            // Projects search on input
            const searchInput = document.getElementById('projects-search');
            if (searchInput) {
                searchInput.addEventListener('input', window.filterProjects);
            }

            // Initialize room buttons and price inputs
            initRoomButtons();
            initPriceInputs();
            initSortChips();
        });

        // Multi-sort state: array of {field, direction} in priority order
        let sortOrder = [{field: 'price', direction: 'ASC'}]; // Default: price ascending

        function initSortChips() {
            updateSortChipsUI();
        }

        function toggleSort(field) {
            const existingIndex = sortOrder.findIndex(s => s.field === field);

            if (existingIndex === -1) {
                // Not in sort order - add as ASC
                sortOrder.push({field, direction: 'ASC'});
            } else {
                const current = sortOrder[existingIndex];
                if (current.direction === 'ASC') {
                    // ASC -> DESC
                    current.direction = 'DESC';
                } else {
                    // DESC -> remove from sort
                    sortOrder.splice(existingIndex, 1);
                }
            }

            // Ensure at least one sort criteria
            if (sortOrder.length === 0) {
                sortOrder.push({field: 'price', direction: 'ASC'});
            }

            updateSortChipsUI();
            if (currentStep === 3) window.loadApartments();
        }

        function resetSort() {
            sortOrder = [{field: 'price', direction: 'ASC'}];
            updateSortChipsUI();
            if (currentStep === 3) window.loadApartments();
        }

        function updateSortChipsUI() {
            document.querySelectorAll('.sort-chip').forEach(chip => {
                const field = chip.dataset.field;
                const sortInfo = sortOrder.find(s => s.field === field);
                const dirEl = chip.querySelector('.sort-dir');
                const orderEl = chip.querySelector('.sort-order');

                chip.classList.remove('asc', 'desc');

                if (sortInfo) {
                    const idx = sortOrder.indexOf(sortInfo) + 1;
                    chip.classList.add(sortInfo.direction.toLowerCase());
                    dirEl.textContent = sortInfo.direction === 'ASC' ? '↑' : '↓';
                    orderEl.textContent = idx;
                } else {
                    dirEl.textContent = '';
                    orderEl.textContent = '';
                }
            });
        }

        function getSortOrderString() {
            return sortOrder.map(s => `${s.field} ${s.direction}`).join(', ');
        }

        // Expose to global
        window.toggleSort = toggleSort;
        window.resetSort = resetSort;
        window.getSortOrderString = getSortOrderString;
    </script>
</body>
</html>
