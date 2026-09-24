# Cost plans and tariff presets

## Component plans (version 2)

Version 2 assigns a `CostComponentKind` compatibility role to each component of each effective period. The four initial kinds are `ENERGY_PURCHASE`, `DISTRIBUTION_VARIABLE`, `DISTRIBUTION_FIXED`, and `SUPPLIER_FIXED`. Preset-backed components select a concrete `componentId`; fixed periodic kinds may also use the inline `rate`/`per` shorthand. More than one component of the same kind is allowed when `componentId` values differ. `billingCycles` are independent of preset selection. See `schema/cost-plan-v2.schema.json`.

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
        "presetId": "PL.TAURON_SPRZEDAZ.G11.2026",
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

Preset input targets are applied only to the selected component. The compiler verifies component category and quantity compatibility, compatible currency/timezone/price basis, full billing-cycle coverage, and unique `componentId` values per plan period. Preset `validFrom` and `validTo` values are informative; the cost-plan period controls when a selected tariff or offer applies. The compiler preserves internal template rule changes while extending its outer rules to the configured plan period. Bundled presets compile from their template defaults; a cost plan's `values` override those defaults explicitly. Distribution tariffs and supply offers are separate presets. `CostPlanStarterCatalog` composes the usual OSD + incumbent-supplier combinations for the simple setup path, while advanced UIs can replace each component independently.

For non-prorated periodic fees, the calculator charges a given component once per charge bucket even when several plan periods intersect that bucket. If its rate changes within the same bucket, calculation rejects the ambiguous fee; split the billing cycle or use explicit proration.

`CostPlanDefinition` is the persistent user-intent model. `BillingDefinition` is the executable calculator model.

Cost-plan periods are ordered, contiguous half-open ranges. The first period may omit or set `validFrom` to `null` for an open start, and the last may omit or set `validTo` to `null` for an open end. Every interior boundary is required and must exactly match the preceding period's `validTo`; a single period may leave both boundaries open.

```text
CostPlanDefinition
  -> presetId + user values/overrides
  -> CostPlanCompiler
  -> BillingDefinition
  -> CostCalculator
```

## Stable preset IDs

A non-generic preset ID identifies one real tariff or offer definition. Generic preset IDs identify a versioned reusable configuration shape. Package maintainers may correct an implementation/data mistake under the same ID when the user-facing input contract remains compatible. Existing plans intentionally pick up that correction the next time they compile.

A real operator change (new validity period, changed tariff semantics, new product) must be published under a new preset ID. Do not use a new revision hash as a substitute for a new semantic ID.

`TariffPreset.revision` is a deterministic SHA-256 content hash. It is useful for diagnostics and cache invalidation, but a persisted cost plan is not pinned to a revision.

## Compilation

`TariffPresetCompiler`:

- validates submitted input IDs and input types;
- applies trusted preset-declared JSON Pointer targets;
- supports RFC 6901 `~0` and `~1` escaping;
- rejects unknown input IDs, malformed pointers and unresolved required values;
- delegates final executable-definition validation to `BillingDefinitionParser`.

`CostPlanCompiler`:

- resolves selected components through the current `TariffPresetCatalog`;
- compiles each selected component with its own user overrides;
- combines selected components into one `BillingDefinition`;
- validates continuous billing-cycle coverage and component rule coverage;
- delegates final validation to `BillingDefinitionParser`.

## Preset corrections

Given a persisted plan that references `TEST.G11.2026`, changing a package-owned default in that preset from `0.10` to `0.20` while keeping the same preset ID changes the next compiled `BillingDefinition`. User-provided values remain unchanged.

If a user explicitly overrode the corrected field, the stored override continues to win.

## Host application responsibility

A host such as `supla-cloud` should persist the version 2 cost-plan JSON and pass it to `CostPlanCompiler`. It should not implement preset input mapping, JSON Pointer handling, component composition, or calculator semantic validation.
