<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

echo "Checking if database is empty (already wiped by migrate:fresh)...\n";
echo "Creating Admin user...\n";
User::updateOrCreate(
    ['email' => 'admin@pecado.ru'],
    [
        'name'     => 'Admin',
        'password' => Hash::make('Admin2024!'),
        'is_admin' => true,
    ]
);
echo "Admin user created successfully.\n";

echo "Cleaning S3 storage...\n";
try {
    $disk = Storage::disk('s3');
    $files = $disk->allFiles();
    $directories = $disk->allDirectories();

    echo "Found " . count($files) . " files and " . count($directories) . " directories in S3.\n";

    if (count($files) > 0) {
        $chunks = array_chunk($files, 1000);
        foreach ($chunks as $chunk) {
            $disk->delete($chunk);
        }
        echo "Deleted all files.\n";
    }

    foreach ($directories as $dir) {
        $disk->deleteDirectory($dir);
    }
    echo "Deleted all directories.\n";
    echo "S3 cleanup completed!\n";
} catch (\Exception $e) {
    echo "Error cleaning S3: " . $e->getMessage() . "\n";
}
