<?php
chdir('/var/www/pm');
require '/var/www/pm/vendor/autoload.php';
$app = require '/var/www/pm/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

$row = DB::selectOne("SELECT id, project_id, storage_path, size FROM collab_documents WHERE id=18");
echo "row: id={$row->id} proj={$row->project_id} path={$row->storage_path} size={$row->size}\n";

$disk = Storage::disk('file_repository');
echo "disk root: " . $disk->path('') . "\n";
echo "disk exists: " . var_export($disk->exists($row->storage_path), true) . "\n";
echo "raw is_file: " . var_export(is_file($disk->path($row->storage_path)), true) . "\n";
echo "raw fileperms: " . substr(sprintf('%o', fileperms($disk->path($row->storage_path))), -4) . "\n";
echo "current user: " . posix_getpwuid(posix_geteuid())['name'] . "\n";
