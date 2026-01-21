<?php
/**
 * PIK Apartment Tracker - Logout
 */

require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/Auth.php';

$config = require __DIR__ . '/config.php';
$db = new Database($config['db_path']);
$auth = new Auth($db->getPdo(), $config);

$auth->logout();

header('Location: login.php');
exit;
