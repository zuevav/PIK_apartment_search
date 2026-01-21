<?php
/**
 * PIK Apartment Tracker - Main Page
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <?= htmlspecialchars($siteName) ?> <span>| Отслеживание квартир</span>
            </div>
            <nav class="nav">
                <button class="nav-btn active" data-tab="apartments">Квартиры</button>
                <button class="nav-btn" data-tab="projects">Проекты</button>
                <button class="nav-btn" data-tab="filters">Фильтры</button>
                <button class="nav-btn" data-tab="settings">Настройки</button>
                <div class="nav-separator"></div>
                <div class="user-menu">
                    <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
                    <a href="logout.php" class="nav-btn logout-btn">Выйти</a>
                </div>
            </nav>
        </div>
    </header>

    <main class="container">
        <!-- Stats -->
        <div class="stats-grid" id="stats">
            <div class="stat-card">
                <div class="stat-value" id="stat-apartments">-</div>
                <div class="stat-label">Квартир</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="stat-projects">-</div>
                <div class="stat-label">Отслеживается ЖК</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="stat-new-today">-</div>
                <div class="stat-label">Новых сегодня</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="stat-price-changes">-</div>
                <div class="stat-label">Изменений цен</div>
            </div>
        </div>

        <!-- Apartments Tab -->
        <div class="tab-content active" id="tab-apartments">
            <div class="main-grid">
                <!-- Sidebar with filters -->
                <aside class="sidebar">
                    <div class="card">
                        <div class="card-header">
                            Фильтры
                            <button class="btn btn-sm btn-outline" onclick="resetFilters()">Сбросить</button>
                        </div>
                        <div class="card-body">
                            <div class="filter-group">
                                <label>Проект (ЖК)</label>
                                <select id="filter-project">
                                    <option value="">Все проекты</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label>Комнаты</label>
                                <div class="filter-row">
                                    <input type="number" id="filter-rooms-min" placeholder="от" min="0">
                                    <input type="number" id="filter-rooms-max" placeholder="до" min="0">
                                </div>
                            </div>

                            <div class="filter-group">
                                <label>Цена (руб.)</label>
                                <div class="filter-row">
                                    <input type="number" id="filter-price-min" placeholder="от" min="0" step="100000">
                                    <input type="number" id="filter-price-max" placeholder="до" min="0" step="100000">
                                </div>
                            </div>

                            <div class="filter-group">
                                <label>Площадь (м²)</label>
                                <div class="filter-row">
                                    <input type="number" id="filter-area-min" placeholder="от" min="0" step="1">
                                    <input type="number" id="filter-area-max" placeholder="до" min="0" step="1">
                                </div>
                            </div>

                            <div class="filter-group">
                                <label>Этаж</label>
                                <div class="filter-row">
                                    <input type="number" id="filter-floor-min" placeholder="от" min="1">
                                    <input type="number" id="filter-floor-max" placeholder="до" min="1">
                                </div>
                            </div>

                            <div class="filter-group">
                                <label>Сортировка</label>
                                <select id="filter-order">
                                    <option value="price ASC">Цена: по возрастанию</option>
                                    <option value="price DESC">Цена: по убыванию</option>
                                    <option value="area ASC">Площадь: по возрастанию</option>
                                    <option value="area DESC">Площадь: по убыванию</option>
                                    <option value="first_seen_at DESC">Сначала новые</option>
                                </select>
                            </div>

                            <button class="btn btn-primary btn-block" onclick="loadApartments()">
                                Применить фильтры
                            </button>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">Действия</div>
                        <div class="card-body">
                            <button class="btn btn-success btn-block mb-1" onclick="fetchApartments()">
                                Обновить данные с PIK
                            </button>
                            <button class="btn btn-outline btn-block" onclick="saveCurrentFilter()">
                                Сохранить фильтр
                            </button>
                        </div>
                    </div>
                </aside>

                <!-- Apartments list -->
                <div class="content">
                    <div class="card">
                        <div class="card-header">
                            <span>Найденные квартиры: <strong id="apartments-count">0</strong></span>
                        </div>
                        <div class="card-body">
                            <div id="apartments-list" class="apartments-grid">
                                <div class="loading">
                                    <div class="spinner"></div>
                                    Загрузка...
                                </div>
                            </div>
                            <div id="pagination" class="pagination hidden">
                                <button class="btn btn-outline btn-sm" onclick="prevPage()">Назад</button>
                                <span class="pagination-info" id="pagination-info"></span>
                                <button class="btn btn-outline btn-sm" onclick="nextPage()">Вперед</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Projects Tab -->
        <div class="tab-content" id="tab-projects">
            <div class="card">
                <div class="card-header">
                    Проекты PIK
                    <button class="btn btn-primary btn-sm" onclick="syncProjects()">
                        Синхронизировать
                    </button>
                </div>
                <div class="card-body">
                    <p class="mb-2">Отметьте проекты, которые хотите отслеживать:</p>
                    <div id="projects-list" class="projects-list">
                        <div class="loading">
                            <div class="spinner"></div>
                            Загрузка проектов...
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Tab -->
        <div class="tab-content" id="tab-filters">
            <div class="card">
                <div class="card-header">
                    Сохраненные фильтры
                    <button class="btn btn-primary btn-sm" onclick="showFilterModal()">
                        Добавить фильтр
                    </button>
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

        <!-- Settings Tab -->
        <div class="tab-content" id="tab-settings">
            <div class="card">
                <div class="card-header">Настройки уведомлений</div>
                <div class="card-body">
                    <div class="filter-group">
                        <label>
                            <input type="checkbox" id="setting-email-enabled">
                            Включить email-уведомления
                        </label>
                    </div>
                    <div class="filter-group">
                        <label>Email для уведомлений</label>
                        <input type="email" id="setting-email" placeholder="your@email.com">
                    </div>
                    <div class="filter-group">
                        <label>Интервал проверки (часов)</label>
                        <select id="setting-interval">
                            <option value="1">Каждый час</option>
                            <option value="3">Каждые 3 часа</option>
                            <option value="6">Каждые 6 часов</option>
                            <option value="12">Каждые 12 часов</option>
                            <option value="24">Раз в день</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" onclick="saveSettings()">
                        Сохранить настройки
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
                <div class="card-header">API Status</div>
                <div class="card-body">
                    <button class="btn btn-outline" onclick="testApi()">
                        Проверить подключение к PIK API
                    </button>
                    <div id="api-status" class="mt-1"></div>
                </div>
            </div>

            <div class="card mt-2">
                <div class="card-header">Cron-задача</div>
                <div class="card-body">
                    <p>Для автоматической проверки добавьте в crontab:</p>
                    <code style="display:block;background:#f5f5f5;padding:1rem;border-radius:4px;margin-top:0.5rem;word-break:break-all;">
                        0 */6 * * * php <?= __DIR__ ?>/cron.php >> /var/log/pik-tracker.log 2>&1
                    </code>
                </div>
            </div>
        </div>
    </main>

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

    <!-- Filter Modal -->
    <div class="modal-overlay" id="filter-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Сохранить фильтр</h3>
                <button class="modal-close" onclick="closeModal('filter-modal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="filter-group">
                    <label>Название фильтра *</label>
                    <input type="text" id="modal-filter-name" placeholder="Например: 2-комнатные до 15 млн">
                </div>
                <div class="filter-group">
                    <label>Email для уведомлений</label>
                    <input type="email" id="modal-filter-email" placeholder="Оставьте пустым, если не нужны">
                </div>
                <p style="font-size:0.85rem;color:#666;">
                    Текущие параметры фильтрации будут сохранены.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeModal('filter-modal')">Отмена</button>
                <button class="btn btn-primary" onclick="doSaveFilter()">Сохранить</button>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
