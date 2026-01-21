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
        $this->delay = $config['request_delay'] ?? 2;
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

        // Rate limiting
        if ($this->delay > 0) {
            sleep($this->delay);
        }

        return $data;
    }

    /**
     * Get all available projects (blocks/ЖК)
     */
    public function getProjects(): array
    {
        $data = $this->request('block');
        if (!$data || !is_array($data)) {
            return [];
        }

        $projects = [];
        foreach ($data as $item) {
            if (!isset($item['id']) || !isset($item['name'])) {
                continue;
            }

            $projects[] = [
                'id' => (int) $item['id'],
                'guid' => $item['guid'] ?? null,
                'name' => $item['name'],
                'name_prepositional' => $item['name_prepositional'] ?? null,
                'slug' => $this->extractSlug($item['url'] ?? ''),
                'url' => $item['url'] ?? null,
            ];
        }

        return $projects;
    }

    /**
     * Get flats for a specific project using search endpoint
     *
     * This uses the PIK search API which accepts filter parameters
     */
    public function getFlats(array $params = []): array
    {
        // Build search query params
        $searchParams = [
            'type' => 1, // 1 = apartments
            'flatLimit' => $params['limit'] ?? 1000,
            'flatOffset' => $params['offset'] ?? 0,
        ];

        // Add block IDs if specified
        if (!empty($params['block_ids'])) {
            $searchParams['block'] = implode(',', (array) $params['block_ids']);
        }

        // Add room filter
        if (!empty($params['rooms'])) {
            $searchParams['rooms'] = implode(',', (array) $params['rooms']);
        }

        // Add price filter
        if (!empty($params['price_min'])) {
            $searchParams['priceMin'] = $params['price_min'];
        }
        if (!empty($params['price_max'])) {
            $searchParams['priceMax'] = $params['price_max'];
        }

        // Add area filter
        if (!empty($params['area_min'])) {
            $searchParams['areaMin'] = $params['area_min'];
        }
        if (!empty($params['area_max'])) {
            $searchParams['areaMax'] = $params['area_max'];
        }

        // Add floor filter
        if (!empty($params['floor_min'])) {
            $searchParams['floorMin'] = $params['floor_min'];
        }
        if (!empty($params['floor_max'])) {
            $searchParams['floorMax'] = $params['floor_max'];
        }

        $data = $this->request('filter', $searchParams);

        if (!$data) {
            // Try alternative endpoint
            return $this->getFlatsFromBlock($params);
        }

        return $this->parseFlatsResponse($data);
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

        return [
            'pik_id' => (int) $data['id'],
            'rooms' => $rooms,
            'area' => (float) $area,
            'floor' => $floor ? (int) $floor : null,
            'floors_total' => $floorsTotal ? (int) $floorsTotal : null,
            'price' => (int) $price,
            'price_per_meter' => $pricePerMeter,
            'address' => $data['address'] ?? $data['block']['address'] ?? null,
            'bulk_id' => $data['bulk_id'] ?? $data['bulkId'] ?? $data['bulk']['id'] ?? null,
            'bulk_name' => $data['bulk_name'] ?? $data['bulk']['name'] ?? null,
            'section' => $data['section'] ?? $data['entrance'] ?? null,
            'finishing' => $data['finishing'] ?? $data['decoration'] ?? null,
            'settlement_date' => $settlementDate,
            'url' => $url,
            'is_studio' => $data['is_studio'] ?? $data['isStudio'] ?? ($rooms === 0),
            'discount' => $data['discount'] ?? null,
            'raw' => $data, // Keep raw data for debugging
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
