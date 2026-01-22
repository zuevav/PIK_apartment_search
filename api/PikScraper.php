<?php
/**
 * PIK Website Scraper
 *
 * Fetches apartment data by parsing PIK website pages
 * This provides more complete data than the limited public API
 */

class PikScraper
{
    private string $baseUrl;
    private int $timeout;
    private int $perPage = 20;

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['pik_site_url'] ?? 'https://www.pik.ru';
        $this->timeout = $config['request_timeout'] ?? 30;
    }

    /**
     * Fetch apartments from PIK website search page
     */
    public function getFlats(array $params = []): array
    {
        $blockSlug = $params['block_slug'] ?? null;
        $blockId = $params['block_id'] ?? null;

        if (!$blockSlug && !$blockId) {
            return [];
        }

        // Build search URL
        $url = $this->buildSearchUrl($params);

        $allFlats = [];
        $page = 1;
        $lastPage = 1;

        do {
            $pageUrl = $page > 1 ? $url . (strpos($url, '?') !== false ? '&' : '?') . "page=$page" : $url;

            $data = $this->fetchPageData($pageUrl);

            if (!$data) {
                break;
            }

            $searchData = $data['props']['pageProps']['initialState']['searchService']['filteredFlats']['data'] ?? null;

            if (!$searchData) {
                break;
            }

            $lastPage = $searchData['lastPage'] ?? 1;
            $flats = $searchData['flats'] ?? [];

            foreach ($flats as $flat) {
                $allFlats[] = $this->parseFlat($flat, $searchData);
            }

            $page++;

            // Safety limit
            if ($page > 10) {
                break;
            }

            // Small delay between requests
            usleep(100000); // 100ms

        } while ($page <= $lastPage);

        // Apply client-side filters (in case website doesn't filter properly)
        $allFlats = $this->applyFilters($allFlats, $params);

        return $allFlats;
    }

    /**
     * Build search URL from parameters
     */
    private function buildSearchUrl(array $params): string
    {
        $slug = $params['block_slug'] ?? '';

        // Determine room type for URL
        $rooms = $params['rooms'] ?? [];
        if (!is_array($rooms)) {
            $rooms = $rooms ? explode(',', $rooms) : [];
        }

        $roomPath = '';
        if (count($rooms) === 1) {
            $roomMap = [
                0 => 'studio',
                1 => 'one-room',
                2 => 'two-room',
                3 => 'three-room'
            ];
            $roomPath = '/' . ($roomMap[$rooms[0]] ?? '');
        }

        $url = "{$this->baseUrl}/search/{$slug}{$roomPath}";

        // Add price filters
        $queryParams = [];
        if (!empty($params['price_min'])) {
            $queryParams['priceFrom'] = $params['price_min'];
        }
        if (!empty($params['price_max'])) {
            $queryParams['priceTo'] = $params['price_max'];
        }
        if (!empty($params['area_min'])) {
            $queryParams['areaFrom'] = $params['area_min'];
        }
        if (!empty($params['area_max'])) {
            $queryParams['areaTo'] = $params['area_max'];
        }

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    /**
     * Fetch and parse page data from __NEXT_DATA__
     */
    private function fetchPageData(string $url): ?array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: ru-RU,ru;q=0.9',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
            CURLOPT_ENCODING => 'gzip, deflate',
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($html)) {
            error_log("PikScraper: Failed to fetch $url (HTTP $httpCode)");
            return null;
        }

        // Extract __NEXT_DATA__ JSON
        if (preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            $json = $matches[1];
            $data = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
            error_log("PikScraper: JSON parse error for $url");
        }

        return null;
    }

    /**
     * Parse flat data from website format
     */
    private function parseFlat(array $flat, array $searchData): array
    {
        $blockData = $searchData['block'] ?? [];

        return [
            'pik_id' => (int) $flat['id'],
            'rooms' => $flat['rooms'] ?? null,
            'area' => (float) ($flat['area'] ?? 0),
            'floor' => isset($flat['floor']) ? (int) $flat['floor'] : null,
            'floors_total' => isset($flat['maxFloor']) ? (int) $flat['maxFloor'] : null,
            'price' => (int) ($flat['price'] ?? 0),
            'price_per_meter' => isset($flat['meterPrice']) ? (int) $flat['meterPrice'] : null,
            'address' => $flat['address'] ?? null,
            'bulk_id' => $flat['bulkId'] ?? null,
            'bulk_name' => $flat['bulkName'] ?? null,
            'block_id' => $blockData['id'] ?? null,
            'block_name' => $blockData['name'] ?? null,
            'section' => $flat['section'] ?? null,
            'finishing' => $flat['finishType'] ?? null,
            'settlement_date' => $flat['settlementDate'] ?? null,
            'url' => isset($flat['id']) ? "{$this->baseUrl}/flat/{$flat['id']}" : null,
            'is_studio' => ($flat['rooms'] ?? 0) === 0,
            'discount' => $flat['benefit']['discount'] ?? null,
        ];
    }

    /**
     * Apply filters client-side
     */
    private function applyFilters(array $flats, array $params): array
    {
        return array_filter($flats, function($flat) use ($params) {
            // Price filter
            if (!empty($params['price_min']) && $flat['price'] < $params['price_min']) {
                return false;
            }
            if (!empty($params['price_max']) && $flat['price'] > $params['price_max']) {
                return false;
            }

            // Area filter
            if (!empty($params['area_min']) && $flat['area'] < $params['area_min']) {
                return false;
            }
            if (!empty($params['area_max']) && $flat['area'] > $params['area_max']) {
                return false;
            }

            // Rooms filter
            if (!empty($params['rooms'])) {
                $rooms = is_array($params['rooms']) ? $params['rooms'] : explode(',', $params['rooms']);
                $flatRooms = $flat['rooms'] ?? 0;

                $match = false;
                foreach ($rooms as $r) {
                    if ($r == 3 && $flatRooms >= 3) {
                        $match = true;
                        break;
                    } elseif ($flatRooms == $r) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Get project/block info from website
     */
    public function getBlockInfo(string $slug): ?array
    {
        $url = "{$this->baseUrl}/{$slug}";
        $data = $this->fetchPageData($url);

        if (!$data) {
            return null;
        }

        $block = $data['props']['pageProps']['initialState']['searchService']['filteredFlats']['data']['block'] ?? null;

        if ($block) {
            return [
                'id' => $block['id'],
                'name' => $block['name'],
                'slug' => $slug,
                'url' => "/{$slug}",
            ];
        }

        return null;
    }
}
