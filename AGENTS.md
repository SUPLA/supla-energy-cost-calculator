# AGENTS.md

## Purpose

This repository is a framework-agnostic PHP library that calculates electricity costs from:

1. interval energy deltas,
2. a JSON billing definition,
3. optional external reference series,
4. optional holiday calendars.

It is intended to run inside `supla-cloud` as a Composer dependency and later, without changing the core engine, inside a standalone service.

## Architectural boundaries

Do not couple the core package to Symfony, Doctrine ORM, SUPLA entities, SUPLA table names, HTTP, or specific energy providers.

Host applications implement the ports in `src/Contract` and convert their storage records into package-owned value objects.

The key model is:

```text
BillingDefinition
  -> periods[]
      -> components[]
          -> quantity
          -> selector
          -> rate
```

Never reintroduce a global "static tariff vs dynamic tariff" distinction. Different components can simultaneously use different mechanisms, e.g. Fixing1 for energy and PDGSZ for distribution.

## Data access

- `EnergyDeltaSource` supplies canonical meter intervals.
- `ReferenceDataSource` supplies time-series values such as Fixing1, Fixing2, RCE and PDGSZ.
- `HolidayCalendarProvider` supplies local-date holiday information.

When a `REFERENCE` rate declares `sourceUnit`, the returned `ReferenceInterval.unit` must match it exactly. Treat `sourceUnit` as a runtime contract assertion; do not silently convert units in the resolver because the rate's `multiplier` already owns any intended conversion. Optional `sourceMin`/`sourceMax` clamp the raw source value before `multiplier` and `add`; they are not settlement-period effective-rate caps.

The core calculator must not query a database directly.

## SUPLA integration assumptions

Initial SUPLA adapters read:

```text
supla_em_delta_log
supla_energy_price_log
```

Raw SUPLA energy values currently use scale `100000 raw units = 1 kWh`; that conversion belongs in the SUPLA adapter.

Suggested external reference IDs include:

```text
PL.TGE.FIXING1
PL.TGE.FIXING1_HOURLY
PL.TGE.FIXING2
PL.TGE.FIXING2_HOURLY
PL.PSE.RCE
PL.PSE.PDGSZ
```

## Holiday calendars

Holiday logic is data-driven.

Bundled calendars live in:

```text
resources/calendars/
```

Bundled tariff presets are production resources exposed through `TariffPresetCatalog` and live in:

```text
resources/tariff-presets/
```

Host applications must not resolve package resource paths directly. A preset revision is the SHA-256 hash of its deterministically encoded JSON document, independent of file formatting and line endings.

Cost-plan compilation also belongs to this package. Persisted version 2 cost plans reference stable preset IDs plus user-specific component values/overrides and are compiled with `CostPlanCompiler` into executable `BillingDefinition` objects. Compatible fixes to an existing preset ID intentionally affect existing plans; real tariff changes require a new preset ID. Do not pin a cost plan to a preset revision. Preset revision hashes are for diagnostics/cache invalidation. Adjacent plan periods with identical billing-cycle definitions must not create artificial billing-cycle boundaries.

`CostComponentKind` is a compatibility role, while `componentId` is the unique identity within a cost-plan period. Repeated kinds require distinct component IDs. Inline periodic components may omit `componentId` only to retain their legacy kind-derived ID.

The current bundled calendar is:

```text
PL_PUBLIC_HOLIDAYS
```

defined by `resources/calendars/PL.json`, with explicit Polish statutory holiday dates for 2018-2030.

Important historical details intentionally represented in data:

- 2018-11-12 was a one-off statutory day off,
- 2024-12-24 is not a statutory holiday in this calendar,
- 2025-12-24 and later covered years include Christmas Eve,
- movable holidays are stored as explicit dates rather than computed at runtime.

`WEEKLY_SCHEDULE` supports:

```json
{
  "type": "WEEKLY_SCHEDULE",
  "timezone": "Europe/Warsaw",
  "calendar": "PL_PUBLIC_HOLIDAYS",
  "rules": [
    {"zone": "OFF_PEAK", "days": ["HOLIDAY"], "from": "00:00", "to": "24:00"}
  ]
}
```

Rules containing `HOLIDAY` are evaluated before ordinary weekday rules. `HOLIDAY` must not be mixed with `MON`-`SUN` in one rule. Using `HOLIDAY` requires `calendar`.

Do not hard-code Polish holiday dates into selectors. To add another country, add data and, if necessary, another provider implementation without changing the generic selector semantics.

Do not silently treat a date outside calendar coverage as a normal day. The bundled provider must throw `HolidayCalendarCoverageException`.

## Time semantics

Intervals are half-open: `[from, to)`.

Use `DateTimeImmutable` where practical. Storage may be UTC, while schedule selectors use an explicit local timezone such as `Europe/Warsaw`.

DST-sensitive schedule behavior must be tested.

## Performance

- Stream meter deltas with `iterable`.
- Preload reference time series once per calculation range.
- Parse/compile JSON once per calculation.
- Holiday calendars are loaded once per provider instance and indexed by `Y-m-d` for O(1) lookup.
- Avoid N+1 queries and ORM hydration in the hot path.
- Keep 15-minute measurements as the canonical source; do not prematurely aggregate them away.

## Errors

Missing or ambiguous data should fail explicitly. Relevant exceptions include missing reference data, missing billing periods, unsupported rules, and holiday calendar coverage errors.

Do not default missing prices or holiday coverage to zero/false unless an explicit future fallback policy says so.

## Tests to preserve

At minimum keep coverage for:

