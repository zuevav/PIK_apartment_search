/**
 * PIK Apartment Tracker - Frontend Application
 */

const API_URL = 'api/handler.php';

// State
let currentPage = 0;
let totalItems = 0;
const itemsPerPage = 50;
let projects = [];

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    loadStats();
    loadProjects();
    loadApartments();
    loadFilters();
    loadSettings();
});

// Tabs
function initTabs() {
    document.querySelectorAll('.nav-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.dataset.tab;

            document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.getElementById(`tab-${tabId}`).classList.add('active');
        });
    });
}

// API calls
async function api(action, params = {}, method = 'GET') {
    try {
        let url = `${API_URL}?action=${action}`;
        const options = {
            method,
            headers: { 'Content-Type': 'application/json' }
        };

        if (method === 'GET') {
            Object.entries(params).forEach(([key, value]) => {
                if (value !== null && value !== undefined && value !== '') {
                    url += `&${key}=${encodeURIComponent(value)}`;
                }
            });
        } else {
            options.body = JSON.stringify(params);
        }

        const response = await fetch(url, options);
        const data = await response.json();

        if (!response.ok) {
            // Handle auth errors - redirect to login
            if (response.status === 401 || data.auth_required) {
                window.location.href = 'login.php';
                return;
            }
            throw new Error(data.error || 'API Error');
        }

        return data;
    } catch (error) {
        console.error('API Error:', error);
        showAlert(error.message, 'danger');
        throw error;
    }
}

// Stats
async function loadStats() {
    try {
        const data = await api('get_stats');
        document.getElementById('stat-apartments').textContent = data.stats.total_apartments || 0;
        document.getElementById('stat-projects').textContent = data.stats.tracked_projects || 0;
        document.getElementById('stat-new-today').textContent = data.stats.new_apartments_today || 0;
        document.getElementById('stat-price-changes').textContent = data.stats.price_changes_today || 0;
    } catch (e) {
        console.error('Failed to load stats:', e);
    }
}

// Projects
async function loadProjects() {
    try {
        const data = await api('get_projects');
        projects = data.projects || [];

        renderProjectsList();
        updateProjectsDropdown();
    } catch (e) {
        document.getElementById('projects-list').innerHTML =
            '<div class="alert alert-danger">Не удалось загрузить проекты</div>';
    }
}

