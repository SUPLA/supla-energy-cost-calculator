# AGENTS.md

## Project purpose

This repository contains a framework-agnostic PHP library for calculating electricity costs from:

1. interval energy measurements,
2. a JSON billing/tariff definition,
3. optional external reference data such as market prices or dynamic grid zones.

The library is intended to be installed with Composer, initially inside `supla-cloud`, but it must remain portable
enough to be used later inside a standalone HTTP service or another PHP application.

The library is a **calculation engine**, not a SUPLA-specific application.

---

## Core architectural rule

Keep the calculation engine independent from:

* Symfony,
* Doctrine ORM,
* `supla-cloud` entities,
* `supla-cloud` database table names,
* HTTP,
* specific electricity providers,
* specific Polish tariff names such as G11, G12 or G14dynamic.

The core library operates only on its own contracts and value objects.

Integration with `supla-cloud` must be implemented through adapters outside the core package.

---

## Mental model

Do not model an electricity tariff as a single price or as a single global tariff type.

A billing definition consists of independent components.

Conceptually:

```text
BillingDefinition
    |
    +-- periods[]
          |
          +-- components[]
                |
                +-- category
                +-- quantity
                +-- selector
                +-- rate
```

Each component answers three independent questions:

```text
1. What quantity is charged?
2. Which rate/zone applies at this time?
3. What is the value of that rate?
```

Examples:

```text
Energy purchase
    quantity = imported active energy
    selector = always
    rate = Fixing1 + margin

Network distribution G12
    quantity = imported active energy
    selector = day/night schedule
    rate = zoned

Network distribution G14dynamic
    quantity = imported active energy
    selector = external PDGSZ value
    rate = zoned

Commercial fee
    quantity = month
    selector = always
    rate = constant
```

Do not introduce a global distinction such as:

```text
STATIC tariff
vs
DYNAMIC tariff
```

Different components of the same billing definition may use different mechanisms simultaneously.

For example this is a valid configuration:

```text
energy:
    Fixing1 + margin

distribution:
    G14dynamic / PDGSZ

commercial fee:
    fixed monthly amount
```

---

## Important domain rule: component independence

Dynamic pricing and dynamic zone selection are different concepts.

Example:

```text
Fixing1
```

is usually a dynamic **rate source**.

```text
PDGSZ
```

is a dynamic **selector source**.

They may use the same infrastructure for retrieving external time-series values, but they have different domain
meanings.

Do not hard-code Fixing1, Fixing2 or PDGSZ into `CostCalculator`.

They should be addressed through generic reference identifiers such as:

```text
PL.TGE.FIXING1
PL.TGE.FIXING2
PL.PSE.RCE
PL.PSE.PDGSZ
```

The calculator should request these values through `ReferenceDataSource`.

---

## Data source contracts

The engine must not query databases directly.

It receives measurement data through a contract similar to:

```php
interface EnergyDeltaSource
{
    /**
     * @return iterable<EnergyDelta>
     */
    public function getDeltas(
        string $meterId,
        TimeRange $range,
    ): iterable;
}
```

External values are provided through:

```php
interface ReferenceDataSource
{
    /**
     * @return iterable<ReferenceInterval>
     */
    public function get(
        ReferenceDataId $id,
        TimeRange $range,
    ): iterable;
}
```

The concrete implementation may use:

* MariaDB,
* PostgreSQL,
* Doctrine DBAL,
* HTTP,
* CSV,
* memory,
* another service.

The engine must not care.

---

## SUPLA integration

In the initial `supla-cloud` integration:

```text
EnergyDeltaSource
    ->
supla_em_delta_log
```

and:

```text
ReferenceDataSource
    ->
supla_energy_price_log
```

The SUPLA adapters belong to `supla-cloud` or to a separate integration package.

Do not import classes such as:

```php
App\Entity\MeasurementLogs\ElectricityMeterDeltaLogItem
App\Entity\MeasurementLogs\EnergyPriceLogItem
```

into the core calculator package.

