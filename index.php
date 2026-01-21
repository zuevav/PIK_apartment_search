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
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
        @media (max-width: 768px) {
            .criteria-grid {
                grid-template-columns: 1fr;
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
                    <p class="mb-2">Задайте критерии поиска (все поля необязательные):</p>

                    <div class="criteria-grid">
                        <div class="filter-group">
                            <label>Количество комнат</label>
                            <div class="filter-row">
                                <input type="number" id="filter-rooms-min" placeholder="от" min="0" max="10">
                                <span style="padding: 0 0.5rem;">—</span>
                                <input type="number" id="filter-rooms-max" placeholder="до" min="0" max="10">
                            </div>
                            <small style="color:#888;">0 = студия</small>
                        </div>

                        <div class="filter-group">
                            <label>Цена (₽)</label>
                            <div class="filter-row">
                                <input type="number" id="filter-price-min" placeholder="от" min="0" step="500000">
                                <span style="padding: 0 0.5rem;">—</span>
                                <input type="number" id="filter-price-max" placeholder="до" min="0" step="500000">
                            </div>
                        </div>

                        <div class="filter-group">
                            <label>Площадь (м²)</label>
                            <div class="filter-row">
                                <input type="number" id="filter-area-min" placeholder="от" min="0" step="5">
                                <span style="padding: 0 0.5rem;">—</span>
                                <input type="number" id="filter-area-max" placeholder="до" min="0" step="5">
                            </div>
                        </div>

                        <div class="filter-group">
                            <label>Этаж</label>
                            <div class="filter-row">
                                <input type="number" id="filter-floor-min" placeholder="от" min="1">
                                <span style="padding: 0 0.5rem;">—</span>
                                <input type="number" id="filter-floor-max" placeholder="до" min="1">
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
                <div class="card-header">
                    <span>Результаты поиска: <strong id="apartments-count">0</strong> квартир</span>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <button class="btn btn-outline btn-sm" onclick="fetchFromPik()" id="btn-fetch-pik">
                            Обновить с PIK
                        </button>
                        <select id="filter-order" style="margin-right: 0.5rem;">
                            <option value="price ASC">Цена ↑</option>
                            <option value="price DESC">Цена ↓</option>
                            <option value="area ASC">Площадь ↑</option>
                            <option value="area DESC">Площадь ↓</option>
                        </select>
                        <button class="btn btn-outline btn-sm" onclick="goToStep(2)">Изменить параметры</button>
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
                        <label>Email по умолчанию</label>
                        <input type="email" id="setting-email" placeholder="your@email.com">
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

            try {
                window.showAlert('Загрузка данных с PIK...', 'info');
                const data = await window.api('fetch_apartments');
                window.showAlert(`Загружено: ${data.fetched}, новых: ${data.new}, обновлено: ${data.updated}`, 'success');
                window.loadApartments();
            } catch (e) {
                window.showAlert('Ошибка загрузки: ' + e.message, 'danger');
            } finally {
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

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            // Projects search on input
            const searchInput = document.getElementById('projects-search');
            if (searchInput) {
                searchInput.addEventListener('input', window.filterProjects);
            }

            // Sort change triggers reload
            const sortSelect = document.getElementById('filter-order');
            if (sortSelect) {
                sortSelect.addEventListener('change', () => {
                    if (currentStep === 3) window.loadApartments();
                });
            }
        });
    </script>
</body>
</html>
