<?php
/**
 * Debug script to compare PIK API results with our database
 */

require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/PikApi.php';

$config = require __DIR__ . '/config.php';
$db = new Database($config['db_path']);
$pik = new PikApi($config);

echo "=== ДИАГНОСТИКА PIK API ===\n\n";

// Get tracked projects
$trackedProjects = $db->getProjects(true);
echo "Отслеживаемые ЖК (" . count($trackedProjects) . "):\n";
foreach ($trackedProjects as $p) {
    echo "  - {$p['name']} (ID в БД: {$p['id']}, PIK ID: {$p['pik_id']})\n";
}

// Build project map
$projectMap = [];
$trackedPikIds = [];
foreach ($trackedProjects as $project) {
    $projectMap[$project['pik_id']] = $project;
    $trackedPikIds[] = $project['pik_id'];
}

echo "\nPIK IDs для запроса: " . implode(', ', $trackedPikIds) . "\n";

// Fetch from PIK API with same params as user
$params = [
    'block_ids' => $trackedPikIds,
    'rooms' => [1],  // 1-комнатные
    'priceMin' => 15000000,  // 15 млн
    'priceMax' => 20000000,  // 20 млн
];

echo "\n=== ЗАПРОС К PIK API ===\n";
echo "Параметры: rooms=1, price=15-20 млн\n\n";

$flats = $pik->getFlats($params);

echo "Всего квартир от API: " . count($flats) . "\n\n";

// Group by block_id to see distribution
$byBlock = [];
foreach ($flats as $flat) {
    $blockId = $flat['block_id'] ?? 'unknown';
    $blockName = $flat['block_name'] ?? 'Неизвестно';
    if (!isset($byBlock[$blockId])) {
        $byBlock[$blockId] = [
            'name' => $blockName,
            'count' => 0,
            'matched' => isset($projectMap[$blockId])
        ];
    }
    $byBlock[$blockId]['count']++;
}

echo "Распределение по block_id:\n";
foreach ($byBlock as $blockId => $info) {
    $status = $info['matched'] ? '✓ СОВПАДАЕТ' : '✗ НЕ СОВПАДАЕТ';
    echo "  Block ID {$blockId}: {$info['name']} - {$info['count']} квартир - {$status}\n";
}

// Count how many would be saved
$savedCount = 0;
$skippedCount = 0;
foreach ($flats as $flat) {
    $blockId = $flat['block_id'] ?? null;
    if ($blockId && isset($projectMap[$blockId])) {
        $savedCount++;
    } else {
        $skippedCount++;
    }
}

echo "\n=== ИТОГ ===\n";
echo "Будет сохранено: {$savedCount}\n";
echo "Будет пропущено (block_id не совпадает): {$skippedCount}\n";

// Check what's in the database
echo "\n=== В БАЗЕ ДАННЫХ ===\n";
$result = $db->getApartments([
    'project_ids' => array_column($trackedProjects, 'id'),
    'rooms' => '1',
    'price_min' => 15000000,
    'price_max' => 20000000
], 100, 0);

echo "Квартир в БД по этим фильтрам: {$result['total']}\n";
