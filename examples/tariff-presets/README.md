# Frontend tariff presets (Poland, 2026)

These files are UI-oriented starting points for building a calculator `BillingDefinition`. They are deliberately not a second calculation language. A frontend should clone `billingDefinitionTemplate`, ask the user for the fields from `inputs[]`, write each value to every JSON Pointer listed in `targets[]`, and then validate the completed object with `schema/billing-definition.schema.json`.

## Scope

The preset describes the selected Polish distribution tariff group (OSD) and provides editable 2026 net defaults for the variable distribution rate. It also contains a minimal `energy-purchase` component so the first UI can produce a complete calculator definition. Energy sale prices are always user inputs. Fixed network fees, subscription fees, quality/OZE/cogeneration/capacity charges, phase-dependent fees and tax are intentionally outside this first preset set.

The `energy-purchase` component in G12/G13 examples mirrors the distribution zones as a UI convenience. Supply and distribution are independent in the calculator. If the user's seller uses a different price structure, the frontend should replace the energy component instead of changing the distribution selector. G14dynamic illustrates this explicitly: PDGSZ controls only the distribution component, while the example energy component is a separate constant user rate.

All bundled distribution defaults are **net PLN/kWh** and can be overwritten by the user. They are snapshots for 2026, not an evergreen tariff catalogue.

## First frontend flow

1. Use `TariffPresetCatalog::presets()` and let the user choose OSD + tariff group.
2. Load the selected preset with `TariffPresetCatalog::get()`.
3. Render `inputs[]`. The current value at the first target can be used as the initial form value; `null` means the user must provide it.
4. Persist a cost-plan entry containing the stable `presetId`, optional user-specific effective-range constraints, and only user-specific values/explicit overrides. Omitted range boundaries inherit the preset validity.
5. Use `CostPlanCompiler` to turn the persisted cost plan into the executable `BillingDefinition`.

Do not persist a copied preset template as the authoritative user plan. Compatible corrections to an existing preset ID are intentionally picked up the next time the plan is compiled. A real tariff change is published under a new preset ID and should become a new cost-plan entry.

Values already present in the preset template are inherited when omitted from `values`. Frontends should therefore avoid blindly persisting every initial form value: persist required user-specific values and fields the user actually overrides. This is what lets a corrected package default flow into existing plans.

`TariffPresetCompiler` owns input validation and JSON Pointer application. `apply-preset.js` remains only a dependency-free illustration of the pointer mechanics. `compiled-examples/` contains four synthetic, fully filled BillingDefinition results for G11, G12, G13 and G14dynamic. The energy prices in those compiled files are examples only.

## Presets

- TAURON Dystrybucja: G11, G12, G13, G14dynamic
- PGE Dystrybucja: G11, G12
- ENEA Operator: G11, G12, G13active
- Energa-Operator: G11, G12
- Stoen Operator: G11, G12

G11 is structurally the same across these OSDs, but separate presets are kept because the editable distribution defaults differ.

G12 differs materially for PGE: the two-hour daytime low-price window is `15:00-17:00` in summer and `13:00-15:00` in winter. TAURON, Energa and Stoen use `13:00-15:00` plus `22:00-06:00` throughout the year. ENEA specifies the durations/allowed windows and states that exact clock hours are determined by the operator. The ENEA preset therefore exposes the four schedule boundaries as form inputs; its initial `22:00-06:00` and `13:00-15:00` values must be confirmed against the meter/contract.

For the requested three-zone group there are two different current products worth modelling separately: TAURON `G13` and ENEA `G13active`. Do not rename ENEA `G13active` to plain `G13`; its monthly zone schedule is different.

The requested G14 dynamic example is TAURON `G14dynamic`, whose distribution zone comes from `PL.PSE.PDGSZ` and maps values `0..3` to `S1..S4`.

## Time-zone caveat

The calculator schedules use `Europe/Warsaw`. Several OSD tariff documents note that legacy tariff clocks may remain on winter time unless the meter can automatically maintain tariff-zone hours across summer/winter time. If a real meter uses that legacy behavior, the UI should eventually offer a meter-specific schedule override instead of silently changing the canonical preset.
