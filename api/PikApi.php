<?php
/**
 * PIK Apartment Tracker - PIK API Client
 *
 * Works with api.pik.ru to fetch apartment data
 */

class PikApi
{
    private string $baseUrl;
    private string $version;
    private string $siteUrl;
    private int $timeout;
    private int $delay;

    public function __construct(array $config)
    {
        $this->baseUrl = $config['pik_api_base'] ?? 'https://api.pik.ru';
        $this->version = $config['pik_api_version'] ?? 'v2';
        $this->siteUrl = $config['pik_site_url'] ?? 'https://www.pik.ru';
        $this->timeout = $config['request_timeout'] ?? 30;
        $this->delay = $config['request_delay'] ?? 0; // No delay for faster response
    }

    /**
     * Make HTTP request to PIK API
     */
    private function request(string $endpoint, array $params = [], string $method = 'GET'): ?array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$endpoint}";

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Origin: https://www.pik.ru',
                'Referer: https://www.pik.ru/',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
                curl_getinfo($ch, CURLINFO_HEADER_OUT) ?: [],
                ['Content-Type: application/json']
            ));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("PIK API Error: $error");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("PIK API HTTP Error: $httpCode for $url");
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("PIK API JSON Error: " . json_last_error_msg());
            return null;
        }

        // Rate limiting (milliseconds)
        if ($this->delay > 0) {
            usleep($this->delay * 1000);
        }

        return $data;
    }

    /**
     * Get all available projects (blocks/ЖК) with apartments for sale
     *
     * Uses /v2/filter endpoint which returns blocks with names and flat counts
     */
    public function getProjects(): array
    {
        // Use filter endpoint to get blocks with full info
        // type=1 means apartments, blockLimit=100 returns all blocks (default is 5)
        $data = $this->request('filter', [
            'type' => 1,
            'flatLimit' => 0,
            'blockLimit' => 100,
        ]);

        if (!$data || !isset($data['blocks']) || !is_array($data['blocks'])) {
            // Fallback to old endpoint
            return $this->getProjectsLegacy();
        }

        $projects = [];
        foreach ($data['blocks'] as $item) {
            // Skip blocks without name or without available flats
            if (empty($item['name'])) {
                continue;
            }

            // Only include projects with apartments for sale
            $count = $item['count'] ?? 0;
            if ($count <= 0) {
                continue;
            }

            $projects[] = [
                'id' => (int) $item['id'],
                'guid' => $item['guid'] ?? null,
                'name' => $item['name'],
                'name_prepositional' => $item['namePrepositional'] ?? $item['name_prepositional'] ?? null,
                'slug' => $item['url'] ?? $this->extractSlug($item['path'] ?? ''),
                'url' => $item['path'] ?? null,
                'flats_count' => $count,
                'price_min' => $item['priceMin'] ?? null,
            ];
        }

        // Sort by name
        usort($projects, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $projects;
    }

    /**
     * Legacy method for getting projects from /v2/block
     */
    private function getProjectsLegacy(): array
    {
        $data = $this->request('block');
        if (!$data || !is_array($data)) {
            return [];
        }

        $projects = [];
        foreach ($data as $item) {
            if (!isset($item['id'])) {
                continue;
            }

            $projects[] = [
                'id' => (int) $item['id'],
                'guid' => $item['guid'] ?? null,
                'name' => $item['name'] ?? "Project #{$item['id']}",
                'name_prepositional' => $item['name_prepositional'] ?? null,
                'slug' => $this->extractSlug($item['url'] ?? ''),
                'url' => $item['url'] ?? null,
            ];
        }

        return $projects;
    }

    /**
     * Get flats for specific projects using search endpoint with pagination
     *
     * Uses flatOffset parameter to paginate through all results
     */
    public function getFlats(array $params = []): array
    {
        $blockIds = !empty($params['block_ids']) ? array_map('intval', (array) $params['block_ids']) : [];

        if (empty($blockIds)) {
            return [];
        }

        $allFlats = [];
        $offset = 0;
        $limit = 100; // Increase limit for efficiency

        // Build base search params - query all blocks at once
        // NOTE: PIK API uses 'blocks' (plural), not 'block' (singular)!
        $baseParams = [
            'type' => 1,
            'blocks' => implode(',', $blockIds), // All blocks in one request
            'onlyFlats' => 1,
        ];

        // Add room filter
        if (!empty($params['rooms'])) {
            $baseParams['rooms'] = implode(',', (array) $params['rooms']);
        }

        // Add price filter
        if (!empty($params['price_min'])) {
            $baseParams['priceMin'] = $params['price_min'];
        }
        if (!empty($params['price_max'])) {
            $baseParams['priceMax'] = $params['price_max'];
        }

        // NOTE: PIK API returns 500 error with areaMin/areaMax params
        // Area filtering is done client-side after fetching results

        // Fetch with pagination
        while (true) {
            $searchParams = array_merge($baseParams, [
                'flatLimit' => $limit,
                'flatOffset' => $offset,
            ]);

            $data = $this->request('filter', $searchParams);

            if (!$data || empty($data['blocks'])) {
                break;
            }

            $foundFlats = 0;
            foreach ($data['blocks'] as $blockData) {
                $flats = $blockData['flats'] ?? [];
                if (empty($flats)) {
                    continue;
                }

                $blockFlats = $this->parseFlatsResponse(['flats' => $flats]);
                foreach ($blockFlats as &$flat) {
                    $flat['block_id'] = $blockData['id'] ?? null;
                    $flat['block_name'] = $blockData['name'] ?? null;
                }
                $allFlats = array_merge($allFlats, $blockFlats);
                $foundFlats += count($flats);
            }

            // If we got less than limit total, we've reached the end
            if ($foundFlats < $limit) {
                break;
            }

            $offset += $limit;

            // Safety limit
            if ($offset > 2000) {
                break;
            }
        }

        // PIK API sometimes ignores filters, so filter on our side too
        $allFlats = array_filter($allFlats, function($flat) use ($params) {
            // Filter by price
            if (!empty($params['price_min']) && $flat['price'] < $params['price_min']) {
                return false;
            }
            if (!empty($params['price_max']) && $flat['price'] > $params['price_max']) {
                return false;
            }
            // Filter by area
            if (!empty($params['area_min']) && $flat['area'] < $params['area_min']) {
                return false;
            }
            if (!empty($params['area_max']) && $flat['area'] > $params['area_max']) {
                return false;
            }
            // Filter by rooms
            if (!empty($params['rooms'])) {
                $rooms = (array) $params['rooms'];
                $flatRooms = $flat['rooms'] ?? 0;
                // Check if flat rooms match any of selected (3 means 3+)
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

        return array_values($allFlats);
    }

    /**
     * Alternative method: get flats directly from block endpoint
     */
    public function getFlatsFromBlock(array $params = []): array
    {
        if (empty($params['block_ids'])) {
            return [];
        }

        $allFlats = [];
        $blockIds = (array) $params['block_ids'];

        foreach ($blockIds as $blockId) {
            // Try to get block details with flats
            $data = $this->request("block/{$blockId}");

            if ($data && isset($data['flats'])) {
                $flats = $this->parseFlatsResponse(['flats' => $data['flats']]);
                foreach ($flats as &$flat) {
                    $flat['block_id'] = $blockId;
                    $flat['block_name'] = $data['name'] ?? null;
                }
                $allFlats = array_merge($allFlats, $flats);
            }
        }

        return $allFlats;
    }

    /**
     * Get single flat details
     */
    public function getFlat(int $flatId): ?array
    {
        $data = $this->request("flat/{$flatId}");

        if (!$data) {
            // Try v1 API
            $data = $this->requestV1("flat", ['id' => $flatId]);
        }

        if (!$data) {
            return null;
        }

        return $this->parseFlatData($data);
    }

    /**
     * Request using v1 API
     */
    private function requestV1(string $endpoint, array $params = []): ?array
    {
        $oldVersion = $this->version;
        $this->version = 'v1';
        $result = $this->request($endpoint, $params);
        $this->version = $oldVersion;
        return $result;
    }

    /**
     * Parse flats response from API
     */
    private function parseFlatsResponse(array $data): array
    {
        $flats = [];
        $items = $data['flats'] ?? $data['items'] ?? $data;

        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            // Skip if item is not an array (might be just an ID)
            if (!is_array($item)) {
                continue;
            }

            $parsed = $this->parseFlatData($item);
            if ($parsed) {
                $flats[] = $parsed;
            }
        }

        return $flats;
    }

    /**
     * Parse single flat data
     */
    private function parseFlatData(array $data): ?array
    {
        if (!isset($data['id'])) {
            return null;
        }

        // Extract rooms count
        $rooms = $data['rooms'] ?? $data['room_count'] ?? null;
        if ($rooms === null && isset($data['roomsType'])) {
            // Parse from room type string (e.g., "1", "2", "studio")
            $rooms = is_numeric($data['roomsType']) ? (int) $data['roomsType'] : 0;
        }

        // Extract floor info
        $floor = $data['floor'] ?? null;
        $floorsTotal = $data['floors_total']
            ?? $data['floorsTotal']
            ?? $data['bulk']['floors']
            ?? null;

        // Extract price
        $price = $data['price'] ?? $data['currentPrice'] ?? 0;
        $area = $data['area'] ?? $data['square'] ?? 0;
        $pricePerMeter = $area > 0 ? round($price / $area) : null;

        // Extract settlement date
        $settlementDate = $data['settlement_date']
            ?? $data['settlementDate']
            ?? $data['bulk']['date_till']
            ?? $data['bulk']['settlementDate']
            ?? null;

        // Build URL
        $url = $data['url'] ?? null;
        if (!$url && isset($data['id'])) {
            $url = "{$this->siteUrl}/flat/{$data['id']}";
        }

        // Extract finishing type
        $finishing = null;
        if (isset($data['finishes']) && is_array($data['finishes']) && !empty($data['finishes'])) {
            $finish = $data['finishes'][0];
            $finishing = $finish['type'] ?? null;
        } elseif (isset($data['finishing'])) {
            $finishing = is_string($data['finishing']) ? $data['finishing'] : null;
        } elseif (isset($data['decoration'])) {
            $finishing = is_string($data['decoration']) ? $data['decoration'] : null;
        }

        // Extract bulk_id (can be array)
        $bulkId = null;
        if (isset($data['bulk_id'])) {
            $bulkId = is_array($data['bulk_id']) ? ($data['bulk_id'][0] ?? null) : $data['bulk_id'];
        } elseif (isset($data['bulkId'])) {
            $bulkId = $data['bulkId'];
        } elseif (isset($data['bulks']) && is_array($data['bulks'])) {
            $bulkId = $data['bulks'][0] ?? null;
        }

        // Extract section (can be array)
        $section = null;
        if (isset($data['section'])) {
            $section = is_array($data['section']) ? ($data['section'][0] ?? null) : $data['section'];
        } elseif (isset($data['sections']) && is_array($data['sections'])) {
            $section = $data['sections'][0] ?? null;
        }

        return [
            'pik_id' => (int) $data['id'],
            'rooms' => $rooms,
            'area' => (float) $area,
            'floor' => $floor ? (int) $floor : null,
            'floors_total' => $floorsTotal ? (int) $floorsTotal : null,
            'price' => (int) $price,
            'price_per_meter' => $pricePerMeter,
            'address' => $data['address'] ?? null,
            'bulk_id' => $bulkId,
            'bulk_name' => $data['bulk_name'] ?? null,
            'section' => $section,
            'finishing' => $finishing,
            'settlement_date' => $settlementDate,
            'url' => $url,
            'is_studio' => $data['is_studio'] ?? $data['isStudio'] ?? ($rooms === 0),
            'discount' => $data['discount'] ?? null,
        ];
    }

    /**
     * Extract slug from URL
     */
    private function extractSlug(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        return ltrim($url, '/');
    }

    /**
     * Fetch apartments using custom search parameters (from browser DevTools)
     *
     * This allows using parameters captured from pik.ru network requests
     */
    public function searchWithCustomParams(array $rawParams): array
    {
        // These are parameters that can be captured from pik.ru DevTools
        // when performing a search on the website

        $data = $this->request('filter', $rawParams, 'POST');

        if (!$data) {
            // Try GET request
            $data = $this->request('filter', $rawParams, 'GET');
        }

        if (!$data) {
            return [];
        }

        return $this->parseFlatsResponse($data);
    }

    /**
     * Test API connection
     */
    public function testConnection(): array
    {
        $start = microtime(true);
        $projects = $this->getProjects();
        $elapsed = round((microtime(true) - $start) * 1000);

        return [
            'success' => !empty($projects),
            'projects_count' => count($projects),
            'response_time_ms' => $elapsed,
            'api_url' => "{$this->baseUrl}/{$this->version}",
        ];
    }
}
