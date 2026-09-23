# Cost plans and tariff presets

## Component plans (version 2)

Version 2 selects a fixed `CostComponentKind` for each line of each effective period. The four initial kinds are `ENERGY_PURCHASE`, `DISTRIBUTION_VARIABLE`, `DISTRIBUTION_FIXED`, and `SUPPLIER_FIXED`. The first two select a component by `componentId` from an existing preset; the fixed kinds specify a decimal rate and a `per` period. `billingCycles` are independent of preset selection. See `schema/cost-plan-v2.schema.json`.

```json
{
  "version": 2,
  "currency": "PLN",
  "timezone": "Europe/Warsaw",
  "priceBasis": "NET",
  "billingCycles": [{
    "validFrom": "2026-01-01T00:00:00+01:00",
    "validTo": "2027-01-01T00:00:00+01:00",
    "anchor": "2026-01-15",
    "length": 1,
    "unit": "MONTH"
  }],
  "periods": [{
    "validFrom": "2026-01-01T00:00:00+01:00",
    "validTo": "2027-01-01T00:00:00+01:00",
    "components": [
      {
        "kind": "ENERGY_PURCHASE",
        "presetId": "PL.TAURON_DYSTRYBUCJA.G11.2026",
        "componentId": "energy-purchase",
        "values": {"energy.rate": "0.71"}
      },
      {
        "kind": "DISTRIBUTION_VARIABLE",
        "presetId": "PL.ENERGA_OPERATOR.G12.2026",
        "componentId": "distribution-variable",
        "values": {}
      },
      {"kind": "SUPPLIER_FIXED", "rate": "12.00", "per": "BILLING_PERIOD"}
    ]
  }]
}
```

Preset input targets are applied only to the selected component. The compiler verifies the component category and quantity, compatible currency/timezone/price basis, full preset and billing-cycle coverage, and unique kinds per plan period. It splits executable periods at preset boundaries. Version 1 plans continue to compile through the existing route. A preset's simulation defaults are never applied implicitly to saved plans. Selecting a sale offer from a supplier requires actual supplier preset data; the current bundled energy components are tariff-zone examples grouped by distribution operator.

For non-prorated periodic fees, the calculator charges a given component once per charge bucket even when several plan periods intersect that bucket. If its rate changes within the same bucket, calculation rejects the ambiguous fee; split the billing cycle or use explicit proration.

## Legacy preset plans (version 1)

`CostPlanDefinition` is the persistent user-intent model. `BillingDefinition` is the executable calculator model.

```text
CostPlanDefinition
  -> presetId + user values/overrides
  -> CostPlanCompiler
  -> BillingDefinition
  -> CostCalculator
```

## Stable preset IDs

A preset ID identifies one real tariff definition. Package maintainers may correct an implementation/data mistake under the same ID when the user-facing input contract remains compatible. Existing plans intentionally pick up that correction the next time they compile.

A real operator change (new validity period, changed tariff semantics, new product) must be published under a new preset ID. Do not use a new revision hash as a substitute for a new semantic ID.

`TariffPreset.revision` is a deterministic SHA-256 content hash. It is useful for diagnostics and cache invalidation, but a persisted cost plan is not pinned to a revision.

## Persisted plan shape

See `schema/cost-plan.schema.json`.

```json
{
  "version": 1,
  "entries": [
    {
      "validFrom": "2026-01-01T00:00:00+01:00",
      "validTo": "2026-07-01T00:00:00+02:00",
      "presetId": "PL.TAURON_DYSTRYBUCJA.G12.2026",
      "values": {
        "billingCycle.anchor": "2026-01-15",
        "energy.DAY": "0.98",
        "energy.NIGHT": "0.62"
      }
    }
  ]
}
```

`values` is intentionally partial. An omitted input inherits the value currently present in the preset template. Persist values that are inherently user-specific (for example the energy-sale price or billing anchor) and values the user explicitly overrides. Do not persist every rendered default automatically, otherwise future corrections to package-owned defaults cannot flow into the plan.

Entry `validFrom` / `validTo` are optional half-open user constraints. When omitted, the preset validity is used. When provided, the compiler intersects the user range with the current preset validity. Effective ranges may be adjacent but must not overlap after that intersection.

## Compilation

`TariffPresetCompiler`:

- validates submitted input IDs and input types;
- applies trusted preset-declared JSON Pointer targets;
- supports RFC 6901 `~0` and `~1` escaping;
- rejects unknown input IDs, malformed pointers and unresolved required values;
- delegates final executable-definition validation to `BillingDefinitionParser`.

`CostPlanCompiler`:

- resolves every entry through the current `TariffPresetCatalog`;
- clips the preset's billing-definition periods to the entry effective range;
- combines all entries into one `BillingDefinition`;
- requires one BillingDefinition version, currency and timezone across the plan;
- merges adjacent billing-cycle segments when anchor/length/unit are unchanged;
- preserves a billing-cycle boundary when the billing-cycle configuration actually changes;
- delegates final validation to `BillingDefinitionParser`.

The billing-cycle merge is important: a price change on July 1 must not split an invoice period if the billing anchor and cycle did not change.

## Preset corrections

Given a persisted plan that references `TEST.G11.2026`, changing a package-owned default in that preset from `0.10` to `0.20` while keeping the same preset ID changes the next compiled `BillingDefinition`. User-provided values remain unchanged.

If a user explicitly overrode the corrected field, the stored override continues to win.

## Host application responsibility

A host such as `supla-cloud` should persist the cost-plan JSON and pass it to `CostPlanCompiler`. It should not implement preset input mapping, JSON Pointer handling, tariff-history merging, billing-cycle history generation, or calculator semantic validation.
