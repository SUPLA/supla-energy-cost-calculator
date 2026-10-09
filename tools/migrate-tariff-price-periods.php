<?php

declare(strict_types=1);

// One-time, explicit source migration. No catalog read-time normalization.
// The script scans/validates every preset before writing anything.

require_once dirname(__DIR__) . '/src/Preset/TariffPresetPricePeriodMigrator.php';

use Supla\EnergyCostCalculator\Preset\TariffPresetPricePeriodMigrator;

$root = dirname(__DIR__) . '/resources/tariff-presets';
$dryRun = in_array('--dry-run', $argv, true);
$migrator = new TariffPresetPricePeriodMigrator();
$changes = [];
$errors = [];
$unchanged = 0;
$paths = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'json' && $file->getFilename() !== 'index.json') {
        $paths[] = $file->getPathname();
    }
}
sort($paths);
foreach ($paths as $path) {
    $relative = substr($path, strlen($root) + 1);
    try {
        $original = file_get_contents($path);
        if ($original === false) {
            throw new RuntimeException('Cannot read file.');
        }
        $document = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document) || array_is_list($document)) {
            throw new RuntimeException('Expected a JSON object.');
        }
        $migrated = $migrator->convert($document);
        $template = $migrated['billingDefinitionTemplate'] ?? null;
        if (!is_array($template) || !isset($template['components']) || isset($template['periods'])) {
            throw new RuntimeException('Conversion did not produce components/pricePeriods.');
        }
        if ($migrated === $document) {
            $unchanged++;
            continue;
        }
        $changes[$path] = json_encode(
            $migrated,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ) . "\n";
        printf("%s %s\n", $dryRun ? 'Would convert' : 'Ready to convert', $relative);
    } catch (Throwable $error) {
        $errors[] = "$relative: {$error->getMessage()}";
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: $error\n");
    }
    fwrite(STDERR, "No files modified. Resolve the errors and rerun the migration.\n");
    exit(1);
}
if (!$dryRun) {
    foreach ($changes as $path => $json) {
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Cannot write '$path'.");
        }
    }
}
printf("%d %s; %d already normalized.\n", count($changes), $dryRun ? 'to convert' : 'converted', $unchanged);
