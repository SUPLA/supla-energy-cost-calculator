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
    options: new CalculationOptions(includeIntervals: false, includeCharges: false),
);

$usageBasedGross = $result->costs['gross']['usageBased']['total'];
$usageBasedComponents = $result->costs['gross']['usageBased']['byComponent'];
$fullGross = $result->costs['gross']['total']; // null when a partial billing period has periodic charges
```


## Requested range vs billing cycle

The requested calculation range and the billing cycle are independent concepts. Callers may request an hour, week, month, custom range, or a complete billing period. If a component uses temporal netting, the requested range must contain complete netting windows for that component.

A billing definition may declare an anchor and cycle length:

```json
{
  "billingCycle": {
    "anchor": "2026-01-15",
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
      "anchor": "2026-01-15",
      "length": 1,
      "unit": "MONTH"
    },
    {
      "validFrom": "2026-07-01T00:00:00+02:00",
      "validTo": null,
      "anchor": "2026-07-01",
      "length": 1,
      "unit": "MONTH"
    }
  ]
}
```

A validity boundary cuts the nominal cycle. In the example above, `15 Jun -> 15 Jul` becomes a transitional `15 Jun -> 1 Jul` period, followed by `1 Jul -> 1 Aug`. There is no gap or overlap. `prorate: true` is calculated against the nominal, uncut period.

The result exposes `billingPeriods[]` summaries with usage, usage-based costs, periodic costs and totals for every effective billing period. Usage-based costs also expose `byZone`, both globally and per billing-period summary.

Usage-based costs are calculated from their natural charge windows. Periodic charges are deliberately kept out of time-series charge facts. Use `charges[]` for cost charts: ordinary components produce charges at meter-delta resolution, temporally netted components without allocation produce one charge per complete netting window, and allocated netting components produce one charge per allocation slot. `intervals[]` remains a meter-interval diagnostic view and never receives an artificial share of a wider netting-window cost.

When the requested range covers complete billing cycles, periodic charges are also calculated and `costs.gross.total` contains the full amount. When the range covers only part of a billing cycle and periodic charges exist, `costs.net.periodic.total`, `costs.gross.periodic.total`, `costs.taxes.total`, and both full totals are `null`; `periodicCharges[]` still contains the fee definitions so the UI can display e.g. `+ 12 PLN/month`.

`charges[]` is the authoritative usage-based cost-fact series when requested with `CalculationOptions(includeCharges: true)`. Each charge has its natural `[from,to)` window, resolved quantity, selector result, `pricing` and tax-qualified `amounts`. Charge materialization is disabled by default so aggregate-only calculations do not retain tens of thousands of detailed facts. `includeIntervals` independently adds the raw meter diagnostic `intervals[]`; a 60-minute netted component is intentionally absent from the four underlying 15-minute interval costs. The top-level `usage` is always the sum of the returned meter deltas.

Missing reference data is strict by default. Opt into partial results with `new CalculationOptions(missingReferencePolicy: MissingReferencePolicy::SKIP_AFFECTED)`. The calculator then skips an affected ordinary meter interval or complete temporal-netting window and returns `incomplete: true` plus structured `warnings[]`; skipped units are absent from usage, costs, intervals, and charges.

## JSON model

The top-level `periods[]` model allows rules for one meter to change over time without modifying historical measurements.

A component is defined by three independent concerns:

1. **quantity** — what is charged and, optionally, how meter deltas are netted in time,
2. **selector** — which zone/rule applies at the timestamp,
3. **rate** — the actual rate, possibly from an external time series.

Tariff presets define a `taxContext` (for example `PL` + `HOUSEHOLD`), source pricing, and the explicit taxes already included in that source price. `CostPlanCompiler` resolves the complete known immutable `TaxProfile` timeline for that context and emits it as executable `taxRuleSets[]`; callers do not select profile IDs or tax rates. CostPlan boundaries are independent of tax-history boundaries, so a plan may be open before the first known tax profile or indefinitely into the future. `CostCalculator` fails only when an actual charge falls outside the known tax timeline. The calculator normalizes every covered source amount as canonical `net`, individual `taxes`, and `gross`. `pricing.rate` remains the source rate and can include the taxes listed in `pricing.includedTaxes`; `amounts.net` removes every tax modeled by the resolved profile. There is no global net/gross or price-basis switch.

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

Supported strategies are `IMPORT_MINUS_EXPORT` and `IMPORT_MINUS_EXPORT_CAP_ZERO`. Without `strategy`, `ACTIVE_ENERGY_IMPORT` keeps the existing forward/import behavior. Without an allocation rule, a netting window must be complete and its selector result and rate must remain constant for the whole window.

Contracts that explicitly redistribute a wider net quantity may add a named allocation rule:

```json
"quantity": {
  "type": "ACTIVE_ENERGY_IMPORT",
  "strategy": "IMPORT_MINUS_EXPORT_CAP_ZERO",
  "periodInMinutes": 60,
  "allocation": {"strategy": "EQUAL", "periodInMinutes": 15}
}
```

`EQUAL` splits the resolved 60-minute quantity equally into complete 15-minute pricing slots. Selector/rate stability is then required per allocated slot, and `charges[]` contains one fact per slot. `intervals[]` remains tied to raw meter deltas and never receives an allocated share.

A `REFERENCE` rate may declare `sourceUnit` as a runtime assertion and optional `sourceMin`/`sourceMax` bounds. The bounds clamp the raw source value before `multiplier` and `add`; they do not cap a billing-period weighted-average/effective price.

Example: dynamic energy (`Fixing1`) plus dynamic network zones (`PDGSZ`) plus a monthly fee:

```json
{
  "version": 1,
  "currency": "PLN",
  "timezone": "Europe/Warsaw",
  "taxRuleSets": [{
    "validFrom": null,
    "validTo": null,
    "rules": [{
      "id": "VAT",
      "type": "PERCENTAGE",
      "appliesToKinds": ["ENERGY_PURCHASE", "DISTRIBUTION_VARIABLE"],
      "rate": "0.23",
      "base": "CURRENT_SUBTOTAL"
    }]
  }],
  "periods": [
    {
      "validFrom": "2026-01-01T00:00:00+01:00",
      "validTo": null,
      "components": [
        {
          "id": "energy",
          "kind": "ENERGY_PURCHASE",
          "category": "ENERGY",
          "quantity": {"type": "ACTIVE_ENERGY_IMPORT"},
          "taxTreatment": {"included": []},
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
          "kind": "DISTRIBUTION_VARIABLE",
          "category": "NETWORK",
          "quantity": {"type": "ACTIVE_ENERGY_IMPORT"},
          "taxTreatment": {"included": []},
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

`resources/tariff-presets/` contains component presets. Polish OSD tariffs expose distribution components, seller tariffs/offers expose supply components, and generic presets provide user-configurable building blocks. Every bundled preset declares its tax jurisdiction/customer class in `taxContext`; tax rates themselves remain package-owned `TaxProfile` resources. Catalogue metadata includes concrete `components` (`kind`, `componentId`, `label`) so a host can render one compatible selector per cost component without hard-coding component IDs.

`CostPlanStarterCatalog` provides the simple setup path: starters such as `TAURON Dystrybucja - G11` return only the matching default component recipe. They do not define billing cycles or period dates. The host inserts `CostPlanStarter::components` into a user-owned CostPlan period; for a first and only period, omitting `validFrom`/`validTo` makes it apply to the whole meter history. Persisted plans keep component preset IDs and explicit user overrides, not the starter ID.

Use the production-facing catalogue API instead of resolving package paths directly:

```php
use Supla\EnergyCostCalculator\Preset\TariffPresetCatalog;

$catalog = new TariffPresetCatalog();
$summaries = $catalog->presets();
$preset = $catalog->get('PL.TAURON_DYSTRYBUCJA.G12.2026');

$preset->document; // Complete preset document.
$preset->revision; // SHA-256 of the deterministically encoded JSON document.
```

`presets()` returns catalogue metadata with a `revision` and without internal resource paths. `get()` rejects unknown identifiers and resources outside the bundled preset directory. A preset ID is a stable reference to one semantic tariff, offer, or generic definition; compatible corrections may change its revision without changing its ID. A real tariff/offer change or incompatible generic contract must use a new preset ID.

Cost plans use the component-based version 2 format described in `docs/cost-plans.md` and `schema/cost-plan-v2.schema.json`. Each effective period selects individual `CostComponentKind` values; `billingCycles` are configured separately.

Plan periods are contiguous and ordered. The first may have an open `validFrom`, the last may have an open `validTo`, and a single period may leave both boundaries open.

Top-level preset `validFrom` / `validTo` describe catalogue availability of that tariff or offer edition and do not clip a user's CostPlan. Executable applicability belongs to `billingDefinitionTemplate.periods[]`: annual tariff editions can remain bounded there, while contract offers and generic presets may use open template periods. The CostPlan period records the user's actual effective range.

Tax profiles describe immutable tax regimes rather than calendar years. For Polish households the `VAT23_EXCISE5` profile is reused from 2019 through 2021 and again from 2023 onward, while the 2022 anti-inflation shield uses a dedicated `VAT5_EXCISE0` profile. The current assignment remains open-ended until tax law changes; when it does, close that assignment at the legal boundary and add a new immutable profile instead of publishing annual copies.

A persisted CostPlan may use `validFrom: null` and/or `validTo: null` independently of that tax timeline. This is useful for the normal UI meaning "apply this plan to all meter history I have". Compilation still succeeds; if calculation reaches a date not covered by `taxRuleSets[]`, it raises an explicit calculation error instead of guessing tax rules.

Use `CostPlanCompiler` for persisted user plans:

```php
use Supla\EnergyCostCalculator\Plan\CostPlanCompiler;

$plan = [
    'version' => 2,
    'currency' => 'PLN',
    'timezone' => 'Europe/Warsaw',
    'billingCycles' => [[
        'validFrom' => '2026-01-01T00:00:00+01:00',
        'validTo' => '2027-01-01T00:00:00+01:00',
        'anchor' => '2026-01-15',
        'length' => 1,
        'unit' => 'MONTH',
    ]],
    'periods' => [[
        'validFrom' => '2026-01-01T00:00:00+01:00',
        'validTo' => '2027-01-01T00:00:00+01:00',
        'components' => [
            ['kind' => 'ENERGY_PURCHASE', 'presetId' => 'PL.TAURON_SPRZEDAZ.G12.2026', 'componentId' => 'energy-purchase', 'values' => ['energy.DAY' => '0.98', 'energy.NIGHT' => '0.62']],
            ['kind' => 'DISTRIBUTION_VARIABLE', 'presetId' => 'PL.TAURON_DYSTRYBUCJA.G12.2026', 'componentId' => 'distribution-variable', 'values' => []],
        ],
    ]],
];

$definition = (new CostPlanCompiler())->compile($plan);
```

The cost-plan JSON stores stable preset IDs and user values/overrides, not a copied executable definition or tax-profile IDs. Compiling later uses the current document for the same preset ID and resolves tax law from its `taxContext` plus the effective date range. A plan made only from inline components must provide an explicit top-level `taxContext`; a host may set it automatically. Omit preset-default values from `values` unless the user explicitly overrides them; this preserves inheritance of corrected defaults. `kind` is a compatibility role and `componentId` is the per-period identity, so repeated kinds require distinct IDs; inline periodic components may omit it to retain their legacy kind-derived ID. See `schema/cost-plan-v2.schema.json` and `docs/cost-plans.md`.

Bundled presets are complete defaults: `TariffPresetCompiler::compileToArray()` resolves template inputs, while `CostPlanCompiler` owns executable BillingDefinition compilation. Every declared input targets a default template value and callers may override any of them when creating a plan. Billing-cycle settings belong to the cost plan.

Standard supply presets preserve the provenance of the energy-price defaults originally bundled with the OSD examples. Named dynamic offers are separate `OFFER` presets and may expose several components, for example `ENERGY_PURCHASE` plus `SUPPLIER_FIXED`. The package also provides generic constant and market-reference energy presets. See `docs/component-presets-and-starters.md`.

The bundled 2026 Polish OSD setup also includes versioned fixed-charge presets for the fixed network charge, capacity charge and distribution subscription fee. Starters select them with empty `values`, so published defaults remain preset-owned and can be overridden like other prices. Defaults assume a 1-phase installation, the 1200-2800 kWh/year capacity band and monthly billing; the distribution subscription is zero from 2026-10-01. Usage-based quality/OZE/cogeneration charges remain outside this fixed-charge set.

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
vendor/bin/phpunit --group performance
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
