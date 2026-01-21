<?php
/**
 * PIK Apartment Tracker - Authentication
 */

class Auth
{
    private PDO $pdo;
    private array $config;
    private ?array $user = null;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;

        // Initialize users table if not exists
        $this->initTable();

        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = $config['session_lifetime'] ?? 86400;
            session_set_cookie_params($lifetime);
            session_start();
        }

        // Load user from session
        if (isset($_SESSION['user_id'])) {
            $this->user = $this->getUserById($_SESSION['user_id']);
        }
    }

    private function initTable(): void
    {
        $this->pdo->exec("
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
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    /**
     * Check if auth is required (from config)
     */
    public function isRequired(): bool
    {
        return ($this->config['auth_required'] ?? true) === true;
    }

    /**
     * Get current user
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    /**
     * Attempt login
     */
    public function login(string $username, string $password): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'Неверный логин или пароль'];
        }

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Неверный логин или пароль'];
        }

        // Update last login
        $stmt = $this->pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $this->user = $user;

        // Regenerate session ID for security
        session_regenerate_id(true);

        return ['success' => true, 'user' => $this->sanitizeUser($user)];
    }

    /**
     * Logout
     */
    public function logout(): void
    {
        $this->user = null;
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Get user by ID
     */
    public function getUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create new user
     */
    public function createUser(string $username, string $password, ?string $email = null, bool $isAdmin = false): array
    {
        // Validate
        if (strlen($username) < 3) {
            return ['success' => false, 'error' => 'Логин должен быть минимум 3 символа'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Пароль должен быть минимум 6 символов'];
        }

        // Check if exists
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Пользователь с таким логином уже существует'];
        }

        // Create
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, password, email, is_admin)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $email,
            $isAdmin ? 1 : 0,
        ]);

        return ['success' => true, 'id' => $this->pdo->lastInsertId()];
    }

    /**
     * Change password
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь не найден'];
        }

        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => 'Неверный текущий пароль'];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'Новый пароль должен быть минимум 6 символов'];
        }

        $stmt = $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

        return ['success' => true];
    }

    /**
     * Remove sensitive data from user array
     */
    private function sanitizeUser(array $user): array
    {
        unset($user['password']);
        return $user;
    }

    /**
     * Require authentication - redirect or return error
     */
    public function requireAuth(): void
    {
        if (!$this->isRequired()) {
            return;
        }

        if (!$this->isLoggedIn()) {
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode(['error' => 'Требуется авторизация', 'auth_required' => true]);
                exit;
            } else {
                header('Location: login.php');
                exit;
            }
        }
    }

    /**
     * Check if request is AJAX
     */
    private function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Check if any users exist
     */
    public function hasUsers(): bool
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        return $stmt->fetchColumn() > 0;
    }
}
