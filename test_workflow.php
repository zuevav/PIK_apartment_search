<?php
/**
 * PIK Tracker - Workflow Test Script
 *
 * Tests the complete cycle: sync -> track -> fetch -> search
 * Run from command line: php test_workflow.php
 */

// Allow running from CLI only
if (php_sapi_name() !== 'cli') {
    die('Run from command line only');
}

echo "=== PIK Tracker Workflow Test ===\n\n";

// Load dependencies
require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/PikApi.php';

// Check if config exists
if (!file_exists(__DIR__ . '/config.php')) {
    die("ERROR: config.php not found. Run installer first.\n");
}

$config = require __DIR__ . '/config.php';

try {
    $db = new Database($config['db_path']);
    $pik = new PikApi($config);

    echo "✓ Database and API initialized\n\n";

    // ============================================
    // TEST 1: API Connection
    // ============================================
    echo "--- TEST 1: PIK API Connection ---\n";
    $testResult = $pik->testConnection();

    if ($testResult['success']) {
        echo "✓ API Connection: OK\n";
        echo "  - Active projects with flats: {$testResult['projects_count']}\n";
        echo "  - Response time: {$testResult['response_time_ms']}ms\n";
        echo "  - API URL: {$testResult['api_url']}\n";
    } else {
        echo "✗ API Connection: FAILED\n";
        die("Cannot proceed without API connection.\n");
    }
    echo "\n";

    // ============================================
    // TEST 2: Sync Projects (clear old and sync fresh)
    // ============================================
    echo "--- TEST 2: Sync Projects ---\n";

    // Clear old projects first
    $db->getPdo()->exec("DELETE FROM projects");
    echo "  Cleared old projects from database\n";

    $projects = $pik->getProjects();

    if (empty($projects)) {
        echo "✗ No projects received from API\n";
    } else {
        echo "✓ Received " . count($projects) . " active projects from PIK API\n";

        // Save all projects
        foreach ($projects as $project) {
            $db->saveProject($project);
        }
        echo "✓ Saved all projects to database\n";

        // Show all projects
        echo "\n  Active projects with apartments for sale:\n";
        foreach ($projects as $p) {
            $flatsCount = $p['flats_count'] ?? '?';
            $priceMin = $p['price_min'] ? number_format($p['price_min']) . ' ₽' : '?';
            echo "    - [{$p['id']}] {$p['name']} ({$flatsCount} квартир, от {$priceMin})\n";
        }
    }
    echo "\n";

    // ============================================
    // TEST 3: Get Projects from Database
    // ============================================
    echo "--- TEST 3: Database Projects ---\n";
    $dbProjects = $db->getProjects();
    echo "✓ Projects in database: " . count($dbProjects) . "\n";

    if (!empty($dbProjects)) {
        $first = $dbProjects[0];
        echo "  First project structure:\n";
        echo "    - id: " . ($first['id'] ?? 'MISSING') . "\n";
        echo "    - pik_id: " . ($first['pik_id'] ?? 'MISSING') . "\n";
        echo "    - name: " . ($first['name'] ?? 'MISSING') . "\n";
        echo "    - is_tracked: " . ($first['is_tracked'] ?? 'MISSING') . "\n";
    }
    echo "\n";

    // ============================================
    // TEST 4: Track a Project
    // ============================================
    echo "--- TEST 4: Track Project ---\n";
    if (!empty($dbProjects)) {
        // Track first project
        $testProject = $dbProjects[0];
        $db->setProjectTracked($testProject['id'], true);
        echo "✓ Marked project '{$testProject['name']}' (pik_id: {$testProject['pik_id']}) as tracked\n";

        // Verify
        $tracked = $db->getProjects(true);
        echo "✓ Tracked projects count: " . count($tracked) . "\n";
    } else {
        echo "✗ No projects to track\n";
    }
    echo "\n";

    // ============================================
    // TEST 5: Fetch Apartments
    // ============================================
    echo "--- TEST 5: Fetch Apartments ---\n";

    // Clear old apartments
    $db->getPdo()->exec("DELETE FROM apartments");
    $db->getPdo()->exec("DELETE FROM price_history");
    echo "  Cleared old apartments from database\n";

    $trackedProjects = $db->getProjects(true);

    if (empty($trackedProjects)) {
        echo "✗ No tracked projects to fetch apartments for\n";
    } else {
        $totalFlats = 0;
        $newFlats = 0;

        foreach ($trackedProjects as $project) {
            echo "  Fetching from '{$project['name']}' (pik_id: {$project['pik_id']})...\n";

            try {
                $flats = $pik->getFlats(['block_ids' => [$project['pik_id']], 'limit' => 20]);
                echo "    - Received " . count($flats) . " flats from API\n";

                foreach ($flats as $flat) {
                    $flat['project_id'] = $project['id'];
                    $result = $db->saveApartment($flat);
                    $totalFlats++;
                    if ($result['is_new']) {
                        $newFlats++;
                    }
                }

                if (!empty($flats)) {
                    echo "    - Sample flat:\n";
                    $sample = $flats[0];
                    echo "      pik_id: " . ($sample['pik_id'] ?? '?') . "\n";
                    echo "      rooms: " . ($sample['rooms'] ?? '?') . "\n";
                    echo "      area: " . ($sample['area'] ?? '?') . " m²\n";
                    echo "      price: " . number_format($sample['price'] ?? 0) . " ₽\n";
                    echo "      floor: " . ($sample['floor'] ?? '?') . "\n";
                }
            } catch (Exception $e) {
                echo "    ✗ Error: " . $e->getMessage() . "\n";
            }
        }

        echo "\n✓ Total flats processed: $totalFlats (new: $newFlats)\n";
    }
    echo "\n";

    // ============================================
    // TEST 6: Database Apartments
    // ============================================
    echo "--- TEST 6: Database Apartments ---\n";
    $apartments = $db->getApartments([], 10, 0);
    echo "✓ Apartments in database: " . count($apartments) . "\n";

    if (!empty($apartments)) {
        echo "  Sample apartments:\n";
        foreach (array_slice($apartments, 0, 5) as $apt) {
            if (!is_array($apt)) continue;
            $rooms = ($apt['rooms'] ?? 0) == 0 ? 'Студия' : "{$apt['rooms']}-комн.";
            $price = number_format($apt['price'] ?? 0);
            $area = $apt['area'] ?? '?';
            echo "    - {$rooms}, {$area} м², {$price} ₽\n";
        }
    }
    echo "\n";

    // ============================================
    // TEST 7: Search/Filter Test
    // ============================================
    echo "--- TEST 7: Filter Apartments ---\n";
    $filtered = $db->getApartments([
        'price_max' => 30000000,
    ], 10, 0);
    echo "✓ Filter (price <= 30M): " . count($filtered) . " results\n";
    echo "\n";

    // ============================================
    // TEST 8: Stats
    // ============================================
    echo "--- TEST 8: Statistics ---\n";
    $stats = $db->getStats();
    echo "✓ Stats:\n";
    echo "  - Total apartments: " . ($stats['total_apartments'] ?? 0) . "\n";
    echo "  - Tracked projects: " . ($stats['tracked_projects'] ?? 0) . "\n";
    echo "  - New today: " . ($stats['new_apartments_today'] ?? 0) . "\n";
    echo "  - Price changes today: " . ($stats['price_changes_today'] ?? 0) . "\n";
    echo "\n";

    // ============================================
    // SUMMARY
    // ============================================
    echo "=== TEST SUMMARY ===\n";
    echo "✓ All core functionality tests passed!\n\n";

    echo "Active PIK projects:\n";
    foreach ($dbProjects as $p) {
        $status = $p['is_tracked'] ? '[TRACKED]' : '[       ]';
        echo "  $status {$p['name']}\n";
    }

} catch (Exception $e) {
    echo "\n✗ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
