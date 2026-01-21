<?php
/*
 * PIK Apartment Tracker - Cron Job
 *
 * Run this script periodically to check for new apartments and price changes.
 *
 * Example crontab entry (every 6 hours):
 * 0 [star]/6 [star] [star] [star] [star] php /path/to/cron.php
 * (replace [star] with *)
 */

// Prevent running from web
if (php_sapi_name() !== 'cli' && !defined('CRON_ALLOW_WEB')) {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/PikApi.php';
require_once __DIR__ . '/api/Mailer.php';

$config = require __DIR__ . '/config.php';

// Set timezone
date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');

echo "[" . date('Y-m-d H:i:s') . "] PIK Tracker Cron started\n";

try {
    $db = new Database($config['db_path']);
    $pik = new PikApi($config);
    $mailer = new Mailer($config);

    // Get tracked projects
    $projects = $db->getProjects(true);

    if (empty($projects)) {
        echo "No tracked projects found. Add projects to track in the web interface.\n";
        exit(0);
    }

    echo "Checking " . count($projects) . " tracked projects...\n";

    $results = [
        'total_fetched' => 0,
        'new_apartments' => [],
        'price_changes' => [],
        'errors' => [],
    ];

    // Process each project
    foreach ($projects as $project) {
        echo "  Processing: {$project['name']}... ";

        try {
            $flats = $pik->getFlats(['block_ids' => [$project['pik_id']]]);
            echo count($flats) . " flats found. ";

            $projectNew = 0;
            $projectUpdated = 0;

            foreach ($flats as $flat) {
                $flat['project_id'] = $project['id'];
                $result = $db->saveApartment($flat);

                $results['total_fetched']++;

                if ($result['is_new']) {
                    $projectNew++;
                    $result['apartment']['project_name'] = $project['name'];
                    $results['new_apartments'][] = $result['apartment'];
                } elseif ($result['price_changed']) {
                    $projectUpdated++;

                    // Get price history to determine old price
                    $history = $db->getPriceHistory($result['apartment']['id']);
                    if (count($history) >= 2) {
                        $results['price_changes'][] = [
                            'apartment' => $result['apartment'],
                            'old_price' => $history[1]['price'],
                            'new_price' => $history[0]['price'],
                        ];
                    }
                }
            }

            echo "New: $projectNew, Updated: $projectUpdated\n";

        } catch (Exception $e) {
            $error = "Error processing {$project['name']}: " . $e->getMessage();
            echo "ERROR: " . $e->getMessage() . "\n";
            $results['errors'][] = $error;
        }
    }

    // Update last check timestamp
    $db->setSetting('last_check', date('Y-m-d H:i:s'));

    // Summary
    echo "\n=== Summary ===\n";
    echo "Total fetched: {$results['total_fetched']}\n";
    echo "New apartments: " . count($results['new_apartments']) . "\n";
    echo "Price changes: " . count($results['price_changes']) . "\n";
    echo "Errors: " . count($results['errors']) . "\n";

    // Send email notifications if enabled
    $emailEnabled = $db->getSetting('email_enabled', '0') === '1';
    $emailTo = $db->getSetting('email_to', '');

    if ($emailEnabled && $emailTo) {
        // Check filters for notifications
        $filters = $db->getFilters(true);

        foreach ($filters as $filter) {
            if (empty($filter['notify_email'])) {
                continue;
            }

            // Find matching new apartments
            $matching = array_filter($results['new_apartments'], function($apt) use ($filter) {
                return matchesFilter($apt, $filter);
            });

            if (!empty($matching)) {
                $email = $mailer->buildNewApartmentsEmail(array_values($matching), $filter['name']);
                $subject = "PIK Tracker: " . count($matching) . " новых квартир ({$filter['name']})";

                if ($mailer->send($filter['notify_email'], $subject, $email)) {
                    echo "Email sent to {$filter['notify_email']} for filter '{$filter['name']}'\n";

                    // Log notifications
                    foreach ($matching as $apt) {
                        $db->logNotification($filter['id'], $apt['id'], 'new', $subject);
                    }
                } else {
                    echo "Failed to send email to {$filter['notify_email']}\n";
                }
            }
        }

        // Send general notification for price drops
        $priceDrops = array_filter($results['price_changes'], function($change) {
            return $change['new_price'] < $change['old_price'];
        });

        if (!empty($priceDrops) && $emailTo) {
            $email = $mailer->buildPriceChangeEmail($priceDrops);
            $subject = "PIK Tracker: " . count($priceDrops) . " квартир подешевели";

            if ($mailer->send($emailTo, $subject, $email)) {
                echo "Price drop notification sent to $emailTo\n";
            }
        }
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Cron completed successfully\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Check if apartment matches filter criteria
 */
function matchesFilter(array $apt, array $filter): bool
{
    // Project filter
    if (!empty($filter['project_ids'])) {
        $projectIds = is_array($filter['project_ids']) ? $filter['project_ids'] : json_decode($filter['project_ids'], true);
        if (!empty($projectIds) && !in_array($apt['project_id'], $projectIds)) {
            return false;
        }
    }

    // Rooms
    if (!empty($filter['rooms_min']) && $apt['rooms'] < $filter['rooms_min']) {
        return false;
    }
    if (!empty($filter['rooms_max']) && $apt['rooms'] > $filter['rooms_max']) {
        return false;
    }

    // Price
    if (!empty($filter['price_min']) && $apt['price'] < $filter['price_min']) {
        return false;
    }
    if (!empty($filter['price_max']) && $apt['price'] > $filter['price_max']) {
        return false;
    }

    // Area
    if (!empty($filter['area_min']) && $apt['area'] < $filter['area_min']) {
        return false;
    }
    if (!empty($filter['area_max']) && $apt['area'] > $filter['area_max']) {
        return false;
    }

    // Floor
    if (!empty($filter['floor_min']) && $apt['floor'] < $filter['floor_min']) {
        return false;
    }
    if (!empty($filter['floor_max']) && $apt['floor'] > $filter['floor_max']) {
        return false;
    }

    return true;
}
