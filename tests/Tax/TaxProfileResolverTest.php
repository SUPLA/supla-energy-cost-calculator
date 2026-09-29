<?php

declare(strict_types=1);

namespace Supla\EnergyCostCalculator\Tests\Tax;

use PHPUnit\Framework\TestCase;
use Supla\EnergyCostCalculator\Exception\DefinitionException;
use Supla\EnergyCostCalculator\Tax\TaxContext;
use Supla\EnergyCostCalculator\Tax\TaxProfileAssignmentCatalog;
use Supla\EnergyCostCalculator\Tax\TaxProfileCatalog;
use Supla\EnergyCostCalculator\Tax\TaxProfileResolver;

final class TaxProfileResolverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/energy-cost-tax-profiles-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testResolvesSingleAssignment(): void
    {
        $this->writeProfile('TEST.2026', 'PLN');
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2027-01-01T00:00:00+01:00', 'TEST.2026'),
        ]);

        $resolved = $this->resolver()->resolve(
            new TaxContext('TEST', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-01-01T00:00:00+01:00'),
            'PLN',
        );

        self::assertCount(1, $resolved);
        self::assertSame('TEST.2026', $resolved[0]['profileId']);
    }

    public function testResolvesTwoContiguousAssignments(): void
    {
        $this->writeProfile('TEST.A', 'PLN');
        $this->writeProfile('TEST.B', 'PLN');
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2026-07-01T00:00:00+02:00', 'TEST.A'),
            $this->assignment('2026-07-01T00:00:00+02:00', '2027-01-01T00:00:00+01:00', 'TEST.B'),
        ]);

        $resolved = $this->resolver()->resolve(
            new TaxContext('TEST', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-01-01T00:00:00+01:00'),
        );

        self::assertSame(['TEST.A', 'TEST.B'], array_column($resolved, 'profileId'));
    }

    public function testRejectsGap(): void
    {
        $this->writeProfile('TEST.A', 'PLN');
        $this->writeProfile('TEST.B', 'PLN');
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2026-06-01T00:00:00+02:00', 'TEST.A'),
            $this->assignment('2026-07-01T00:00:00+02:00', '2027-01-01T00:00:00+01:00', 'TEST.B'),
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('gap');
        $this->resolver()->resolve(
            new TaxContext('TEST', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-01-01T00:00:00+01:00'),
        );
    }

    public function testRejectsOverlap(): void
    {
        $this->writeProfile('TEST.A', 'PLN');
        $this->writeProfile('TEST.B', 'PLN');
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2026-08-01T00:00:00+02:00', 'TEST.A'),
            $this->assignment('2026-07-01T00:00:00+02:00', '2027-01-01T00:00:00+01:00', 'TEST.B'),
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('overlap');
        $this->resolver()->resolve(
            new TaxContext('TEST', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-01-01T00:00:00+01:00'),
        );
    }

    public function testRejectsUnknownContext(): void
    {
        $this->writeProfile('TEST.2026', 'PLN');
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2027-01-01T00:00:00+01:00', 'TEST.2026'),
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Unknown tax context OTHER/HOUSEHOLD');
        $this->resolver()->resolve(
            new TaxContext('OTHER', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-01-01T00:00:00+01:00'),
        );
    }

    public function testRejectsUnknownProfileId(): void
    {
        $this->writeIndex([]);
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2027-01-01T00:00:00+01:00', 'MISSING'),
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Unknown tax profile 'MISSING'");
        $this->resolver()->resolve(
            new TaxContext('TEST', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-01-01T00:00:00+01:00'),
        );
    }

    public function testRejectsProfileCurrencyMismatch(): void
    {
        $this->writeProfile('TEST.2026', 'EUR');
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2027-01-01T00:00:00+01:00', 'TEST.2026'),
        ]);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('incompatible currency');
        $this->resolver()->resolve(
            new TaxContext('TEST', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-01-01T00:00:00+01:00'),
            new \DateTimeImmutable('2027-01-01T00:00:00+01:00'),
            'PLN',
        );
    }

    public function testClipsAssignmentToRequestedRange(): void
    {
        $this->writeProfile('TEST.2026', 'PLN');
        $this->writeAssignments([
            $this->assignment('2026-01-01T00:00:00+01:00', '2027-01-01T00:00:00+01:00', 'TEST.2026'),
        ]);

        $resolved = $this->resolver()->resolve(
            new TaxContext('TEST', 'HOUSEHOLD'),
            new \DateTimeImmutable('2026-03-01T00:00:00+01:00'),
            new \DateTimeImmutable('2026-04-01T00:00:00+02:00'),
        );

        self::assertSame('2026-03-01T00:00:00+01:00', $resolved[0]['validFrom']?->format(DATE_ATOM));
        self::assertSame('2026-04-01T00:00:00+02:00', $resolved[0]['validTo']?->format(DATE_ATOM));
    }

    private function resolver(): TaxProfileResolver
    {
        return new TaxProfileResolver(
            new TaxProfileAssignmentCatalog($this->directory),
            new TaxProfileCatalog($this->directory),
        );
    }

    /** @return array<string, mixed> */
    private function assignment(string $from, string $to, string $profileId): array
    {
        return [
            'taxContext' => ['jurisdiction' => 'TEST', 'customerClass' => 'HOUSEHOLD'],
            'validFrom' => $from,
            'validTo' => $to,
            'profileId' => $profileId,
        ];
    }

    /** @param list<array<string, mixed>> $assignments */
    private function writeAssignments(array $assignments): void
    {
        file_put_contents($this->directory . '/assignments.json', json_encode(['assignments' => $assignments], JSON_THROW_ON_ERROR));
    }

    private function writeProfile(string $id, string $currency): void
    {
        $file = strtolower(str_replace('.', '-', $id)) . '.json';
        file_put_contents($this->directory . '/' . $file, json_encode([
            'version' => 1,
            'id' => $id,
            'label' => $id,
            'currency' => $currency,
            'rules' => [[
                'id' => 'VAT',
                'type' => 'PERCENTAGE',
                'appliesToKinds' => ['ENERGY_PURCHASE'],
                'rate' => '0.23',
                'base' => 'CURRENT_SUBTOTAL',
            ]],
        ], JSON_THROW_ON_ERROR));

        $index = is_file($this->directory . '/index.json')
            ? json_decode((string) file_get_contents($this->directory . '/index.json'), true, 512, JSON_THROW_ON_ERROR)
            : ['profiles' => []];
        $index['profiles'][] = ['id' => $id, 'path' => $file];
        $this->writeIndex($index['profiles']);
    }

    /** @param list<array<string, string>> $profiles */
    private function writeIndex(array $profiles): void
    {
        file_put_contents($this->directory . '/index.json', json_encode(['profiles' => $profiles], JSON_THROW_ON_ERROR));
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
