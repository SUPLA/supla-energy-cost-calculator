# Component presets and cost-plan starters

Cost plans are persisted user intent. A cost-plan starter is only a convenient way to populate one period with a sensible set of components.

A starter contains only `components[]`. It does not define the CostPlan envelope, billing cycles, or period boundaries. Those belong to the user's plan. For the first and only period, a host should normally omit `validFrom` and `validTo`, which makes the selected configuration apply to the whole meter history. When the user later adds another period, the host owns the boundary between the periods and may populate the new period from another starter.

Component `values` in starters are normally empty. Empty values inherit defaults from the referenced preset. When a user overrides one field, only that field is persisted in `values`; removing the override makes the plan inherit the current preset default again.

`CostPlanStarterCatalog` exposes the bundled simple paths such as `TAURON Dystrybucja - G11`. Hosts copy `CostPlanStarter::components` into the selected CostPlan period and persist the resulting user-owned plan. Existing plans must not stay linked to the starter ID: changing which component preset a starter recommends is intended to affect new drafts only. Standard tariff preset IDs are stable across price changes; dated offer editions may have versioned IDs.

For example, a first-time setup may create the surrounding plan independently and insert only the starter recipe:

```php
$starter = (new CostPlanStarterCatalog())->get('PL.STARTER.TAURON_DYSTRYBUCJA.G11');

$plan['periods'] = [[
    'components' => $starter->components,
]];
```

The resulting single period has open boundaries. Billing-cycle defaults or invoice-specific anchors are configured by the host/user independently from the selected starter.

## Preset roles

The Polish catalogue separates the components that were previously bundled in one OSD document:

- distribution presets keep stable OSD IDs such as `PL.TAURON_DYSTRYBUCJA.G11` and provide `DISTRIBUTION_VARIABLE`;
- standard supply presets use seller IDs such as `PL.TAURON_SPRZEDAZ.G11` and provide `ENERGY_PURCHASE`;
- real retail offers may provide more than one component, for example dynamic energy plus `SUPPLIER_FIXED`;
- generic presets provide user-configurable building blocks without pretending to represent a named market offer.

The default G11/G12/G13 starters compose the incumbent seller tariff (initial defaults from 2026) with the matching OSD distribution tariff. `G14dynamic` uses TAURON's G11 supply component plus the PDGSZ-driven G14dynamic distribution component. ENEA `G13active` uses ENEA G11 supply because the previously bundled energy price was the same constant price in every G13active zone.

Every bundled Polish preset also declares `taxContext: {jurisdiction: PL, customerClass: HOUSEHOLD}`. This is compatibility/context metadata, not tax law: VAT/excise rates live in immutable tax-profile resources and are selected by the library for the effective date range. `taxTreatment.included` remains separate and tells the compiler which applicable taxes are already present in the preset's source price.

Catalogue metadata declares concrete `components` (`kind`, `componentId`, `label`) so a UI can present one compatible dropdown per component without a hard-coded `kind -> componentId` map. Multi-component offers can therefore tell the host which sibling components should be instantiated together by default. `presetType` distinguishes `TARIFF`, `OFFER` and `GENERIC` entries. `provider` is generic seller/operator metadata; `operator` and `tariffGroup` remain available where useful for the simple starter path.

## Generic energy presets

The package ships two generic `ENERGY_PURCHASE` presets:

- `PL.GENERIC.ENERGY_PURCHASE.CONSTANT.V1` - editable constant PLN/kWh price with 60-minute import/export netting;
- `PL.GENERIC.ENERGY_PURCHASE.MARKET_REFERENCE.V1` - one configurable `REFERENCE` rate for Fixing I, hourly Fixing I, Fixing II, hourly Fixing II or RCE, with editable multiplier and additive term.

Both generic energy presets require a tax-source choice: `WITHOUT_TAXES`, `WITH_EXCISE`, or `WITH_EXCISE_AND_VAT`. The choice replaces only `taxTreatment.included`; rate arithmetic is unchanged.

The generic dynamic preset deliberately does not impose temporal netting or allocation. `_HOURLY` references are ordinary selectable sources whose value stays constant across the four 15-minute SUPLA price slots; they do not need a separate preset. The engine supports explicit contract-defined `EQUAL` allocation, but generic users are not offered `strategy`, `periodInMinutes` or `allocation` knobs because those choices change settlement semantics and belong in named presets.

Generic presets use the same `inputs`, defaults and overrides as real tariff presets. They are not a second configuration language.

`CHOICE` inputs replace a scalar target exactly like the existing input types, but the submitted value must match one of the declared option values.

