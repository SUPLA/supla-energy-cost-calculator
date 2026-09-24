# Architecture notes

The library is intentionally framework- and storage-agnostic.

## Ports

- `EnergyDeltaSource` supplies canonical meter intervals.
- `ReferenceDataSource` supplies external interval series such as Fixing1, Fixing2, RCE and PDGSZ.

The package never imports Doctrine, Symfony or SUPLA entities.

## Calculation pipeline

1. Parse and validate the billing definition.
2. Collect unique external reference IDs used by the definition.
3. Fetch each reference series once for the whole requested range.
4. Stream meter deltas.
5. Resolve the active billing-definition period for each delta.
6. For ordinary metered components: resolve quantity -> selector -> rate -> charge.
7. For temporally netted quantities: accumulate complete aligned windows, require a stable selector/rate across the window, then resolve one charge for the whole window.
8. Resolve the billing-cycle context (`anchor + length + unit`).
9. Keep periodic charge definitions separate from usage-based charge facts.
10. Calculate periodic charges only when the requested range covers complete billing cycles.
11. Return usage totals, optional meter-interval diagnostics, and optional natural-resolution `charges[]`.

Persisted cost-plan periods compile into the same billing-definition periods. Their boundaries may be open at either outer edge, so calculation can resolve logs before the first dated tariff change and after the last one.

Within a cost-plan period, `CostComponentKind` describes compatibility while `componentId` identifies the resulting charge component. A kind may occur more than once when the IDs differ. Inline periodic components may omit `componentId` only for the legacy kind-derived ID.

## Important modelling choice

Dynamicity belongs to a component, not to the entire tariff. A single billing period can therefore combine:

- energy price from `PL.TGE.FIXING1` or an hourly source such as `PL.TGE.FIXING1_HOURLY`,
- distribution zone from `PL.PSE.PDGSZ`,
- a static monthly service fee.

## Current deliberate limitations

This is an initial package skeleton. Before production billing use, consider adding:

- exact decimal implementation (`DecimalMath`) backed by `brick/math` or BCMath,
- demand / contracted-power quantity strategies,
- explicit handling of gaps in delta logs,
- configurable behavior for definition boundaries that cut through meter intervals,
- richer validation of units and component compatibility,
- result/cost caching outside the library if large fleet-wide reports need it.

## Holiday calendars

`WEEKLY_SCHEDULE` can optionally reference a `HolidayCalendarProvider` calendar and use `HOLIDAY` as a pseudo-day.

The default `BundledHolidayCalendarProvider` loads JSON files from `resources/calendars/`. The first bundled calendar is `PL_PUBLIC_HOLIDAYS`, with explicit Polish statutory holiday dates for 2018-2030.

Holiday matching is intentionally data-driven. Country-specific legal dates do not belong in `DefaultSelectorResolver`. The selector only knows the generic concepts `calendar` and `HOLIDAY`.

When both a holiday rule and an ordinary weekday rule could match a timestamp, the holiday rule has priority. Requests outside a bundled calendar's declared coverage fail explicitly.

## Requested range and billing cycle

`TimeRange` is the caller's analytical range and may be arbitrary. `billingCycle` describes one invoice-boundary rule and does not constrain `TimeRange`. `billingCycles[]` is the historical form when that rule changes over time.

Billing-cycle validity boundaries are hard boundaries. They cut the nominal period instead of creating a gap or overlapping invoices. For example, changing from a cycle anchored on day 15 to a cycle anchored on day 1 at `2026-07-01` yields an effective transitional period `2026-06-15 -> 2026-07-01`, then `2026-07-01 -> 2026-08-01`. A prorated fee uses the nominal uncut period as its denominator.

`CalculationResult.billingPeriods[]` contains per-effective-period usage and cost summaries. `costs.usageBased.byZone` is available both globally and inside these summaries; phase-specific reporting remains an adapter/application concern.

Periodic fees are not distributed into 15-minute/hour/day chart facts. For partial billing-cycle queries the result exposes their definitions but leaves the periodic/full total unknown (`null`). For ranges aligned to complete billing cycles the engine calculates them. Periodic units are anchored to the billing period start, so a `MONTH` fee on a `15 Jan -> 15 Feb` billing cycle counts as one unit, not two calendar-month overlaps.

This separation lets clients build hour/day/month charts from `charges[]` while still presenting fixed-fee information and exact full totals for billing-period views. `intervals[]` remains tied to raw meter intervals; a wider netting-window charge is never smeared back into its constituent intervals.


## Temporal netting

A metered quantity may declare a temporal strategy, for example:

```json
{
  "type": "ACTIVE_ENERGY_IMPORT",
  "strategy": "IMPORT_MINUS_EXPORT_CAP_ZERO",
  "periodInMinutes": 60
}
```

Netting windows are aligned using the billing-definition timezone. The engine keeps raw meter deltas as source facts and combines them only for the component that declares the strategy. `IMPORT_MINUS_EXPORT` returns signed `sum(import) - sum(export)`. `IMPORT_MINUS_EXPORT_CAP_ZERO` returns `max(sum(import) - sum(export), 0)`.

A charge exists only for a complete netting window. Missing meter intervals, requested ranges cutting a window, billing-definition or billing-cycle boundaries inside a window, selector changes inside a window, or rate changes inside a window are explicit calculation errors. This makes a 60-minute netting component compatible with hourly Fixing data and intentionally incompatible with a price or selector that changes every 15 minutes.