Convert SUPLA records into library-owned DTOs/value objects at the adapter boundary.

---

## Existing SUPLA data

The SUPLA delta table contains 15-minute electricity deltas.

Relevant measurements include, among others:

```text
phase1_fae
phase2_fae
phase3_fae

phase1_rae
phase2_rae
phase3_rae

phase*_fre
phase*_rre

fae_balanced
rae_balanced
```

SUPLA raw active/reactive energy values currently use a scale of:

```text
100000 raw units = 1 kWh
```

That conversion belongs in the SUPLA adapter, not in the core engine.

The core engine should receive meaningful quantities using explicit units.

---

## External SUPLA reference data

`supla_energy_price_log` already contains time intervals with data including:

```text
rce
pdgsz
fixing1
fixing2
```

Typical identifiers used by the calculator may be:

```text
PL.PSE.RCE
PL.PSE.PDGSZ
PL.TGE.FIXING1
PL.TGE.FIXING2
```

The mapping between these identifiers and SUPLA database columns belongs to `SuplaReferenceDataSource`.

The core library must not know the column names.

---

## PDGSZ

PDGSZ currently represents four dynamic levels:

```text
0 -> recommended use
1 -> normal use
2 -> recommended saving
3 -> required limitation
```

Do not hard-code these semantic meanings into the generic reference-data infrastructure.

A tariff definition may map the external values to tariff zones, for example:

```json
{
  "selector": {
    "type": "REFERENCE",
    "source": "PL.PSE.PDGSZ",
    "mapping": {
      "0": "S1",
      "1": "S2",
      "2": "S3",
      "3": "S4"
    }
  }
}
```

The rate definition then assigns monetary rates to `S1`-`S4`.

---

## Time model

Time handling is critical.

Use:

```php
DateTimeImmutable
```

internally whenever practical.

Measurement and reference intervals should be treated as half-open ranges:

```text
[from, to)
```

Example:

```text
12:00 <= timestamp < 12:15
```

Do not invent implicit timezone conversions.

Raw storage may be UTC, while schedule-based tariff rules may require a local timezone such as:

```text
Europe/Warsaw
```

Timezone must be explicit in schedule definitions.

Daylight-saving transitions must be considered when implementing local-time schedules.

---

## Billing history

A single meter may have different billing rules over time.

Never assume:

```text
meter -> one tariff
```

Instead model:

```text
meter
    ->
billing periods
    ->
definition valid during each period
```

The JSON definition supports or should support periods such as:

```json
{
  "periods": [
    {
      "validFrom": "2026-01-01T00:00:00Z",
      "validTo": "2026-07-01T00:00:00Z",
      "components": []
    },
    {
      "validFrom": "2026-07-01T00:00:00Z",
      "validTo": null,
      "components": []
    }
  ]
}
```

Historical calculations must remain reproducible.

Do not mutate an old pricing period to represent a new contract.

Add a new period instead.

---

## Quantity model

Do not use a single global concept such as:

```text
chargeableKWh = max(import - export, 0)
```

for all components.

Each component defines what quantity it charges.

Examples may include:

```text
ACTIVE_ENERGY_IMPORT
ACTIVE_ENERGY_EXPORT
ACTIVE_ENERGY_BALANCED_IMPORT
ACTIVE_ENERGY_BALANCED_EXPORT
REACTIVE_ENERGY
CONTRACTED_POWER
MAX_DEMAND
PERIOD
DAY
MONTH
```

The current first implementation may support only a subset.

Adding new quantity models should not require changing unrelated rate or selector code.

Prefer strategy implementations.

---

## Selector model

Selectors determine which zone or variant applies.

Typical selector types:

```text
ALWAYS
WEEKLY_SCHEDULE
CALENDAR
REFERENCE
```

Possible future types:

```text
SEASONAL_SCHEDULE
HOLIDAY_AWARE_SCHEDULE
EXTERNAL_SCHEDULE
LOCATION
```

A selector returns a selection/zone identifier.

It does not calculate money.

