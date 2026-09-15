# Scheduled tariff zones

`WEEKLY_SCHEDULE` supports both the original shorthand rule form and the richer schedule model ported from `supla-cloud` `issue-307`.

## Rich rule form

```json
{
  "type": "WEEKLY_SCHEDULE",
  "timezone": "Europe/Warsaw",
  "calendar": "PL_PUBLIC_HOLIDAYS",
  "seasons": [
    {"id": "SUMMER", "from": "--04-01", "to": "--10-01"},
    {"id": "WINTER", "from": "--10-01", "to": "--04-01"}
  ],
  "rules": [
    {
      "zone": "OFF_PEAK",
      "days": ["HOLIDAY"],
      "season": "*",
      "priority": 100,
      "time_ranges": [{"from": "00:00", "to": "24:00"}]
    },
    {
      "zone": "OFF_PEAK",
      "days": ["MON", "TUE", "WED", "THU", "FRI"],
      "season": "WINTER",
      "time_ranges": [
        {"from": "21:00", "to": "07:00"},
        {"from": "13:00", "to": "16:00"}
      ]
    }
  ]
}
```

Seasons are recurring half-open ranges `[from, to)`. A season whose `from` is later in the calendar year than its `to` wraps across New Year. Equal `from` and `to` represents the full year, matching the legacy resolver semantics.

A rule may use `season: "*"` or omit `season` to apply in every season. Lower numeric `priority` wins; ties are resolved by rule order.

## Time ranges crossing midnight

A time range whose end is not later than its start crosses midnight:

```json
{"from": "22:00", "to": "06:00"}
```

The range belongs to the day on which it starts. Therefore a Monday rule from `22:00` to `06:00` also matches Tuesday at `02:00`. Holiday and season matching use that starting day, preserving the behavior from the `issue-307` tariff resolver.

`24:00` is accepted only as a range end.

## Backwards-compatible shorthand

The initial syntax remains valid:

```json
{
  "zone": "DAY",
  "days": ["MON", "TUE", "WED", "THU", "FRI"],
  "from": "06:00",
  "to": "22:00"
}
```

A rule must use either `from`/`to` or `time_ranges`, not both.
