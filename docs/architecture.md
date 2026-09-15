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
7. Add periodic components separately.
8. Return totals and optional per-interval diagnostics.

## Important modelling choice

Dynamicity belongs to a component, not to the entire tariff. A single billing period can therefore combine:

- energy price from `PL.TGE.FIXING1`,
- distribution zone from `PL.PSE.PDGSZ`,
- a static monthly service fee.

## Current deliberate limitations

This is an initial package skeleton. Before production billing use, consider adding:

- exact decimal implementation (`DecimalMath`) backed by `brick/math` or BCMath,
- public-holiday/calendar selectors,
- demand / contracted-power quantity strategies,
- explicit handling of gaps in delta logs,
- configurable behavior for definition boundaries that cut through meter intervals,
- richer validation of units and component compatibility,
- result/cost caching outside the library if large fleet-wide reports need it.
