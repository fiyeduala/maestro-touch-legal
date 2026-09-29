<?php

/*
 * Builds the upload packages for cPanel from the last commit (docs/DEPLOYMENT.md).
 *
 * Usage (from the project folder, after `npm run build`):
 *   php tools/deploy/package.php                 app zip + vendor zip + manifest
 *   php tools/deploy/package.php --skip-vendor   app zip + manifest only (server runs composer itself)
 *   php tools/deploy/package.php --allow-dirty   package even with uncommitted changes (for a local trial only)
 *
 * Writes to dist/ (git-ignored):
 *   mtl-app-<date>-<commit>.zip     the committed code plus the compiled assets in public/build
 *   mtl-vendor-<date>-<commit>.zip  the PHP libraries (composer install --no-dev), for hosts without Composer
 *   mtl-<date>-<commit>-MANIFEST.txt  what went in, with SHA-256 checksums of both zips
 *
 * Only committed files are packaged (git archive), so .env, storage contents, backups, uploads and local
 * databases can never be included. Tests, docs and local tools are left out.
 */

$root = dirname(__DIR__, 2);
chdir($root);
$args = array_slice($argv, 1);
$skipVendor = in_array('--skip-vendor', $args, true);
$allowDirty = in_array('--allow-dirty', $args, true);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function run(string $command, ?string $cwd = null): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        fail("`{$command}` failed:\n{$err}{$out}");
    }

    return trim($out);
}

function addTree(ZipArchive $zip, string $dir, string $prefix): int
{
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $zip->addFile($file->getPathname(), $prefix.$relative);
            $count++;
        }
    }

    return $count;
}

function copyTree(string $from, string $to): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    @mkdir($to, 0777, true);
    foreach ($it as $file) {
        $target = $to.DIRECTORY_SEPARATOR.substr($file->getPathname(), strlen($from) + 1);
        $file->isDir() ? @mkdir($target, 0777, true) : copy($file->getPathname(), $target);
    }
}

function removeTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

if (! class_exists(ZipArchive::class)) {
    fail('The PHP zip extension is required.');
}
if (run('git status --porcelain') !== '' && ! $allowDirty) {
    fail('There are uncommitted changes. Commit them first (or pass --allow-dirty for a local trial).');
}
$manifestFile = 'public/build/manifest.json';
if (! is_file($manifestFile)) {
    fail('public/build is missing. Run `npm run build` first.');
}

$commit = run('git rev-parse --short HEAD');
$stamp = date('Ymd-His');
$name = "mtl-{$stamp}-{$commit}";
@mkdir('dist');

// 1. Committed code, minus what the server does not need.
$appZip = "dist/mtl-app-{$stamp}-{$commit}.zip";
run('git archive --format=zip -o '.escapeshellarg($appZip).' HEAD');
$exclude = ['tests/', 'tools/', 'docs/', '.github/', 'phpunit.xml', 'phpunit.mariadb.xml', 'node_modules/'];
$zip = new ZipArchive;
$zip->open($appZip) === true || fail("Cannot open {$appZip}");
$removed = 0;
for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
    $entry = $zip->getNameIndex($i);
    foreach ($exclude as $prefix) {
        if ($entry === $prefix || str_starts_with($entry, $prefix)) {
            $zip->deleteIndex($i);
            $removed++;

            break;
        }
    }
}
// 2. Compiled CSS/JS (git-ignored, so added here) and the published Filament assets.
$assets = addTree($zip, "{$root}/public/build", 'public/build/');
foreach (['public/css/filament', 'public/js/filament', 'public/fonts/filament'] as $dir) {
    if (is_dir($dir)) {
        $assets += addTree($zip, "{$root}/{$dir}", "{$dir}/");
    }
}
$appFiles = $zip->numFiles;
$zip->close();

// 3. PHP libraries, installed without development packages from the committed composer.lock.
$vendorZip = null;
if (! $skipVendor) {
    $work = sys_get_temp_dir().DIRECTORY_SEPARATOR."mtl-package-{$commit}-".bin2hex(random_bytes(3));
    mkdir($work);
    $extract = new ZipArchive;
    $extract->open($appZip);
    $extract->extractTo($work);
    $extract->close();
    // Start from the local vendor folder (same composer.lock) so nothing is downloaded; Composer then
    // removes the development packages and rebuilds the autoloader for production.
    if (! is_dir("{$root}/vendor")) {
        fail('vendor/ is missing. Run `composer install` first.');
    }
    echo "Copying vendor/ and removing development packages...\n";
    copyTree("{$root}/vendor", "{$work}/vendor");
    run('composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader', $work);
    $vendorZip = "dist/mtl-vendor-{$stamp}-{$commit}.zip";
    $vz = new ZipArchive;
    $vz->open($vendorZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $vendorFiles = addTree($vz, "{$work}/vendor", 'vendor/');
    $vz->close();
    removeTree($work);
}

// 4. Manifest.
$lines = [
    "Maestro Touch Legal release {$name}",
    'Commit: '.run('git rev-parse HEAD'),
    'Commit date: '.run('git log -1 --format=%cI'),
    'Built on PHP '.PHP_VERSION.' (server needs PHP 8.2 or newer)',
    '',
    "{$appZip}: {$appFiles} files ({$assets} compiled asset files; {$removed} test/doc/tool files left out)",
    '  sha256 '.hash_file('sha256', $appZip),
];
if ($vendorZip) {
    $lines[] = "{$vendorZip}: {$vendorFiles} files";
    $lines[] = '  sha256 '.hash_file('sha256', $vendorZip);
}
$lines[] = '';
$lines[] = 'Not included (by design): .env, storage contents, backups, uploaded files, local databases.';
$lines[] = 'Compiled asset manifest (public/build/manifest.json):';
foreach (json_decode(file_get_contents($manifestFile), true) as $source => $entry) {
    $lines[] = "  {$source} -> {$entry['file']}";
}
file_put_contents("dist/{$name}-MANIFEST.txt", implode("\n", $lines)."\n");

echo implode("\n", $lines)."\n\nWritten to dist/.\n";
