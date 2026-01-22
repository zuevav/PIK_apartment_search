<?php
/**
 * PIK Apartment Tracker - API Handler
 *
 * Handles AJAX requests from frontend
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Parse JSON input for POST/PUT requests
$rawInput = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $_POST = array_merge($_POST, $json);
        }
    }
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PikApi.php';
require_once __DIR__ . '/Auth.php';

$config = require __DIR__ . '/../config.php';

try {
    $db = new Database($config['db_path']);
    $pik = new PikApi($config);
    $auth = new Auth($db->getPdo(), $config);

    // Check authentication
    $auth->requireAuth();

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        // Projects
        case 'get_projects':
            $trackedOnly = filter_var($_GET['tracked_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
            respond(['projects' => $db->getProjects($trackedOnly)]);
            break;

        case 'sync_projects':
            $projects = $pik->getProjects();

            // Get currently tracked project PIK IDs to preserve tracking status
            $trackedBefore = $db->getProjects(true);
            $trackedPikIds = array_column($trackedBefore, 'pik_id');

            // Delete all old projects and insert fresh data
            $db->getPdo()->exec("DELETE FROM projects");

            $saved = 0;
            foreach ($projects as $project) {
                // Restore tracked status if was tracked before
                $project['is_tracked'] = in_array($project['id'], $trackedPikIds);
                $db->saveProject($project);
                $saved++;
            }
            respond(['success' => true, 'synced' => $saved]);
            break;

        case 'track_project':
            $projectId = (int) ($_POST['project_id'] ?? 0);
            $tracked = filter_var($_POST['tracked'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if ($projectId > 0) {
                $db->setProjectTracked($projectId, $tracked);
                respond(['success' => true]);
            } else {
                respond(['error' => 'Invalid project ID'], 400);
            }
            break;

        // Apartments
        case 'get_apartments':
            // Support both single project_id and array project_ids
            $projectIds = null;
            if (!empty($_GET['project_ids'])) {
                // Comma-separated list from URL
                $projectIds = array_filter(array_map('intval', explode(',', $_GET['project_ids'])));
            } elseif (!empty($_GET['project_id'])) {
                $projectIds = [(int) $_GET['project_id']];
            }

            $filters = [
                'project_ids' => $projectIds,
                'rooms_min' => $_GET['rooms_min'] ?? null,
                'rooms_max' => $_GET['rooms_max'] ?? null,
                'price_min' => $_GET['price_min'] ?? null,
                'price_max' => $_GET['price_max'] ?? null,
                'area_min' => $_GET['area_min'] ?? null,
                'area_max' => $_GET['area_max'] ?? null,
                'floor_min' => $_GET['floor_min'] ?? null,
                'floor_max' => $_GET['floor_max'] ?? null,
                'order_by' => $_GET['order_by'] ?? 'price ASC',
            ];
            $limit = min((int) ($_GET['limit'] ?? 50), 200);
            $offset = (int) ($_GET['offset'] ?? 0);

            $result = $db->getApartments(array_filter($filters, fn($v) => $v !== null), $limit, $offset);
            respond($result);
            break;

        case 'get_apartment':
            $apartmentId = (int) ($_GET['id'] ?? 0);
            if ($apartmentId > 0) {
                $stmt = $db->getPdo()->prepare("
                    SELECT a.*, p.name as project_name
                    FROM apartments a
                    LEFT JOIN projects p ON a.project_id = p.id
                    WHERE a.id = ?
                ");
                $stmt->execute([$apartmentId]);
                $apartment = $stmt->fetch();

                if ($apartment) {
                    $apartment['price_history'] = $db->getPriceHistory($apartmentId);
                    respond(['apartment' => $apartment]);
                } else {
                    respond(['error' => 'Apartment not found'], 404);
                }
            } else {
                respond(['error' => 'Invalid apartment ID'], 400);
            }
            break;

        case 'fetch_apartments':
            // Fetch apartments from PIK API using filters from request
            $trackedProjects = $db->getProjects(true);

            if (empty($trackedProjects)) {
                respond(['error' => 'No tracked projects. Please add projects to track first.'], 400);
            }

            // Build map of pik_id => project for quick lookup
            $projectMap = [];
            $trackedPikIds = [];
            foreach ($trackedProjects as $project) {
                $projectMap[$project['pik_id']] = $project;
                $trackedPikIds[] = $project['pik_id'];
            }

            $apiParams = [
                'block_ids' => $trackedPikIds,
                'limit' => 1000,
            ];

            // Apply filters from request (UI form)
            $rooms = $_GET['rooms'] ?? '';
            $priceMin = $_GET['price_min'] ?? '';
            $priceMax = $_GET['price_max'] ?? '';
            $areaMin = $_GET['area_min'] ?? '';
            $areaMax = $_GET['area_max'] ?? '';

            // Parse rooms (can be "0,1,2" format)
            if (!empty($rooms)) {
                $roomsArray = array_map('intval', explode(',', $rooms));
                // Handle "3+" case - if 3 is selected, include 3,4,5,6
                if (in_array(3, $roomsArray)) {
                    $roomsArray = array_merge($roomsArray, [4, 5, 6]);
                    $roomsArray = array_unique($roomsArray);
                }
                $apiParams['rooms'] = $roomsArray;
            }

            if (!empty($priceMin)) $apiParams['price_min'] = (int)$priceMin;
            if (!empty($priceMax)) $apiParams['price_max'] = (int)$priceMax;
            if (!empty($areaMin)) $apiParams['area_min'] = (float)$areaMin;
            if (!empty($areaMax)) $apiParams['area_max'] = (float)$areaMax;

            $results = [
                'fetched' => 0,
                'new' => 0,
                'updated' => 0,
                'filters' => $apiParams,
                'errors' => [],
            ];

            try {
                $flats = $pik->getFlats($apiParams);

                foreach ($flats as $flat) {
                    $blockId = $flat['block_id'] ?? null;

                    // Find which project this flat belongs to
                    if ($blockId && isset($projectMap[$blockId])) {
                        $flat['project_id'] = $projectMap[$blockId]['id'];
                        $result = $db->saveApartment($flat);

                        $results['fetched']++;
                        if ($result['is_new']) {
                            $results['new']++;
                        } elseif ($result['price_changed']) {
                            $results['updated']++;
                        }
                    }
                }
            } catch (Exception $e) {
                $results['errors'][] = $e->getMessage();
            }

            respond($results);
            break;

        // Search Filters
        case 'get_filters':
            $activeOnly = filter_var($_GET['active_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
            respond(['filters' => $db->getFilters($activeOnly)]);
            break;

        case 'get_filter':
            $filterId = (int) ($_GET['id'] ?? 0);
            if ($filterId > 0) {
                $filter = $db->getFilter($filterId);
                if ($filter) {
                    respond(['filter' => $filter]);
                } else {
                    respond(['error' => 'Filter not found'], 404);
                }
            } else {
                respond(['error' => 'Invalid filter ID'], 400);
            }
            break;

        case 'save_filter':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

            if (empty($data['name'])) {
                respond(['error' => 'Filter name is required'], 400);
            }

            $filterData = [
                'id' => $data['id'] ?? null,
                'name' => $data['name'],
                'project_ids' => $data['project_ids'] ?? [],
                'rooms_min' => $data['rooms_min'] ?? null,
                'rooms_max' => $data['rooms_max'] ?? null,
                'price_min' => $data['price_min'] ?? null,
                'price_max' => $data['price_max'] ?? null,
                'area_min' => $data['area_min'] ?? null,
                'area_max' => $data['area_max'] ?? null,
                'floor_min' => $data['floor_min'] ?? null,
                'floor_max' => $data['floor_max'] ?? null,
                'is_active' => $data['is_active'] ?? 1,
                'notify_email' => $data['notify_email'] ?? null,
            ];

            $id = $db->saveFilter($filterData);
            respond(['success' => true, 'id' => $id]);
            break;

        case 'delete_filter':
            $filterId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($filterId > 0) {
                $db->deleteFilter($filterId);
                respond(['success' => true]);
            } else {
                respond(['error' => 'Invalid filter ID'], 400);
            }
            break;

        // Statistics
        case 'get_stats':
            respond(['stats' => $db->getStats()]);
            break;

        // Price history
        case 'get_price_history':
            $apartmentId = (int) ($_GET['apartment_id'] ?? 0);
            if ($apartmentId > 0) {
                respond(['history' => $db->getPriceHistory($apartmentId)]);
            } else {
                respond(['error' => 'Invalid apartment ID'], 400);
            }
            break;

        // API test
        case 'test_api':
            respond($pik->testConnection());
            break;

        // Settings
        case 'get_settings':
            $settings = [
                'email_enabled' => $db->getSetting('email_enabled', '0'),
                'email_to' => $db->getSetting('email_to', ''),
                'email_provider' => $db->getSetting('email_provider', ''),
                'smtp_host' => $db->getSetting('smtp_host', ''),
                'smtp_port' => $db->getSetting('smtp_port', '465'),
                'check_interval' => $db->getSetting('check_interval', '6'),
                'last_check' => $db->getSetting('last_check', null),
            ];
            respond(['settings' => $settings]);
            break;

        case 'save_settings':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

            if (isset($data['email_enabled'])) {
                $db->setSetting('email_enabled', $data['email_enabled'] ? '1' : '0');
            }
            if (isset($data['email_to'])) {
                $db->setSetting('email_to', $data['email_to']);
            }
            if (isset($data['email_provider'])) {
                $db->setSetting('email_provider', $data['email_provider']);
            }
            if (!empty($data['email_password'])) {
                // Encrypt password before saving
                $db->setSetting('email_password', base64_encode($data['email_password']));
            }
            if (isset($data['smtp_host'])) {
                $db->setSetting('smtp_host', $data['smtp_host']);
            }
            if (isset($data['smtp_port'])) {
                $db->setSetting('smtp_port', (string) $data['smtp_port']);
            }
            if (isset($data['check_interval'])) {
                $db->setSetting('check_interval', (string) $data['check_interval']);
            }

            respond(['success' => true]);
            break;

        case 'test_email':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $email = $data['email'] ?? '';

            if (empty($email)) {
                respond(['error' => 'Email not provided'], 400);
            }

            // Get SMTP settings
            $provider = $db->getSetting('email_provider');
            $password = base64_decode($db->getSetting('email_password') ?: '');

            // Provider presets
            $providers = [
                'yandex' => ['host' => 'smtp.yandex.ru', 'port' => 465, 'encryption' => 'ssl'],
                'mailru' => ['host' => 'smtp.mail.ru', 'port' => 465, 'encryption' => 'ssl'],
                'gmail' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
            ];

            $smtpConfig = [];
            if ($provider && isset($providers[$provider])) {
                $smtpConfig = $providers[$provider];
            } elseif ($provider === 'custom') {
                $smtpConfig = [
                    'host' => $db->getSetting('smtp_host'),
                    'port' => (int) ($db->getSetting('smtp_port') ?: 465),
                    'encryption' => 'ssl'
                ];
            }

            if (empty($smtpConfig['host']) || empty($password)) {
                respond(['error' => 'Настройте почтовый сервис и пароль приложения'], 400);
            }

            // Create mailer with SMTP settings
            $mailerConfig = [
                'email' => [
                    'enabled' => true,
                    'from' => $email,
                    'from_name' => 'PIK Tracker',
                    'smtp' => [
                        'host' => $smtpConfig['host'],
                        'port' => $smtpConfig['port'],
                        'encryption' => $smtpConfig['encryption'],
                        'username' => $email,
                        'password' => $password,
                    ]
                ]
            ];

            require_once __DIR__ . '/Mailer.php';
            $mailer = new Mailer($mailerConfig);

            $subject = 'PIK Tracker - Тестовое сообщение';
            $body = '<h2>Уведомления настроены!</h2><p>Это тестовое сообщение от PIK Apartment Tracker.</p><p>Теперь вы будете получать уведомления о новых квартирах и изменениях цен.</p>';

            $result = $mailer->send($email, $subject, $body);

            if ($result) {
                respond(['success' => true]);
            } else {
                respond(['error' => 'Не удалось отправить. Проверьте email и пароль приложения.'], 500);
            }
            break;

        // Change password
        case 'change_password':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $user = $auth->getUser();

            if (!$user) {
                respond(['error' => 'Not authenticated'], 401);
            }

            $currentPassword = $data['current_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';

            $result = $auth->changePassword($user['id'], $currentPassword, $newPassword);
            respond($result, $result['success'] ? 200 : 400);
            break;

        default:
            respond(['error' => 'Unknown action', 'available_actions' => [
                'get_projects', 'sync_projects', 'track_project',
                'get_apartments', 'get_apartment', 'fetch_apartments',
                'get_filters', 'get_filter', 'save_filter', 'delete_filter',
                'get_stats', 'get_price_history', 'test_api',
                'get_settings', 'save_settings',
            ]], 400);
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    respond(['error' => 'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    respond(['error' => $e->getMessage()], 500);
}

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
