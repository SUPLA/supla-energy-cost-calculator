<?php

declare(strict_types=1);

// Example integration class to be copied/adapted inside supla-cloud.
// It intentionally is NOT part of the package autoload and the package does not depend on Doctrine DBAL.

namespace App\Model\EnergyCost;

use Doctrine\DBAL\Connection;
use Supla\EnergyCostCalculator\Contract\ReferenceDataSource;
use Supla\EnergyCostCalculator\Model\ReferenceDataId;
use Supla\EnergyCostCalculator\Model\ReferenceInterval;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class SuplaReferenceDataSource implements ReferenceDataSource
{
    private const SOURCES = [
        'PL.PSE.RCE' => ['column' => 'rce', 'unit' => 'PLN/MWh'],
        'PL.PSE.PDGSZ' => ['column' => 'pdgsz', 'unit' => null],
        'PL.TGE.FIXING1' => ['column' => 'fixing1', 'unit' => 'PLN/MWh'],
        'PL.TGE.FIXING2' => ['column' => 'fixing2', 'unit' => 'PLN/MWh'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function get(ReferenceDataId $id, TimeRange $range): iterable
    {
        $source = self::SOURCES[$id->value] ?? throw new \InvalidArgumentException("Unsupported reference source: {$id->value}");
        $column = $source['column']; // Safe: comes from constant map, not user input.

        $rows = $this->connection->executeQuery(
            "SELECT date_from, date_to, $column AS value
               FROM supla_energy_price_log
              WHERE date_to > :from
                AND date_from < :to
                AND $column IS NOT NULL
              ORDER BY date_from ASC",
            [
                'from' => $range->from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'to' => $range->to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]
        );

        foreach ($rows->iterateAssociative() as $row) {
            yield new ReferenceInterval(
                new \DateTimeImmutable($row['date_from'], new \DateTimeZone('UTC')),
                new \DateTimeImmutable($row['date_to'], new \DateTimeZone('UTC')),
                (string)$row['value'],
                $source['unit'],
            );
        }
    }
}
