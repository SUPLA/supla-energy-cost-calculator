# Polish household tariff coverage

The bundled catalogue focuses on current household groups that can be represented faithfully by the existing selector/rate/quantity DSL. A starter is provided when there is a sensible incumbent-supplier + OSD default combination.

## Bundled simple paths for 2026

| OSD | Starter tariff groups |
| --- | --- |
| TAURON Dystrybucja | G11, G12, G12w, G13, G14dynamic |
| PGE Dystrybucja | G11, G12, G12w, G12n |
| ENEA Operator | G11, G12, G12w, G12sezON, G13active |
| Energa-Operator | G11, G12, G12w, G12r |
| Stoen Operator | G11, G12, G12w |

G12w is operator-specific: the inexpensive weekend/holiday zone is common, but weekday hours differ. PGE additionally changes its two-hour off-peak window seasonally. Each OSD therefore has its own G12w preset rather than a shared schedule.

ENEA G12sezON reuses `PL.ENEA.G11.2026` for the starter's energy-purchase component. In ENEA's standard 2026 tariff both G12sezON energy zones have the same `0.5030 PLN/kWh` net price as G11, while distribution remains genuinely time-dependent.

## Intentionally not bundled as simple defaults

- **G12as** (multiple OSDs): the discounted quantity depends on consumption in the analogous period of the previous year. The current DSL intentionally has no history-dependent quantity baseline.
- **PGE G12e**: the 2026 tariff has an area-dependent variant whose time zones are published separately by the operator. A single nationwide preset would be misleading; model this only after the applicable area/schedule becomes explicit configuration.
- **TAURON G13s**: the distribution schedule is representable (season + workday/free-day + three zones), but there is no bundled incumbent-supply mapping in this catalogue yet. It can be added later as an advanced distribution-only preset without changing the DSL.
- **Stoen G12eko**: introduced for use from October 2026 and not included in the simple starter set yet. Add it together with a deliberate supply/default-product decision rather than guessing one.
- **Energa G11f** and offer-specific smart/dynamic products: keep these as named offers when their actual price source and contract semantics are known instead of treating the group name alone as a complete price definition.

This coverage list is deliberately narrower than the set of all group names published by Polish OSDs. Missing groups should be added when their calculation semantics and a useful default composition are unambiguous; they should not be approximated with a superficially similar schedule.
