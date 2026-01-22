<?php
/**
 * Check PIK API directly
 */

// Fetch blocks list
$ch = curl_init('https://api.pik.ru/v2/filter?type=1&flatLimit=0&blockLimit=100');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0',
        'Origin: https://www.pik.ru',
    ],
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if (!$data || !isset($data['blocks'])) {
    echo "Error fetching blocks\n";
    exit(1);
}

echo "=== ЖК с квартирами (Кронштадтский, Онежский) ===\n\n";

$targetBlocks = [];
foreach ($data['blocks'] as $block) {
    $name = $block['name'] ?? '???';
    $lowerName = mb_strtolower($name);
    if (mb_strpos($lowerName, 'кронштадт') !== false || mb_strpos($lowerName, 'онежск') !== false) {
        $id = $block['id'] ?? '?';
        $count = $block['count'] ?? 0;
        echo "ID: $id | $name | $count квартир\n";
        $targetBlocks[] = $id;
    }
}

if (empty($targetBlocks)) {
    echo "ЖК не найдены!\n";
    exit;
}

echo "\n=== Запрос квартир для этих ЖК (1-комн, 15-20 млн) ===\n\n";

// Fetch flats for these blocks
$blockParam = implode(',', $targetBlocks);
$url = "https://api.pik.ru/v2/filter?type=1&block={$blockParam}&rooms=1&priceMin=15000000&priceMax=20000000&flatLimit=100&onlyFlats=1";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0',
        'Origin: https://www.pik.ru',
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";

$data = json_decode($response, true);
if (!$data || !isset($data['blocks'])) {
    echo "Error fetching flats\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
    exit(1);
}

$totalFlats = 0;
echo "\nРезультат по блокам:\n";
foreach ($data['blocks'] as $block) {
    $name = $block['name'] ?? '???';
    $id = $block['id'] ?? '?';
    $flats = $block['flats'] ?? [];
    $count = count($flats);
    $totalFlats += $count;
    echo "  Block ID $id: $name - $count квартир\n";
}

echo "\nВСЕГО квартир от API: $totalFlats\n";
