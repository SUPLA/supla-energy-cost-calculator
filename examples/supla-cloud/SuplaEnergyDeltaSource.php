<?php

declare(strict_types=1);

// Example integration class to be copied/adapted inside supla-cloud.
// It intentionally is NOT part of the package autoload and the package does not depend on Doctrine DBAL.

namespace App\Model\EnergyCost;

use Doctrine\DBAL\Connection;
use Supla\EnergyCostCalculator\Contract\EnergyDeltaSource;
use Supla\EnergyCostCalculator\Model\EnergyDelta;
use Supla\EnergyCostCalculator\Model\QuantityType;
use Supla\EnergyCostCalculator\Model\TimeRange;

final class SuplaEnergyDeltaSource implements EnergyDeltaSource
{
    private const RAW_ENERGY_PRECISION = 100000;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getDeltas(string $meterId, TimeRange $range): iterable
    {
        // supla_em_delta_log.date is the END of the 15-minute slot.
        $rows = $this->connection->executeQuery(
            'SELECT date, phase1_fae, phase2_fae, phase3_fae,
                    phase1_rae, phase2_rae, phase3_rae
               FROM supla_em_delta_log
              WHERE channel_id = :channelId
                AND date > :from
                AND date <= :to
              ORDER BY date ASC',
            [
                'channelId' => (int)$meterId,
                'from' => $range->from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'to' => $range->to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]
        );

        foreach ($rows->iterateAssociative() as $row) {
            $to = new \DateTimeImmutable($row['date'], new \DateTimeZone('UTC'));
            $from = $to->modify('-15 minutes');

            $forward = (int)($row['phase1_fae'] ?? 0) + (int)($row['phase2_fae'] ?? 0) + (int)($row['phase3_fae'] ?? 0);
            $reverse = (int)($row['phase1_rae'] ?? 0) + (int)($row['phase2_rae'] ?? 0) + (int)($row['phase3_rae'] ?? 0);

            $quantities = [
                QuantityType::ACTIVE_ENERGY_IMPORT->value => $this->toKwh($forward),
                QuantityType::ACTIVE_ENERGY_EXPORT->value => $this->toKwh($reverse),
            ];

            yield new EnergyDelta($from, $to, $quantities);
        }
    }

    private function toKwh(int $raw): string
    {
        return rtrim(rtrim(number_format($raw / self::RAW_ENERGY_PRECISION, 6, '.', ''), '0'), '.') ?: '0';
    }
}
