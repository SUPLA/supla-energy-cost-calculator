# Tariff fixtures

These files are regression fixtures for tariff **shapes and calculation mechanisms**, not an official tariff catalogue or price list.

The G11/G12/G13 schedules intentionally model the relevant Polish/TAURON-style structures used by the tests, while monetary rates are synthetic and chosen for readable assertions. G14dynamic tests the PDGSZ-to-zone mechanism with synthetic rates.

Real presets must be versioned by operator and validity period because schedules and rates can differ between Polish DSOs and over time.


## Scenario format

A YAML case may explicitly define the analytical request as well as the meter/reference facts:

```yaml
cases:
  example:
    query:
      from: '2026-01-19T00:00:00+01:00'
      to: '2026-01-26T00:00:00+01:00'
    deltas:
      - { datetime: '2026-01-19T00:15:00+01:00', import: '1' }
    expected:
      result:
        costs:
          usageBased: { total: '0.8' }
          periodic: { total: null }
          total: null
```

`expected.result` is matched as a recursive subset of the serialized `CalculationResult`, so a case can assert only the fields relevant to the scenario. This gives the fixtures the form: **rules + logs/reference data + query -> expected result**.