- constant energy rate,
- periodic fee,
- Fixing1 reference rate,
- PDGSZ dynamic zone selection,
- mixed dynamic mechanisms,
- rule changes over time,
- holiday override of a weekday schedule,
- 2018-11-12 one-off Polish holiday,
- 2024-12-24 non-holiday vs 2025-12-24 holiday,
- date outside calendar coverage,
- DST behavior when schedule functionality is expanded.

Core tests must not require Symfony, Doctrine, a database, or network access.

## Before finishing changes

Run:

```bash
composer validate
composer test
composer lint
```

If Composer is not available, at least run syntax checks for every PHP file and validate JSON resources.

Keep these synchronized when public concepts change:

```text
README.md
AGENTS.md
docs/architecture.md
schema/billing-definition.schema.json
schema/cost-plan-v2.schema.json
schema/frontend-tariff-preset.schema.json
schema/holiday-calendar.schema.json
examples/
```

## Seasonal and overnight schedule invariant

`WEEKLY_SCHEDULE` supports the richer schedule representation inherited from `supla-cloud` `issue-307`: selector-level recurring `seasons`, rule-level `season`/`priority`, and one or more `time_ranges`. Keep the legacy `from`/`to` shorthand compatible unless a versioned schema change intentionally removes it.

A time range may cross midnight (`22:00` -> `06:00`). Such a range belongs to the local calendar day on which it starts; weekday, holiday and season matching use that starting day. Lower numeric rule priority wins, then declaration order. See `docs/schedules.md`.

## Requested range vs billing cycle

Do not equate the requested calculation range with the billing period.

The caller may request any `TimeRange` (hour, day, week, month, arbitrary range, or full billing period). `billingCycle` exists to define invoice boundaries and periodic-charge semantics:

```json
{
  "billingCycle": {
    "anchor": "2026-01-15",
    "length": 1,
    "unit": "MONTH"
  }
}
```

Usage-based charges must be calculated only at their natural resolution. Periodic/fixed charges must never be distributed into interval facts or chart buckets. A component with temporal netting may only produce a charge for a complete netting window; do not smear that charge back into the underlying meter intervals.

For a partial billing-period request:

- return usage and usage-based costs,
- return periodic charge definitions,
- leave periodic/full totals unknown (`null`) when periodic charges exist.

For a request aligned to complete billing periods:

- calculate periodic charges,
- return their units/amounts,
- return the full total.

Periodic units are anchored to the billing-period start. A `MONTH` fee for a billing cycle `15 Jan -> 15 Feb` is one monthly unit, not two units because two calendar months are touched.

When invoice boundaries change historically, represent them with top-level `billingCycles[]` entries carrying `validFrom`/`validTo`. A validity boundary cuts the nominal cycle and creates a shorter transitional billing period; never create a gap or overlap. For `prorate: true`, use the nominal uncut period as the denominator. Keep price-rule history (`periods[]`) independent from billing-cycle history (`billingCycles[]`).

`billingPeriods[]` in the result is the canonical per-invoice-period summary. It should contain usage, usage-based costs, periodic costs, total, and usage-based `byZone`. Do not force `byPhase` into the core model when phase data is not naturally available.

Tariff regression fixtures should support the form:

```text
rules + meter/reference facts + query -> expected result
```

Prefer adding scenario data to `tests/Fixtures/Tariffs/*.yml` using `query` and `expected.result`. The test harness supports recursive subset assertions so scenarios only need to state fields relevant to the behavior under test.


## Temporal netting invariant

A metered quantity may opt into temporal netting directly on `quantity`:

```json
{
  "type": "ACTIVE_ENERGY_IMPORT",
  "strategy": "IMPORT_MINUS_EXPORT_CAP_ZERO",
  "periodInMinutes": 60
}
```

Supported strategies are:

- `IMPORT_MINUS_EXPORT` = signed `sum(import) - sum(export)`,
- `IMPORT_MINUS_EXPORT_CAP_ZERO` = `max(sum(import) - sum(export), 0)`.

Without `strategy`, quantities keep the existing per-delta behavior. Temporal netting currently applies to `ACTIVE_ENERGY_IMPORT` and requires both `ACTIVE_ENERGY_IMPORT` and `ACTIVE_ENERGY_EXPORT` in every source delta.

Device-reported vector-balanced counters such as SUPLA `fae_balanced`/`rae_balanced` are not calculator quantity types. Adapters expose canonical active import/export facts; tariff-specific balancing belongs to `quantity.strategy` and its explicit time window.

Netting windows are aligned using `BillingDefinition.timezone`. Raw meter deltas remain canonical source facts. A netting charge may be emitted only when the full window is covered contiguously. A requested range that cuts a netting window, a gap in meter deltas, a billing-definition or billing-cycle boundary inside a window, a selector change inside a window, or a rate change inside a window must fail explicitly.

This means `periodInMinutes: 60` is compatible with an hourly Fixing series such as `PL.TGE.FIXING1_HOURLY` or `PL.TGE.FIXING2_HOURLY`, but not with a 15-minute-changing price/selector on the same component. Do not weaken this invariant by averaging rates or smearing a netting-window quantity across sub-intervals. A future offer that explicitly redistributes a wider net quantity among shorter price intervals needs a named allocation strategy.

`charges[]` is the authoritative cost-fact series for charting. Ordinary components produce charge facts at meter-delta resolution; temporally netted components produce one charge fact per netting window. `intervals[]` remains a raw meter-interval diagnostic view and must not contain an allocated share of a wider netting-window charge.
