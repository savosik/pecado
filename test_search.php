<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$raw = \App\Models\Product::search('767002')->raw();
echo "Raw Meilisearch:\n";
print_r($raw);

$models = \App\Models\Product::search('767002')->get();
echo "\nEloquent Models count: " . $models->count() . "\n";
