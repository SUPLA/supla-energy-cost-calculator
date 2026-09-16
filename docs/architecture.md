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
6. For every metered component: resolve quantity -> selector -> rate -> cost.
7. Resolve the billing-cycle context (`anchor + length + unit`).
8. Keep periodic charge definitions separate from metered interval costs.
9. Calculate periodic charges only when the requested range covers complete billing cycles.
10. Return usage totals and optional per-interval usage/cost diagnostics.

## Important modelling choice

Dynamicity belongs to a component, not to the entire tariff. A single billing period can therefore combine:

- energy price from `PL.TGE.FIXING1`,
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

`TimeRange` is the caller's analytical range and may be arbitrary. `billingCycle` describes invoice boundaries and does not constrain `TimeRange`.

Periodic fees are not distributed into 15-minute/hour/day chart facts. For partial billing-cycle queries the result exposes their definitions but leaves the periodic/full total unknown (`null`). For ranges aligned to complete billing cycles the engine calculates them. Periodic units are anchored to the billing period start, so a `MONTH` fee on a `15 Jan -> 15 Feb` billing cycle counts as one unit, not two calendar-month overlaps.

This separation lets clients build hour/day/month charts from `intervals[]` while still presenting fixed-fee information and exact full totals for billing-period views.
