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

$usageBasedTotal = $result->usageBasedTotal;
$usageBasedComponents = $result->usageBasedByComponent;
$fullTotal = $result->total; // null when a partial billing period has periodic charges
```


## Requested range vs billing cycle

The requested calculation range and the billing cycle are independent concepts. Callers may request an hour, week, month, custom range, or a complete billing period. If a component uses temporal netting, the requested range must contain complete netting windows for that component.

A billing definition may declare an anchor and cycle length:

```json
{
  "billingCycle": {
    "anchor": "2026-01-15T00:00:00+01:00",
    "length": 1,
    "unit": "MONTH"
  }
}
```

This produces cycles such as `15 Jan -> 15 Feb`, `15 Feb -> 15 Mar`, etc. If `billingCycle` is omitted, the default is a natural one-month cycle.

If invoice boundaries change over time, use `billingCycles[]` instead of `billingCycle`:

```json
{
  "billingCycles": [
    {
      "validFrom": null,
      "validTo": "2026-07-01T00:00:00+02:00",
      "anchor": "2026-01-15T00:00:00+01:00",
      "length": 1,
      "unit": "MONTH"
    },
    {
      "validFrom": "2026-07-01T00:00:00+02:00",
      "validTo": null,
      "anchor": "2026-07-01T00:00:00+02:00",
      "length": 1,
      "unit": "MONTH"
    }
  ]
}
```

A validity boundary cuts the nominal cycle. In the example above, `15 Jun -> 15 Jul` becomes a transitional `15 Jun -> 1 Jul` period, followed by `1 Jul -> 1 Aug`. There is no gap or overlap. `prorate: true` is calculated against the nominal, uncut period.

The result exposes `billingPeriods[]` summaries with usage, usage-based costs, periodic costs and totals for every effective billing period. Usage-based costs also expose `byZone`, both globally and per billing-period summary.

Usage-based costs are calculated from their natural charge windows. Periodic charges are deliberately kept out of time-series charge facts. Use `charges[]` for cost charts: ordinary components produce charges at meter-delta resolution, while temporally netted components produce one charge per complete netting window. `intervals[]` remains a meter-interval diagnostic view and never receives an artificial share of a wider netting-window cost.

When the requested range covers complete billing cycles, periodic charges are also calculated and `costs.total` contains the full amount. When the range covers only part of a billing cycle and periodic charges exist, `costs.periodic.total` and `costs.total` are `null`; `periodicCharges[]` still contains the fee definitions so the UI can display e.g. `+ 12 PLN/month`.

With `includeIntervals`, the result also returns `charges[]`. Each charge has its natural `[from,to)` window, resolved quantity, selector result, rate and cost. `intervals[]` still contains raw meter `usage` and costs that are genuinely resolvable at that meter interval; a 60-minute netted component is intentionally absent from the four underlying 15-minute interval costs. The top-level `usage` is always the sum of the returned meter deltas.

## JSON model

The top-level `periods[]` model allows rules for one meter to change over time without modifying historical measurements.

A component is defined by three independent concerns:

1. **quantity** — what is charged and, optionally, how meter deltas are netted in time,
2. **selector** — which zone/rule applies at the timestamp,
3. **rate** — the actual rate, possibly from an external time series.

Temporal netting is declared directly on the quantity:

```json
{
  "quantity": {
    "type": "ACTIVE_ENERGY_IMPORT",
    "strategy": "IMPORT_MINUS_EXPORT_CAP_ZERO",
    "periodInMinutes": 60
  }
}
```

Supported strategies are `IMPORT_MINUS_EXPORT` and `IMPORT_MINUS_EXPORT_CAP_ZERO`. Without `strategy`, `ACTIVE_ENERGY_IMPORT` keeps the existing forward/import behavior. A netting window must be complete and its selector result and rate must remain constant for the whole window. Therefore a 60-minute netting component can use an hourly Fixing series, but it is invalid with a rate or zone changing every 15 minutes.

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

## Tariff presets

`resources/tariff-presets/` contains UI-oriented 2026 presets for Polish household groups G11, G12, TAURON G13, ENEA G13active and TAURON G14dynamic, split by OSD where schedule/rates differ. A preset contains a partial `billingDefinitionTemplate` plus `inputs[]` whose JSON Pointer targets tell the host application exactly where to write user values. After filling the required fields, the result is an ordinary `BillingDefinition`.

Use the production-facing catalogue API instead of resolving package paths directly:

```php
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;

