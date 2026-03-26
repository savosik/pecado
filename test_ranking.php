<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$client = new \MeiliSearch\Client('http://meilisearch:7700', 'masterKey123');
$res = $client->index('products')->search('40662', ['limit' => 10, 'showRankingScore' => true, 'showRankingScoreDetails' => true]);
print_r($res->getRaw());
