# supla/energy-cost-calculator

Framework-agnostic PHP 8.2+ library for calculating electricity-cost simulations from interval meter deltas and JSON billing rules.

The main design goal is to keep calculation logic independent from `supla-cloud`, Doctrine and the physical database schema. `supla-cloud` provides small adapters implementing two ports:

- `EnergyDeltaSource`
- `ReferenceDataSource`

This makes the same Composer package usable today inside `supla-cloud` and later inside a standalone HTTP service.

## Install during development

Add the package repository/path as usual and then:

```bash
composer require supla/energy-cost-calculator
```

## Minimal usage

```php
use Supla\EnergyCostCalculator\Engine\CostCalculator;
use Supla\EnergyCostCalculator\Engine\CalculationOptions;
use Supla\EnergyCostCalculator\Model\TimeRange;

$calculator = new CostCalculator(
    $energyDeltaSource,
    $referenceDataSource,
);

$result = $calculator->calculate(
    meterId: (string)$channelId,
    range: new TimeRange($from, $to),
    definition: $jsonDefinition,
    options: new CalculationOptions(includeIntervals: false),
);

$total = $result->total;
$components = $result->byComponent;
```

## JSON model

The top-level `periods[]` model allows rules for one meter to change over time without modifying historical measurements.

A component is defined by three independent concerns:

1. **quantity** — what is charged, e.g. imported active energy or a calendar month,
2. **selector** — which zone/rule applies at the timestamp,
3. **rate** — the actual rate, possibly from an external time series.

Example: dynamic energy (`Fixing1`) plus dynamic network zones (`PDGSZ`) plus a monthly fee:

```json
{
  "version": 1,
  "currency": "PLN",
  "timezone": "Europe/Warsaw",
  "periods": [
    {
      "validFrom": "2026-01-01T00:00:00+01:00",
      "validTo": null,
      "components": [
        {
          "id": "energy",
          "category": "ENERGY",
          "quantity": {"type": "ACTIVE_ENERGY_IMPORT"},
          "rate": {
            "type": "REFERENCE",
            "source": "PL.TGE.FIXING1",
            "multiplier": "0.001",
            "add": "0.05",
            "unit": "PLN/kWh"
          }
        },
        {
          "id": "network",
          "category": "NETWORK",
          "quantity": {"type": "ACTIVE_ENERGY_IMPORT"},
          "selector": {
            "type": "REFERENCE",
            "source": "PL.PSE.PDGSZ",
            "mapping": {"0": "S1", "1": "S2", "2": "S3", "3": "S4"}
          },
          "rate": {
            "type": "ZONED",
            "rates": {"S1": "0.0276", "S2": "0.1098", "S3": "0.4774", "S4": "2.9220"},
            "unit": "PLN/kWh"
          }
        }
      ]
    }
  ]
}
```

See `examples/definitions/` and `schema/billing-definition.schema.json`.

## SUPLA integration

`examples/supla-cloud/` contains adapter examples for the existing tables:

- `supla_em_delta_log`
- `supla_energy_price_log`

In the current `issue-307` branch, raw energy is stored with precision `100000`, and `supla_em_delta_log.date` is the end of the 15-minute interval. The adapter converts raw values to kWh and yields package-owned DTOs.

Reference IDs proposed by the examples:

- `PL.PSE.RCE`
- `PL.PSE.PDGSZ`
- `PL.TGE.FIXING1`
- `PL.TGE.FIXING2`

## Performance characteristics

The engine preloads each referenced external series once per calculation range, so it does **not** query Fixing/PDGSZ per meter interval. Meter deltas remain streamable through `iterable`.

For a single meter, 15-minute intervals are a small workload (~35k rows/year). If fleet-wide historical reports later become expensive, cache/materialize calculated cost facts outside this package; keep the 15-minute delta table as the measurement source of truth.

## Decimal arithmetic

The default `NativeDecimalMath` keeps the package dependency-free and is appropriate for simulations. The public `DecimalMath` port is deliberately injectable so invoice-grade deployments can replace it with an exact decimal implementation without changing the engine.

## Tests

```bash
composer install
composer test
```

The starter tests cover:

- constant energy rate,
- periodic fee,
- YAML-defined G11, G12, and G13 tariff profile cases,
- YAML-defined TAURON G14dynamic cases using PDGSZ reference zones,
- Fixing1 reference rate,
- PDGSZ-based dynamic zone selection,
- billing rules changing over time.

## Bundled public-holiday calendars

The package currently bundles the Polish statutory public-holiday calendar:

```text
PL_PUBLIC_HOLIDAYS
```

The source file is `resources/calendars/PL.json` and explicitly covers local dates from `2018-01-01` up to, but not including, `2031-01-01` (therefore through the end of 2030).

Dates are stored explicitly rather than generated algorithmically. This keeps historical calculations deterministic and allows legal exceptions to be represented directly. The bundled Polish data includes, among other dates:

- the one-off public holiday on 12 November 2018,
- movable Easter/Pentecost/Corpus Christi dates,
- Christmas Eve starting from 24 December 2025.

`WEEKLY_SCHEDULE` supports an optional `calendar` and the pseudo-day `HOLIDAY`:

```json
{
  "type": "WEEKLY_SCHEDULE",
  "timezone": "Europe/Warsaw",
  "calendar": "PL_PUBLIC_HOLIDAYS",
  "rules": [
    {"zone": "OFF_PEAK", "days": ["HOLIDAY"], "from": "00:00", "to": "24:00"},
    {"zone": "OFF_PEAK", "days": ["SAT", "SUN"], "from": "00:00", "to": "24:00"},
    {"zone": "PEAK", "days": ["MON", "TUE", "WED", "THU", "FRI"], "from": "00:00", "to": "24:00"}
  ]
}
```

Holiday rules are evaluated before ordinary weekday rules. A `HOLIDAY` rule requires `calendar` to be configured. `HOLIDAY` must not be mixed with weekday names in the same rule.

If a calculation requests a holiday date outside the bundled calendar coverage, the default provider throws `HolidayCalendarCoverageException` instead of silently treating the day as a non-holiday.

Applications that need calendars from another source can inject a custom `HolidayCalendarProvider` by constructing `DefaultSelectorResolver` with their provider and passing that resolver to `CostCalculator`.

See `examples/definitions/weekly-schedule-polish-holidays.json`.