Temporal netting is part of the component preset's executable `quantity`, not starter metadata. Standard Polish household supply/distribution presets use `IMPORT_MINUS_EXPORT_CAP_ZERO` with a 60-minute window. This remains a useful default for a non-prosumer when selector/rate is constant throughout the hour: with zero export the monetary result is unchanged, while natural `charges[]` are hourly. Dynamic consumer offers whose rate can change inside the hour instead use the actual raw import profile; their prosumer counterpart may use a separate 60-minute netting + explicit allocation preset when the contract requires it.

SUPLA's device-reported `fae_balanced`/`rae_balanced` counters describe vector phase-to-phase balancing and remain measurement/UI data. They are deliberately not separate calculator `QuantityType` values. Cost definitions operate on canonical active import/export quantities and apply temporal netting explicitly when the tariff requires it.

## Reference source clamps

A `REFERENCE` rate may define optional `sourceMin` and/or `sourceMax`. They clamp the raw reference value in `sourceUnit` before `multiplier` and `add` are applied:

```text
clampedSource = min(max(reference, sourceMin), sourceMax)
rate = clampedSource * multiplier + add
```

This is intentionally different from a cap/floor on a billing-period weighted-average or effective rate. `sourceMin`/`sourceMax` must not be used to approximate such settlement rules.

## Bundled dynamic offers

Named dynamic offers are bundled only when the current calculator can express the published formula without approximation.

ENERGA-OBRÓT's Oferta dynamiczna II is split into `...KONSUMENT.2025_11` and `...PROSUMENT.2025_11`. Both use natural-resolution `PL.TGE.FIXING1` plus `0.0878 PLN/kWh` net. The consumer preset prices the real import profile. The prosumer preset first performs 60-minute import/export netting and then applies `EQUAL/15` allocation before pricing each ORN. Both expose the monthly supplier fee separately. Source: https://www.energa.pl/dam/jcr%3A2971d640-ec36-434b-ab84-e310a8b1ea07/Regulamin%20naszej%20oferty%20Oferta%20dynamiczna%20II%20dla%20domu%20obowi%C4%85zuj%C4%85cy%20od%201%20listopada%202025%20roku.pdf

ENEA's `DI12011227_G` Ceny Dynamiczne is likewise split into `.KONSUMENT` and `.PROSUMENT`. Both use natural-resolution `PL.TGE.FIXING1` plus `0.0050 PLN/kWh` excise and `0.0820 PLN/kWh` cost/margin (`add = 0.0870`). The consumer keeps its real profile; the prosumer uses 60-minute netting followed by `EQUAL/15` allocation when the source is sub-hourly. Source: https://www.enea.pl/media/9061/cennik-oferty-ceny-dynamicznedi12011227god-01072026-do-30092026pdf.pdf

PGE's `Dynamiczna energia z PGE` Ed. 1.2026 has separate consumer and prosumer presets. The consumer uses natural-resolution Fixing I, raw import, `0..4000 PLN/MWh` source clamp, `K = 0.0855` plus excise (`add = 0.0905`) and a `22.00 PLN` monthly fee. The prosumer keeps the same source clamp but uses 60-minute netting + `EQUAL/15`, `K = 0.1150` plus excise (`add = 0.1200`) and a `27.00 PLN` monthly fee. Source: https://www.gkpge.pl/content/download/6f23e27ebdb19716dc20233047913cc5/file/zal-nr-2-ceny-energii-elektrycznej-dla-g1x.pdf?contentId=143446&inLanguage=pol-PL&version=26

For named offers, top-level preset validity records the availability window of that offer edition. The actual contract start/end belongs to the user's CostPlan period.

## Deliberately unsupported / deferred settlement rules

The following cases are intentionally not approximated by the current DSL:

- **Billing-period effective-rate floors/caps**, including TAURON Dynamiczne/Dynamiczne MAX rules applied after calculating a consumption-weighted average. `sourceMin`/`sourceMax` clamp each raw reference observation and are not equivalent to a billing-period cap/floor.
- **Reference-data fallback policies** specified by some dynamic offers (for example using earlier market prices when the current publication is missing). Missing reference data currently fails explicitly instead of guessing a fallback.
- **History-dependent quantity thresholds**, such as G12as rules in which part of current consumption is priced according to an analogous previous-year consumption baseline.
- **Full prosumer export settlement/revenue.** The current plan model calculates import-side costs and hourly import/export netting; it does not calculate net-billing revenue for exported energy.

PGE's bundled `.2026` dynamic consumer preset intentionally models the 2026 Fixing I rules only. The published offer states that Fixing II applies from 2027; later contract periods should use a future preset edition rather than silently extending the 2026 source selection.

## Component identity

A `CostComponentKind` is a compatibility role, not a component identity. More than one component of the same kind may exist in one plan period as long as their `componentId` values are distinct. Preset-backed components always declare an ID; inline periodic components may declare one, and otherwise retain their legacy kind-derived ID. `CostPlanCompiler` validates compatibility by component category and quantity semantics rather than by a hard-coded `kind -> componentId` mapping.
