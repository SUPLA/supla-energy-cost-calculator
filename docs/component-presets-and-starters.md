# Component presets and cost-plan starters

Cost plans are persisted user intent. A cost-plan starter is only a convenient way to populate one period with a sensible set of components.

A starter contains only `components[]`. It does not define the CostPlan envelope, billing cycles, or period boundaries. Those belong to the user's plan. For the first and only period, a host should normally omit `validFrom` and `validTo`, which makes the selected configuration apply to the whole meter history. When the user later adds another period, the host owns the boundary between the periods and may populate the new period from another starter.

Component `values` in starters are normally empty. Empty values inherit defaults from the referenced preset. When a user overrides one field, only that field is persisted in `values`; removing the override makes the plan inherit the current preset default again.

`CostPlanStarterCatalog` exposes the bundled simple paths such as `TAURON Dystrybucja - G11`. Hosts copy `CostPlanStarter::components` into the selected CostPlan period and persist the resulting user-owned plan. Existing plans must not stay linked to the starter ID: changing which component preset a starter recommends is intended to affect new drafts only. Starter IDs are intentionally not year-versioned; the concrete referenced preset IDs remain versioned and are copied into the user's CostPlan.

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

- distribution presets keep stable OSD IDs such as `PL.TAURON_DYSTRYBUCJA.G11.2026` and provide `DISTRIBUTION_VARIABLE`;
- standard supply presets use seller IDs such as `PL.TAURON_SPRZEDAZ.G11.2026` and provide `ENERGY_PURCHASE`;
- real retail offers may provide more than one component, for example dynamic energy plus `SUPPLIER_FIXED`;
- generic presets provide user-configurable building blocks without pretending to represent a named market offer.

The default G11/G12/G13 starters compose the incumbent 2026 seller tariff from the original bundled data with the matching OSD distribution tariff. `G14dynamic` uses TAURON's G11 supply component plus the PDGSZ-driven G14dynamic distribution component. ENEA `G13active` uses ENEA G11 supply because the previously bundled energy price was the same constant price in every G13active zone.

Catalogue metadata declares concrete `components` (`kind`, `componentId`, `label`) so a UI can present one compatible dropdown per component without a hard-coded `kind -> componentId` map. Multi-component offers can therefore tell the host which sibling components should be instantiated together by default. `presetType` distinguishes `TARIFF`, `OFFER` and `GENERIC` entries. `provider` is generic seller/operator metadata; `operator` and `tariffGroup` remain available where useful for the simple starter path.

## Generic energy presets

The package ships two generic `ENERGY_PURCHASE` presets:

- `PL.GENERIC.ENERGY_PURCHASE.CONSTANT.V1` - editable constant PLN/kWh price;
- `PL.GENERIC.ENERGY_PURCHASE.MARKET_REFERENCE.V1` - `REFERENCE` rate with selectable Polish reference source plus editable multiplier and additive term.

Generic presets use the same `inputs`, defaults and overrides as real tariff presets. They are not a second configuration language.

`CHOICE` inputs replace a scalar target exactly like the existing input types, but the submitted value must match one of the declared option values.

## Bundled dynamic offers

Named dynamic offers are bundled only when the current calculator can express the published formula without approximation.

`PL.ENERGA_OBROT.OFERTA_DYNAMICZNA_II.2025_11` models ENERGA-OBRÓT's Oferta dynamiczna II as hourly RDN Fixing I converted from PLN/MWh to PLN/kWh plus `0.0878 PLN/kWh` net. It also exposes the monthly supplier fee as a separate, overridable `SUPPLIER_FIXED` component. Source: https://www.energa.pl/dam/jcr%3A2971d640-ec36-434b-ab84-e310a8b1ea07/Regulamin%20naszej%20oferty%20Oferta%20dynamiczna%20II%20dla%20domu%20obowi%C4%85zuj%C4%85cy%20od%201%20listopada%202025%20roku.pdf

`PL.ENEA.CENY_DYNAMICZNE.DI12011227_G` models ENEA's `DI12011227_G` Ceny Dynamiczne cennik as hourly RDN Fixing I plus `0.0050 PLN/kWh` excise and `0.0820 PLN/kWh` cost/margin component, therefore `add = 0.0870 PLN/kWh` net. Its monthly supplier fee is also a separate overridable component. Source: https://www.enea.pl/media/9061/cennik-oferty-ceny-dynamicznedi12011227god-01072026-do-30092026pdf.pdf

For these offers, top-level preset validity records the availability window of that offer edition. Their internal price rule is intentionally open-ended because the actual contract start/end belongs to the user's CostPlan period.

TAURON Dynamiczne/Dynamiczne MAX and the investigated PGE dynamic offer are intentionally not bundled yet. Their published settlement rules include behavior such as billing-period minimum/maximum prices or a floor applied to negative market prices, which cannot be represented faithfully by the current per-reference affine formula `reference * multiplier + add`. Add the required rate/aggregation semantics before adding those named offers rather than approximating them with a misleading preset.

## Component identity

A `CostComponentKind` is a compatibility role, not a component identity. More than one component of the same kind may exist in one plan period as long as their `componentId` values are distinct. Preset-backed components always declare an ID; inline periodic components may declare one, and otherwise retain their legacy kind-derived ID. `CostPlanCompiler` validates compatibility by component category and quantity semantics rather than by a hard-coded `kind -> componentId` mapping.
