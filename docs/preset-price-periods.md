# Okresy cenowe w presetach

## Format i otwarte granice

Źródłem wykonawczym historii cen jest obecnie `billingDefinitionTemplate.periods[]`
wewnątrz presetu. Okresy te NIE są okresami taryf (`CostPlan.periods[]`)
wybieranymi przez użytkownika. Granice są półotwarte `[validFrom, validTo)`.

- **Pierwszy okres** ma `validFrom: null`, a **ostatni** `validTo: null`.
  Oznacza to świadome używanie najstarszej znanej stawki również dla wcześniejszych
  dat, a najnowszej stawki dla wszystkich przyszłych dat aż do publikacji zmiany.
- Pozostałe okresy mają jawne, sąsiadujące ze sobą daty i nie tworzą luk.
- `validFrom` i `validTo` na poziomie głównym presetu również są `null`:
  preset nie narzuca użytkownikowi sztucznego zakresu lat 2025-2026.
- Nie potrzebujemy `priceHistoryCoverage`: kompletność i rozciąganie okresów
  wynika z ich granic. Dane mogą być orientacyjne dla dat poza zweryfikowanym
  zakresem obowiązywania stawek; to celowo preferowane nad błędem obliczenia.

Każdy okres ma własne `components[]` (ograniczenie obecnego DSL). Cena każdego
składnika w każdym okresie otrzymuje **osobny input** skierowany do dokładnie
jednej stawki, np.:

```json
{
  "id": "distribution.rate.2025",
  "type": "DECIMAL",
  "targets": ["/periods/0/components/0/rate/value"],
  "required": true
}
```

Dla roku 2026 input `distribution.rate.2026` wskazuje na
`/periods/1/components/0/rate/value`. Dla `ZONED` każda strefa ma odrębny
input w każdym okresie. Dzięki temu zmiana ceny w jednym okresie nie nadpisuje
stawek w innym. Dotyczy to również opłat wyzerowanych: wartość `0.00` może
być ręcznie nadpisana w odpowiednim okresie.

Nazwa i identyfikator taryfy nie zawierają roku. Katalog używa stałych ID
(np. `PL.TAURON_DYSTRYBUCJA.G11`) i aktualizuje historię wewnątrz presetu.
Wszystkie standardowe taryfy dystrybucyjne i sprzedażowe używają stałych ID;
identyfikator nie zawiera roku. Oferta dynamiczna może zachować datę w ID,
jeżeli oznacza konkretną edycję kontraktu. Nie stosujemy aliasów ani migracji
nazw inputów (brak istniejących planów użytkowników).

## Zweryfikowane zmiany i przybliżenia

- 2025 TAURON G11: dystrybucja zmienna 0,2541 PLN/kWh;
  G12: DAY 0,2899; NIGHT 0,0609 PLN/kWh (netto).
- 2026 TAURON G11: 0,2464; G12: DAY 0,2841; NIGHT 0,0558 PLN/kWh
  (wartości już znajdujące się w katalogu).
- Stała sieciowa dla układu 1-fazowego: 2025 7,02; 2026 7,38 PLN/mies.
- Opłata mocowa dla progu >1200-2800 kWh: do 2025-06-30 0,
  2025-07-01-2025-12-31 11,44, od 2026 17,18 PLN/mies.
- Opłata abonamentowa 4,56 PLN/mies. do 2026-09-30;
  od 2026-10-01: 0,00 PLN/mies. (wcześniej istniejąca reguła 2026).

Stawki z okresu 2025-2026 są udokumentowane źródłami, ale `null` na
zewnętrznych granicach **nie oznacza**, że te stawki rzeczywiście obowiązywały
w każdym poprzednim i przyszłym roku. Jest to przyjęta polityka fallback.

### Źródła

- [TAURON, taryfa 2025](https://www.tauron-dystrybucja.pl/-/media/offer-documents/dystrybucja/archiwum-taryf/2025/taryfa-td_sa-na-rok-2025.ashx)
- [TAURON, taryfa 2026](https://www.tauron-dystrybucja.pl/-/media/offer-documents/dystrybucja/aktualna-taryfa/taryfa-tauron-dystrybucja-2026_ocr.ashx)
- [URE, opłata mocowa - stawki historyczne](https://www.ure.gov.pl/pl/energia-elektryczna/ceny-wskazniki/9165,dok.html)
- [URE, kalkulacja taryf OSD 2025 i stawka 0 w I półroczu](https://www.ure.gov.pl/download/9/15205/InformacjawsprawiekalukacjitaryfOSDna2025r.pdf)

Nie dodano taryf sprzedażowych za 2025 r. na podstawie samych cen
maksymalnych: limity ustawowe i nominalne ceny ofertowe to inne pojęcia.
Nie dodano także przyszłych podwyżek bez potwierdzonych taryf.

**Pozostające ograniczenie silnika:** przy zmianie stawki opłaty okresowej
w środku cyklu rozliczeniowego `prorate: false` może powodować błąd naliczania;
to zagadnienie jest niezależne od otwartych granic historii cen.

## Struktura katalogu

- `resources/tariff-presets/PL/distribution/<OSD>/` – taryfy i stawki dystrybucyjne (także G14dynamic TAURON).
- `resources/tariff-presets/PL/supply/<SPRZEDAWCA>/` – taryfy sprzedaży energii.
- `resources/tariff-presets/PL/dynamic/<SPRZEDAWCA>/` – wydzielone edycje ofert dynamicznych; nazwa lub rok w ID może identyfikować edycję.

Pozostałe presety przeniesione z `PL/2026` zachowują obecne domyślne ceny; ich pierwszy i ostatni okres jest otwarty. Nowe źródła i okresy cenowe można dołączać do tych samych plików.