Example:

```text
timestamp
    ->
REFERENCE selector using PL.PSE.PDGSZ
    ->
"2"
    ->
mapping
    ->
"S3"
```

---

## Rate model

Rates determine the monetary/unit price.

Typical rate types:

```text
CONSTANT
ZONED
REFERENCE
FORMULA
```

Examples:

```text
CONSTANT
0.65 PLN/kWh
```

```text
ZONED
DAY   = 0.32 PLN/kWh
NIGHT = 0.14 PLN/kWh
```

```text
REFERENCE
PL.TGE.FIXING1
```

or:

```text
REFERENCE + transformation
Fixing1 * 0.001 + 0.05 PLN/kWh
```

Do not mix selector responsibilities into rate resolvers unless the rate definition explicitly requires a selection
produced by a selector.

---

## Unit conversion

Units must be explicit.

Examples:

```text
kWh
MWh
kW
PLN/kWh
PLN/MWh
EUR/kWh
month
day
```

Fixing values stored by SUPLA are currently expressed as:

```text
PLN/MWh
```

while meter deltas are normally evaluated in:

```text
kWh
```

Therefore a transformation such as:

```text
PLN/MWh * 0.001 = PLN/kWh
```

may be required.

Do not scatter magic conversion constants through calculation code.

Prefer explicit transformations or unit-conversion utilities.

---

## Decimal arithmetic

Electricity-cost calculation should not rely permanently on binary floating-point arithmetic.

The starter may currently use a simple `DecimalMath` implementation backed by PHP floats for convenience.

Keep arithmetic behind the abstraction.

Future work should preferably migrate monetary/decimal arithmetic to something like:

```text
brick/math
```

or another deterministic decimal implementation.

Avoid introducing new direct floating-point calculations outside the math abstraction.

---

## Calculation flow

The intended calculation flow is:

```text
JSON
    |
    v
parse + validate
    |
    v
BillingDefinition
    |
    v
load reference data required by the definition
    |
    v
stream EnergyDelta records
    |
    v
find applicable billing period
    |
    v
for each component:
    resolve quantity
    resolve selector
    resolve rate
    calculate component cost
    |
    v
aggregate CalculationResult
```

Do not query external/reference data separately for every measurement interval.

---

## Performance rules

A typical meter produces approximately:

```text
96 delta records / day
~35,000 records / year
```

This is small enough to calculate interactively if the implementation is careful.

Important rules:

1. Stream deltas with `iterable` where possible.
2. Do not hydrate thousands of Doctrine entities inside the library.
3. Load external reference intervals once for the requested range.
4. Index/cache reference intervals in memory for repeated lookup.
5. Parse and compile the JSON definition once per calculation.
6. Do not repeatedly interpret the raw JSON for every delta.
7. Avoid N+1 database queries.
8. Do not add database-level hourly/daily measurement aggregates prematurely.

The 15-minute delta data should remain the canonical measurement source.

If performance later requires caching, prefer caching calculated facts/results tied to a definition version/hash rather
than destroying measurement resolution.

---

## Caching and reproducibility

If calculated costs are cached in the future, the cache identity must include enough information to reproduce the
result.

At minimum consider:

```text
meter
time range / interval
billing definition hash/version
reference data version/revision
calculator version if required
```

Do not cache solely by:

```text
meter + timestamp
```

because the same measurement may be calculated against several tariff definitions.

---

## Calculation results

Prefer returning enough detail for debugging and user-facing explanations.

A useful component result may contain:

```text
component id
category
time interval
quantity
quantity unit
selected zone
rate
rate unit
reference source if used
calculated amount
currency
```

The aggregated result may additionally contain:

```text
total usage
total cost
cost by component
cost by category
cost by zone
```

The calculator should make it possible to answer:

> Why did this interval cost this amount?

Avoid returning only one opaque total.

Auditability is important.

---

## Error handling

Do not silently guess missing data.

Examples that should produce an explicit error/warning depending on policy:

```text
missing reference price
missing PDGSZ interval
unknown zone mapping
unsupported quantity type
unsupported unit conversion
overlapping billing periods
gap between billing periods
invalid timezone
malformed JSON definition
```

Prefer domain exceptions with useful context.

For example:

```text
MissingReferenceDataException
UnsupportedRateTypeException
UnsupportedQuantityTypeException
InvalidBillingDefinitionException
```

Do not silently use zero unless the definition explicitly requests such fallback behavior.

---

## JSON definitions

Treat JSON as an external serialized representation.

The calculation engine should operate on typed PHP objects after parsing.

Do not pass arbitrary associative arrays throughout the engine.

Preferred direction:

```text
JSON
  ->
DefinitionParser
  ->
BillingDefinition
  ->
BillingPeriodDefinition
  ->
ComponentDefinition
  ->
QuantityDefinition
  ->
SelectorDefinition
  ->
RateDefinition
```

Validate JSON before calculation.

Keep `schema/billing-definition.schema.json` synchronized with parser behavior.

If schema and implementation disagree, fix both in the same change.

---

## Extending the engine

When adding a new mechanism, prefer adding a strategy instead of another branch in `CostCalculator`.

Bad:

```php
if ($type === 'fixing1') {
    ...
} elseif ($type === 'g14dynamic') {
    ...
} elseif ($type === 'g12') {
    ...
}
```

Good:

```text
QuantityResolverRegistry
SelectorResolverRegistry
RateResolverRegistry
```

or equivalent explicit strategy composition.

The core calculator should remain orchestration code.

---

## Country-specific tariffs

Names such as:

```text
G11
G12
G12w
G13
G14dynamic
```

are presets/definitions, not engine types.

A future application may expose:

```text
Poland
 -> Tauron
 -> G14dynamic
```

to users, but this should eventually compile into generic component definitions understood by this library.

The same engine should be usable for tariffs from other European countries.

Do not introduce Polish-specific concepts into generic engine classes unless they are represented as data/reference
sources.

---

## Framework dependencies

The core package should remain lightweight.

Avoid adding:

```text
symfony/framework-bundle
doctrine/orm
doctrine/doctrine-bundle
```

as runtime dependencies.

Small standalone packages are acceptable if they solve a real library-level problem.

Before adding a dependency, ask whether it belongs in:

```text
the core calculator
```

or:

```text
the host application adapter
```

Database integration normally belongs in the host application.

---

## Composer / PHP compatibility

The initial target is compatible with current `supla-cloud`, which uses PHP 8.2.

Use modern PHP features where they improve clarity:

```text
readonly classes/properties
enums
constructor property promotion
strict typing
DateTimeImmutable
```

Do not introduce a higher minimum PHP version without intentionally coordinating it with the host application.

---

## Tests

Core calculator tests should not require:

```text
Symfony kernel
Doctrine
MariaDB
PostgreSQL
network access
```

Use in-memory sources.

Important scenarios to maintain/add tests for:

### Constant energy price

```text
import energy * constant PLN/kWh
```

### Fixing1

```text
import energy
*
Fixing1 converted PLN/MWh -> PLN/kWh
+
margin
```

### Static time zones

Example:

```text
G12-like DAY/NIGHT selector
```

### Dynamic zones

Example:

```text
PDGSZ
0 -> S1
1 -> S2
2 -> S3
3 -> S4
```

### Mixed mechanisms

This is especially important:

```text
ENERGY:
Fixing1 + margin

NETWORK:
PDGSZ / G14dynamic
```

The system must support both mechanisms concurrently.

### Billing definition changes over time

Example:

```text
Jan-Jun:
Fixing1 + G12

Jul-Dec:
fixed energy + G14dynamic
```

### Fixed fees

Examples:

```text
monthly commercial fee
daily fee
```

### Missing reference data

Calculation must not silently produce a plausible but incorrect cost.

### Timezones and DST

Include tests around:

```text
Europe/Warsaw spring DST transition
Europe/Warsaw autumn DST transition
```