function renderProjectsList() {
    const container = document.getElementById('projects-list');

    if (projects.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">🏗️</div>
                <p>Проекты не загружены</p>
                <button class="btn btn-primary mt-1" onclick="syncProjects()">
                    Синхронизировать с PIK
                </button>
            </div>
        `;
        return;
    }

    container.innerHTML = projects.map(p => `
        <div class="project-item">
            <input type="checkbox"
                   id="project-${p.id}"
                   ${p.is_tracked ? 'checked' : ''}
                   onchange="toggleProject(${p.id}, this.checked)">
            <label for="project-${p.id}" class="project-name">${p.name}</label>
        </div>
    `).join('');
}

function updateProjectsDropdown() {
    const select = document.getElementById('filter-project');
    const trackedProjects = projects.filter(p => p.is_tracked);

    select.innerHTML = '<option value="">Все проекты</option>' +
        trackedProjects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
}

async function syncProjects() {
    try {
        showLoading('projects-list');
        const data = await api('sync_projects');
        showAlert(`Синхронизировано ${data.synced} проектов`, 'success');
        await loadProjects();
    } catch (e) {
        showAlert('Ошибка синхронизации проектов', 'danger');
    }
}

async function toggleProject(projectId, tracked) {
    try {
        await api('track_project', { project_id: projectId, tracked }, 'POST');

        const project = projects.find(p => p.id === projectId);
        if (project) project.is_tracked = tracked;

        updateProjectsDropdown();
        loadStats();
    } catch (e) {
        // Revert checkbox
        document.getElementById(`project-${projectId}`).checked = !tracked;
    }
}

// Apartments
async function loadApartments() {
    const filters = getFilters();
    filters.limit = itemsPerPage;
    filters.offset = currentPage * itemsPerPage;

    try {
        showLoading('apartments-list');
        const data = await api('get_apartments', filters);

        totalItems = data.total;
        document.getElementById('apartments-count').textContent = totalItems;

        renderApartments(data.items);
        updatePagination();
    } catch (e) {
        document.getElementById('apartments-list').innerHTML =
            '<div class="alert alert-danger">Не удалось загрузить квартиры</div>';
    }
}

function getFilters() {
    return {
        project_id: document.getElementById('filter-project').value,
        rooms_min: document.getElementById('filter-rooms-min').value,
        rooms_max: document.getElementById('filter-rooms-max').value,
        price_min: document.getElementById('filter-price-min').value,
        price_max: document.getElementById('filter-price-max').value,
        area_min: document.getElementById('filter-area-min').value,
        area_max: document.getElementById('filter-area-max').value,
        floor_min: document.getElementById('filter-floor-min').value,
        floor_max: document.getElementById('filter-floor-max').value,
        order_by: document.getElementById('filter-order').value
    };
}

function resetFilters() {
    document.getElementById('filter-project').value = '';
    document.getElementById('filter-rooms-min').value = '';
    document.getElementById('filter-rooms-max').value = '';
    document.getElementById('filter-price-min').value = '';
    document.getElementById('filter-price-max').value = '';
    document.getElementById('filter-area-min').value = '';
    document.getElementById('filter-area-max').value = '';
    document.getElementById('filter-floor-min').value = '';
    document.getElementById('filter-floor-max').value = '';
    document.getElementById('filter-order').value = 'price ASC';
    currentPage = 0;
    loadApartments();
}

function renderApartments(apartments) {
    const container = document.getElementById('apartments-list');

    if (apartments.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="grid-column: 1/-1;">
                <div class="empty-state-icon">🏠</div>
                <p>Квартиры не найдены</p>
                <p style="font-size:0.85rem;color:#888;margin-top:0.5rem;">
                    Попробуйте изменить фильтры или добавить проекты для отслеживания
                </p>
            </div>
        `;
        return;
    }

    container.innerHTML = apartments.map(apt => {
        const rooms = apt.rooms === 0 ? 'Студия' : `${apt.rooms}-комн.`;
        const priceFormatted = formatPrice(apt.price);
        const pricePerMeter = apt.price_per_meter ? formatPrice(apt.price_per_meter) + '/м²' : '';
        const isNew = isToday(apt.first_seen_at);

        return `
            <div class="apartment-card" onclick="showApartmentDetails(${apt.id})">
                <div class="apartment-header">
                    <div class="apartment-price">
                        ${priceFormatted}
                        ${isNew ? '<span class="price-badge new">Новая</span>' : ''}
                    </div>
                    <div class="apartment-price-per-meter">${pricePerMeter}</div>
                </div>
                <div class="apartment-body">
                    <div class="apartment-params">
                        <div class="param">
                            <span class="param-label">Комнаты</span>
                            <span class="param-value">${rooms}</span>
                        </div>
                        <div class="param">
                            <span class="param-label">Площадь</span>
                            <span class="param-value">${apt.area} м²</span>
                        </div>
                        <div class="param">
                            <span class="param-label">Этаж</span>
                            <span class="param-value">${apt.floor || '-'}${apt.floors_total ? '/' + apt.floors_total : ''}</span>
                        </div>
                        <div class="param">
                            <span class="param-label">Сдача</span>
                            <span class="param-value">${apt.settlement_date || '-'}</span>
                        </div>
                    </div>
                </div>
                <div class="apartment-footer">
                    <span class="apartment-project">${apt.project_name || apt.address || 'ЖК'}</span>
                    ${apt.url ? `<a href="${apt.url}" target="_blank" class="apartment-link" onclick="event.stopPropagation()">На сайте PIK →</a>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function updatePagination() {
    const pagination = document.getElementById('pagination');
    const totalPages = Math.ceil(totalItems / itemsPerPage);

    if (totalPages <= 1) {
        pagination.classList.add('hidden');
        return;
    }

    pagination.classList.remove('hidden');
    document.getElementById('pagination-info').textContent =
        `${currentPage + 1} из ${totalPages}`;
}

function prevPage() {
    if (currentPage > 0) {
        currentPage--;
        loadApartments();
    }
}

function nextPage() {
    if ((currentPage + 1) * itemsPerPage < totalItems) {
        currentPage++;
        loadApartments();
    }
}

async function fetchApartments() {
    try {
        showAlert('Получение данных с PIK...', 'info');
        const data = await api('fetch_apartments');
        showAlert(`Загружено: ${data.fetched}, новых: ${data.new}, обновлено: ${data.updated}`, 'success');
        loadApartments();
        loadStats();
    } catch (e) {
        showAlert('Ошибка получения данных: ' + e.message, 'danger');
    }
}

async function showApartmentDetails(apartmentId) {
    try {
        const data = await api('get_apartment', { id: apartmentId });
        const apt = data.apartment;

        const rooms = apt.rooms === 0 ? 'Студия' : `${apt.rooms}-комн.`;
        const history = apt.price_history || [];

        document.getElementById('apartment-modal-body').innerHTML = `
            <div style="margin-bottom:1rem;">
                <h2 style="font-size:1.75rem;color:var(--primary);margin-bottom:0.25rem;">
                    ${formatPrice(apt.price)}
                </h2>
                <div style="color:#666;">${apt.price_per_meter ? formatPrice(apt.price_per_meter) + '/м²' : ''}</div>
            </div>

            <div class="apartment-params" style="margin-bottom:1.5rem;">
                <div class="param">
                    <span class="param-label">Комнаты</span>
                    <span class="param-value">${rooms}</span>
                </div>
                <div class="param">
                    <span class="param-label">Площадь</span>
                    <span class="param-value">${apt.area} м²</span>
                </div>
                <div class="param">
                    <span class="param-label">Этаж</span>
                    <span class="param-value">${apt.floor || '-'}${apt.floors_total ? '/' + apt.floors_total : ''}</span>
                </div>
                <div class="param">
                    <span class="param-label">Сдача</span>
                    <span class="param-value">${apt.settlement_date || '-'}</span>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <strong>Проект:</strong> ${apt.project_name || '-'}<br>
                <strong>Корпус:</strong> ${apt.bulk_name || '-'}<br>
                <strong>Адрес:</strong> ${apt.address || '-'}<br>
                <strong>Отделка:</strong> ${apt.finishing || '-'}
            </div>

            <div style="margin-bottom:1rem;">
                <strong>Впервые замечена:</strong> ${formatDate(apt.first_seen_at)}<br>
                <strong>Последнее обновление:</strong> ${formatDate(apt.last_seen_at)}
            </div>

            ${history.length > 1 ? `
                <div>
                    <strong>История цен:</strong>
                    <table style="width:100%;margin-top:0.5rem;">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th style="text-align:right;">Цена</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${history.map((h, i) => {
                                const prevPrice = history[i+1]?.price;
                                const diff = prevPrice ? h.price - prevPrice : 0;
                                const diffClass = diff > 0 ? 'price-up' : (diff < 0 ? 'price-down' : '');
                                const diffText = diff !== 0 ? ` (${diff > 0 ? '+' : ''}${formatPrice(diff)})` : '';
                                return `
                                    <tr>
                                        <td>${formatDate(h.recorded_at)}</td>
                                        <td style="text-align:right;">
                                            ${formatPrice(h.price)}
                                            <span class="${diffClass}">${diffText}</span>
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            ` : ''}

            ${apt.url ? `
                <div style="margin-top:1rem;">
                    <a href="${apt.url}" target="_blank" class="btn btn-primary">
                        Открыть на сайте PIK
                    </a>
                </div>
            ` : ''}
        `;

        openModal('apartment-modal');
    } catch (e) {
        showAlert('Не удалось загрузить детали квартиры', 'danger');
    }
}

// Filters
async function loadFilters() {
    try {
        const data = await api('get_filters');
        renderFiltersTable(data.filters || []);
    } catch (e) {
        console.error('Failed to load filters:', e);
    }
}

function renderFiltersTable(filters) {
    const tbody = document.getElementById('filters-table-body');

    if (filters.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center;color:#666;padding:2rem;">
                    Нет сохраненных фильтров
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = filters.map(f => {
        const params = [];
        if (f.rooms_min || f.rooms_max) params.push(`Комнаты: ${f.rooms_min || ''}–${f.rooms_max || ''}`);
        if (f.price_min || f.price_max) params.push(`Цена: ${formatPrice(f.price_min) || ''}–${formatPrice(f.price_max) || ''}`);
        if (f.area_min || f.area_max) params.push(`Площадь: ${f.area_min || ''}–${f.area_max || ''} м²`);
        if (f.floor_min || f.floor_max) params.push(`Этаж: ${f.floor_min || ''}–${f.floor_max || ''}`);

        return `
            <tr>
                <td><strong>${f.name}</strong></td>
                <td style="font-size:0.85rem;">${params.join(', ') || '—'}</td>
                <td>${f.notify_email || '—'}</td>
                <td>
                    <span style="color:${f.is_active ? 'var(--success)' : '#999'};">
                        ${f.is_active ? 'Активен' : 'Неактивен'}
                    </span>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick="applyFilter(${f.id})">Применить</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteFilter(${f.id})">Удалить</button>
                </td>
            </tr>
        `;
    }).join('');
}

function showFilterModal() {
    document.getElementById('modal-filter-name').value = '';
    document.getElementById('modal-filter-email').value = '';
    openModal('filter-modal');
}

function saveCurrentFilter() {
    showFilterModal();
}

async function doSaveFilter() {
    const name = document.getElementById('modal-filter-name').value.trim();
    const email = document.getElementById('modal-filter-email').value.trim();

    if (!name) {
        showAlert('Введите название фильтра', 'warning');
        return;
    }

    const filters = getFilters();

    try {
        await api('save_filter', {
            name,
            notify_email: email || null,
            project_ids: filters.project_id ? [parseInt(filters.project_id)] : [],
            rooms_min: filters.rooms_min || null,
            rooms_max: filters.rooms_max || null,
            price_min: filters.price_min || null,
            price_max: filters.price_max || null,
            area_min: filters.area_min || null,
            area_max: filters.area_max || null,
            floor_min: filters.floor_min || null,
            floor_max: filters.floor_max || null
        }, 'POST');

        closeModal('filter-modal');
        showAlert('Фильтр сохранен', 'success');
        loadFilters();
    } catch (e) {
        showAlert('Ошибка сохранения фильтра', 'danger');
    }
}

async function applyFilter(filterId) {
    try {
        const data = await api('get_filter', { id: filterId });
        const f = data.filter;

        if (f.project_ids && f.project_ids.length > 0) {
            document.getElementById('filter-project').value = f.project_ids[0];
        }
        document.getElementById('filter-rooms-min').value = f.rooms_min || '';
        document.getElementById('filter-rooms-max').value = f.rooms_max || '';
        document.getElementById('filter-price-min').value = f.price_min || '';
        document.getElementById('filter-price-max').value = f.price_max || '';
        document.getElementById('filter-area-min').value = f.area_min || '';
        document.getElementById('filter-area-max').value = f.area_max || '';
        document.getElementById('filter-floor-min').value = f.floor_min || '';
        document.getElementById('filter-floor-max').value = f.floor_max || '';

        // Switch to apartments tab
        document.querySelector('[data-tab="apartments"]').click();
        currentPage = 0;
        loadApartments();
    } catch (e) {
        showAlert('Ошибка загрузки фильтра', 'danger');
    }
}

async function deleteFilter(filterId) {
    if (!confirm('Удалить этот фильтр?')) return;

    try {
        await api('delete_filter', { id: filterId }, 'POST');
        showAlert('Фильтр удален', 'success');
        loadFilters();
    } catch (e) {
        showAlert('Ошибка удаления фильтра', 'danger');
    }
}

// Settings
async function loadSettings() {
    try {
        const data = await api('get_settings');
        const s = data.settings;

        document.getElementById('setting-email-enabled').checked = s.email_enabled === '1';
        document.getElementById('setting-email').value = s.email_to || '';
        document.getElementById('setting-interval').value = s.check_interval || '6';
    } catch (e) {
        console.error('Failed to load settings:', e);
    }
}

async function saveSettings() {
    try {
        await api('save_settings', {
            email_enabled: document.getElementById('setting-email-enabled').checked,
            email_to: document.getElementById('setting-email').value,
            check_interval: document.getElementById('setting-interval').value
        }, 'POST');

        showAlert('Настройки сохранены', 'success');
    } catch (e) {
        showAlert('Ошибка сохранения настроек', 'danger');
    }
}

async function testApi() {
    const statusDiv = document.getElementById('api-status');
    statusDiv.innerHTML = '<div class="loading"><div class="spinner"></div>Проверка...</div>';

    try {
        const data = await api('test_api');

        if (data.success) {
            statusDiv.innerHTML = `
                <div class="alert alert-success">
                    Подключение успешно!<br>
                    Найдено проектов: ${data.projects_count}<br>
                    Время ответа: ${data.response_time_ms}мс
                </div>
            `;
        } else {
            statusDiv.innerHTML = '<div class="alert alert-danger">Ошибка подключения к API</div>';
        }
    } catch (e) {
        statusDiv.innerHTML = '<div class="alert alert-danger">Ошибка: ' + e.message + '</div>';
    }
}

// Change password
async function changePassword() {
    const currentPassword = document.getElementById('current-password').value;
    const newPassword = document.getElementById('new-password').value;
    const newPassword2 = document.getElementById('new-password2').value;

    if (!currentPassword || !newPassword || !newPassword2) {
        showAlert('Заполните все поля', 'warning');
        return;
    }

    if (newPassword !== newPassword2) {
        showAlert('Новые пароли не совпадают', 'danger');
        return;
    }

    if (newPassword.length < 6) {
        showAlert('Новый пароль должен быть минимум 6 символов', 'warning');
        return;
    }

    try {
        const result = await api('change_password', {
            current_password: currentPassword,
            new_password: newPassword
        }, 'POST');

        if (result.success) {
            showAlert('Пароль успешно изменен', 'success');
            document.getElementById('current-password').value = '';
            document.getElementById('new-password').value = '';
            document.getElementById('new-password2').value = '';
        } else {
            showAlert(result.error || 'Ошибка смены пароля', 'danger');
        }
    } catch (e) {
        showAlert('Ошибка: ' + e.message, 'danger');
    }
}

// Helpers
function formatPrice(price) {
    if (!price) return '—';
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: 0
    }).format(price);
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    return date.toLocaleDateString('ru-RU', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function isToday(dateStr) {
    if (!dateStr) return false;
    const date = new Date(dateStr);
    const today = new Date();
    return date.toDateString() === today.toDateString();
}

function showLoading(containerId) {
    document.getElementById(containerId).innerHTML = `
        <div class="loading">
            <div class="spinner"></div>
            Загрузка...
        </div>
    `;
}

function showAlert(message, type = 'info') {
    // Remove existing alerts
    document.querySelectorAll('.floating-alert').forEach(el => el.remove());

    const alert = document.createElement('div');
    alert.className = `alert alert-${type} floating-alert`;
    alert.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 2000;
        min-width: 300px;
        max-width: 400px;
        animation: slideIn 0.3s ease;
    `;
    alert.textContent = message;
    document.body.appendChild(alert);

    setTimeout(() => {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    }, 4000);
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.classList.remove('active');
        }
    });
});

// Close modal on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => {
            m.classList.remove('active');
        });
    }
});
