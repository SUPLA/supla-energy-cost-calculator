<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Preset;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\InvalidTariffPresetException;
use Supla\EnergyCostCalculator\Exception\TariffPresetNotFoundException;
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;

final class TariffPresetCatalogTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/energy-cost-presets-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testListsBundledPresetMetadataWithRevision(): void
    {
        $presets = (new TariffPresetCatalog())->presets();

        self::assertCount(13, $presets);
        self::assertSame('PL.TAURON_DYSTRYBUCJA.G11.2026', $presets[0]['id']);
        self::assertSame('TAURON Dystrybucja - G11', $presets[0]['label']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $presets[0]['revision']);
        self::assertArrayNotHasKey('path', $presets[0]);
    }

    public function testLoadsBundledPresetById(): void
    {
        $preset = (new TariffPresetCatalog())->get('PL.TAURON_DYSTRYBUCJA.G12.2026');

        self::assertSame('PL.TAURON_DYSTRYBUCJA.G12.2026', $preset->id);
        self::assertSame($preset->id, $preset->document['id']);
        self::assertSame('G12', $preset->metadata['tariffGroup']);
        self::assertArrayHasKey('billingDefinitionTemplate', $preset->document);
    }

    public function testRevisionIsDeterministicContentHash(): void
    {
        $document = [
            'id' => 'TEST.G11.2026',
            'billingDefinitionTemplate' => ['version' => 1],
        ];
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $this->writeCatalog('preset.json', $json);

        $preset = (new TariffPresetCatalog($this->temporaryDirectory))->get('TEST.G11.2026');

        $canonicalJson = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        self::assertSame(hash('sha256', $canonicalJson), $preset->revision);
        self::assertSame($preset->revision, (new TariffPresetCatalog($this->temporaryDirectory))->get($preset->id)->revision);
    }

    public function testRejectsUnknownId(): void
    {
        $this->expectException(TariffPresetNotFoundException::class);

        (new TariffPresetCatalog())->get('../index.json');
    }

    public function testRejectsResourceOutsidePresetDirectory(): void
    {
        $outsideFile = dirname($this->temporaryDirectory) . '/outside-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($outsideFile, '{"id":"TEST.G11.2026"}');
        $this->writeCatalog('../' . basename($outsideFile), null);

        try {
            $this->expectException(InvalidTariffPresetException::class);
            $this->expectExceptionMessage('escapes the preset directory');
            (new TariffPresetCatalog($this->temporaryDirectory))->get('TEST.G11.2026');
        } finally {
            unlink($outsideFile);
        }
    }

    private function writeCatalog(string $path, ?string $document): void
    {
        file_put_contents($this->temporaryDirectory . '/index.json', json_encode([
            'version' => 1,
            'presets' => [[
                'id' => 'TEST.G11.2026',
                'label' => 'Test G11',
                'path' => $path,
            ]],
        ], JSON_THROW_ON_ERROR));

        if ($document !== null) {
            file_put_contents($this->temporaryDirectory . '/' . $path, $document);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($directory);
    }
}