$catalog = new TariffPresetCatalog();
$summaries = $catalog->presets();
$preset = $catalog->get('PL.TAURON_DYSTRYBUCJA.G12.2026');

$preset->document; // Complete preset document.
$preset->revision; // SHA-256 of the deterministically encoded JSON document.
```

`presets()` returns catalogue metadata with a `revision` and without internal resource paths. `get()` rejects unknown identifiers and resources outside the bundled preset directory. A preset ID is a stable reference to one real tariff definition; compatible corrections to that definition may change its revision without changing its ID. A real operator/tariff change must use a new preset ID.

Use `TariffPresetCompiler` when compiling one preset and `CostPlanCompiler` for persisted user plans:

```php
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;

$plan = [
    'version' => 1,
    'entries' => [[
        'validFrom' => '2026-01-01T00:00:00+01:00',
        'validTo' => '2027-01-01T00:00:00+01:00',
        'presetId' => 'PL.TAURON_DYSTRYBUCJA.G12.2026',
        'values' => [
            'billingCycle.anchor' => '2026-01-15T00:00:00+01:00',
            'energy.DAY' => '0.98',
            'energy.NIGHT' => '0.62',
        ],
    ]],
];

$definition = (new CostPlanCompiler())->compile($plan);
```

The cost-plan JSON stores stable preset IDs and user values/overrides, not a copied executable definition. Compiling later uses the current document for the same preset ID, so package-owned corrections automatically apply to existing plans. Omit preset-default values from `values` unless the user explicitly overrides them; this preserves inheritance of corrected defaults. Adjacent plan entries with the same billing-cycle configuration are merged on the billing-cycle timeline, so a price-only change does not create an artificial invoice boundary. See `schema/cost-plan.schema.json` and `docs/cost-plans.md`.

For zero-input tariff comparisons, bundled presets also expose optional `simulationDefaults`. These are suggested values for transient simulations only; they do not become normal preset defaults and `TariffPresetCompiler` does not apply them automatically. The bundled 2026 presets use the standard seller naturally associated with each OSD, net energy prices including excise and excluding VAT, plus a deterministic billing-cycle anchor at the preset validity start. A host may merge `simulationDefaults.values` into a transient cost-plan entry before compiling a simulation. Sources and assumptions are included in the preset document.

These presets intentionally cover only the first UI scope: energy purchase input, variable distribution component, tariff-zone schedule and billing cycle. Fixed/phase-dependent/statutory charges are not baked into the presets. See `examples/tariff-presets/README.md`.

## SUPLA integration

`examples/supla-cloud/` contains adapter examples for the existing tables:

- `supla_em_delta_log`
- `supla_energy_price_log`

In the current `issue-307` branch, raw energy is stored with precision `100000`, and `supla_em_delta_log.date` is the end of the 15-minute interval. The adapter converts raw values to kWh and yields package-owned DTOs.

Reference IDs proposed by the examples:

- `PL.PSE.RCE`
- `PL.PSE.PDGSZ`
- `PL.TGE.FIXING1`
- `PL.TGE.FIXING1_HOURLY`
- `PL.TGE.FIXING2`
- `PL.TGE.FIXING2_HOURLY`

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
- Fixing1 reference rate,
- hourly import/export netting and hourly Fixing compatibility,
- rejection of rate changes inside a netting window,
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

## Seasonal and overnight schedules

`WEEKLY_SCHEDULE` also supports the richer rule format carried over from the `supla-cloud` `issue-307` tariff resolver:

- recurring `seasons` defined with `--MM-DD` boundaries,
- optional `season` and `priority` per rule,
- multiple `time_ranges` per rule,
- ranges crossing midnight, e.g. `22:00`–`06:00`.

The original single `from`/`to` rule syntax remains supported for backwards compatibility. See `docs/schedules.md` for the full format and semantics.

The files in `tests/Fixtures/Tariffs/` are regression fixtures for tariff structures. Their monetary rates are intentionally synthetic; they are not an official operator price catalogue.
