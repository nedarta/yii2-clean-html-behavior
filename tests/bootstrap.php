<?php

// Ensure we find the composer autoloader
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloader)) {
    die("Composer autoloader not found. Run 'composer install' first.\n");
}
require_once $autoloader;
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

new \yii\console\Application([
    'id' => 'testapp',
    'basePath' => __DIR__,
    'vendorPath' => __DIR__ . '/../vendor',
]);