when schedule selectors are implemented.

---

## Test style

Tests should describe domain behavior.

Prefer:

```text
test_it_combines_fixing1_energy_price_with_pdgsz_distribution_zones
```

over implementation-focused names such as:

```text
test_reference_resolver_method
```

A refactor should not require rewriting every test if business behavior remains unchanged.

---

## Do not reintroduce these assumptions

Previous experiments in `supla-cloud` made assumptions that this package is intended to avoid.

Do NOT assume:

```text
a tariff is globally static or dynamic
```

Do NOT assume:

```text
one global zone applies to all components
```

Do NOT assume:

```text
every cost component is quantity_kwh * rate
```

Do NOT assume:

```text
chargeable energy is always max(import - export, 0)
```

Do NOT assume:

```text
one meter has one immutable tariff forever
```

Do NOT assume:

```text
all components use the same aggregation period
```

Do NOT assume:

```text
market price resolution equals measurement resolution
```

Do NOT assume:

```text
dynamic price and dynamic zone are the same concept
```

These are architectural invariants of this project.

---

## Preferred development approach

When implementing a new feature:

1. Start from a real billing example.
2. Express it using generic `quantity + selector + rate`.
3. Add/update JSON schema.
4. Add parser support.
5. Add a focused strategy implementation.
6. Add in-memory tests.
7. Ensure existing mixed-tariff tests still pass.
8. Only then add host-application integration if necessary.

Avoid adding generic abstraction without at least one concrete tariff/use case that requires it.

---

## Examples of good abstractions

Good:

```text
ReferenceDataSource
ReferenceRate
ReferenceSelector
ZonedRate
WeeklyScheduleSelector
EnergyQuantityResolver
PeriodicQuantityResolver
```

Usually bad:

```text
PolishG12Calculator
G14DynamicCalculator
Fixing1Calculator
TauronCalculator
```

Country/provider-specific definitions should usually be data/configuration.

---

## Repository orientation

Important directories should conceptually have these responsibilities:

```text
src/Contract
    ports implemented by host applications

src/Model
    value objects and core domain values

src/Definition
    JSON parsing, validation and typed definitions

src/Engine
    orchestration of calculations

src/Quantity
    quantity calculation strategies

src/Selector
    zone/variant selection strategies

src/Rate
    rate resolution strategies

schema
    public JSON schema

examples
    examples and host-integration sketches

tests
    framework-independent domain tests

docs
    architecture and design decisions
```

If the actual directory layout changes, update this file.

---

## Documentation rule

If you change a public concept, update the relevant documentation in the same task.

Especially keep synchronized:

```text
README.md
AGENTS.md
docs/architecture.md
schema/billing-definition.schema.json
examples/
```

---

## Before finishing a change

Run at least:

```bash
composer validate
composer test
```

and syntax checks if needed.

If additional static analysis tools are introduced, update this section.

Verify:

```text
- no Symfony dependency leaked into core
- no Doctrine entity leaked into core
- JSON schema matches parser behavior
- missing external data has explicit behavior
- mixed dynamic/static components still work
- calculations remain explainable per component
```

---

## Guidance for AI coding agents

When the requested change seems to require a special case for a named tariff, first determine whether it can be
represented by the existing generic model.

Before modifying the architecture, inspect:

```text
README.md
docs/architecture.md
schema/billing-definition.schema.json
existing tests
```

Prefer extending existing strategies over modifying the central calculator.

Do not make broad architectural changes only to reduce a small amount of code.

Do not couple the package to `supla-cloud` for convenience.

When uncertain, preserve these priorities in order:

```text
1. correctness
2. reproducibility
3. explicit domain semantics
4. extensibility
5. testability
6. performance
7. convenience
```

Performance optimizations must not make billing semantics implicit or lose the original 15-minute measurement
resolution.

The long-term goal is that the same package can be used:

```text
inside supla-cloud via Composer
```

and later:

```text
inside a standalone calculation service via Composer + HTTP wrapper
```

without changing the core calculation model.
