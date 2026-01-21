<?php
/**
 * PIK Apartment Tracker - Database Handler
 */

class Database
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
        $this->connect();
        $this->initTables();
    }

    private function connect(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    private function initTables(): void
    {
        // Projects (ЖК)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY,
                pik_id INTEGER UNIQUE NOT NULL,
                name TEXT NOT NULL,
                slug TEXT,
                url TEXT,
                is_tracked INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Search filters
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS search_filters (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                project_ids TEXT, -- JSON array of project IDs
                rooms_min INTEGER,
                rooms_max INTEGER,
                price_min INTEGER,
                price_max INTEGER,
                area_min REAL,
                area_max REAL,
                floor_min INTEGER,
                floor_max INTEGER,
                is_active INTEGER DEFAULT 1,
                notify_email TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Apartments
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS apartments (
                id INTEGER PRIMARY KEY,
                pik_id INTEGER UNIQUE NOT NULL,
                project_id INTEGER,
                bulk_id INTEGER,
                rooms INTEGER,
                area REAL,
                floor INTEGER,
                floors_total INTEGER,
                price INTEGER,
                price_per_meter INTEGER,
                address TEXT,
                bulk_name TEXT,
                section TEXT,
                finishing TEXT,
                settlement_date TEXT,
                url TEXT,
                status TEXT DEFAULT 'active',
                first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id)
            )
        ");

        // Price history
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS price_history (
                id INTEGER PRIMARY KEY,
                apartment_id INTEGER NOT NULL,
                price INTEGER NOT NULL,
                price_per_meter INTEGER,
                recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (apartment_id) REFERENCES apartments(id)
            )
        ");

        // Notifications log
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY,
                filter_id INTEGER,
                apartment_id INTEGER,
                type TEXT NOT NULL, -- 'new', 'price_drop', 'price_increase'
                message TEXT,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (filter_id) REFERENCES search_filters(id),
                FOREIGN KEY (apartment_id) REFERENCES apartments(id)
            )
        ");

        // Settings
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ");

        // Create indexes
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apartments_project ON apartments(project_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apartments_price ON apartments(price)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_apartments_rooms ON apartments(rooms)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_price_history_apartment ON price_history(apartment_id)");
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // Projects
    public function saveProject(array $data): int
    {
        $isTracked = $data['is_tracked'] ?? 0;

        $stmt = $this->pdo->prepare("
            INSERT INTO projects (pik_id, name, slug, url, is_tracked, updated_at)
            VALUES (:pik_id, :name, :slug, :url, :is_tracked, CURRENT_TIMESTAMP)
            ON CONFLICT(pik_id) DO UPDATE SET
                name = excluded.name,
                slug = excluded.slug,
                url = excluded.url,
                is_tracked = CASE WHEN :is_tracked_update = 1 THEN 1 ELSE projects.is_tracked END,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'pik_id' => $data['id'],
            'name' => $data['name'],
            'slug' => $data['slug'] ?? null,
            'url' => $data['url'] ?? null,
            'is_tracked' => $isTracked ? 1 : 0,
            'is_tracked_update' => $isTracked ? 1 : 0,
        ]);

        return $this->pdo->lastInsertId() ?: $this->getProjectByPikId($data['id'])['id'];
    }

    public function getProjectByPikId(int $pikId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE pik_id = ?");
        $stmt->execute([$pikId]);
        return $stmt->fetch() ?: null;
    }

    public function getProjects(bool $trackedOnly = false): array
    {
        $sql = "SELECT * FROM projects";
        if ($trackedOnly) {
            $sql .= " WHERE is_tracked = 1";
        }
        $sql .= " ORDER BY name";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function setProjectTracked(int $projectId, bool $tracked): void
    {
        $stmt = $this->pdo->prepare("UPDATE projects SET is_tracked = ? WHERE id = ?");
        $stmt->execute([$tracked ? 1 : 0, $projectId]);
    }

    // Apartments
    public function saveApartment(array $data): array
    {
        $existing = $this->getApartmentByPikId($data['pik_id']);
        $priceChanged = false;
        $isNew = false;

        if ($existing) {
            // Update existing
            if ($existing['price'] != $data['price']) {
                $priceChanged = true;
                $this->recordPriceChange($existing['id'], $data['price'], $data['price_per_meter'] ?? null);
            }

            $stmt = $this->pdo->prepare("
                UPDATE apartments SET
                    rooms = :rooms,
                    area = :area,
                    floor = :floor,
                    floors_total = :floors_total,
                    price = :price,
                    price_per_meter = :price_per_meter,
                    address = :address,
                    bulk_name = :bulk_name,
                    section = :section,
                    finishing = :finishing,
                    settlement_date = :settlement_date,
                    url = :url,
                    status = 'active',
                    last_seen_at = CURRENT_TIMESTAMP
                WHERE pik_id = :pik_id
            ");
        } else {
            // Insert new
            $isNew = true;
            $stmt = $this->pdo->prepare("
                INSERT INTO apartments (
                    pik_id, project_id, bulk_id, rooms, area, floor, floors_total,
                    price, price_per_meter, address, bulk_name, section, finishing,
                    settlement_date, url, status, first_seen_at, last_seen_at
                ) VALUES (
                    :pik_id, :project_id, :bulk_id, :rooms, :area, :floor, :floors_total,
                    :price, :price_per_meter, :address, :bulk_name, :section, :finishing,
                    :settlement_date, :url, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
        }

        $stmt->execute([
            'pik_id' => $data['pik_id'],
            'project_id' => $data['project_id'] ?? null,
            'bulk_id' => $data['bulk_id'] ?? null,
            'rooms' => $data['rooms'] ?? null,
            'area' => $data['area'] ?? null,
            'floor' => $data['floor'] ?? null,
            'floors_total' => $data['floors_total'] ?? null,
            'price' => $data['price'],
            'price_per_meter' => $data['price_per_meter'] ?? null,
            'address' => $data['address'] ?? null,
            'bulk_name' => $data['bulk_name'] ?? null,
            'section' => $data['section'] ?? null,
            'finishing' => $data['finishing'] ?? null,
            'settlement_date' => $data['settlement_date'] ?? null,
            'url' => $data['url'] ?? null,
        ]);

        if ($isNew) {
            $apartmentId = $this->pdo->lastInsertId();
            $this->recordPriceChange($apartmentId, $data['price'], $data['price_per_meter'] ?? null);
        }

        return [
            'is_new' => $isNew,
            'price_changed' => $priceChanged,
            'apartment' => $this->getApartmentByPikId($data['pik_id']),
        ];
    }

    public function getApartmentByPikId(int $pikId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM apartments WHERE pik_id = ?");
        $stmt->execute([$pikId]);
        return $stmt->fetch() ?: null;
    }

    public function getApartments(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["status = 'active'"];
        $params = [];

        // Support both single project_id and array project_ids
        if (!empty($filters['project_ids']) && is_array($filters['project_ids'])) {
            $placeholders = [];
            foreach ($filters['project_ids'] as $i => $pid) {
                $key = "project_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $pid;
            }
            $where[] = "a.project_id IN (" . implode(',', $placeholders) . ")";
        } elseif (!empty($filters['project_id'])) {
            $where[] = "a.project_id = :project_id";
            $params['project_id'] = $filters['project_id'];
        }
        if (!empty($filters['rooms_min'])) {
            $where[] = "a.rooms >= :rooms_min";
            $params['rooms_min'] = $filters['rooms_min'];
        }
        if (!empty($filters['rooms_max'])) {
            $where[] = "a.rooms <= :rooms_max";
            $params['rooms_max'] = $filters['rooms_max'];
        }
        if (!empty($filters['price_min'])) {
            $where[] = "a.price >= :price_min";
            $params['price_min'] = $filters['price_min'];
        }
        if (!empty($filters['price_max'])) {
            $where[] = "a.price <= :price_max";
            $params['price_max'] = $filters['price_max'];
        }
        if (!empty($filters['area_min'])) {
            $where[] = "a.area >= :area_min";
            $params['area_min'] = $filters['area_min'];
        }
        if (!empty($filters['area_max'])) {
            $where[] = "a.area <= :area_max";
            $params['area_max'] = $filters['area_max'];
        }
        if (!empty($filters['floor_min'])) {
            $where[] = "a.floor >= :floor_min";
            $params['floor_min'] = $filters['floor_min'];
        }
        if (!empty($filters['floor_max'])) {
            $where[] = "a.floor <= :floor_max";
            $params['floor_max'] = $filters['floor_max'];
        }

        $whereClause = implode(' AND ', $where);
        $orderBy = $filters['order_by'] ?? 'price ASC';

        // Build count query with same where clause
        $countSql = "SELECT COUNT(*) FROM apartments a WHERE $whereClause";
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(":$key", $value);
        }
        $countStmt->execute();
        $total = $countStmt->fetchColumn();

        $sql = "SELECT a.*, p.name as project_name
                FROM apartments a
                LEFT JOIN projects p ON a.project_id = p.id
                WHERE $whereClause
                ORDER BY a.$orderBy
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function markApartmentsSold(array $activePikIds, ?int $projectId = null): int
    {
        if (empty($activePikIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($activePikIds), '?'));
        $sql = "UPDATE apartments SET status = 'sold', last_seen_at = CURRENT_TIMESTAMP
                WHERE pik_id NOT IN ($placeholders) AND status = 'active'";

        if ($projectId) {
            $sql .= " AND project_id = ?";
            $activePikIds[] = $projectId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($activePikIds);

        return $stmt->rowCount();
    }

    // Price history
    private function recordPriceChange(int $apartmentId, int $price, ?int $pricePerMeter): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO price_history (apartment_id, price, price_per_meter)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$apartmentId, $price, $pricePerMeter]);
    }

    public function getPriceHistory(int $apartmentId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM price_history
            WHERE apartment_id = ?
            ORDER BY recorded_at DESC
        ");
        $stmt->execute([$apartmentId]);
        return $stmt->fetchAll();
    }

    // Search filters
    public function saveFilter(array $data): int
    {
        if (!empty($data['id'])) {
            $stmt = $this->pdo->prepare("
                UPDATE search_filters SET
                    name = :name,
                    project_ids = :project_ids,
                    rooms_min = :rooms_min,
                    rooms_max = :rooms_max,
                    price_min = :price_min,
                    price_max = :price_max,
                    area_min = :area_min,
                    area_max = :area_max,
                    floor_min = :floor_min,
                    floor_max = :floor_max,
                    is_active = :is_active,
                    notify_email = :notify_email,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $data['id'] = $data['id'];
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO search_filters (
                    name, project_ids, rooms_min, rooms_max, price_min, price_max,
                    area_min, area_max, floor_min, floor_max, is_active, notify_email
                ) VALUES (
                    :name, :project_ids, :rooms_min, :rooms_max, :price_min, :price_max,
                    :area_min, :area_max, :floor_min, :floor_max, :is_active, :notify_email
                )
            ");
        }

        $stmt->execute([
            'id' => $data['id'] ?? null,
            'name' => $data['name'],
            'project_ids' => json_encode($data['project_ids'] ?? []),
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
        ]);

        return $data['id'] ?? $this->pdo->lastInsertId();
    }

    public function getFilters(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM search_filters";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name";

        $filters = $this->pdo->query($sql)->fetchAll();
        foreach ($filters as &$filter) {
            $filter['project_ids'] = json_decode($filter['project_ids'], true) ?? [];
        }
        return $filters;
    }

    public function getFilter(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM search_filters WHERE id = ?");
        $stmt->execute([$id]);
        $filter = $stmt->fetch();
        if ($filter) {
            $filter['project_ids'] = json_decode($filter['project_ids'], true) ?? [];
        }
        return $filter ?: null;
    }

    public function deleteFilter(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM search_filters WHERE id = ?");
        $stmt->execute([$id]);
    }

    // Notifications
    public function logNotification(int $filterId, int $apartmentId, string $type, string $message): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (filter_id, apartment_id, type, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$filterId, $apartmentId, $type, $message]);
    }

    // Settings
    public function getSetting(string $key, $default = null)
    {
        $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['value'] : $default;
    }

    public function setSetting(string $key, $value): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ");
        $stmt->execute([$key, $value]);
    }

    // Statistics
    public function getStats(): array
    {
        return [
            'total_apartments' => $this->pdo->query("SELECT COUNT(*) FROM apartments WHERE status = 'active'")->fetchColumn(),
            'total_projects' => $this->pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
            'tracked_projects' => $this->pdo->query("SELECT COUNT(*) FROM projects WHERE is_tracked = 1")->fetchColumn(),
            'active_filters' => $this->pdo->query("SELECT COUNT(*) FROM search_filters WHERE is_active = 1")->fetchColumn(),
            'price_changes_today' => $this->pdo->query("SELECT COUNT(*) FROM price_history WHERE DATE(recorded_at) = DATE('now')")->fetchColumn(),
            'new_apartments_today' => $this->pdo->query("SELECT COUNT(*) FROM apartments WHERE DATE(first_seen_at) = DATE('now')")->fetchColumn(),
        ];
    }
}
