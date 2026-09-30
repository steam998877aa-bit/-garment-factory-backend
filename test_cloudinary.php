<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    /** @var \App\Services\EmployeeFileService $service */
    $service = app(\App\Services\EmployeeFileService::class);
    echo "hasCloudinary: " . ($service->hasCloudinary() ? "YES\n" : "NO\n");

    $cloudinary = app(\Cloudinary\Cloudinary::class);
    echo "Cloudinary class: " . get_class($cloudinary) . "\n";
    echo "Cloud name: " . $cloudinary->configuration->cloud->cloudName . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
