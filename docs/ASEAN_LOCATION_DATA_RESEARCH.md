# ASEAN State/Province and Boundary Dataset Research

| Item | Value |
|---|---|
| Project | DisasterLink SEA |
| Purpose | Choose the Country → State/Province (ADM1) dataset and boundary geometry for registration dropdowns and disaster-to-province matching |
| Verification date | 9 November 2026 |
| Scope | ADM1 only (State/Province). No cities, townships or districts are stored. ADM2 data was examined only where needed to *derive* a current ADM1 unit. |
| Status | Research only. No application code, migrations, seeders or configuration were changed. |

> **How the data was verified.** geoBoundaries' metadata file (`releaseData/geoBoundariesOpen-meta.csv`) and the simplified GeoJSON files were downloaded directly from the geoBoundaries GitHub release data (`wmgeolab/geoBoundaries`, release commit `4004e96`, build dates July–December 2023). Each file was checked with Shapely 2.2: record count, unique IDs, geometry type, validity and coordinate system.
>
> The geoboundaries.org website and the HDX portal could not be opened from the research environment. Facts that depend on them are marked **Unverified**.
>
> Current administrative structures were checked against government or official-press sources where possible, and against Wikipedia otherwise.

---

## 1. Executive summary

1. **Recommended base dataset: geoBoundaries "gbOpen" ADM1** (simplified GeoJSON).
   - Every country has valid polygons: 0 invalid geometries across 11 countries.
   - Coordinates are WGS84 (CRS84).
   - The data is free to download, and every licence it uses allows modification and redistribution with attribution.
   - It is the only open source checked that covers all 11 countries in one consistent format.
2. **But geoBoundaries is out of date for 5 of the 11 countries.** Using it as-is would not give correct, complete ASEAN coverage.

   | Country | geoBoundaries has | Reality today |
   |---|---|---|
   | **Vietnam** | 64 units representing **2008** | **34 units since 1 July 2025** |
   | **Indonesia** | 34 provinces (2017) | **38** (4 new Papua provinces, 2022) |
   | **Philippines** | ADM1 = 17 **regions** (2020) | People think in **provinces** (82 per PSA, March 2025). Region list also missing the 2024 Negros Island Region. |
   | **Myanmar** | 14 | **15**: Naypyidaw Union Territory is missing; its area sits inside "Mandalay" |
   | **Timor-Leste** | 13 | **14**: Atauro became a municipality on 1 Jan 2022; its area sits inside "Dili" |

3. **Most gaps can be fixed from geoBoundaries itself, without another source:**
   - **Vietnam:** the 2025 reform merged *whole* provinces. Merging (dissolving) the old polygons by the official mapping produces **exactly 34 valid polygons**, using all 64 old units once each. (Verified in this research.)
   - **Philippines:** use geoBoundaries **ADM2 = Provinces** (87 records, 2020, NAMRIA). After cleanup this gives **82 selectable records**.
   - **Timor-Leste:** split Atauro out of Dili using the ADM2 administrative-post layer, which contains "Atauro".
   - **Indonesia:** the 4 new provinces can be derived from ADM2 regencies, but the regency → province mapping must be verified against the 2022 laws first (Stage 2).
   - **Myanmar:** Naypyidaw can't be derived, because the ADM2 layer has no Naypyidaw districts. Stage 1 keeps 14 records and documents that Naypyidaw residents select Mandalay.
4. **Licences are mixed by country** (public domain, ODbL 1.0, CC BY 4.0, CC BY 3.0 IGO). All allow use in a free training project. **Attribution is required** for most. **ODbL share-alike applies** to the OpenStreetMap-derived countries (§4).
5. **Proposed Stage 1 (MVP): 319 State/Province records across all 11 countries**, every one with a polygon. 6 countries are current as published, Vietnam and the Philippines are made current by processing, and 3 countries (Indonesia, Myanmar, Timor-Leste) carry known name gaps. Geographic coverage is still complete in those 3, because the new units sit inside existing polygons. **This is not "fully current" coverage, and must not be described as such.** Stage 2 closes the remaining gaps.

---

## 2. Sources investigated

| Source | What it offers | Format | Verified? | Verdict |
|---|---|---|---|---|
| **geoBoundaries gbOpen** (William & Mary geoLab) | ADM0–ADM5 for most countries, with per-country metadata (source, licence, year represented, unit count) | GeoJSON, Shapefile, TopoJSON; full and simplified | **Yes**: metadata + files downloaded and validated | **Recommended base** |
| **OCHA COD-AB** via HDX | Humanitarian "Common Operational Dataset" boundaries with P-codes | Shapefile, geodatabase, tabular | Partial. A catalogue record confirms **Viet Nam COD-AB v02 with 34 provinces** (source boundaries dated 1 July 2025, reviewed 25 September 2025). Licence and files **Unverified** (HDX not reachable). | **Best cross-check for Vietnam**; possible alternative for the Philippines and Indonesia |
| **GADM 4.x** | Global ADM levels | GeoPackage, Shapefile | Licence page verified | **Not recommended.** Free for academic/non-commercial use, but redistribution or commercial use needs prior permission. A seed file in a Git repo, served through a public app, risks counting as redistribution. |
| **Natural Earth Admin-1** | Global states/provinces at 1:10m | Shapefile, GeoJSON | Licence summary verified | Not recommended as the primary source. Public domain, but generalised (coarse at province scale). Three columns derive from GADM. Currency for Vietnam/Indonesia **Unverified**. |
| **OpenStreetMap directly** (Overpass / extracts) | Current boundaries maintained by the community | OSM XML/PBF, convertible | Not checked per country | Future option. Likely the most current, but ODbL and heavy processing. Not needed for the MVP. |
| **Official statistics** (PSA PSGC, Vietnamese National Assembly resolution, Indonesian laws) | Authoritative **names and counts**, no open geometry checked | Web/PDF | Yes, for counts | Used to **verify names and counts**, not geometry |

---

## 3. Country-by-country ADM1 coverage

### 3.1 What geoBoundaries gbOpen contains (verified by downloading the files)

"Year" is geoBoundaries' `boundaryYearRepresented`. "Source updated" is `sourceDataUpdateDate`. All releases were built in 2023.

| Country | Layer used | Records | Level represented | Year | Source updated | Source / licence | Valid geometry | ISO 3166-2 codes | Complete & current? |
|---|---|---|---|---|---|---|---|---|---|
| Brunei | ADM1 | 4 | Districts | 2011 | 2023-05-03 | Wikimedia Commons · **Public Domain** | 4/4 | Yes (BN-BE…) | **Yes**: 4 districts |
| Cambodia | ADM1 | 25 | Provinces + Phnom Penh | 2017 | 2023-01-19 | OSM (Wambacher) · **ODbL 1.0** | 25/25 | Yes (KH-1…) | **Yes**: 24 provinces + capital (Tbong Khmum present) |
| Indonesia | ADM1 | 34 | Provinces | 2017 | 2023-01-19 | OSM (Wambacher) · **ODbL 1.0** | 34/34 | Yes (ID-AC…) | **No**: 38 since 2022. Central Papua, Highland Papua and South Papua sit inside "Papua"; Southwest Papua sits inside "West Papua" (spot-checked with Nabire → Papua, Sorong → West Papua). |
| Laos | ADM1 | 18 | Provinces + capital | 2017 | 2023-01-19 | OSM (Wambacher) · **ODbL 1.0** | 18/18 | Yes (LA-AT…) | **Yes**: 17 provinces + Vientiane Capital (Xaisomboun present) |
| Malaysia | ADM1 | 16 | 13 states + 3 federal territories | 2017 | 2023-01-19 | OSM (Wambacher) · **ODbL 1.0** | 16/16 | Yes (MY-01…) | **Yes** |
| Myanmar | ADM1 | 14 | States/Regions | 2019 | 2023-01-19 | Myanmar Analytics Project / MIMU · **CC BY 4.0** | 14/14 | Yes (MM-01…) | **No**: 15 top-level units (7 regions, 7 states, 1 union territory). Nay Pyi Taw falls inside "Mandalay" (spot-checked). Spelling issues: "Saigang" (Sagaing), "Tanitharyi" (Tanintharyi). |
| Philippines | ADM1 | 17 | **Regions** | 2020 | 2023-01-19 | NAMRIA · **CC BY 3.0 IGO** | 17/17 | Yes (PH-00…) | Wrong level for this app, and outdated: shows "ARMM" (now BARMM) and no Negros Island Region (RA 12000, June 2024). PSA lists **18 regions** as of 31 Mar 2025. |
| Philippines | **ADM2** | 87 | **Provinces** (+ NCR districts, 2 independent cities) | 2020 | 2023-01-19 | NAMRIA · **CC BY 3.0 IGO** | 87/87 | **No** (empty `shapeISO`) | Usable after cleanup (§3.2). Outdated in 2 places: "Compostela Valley" (renamed Davao de Oro, 2019) and a single "Maguindanao" (split into del Norte and del Sur in 2022). |
| Singapore | ADM1 | 5 | URA planning regions | 2016 | 2023-01-19 | URA-derived · **ODbL 1.0** | 5/5 | **No** | Singapore has no states or provinces. The 5 regions are planning regions most residents wouldn't recognise. |
| Thailand | ADM1 | 77 | 76 provinces + Bangkok | 2017 | 2023-01-19 | OSM (Wambacher) · **ODbL 1.0** | 77/77 | Yes (TH-10…) | **Yes** (Bueng Kan present). Names carry a " Province" suffix to strip. |
| Timor-Leste | ADM1 | 13 | Municipalities | 2017 | 2023-01-19 | OSM (Wambacher) · **ODbL 1.0** | 13/13 | Yes (TL-AL…) | **No**: 14 since 1 Jan 2022 (Atauro). Atauro island falls inside "Dili" (spot-checked). |
| Vietnam | ADM1 | 64 | Provinces + municipalities | **2008** | 2023-01-19 | Wikipedia-derived · **Public Domain** | 64/64 | Yes, but **old** codes | **No**: 63 pre-2025 units plus a separate "Côn Đảo" polygon (an island district of Bà Rịa–Vũng Tàu). Since **1 July 2025** Vietnam has **34** provincial-level units. One name contains a stray tab ("Hà Nội\t"). |

### 3.2 Proposed Stage 1 records (what the app would seed)

| Country | Records | How produced | Status flag |
|---|---|---|---|
| Brunei | 4 | As-is | `current` |
| Cambodia | 25 | As-is | `current` |
| Indonesia | **34** | As-is | `outdated_names`. Users in the 4 new provinces must pick "Papua" or "West Papua". Matching still works geographically. |
| Laos | 18 | As-is | `current` |
| Malaysia | 16 | As-is | `current` |
| Myanmar | **14** | As-is, spellings corrected | `outdated_names`. Naypyidaw residents pick "Mandalay". Matching still works geographically. |
| Philippines | **82** | ADM2: the 4 NCR district polygons merged into one "Metro Manila (NCR)"; "City of Isabela" merged into Basilan; "Cotabato City" merged into Maguindanao; "Compostela Valley" renamed "Davao de Oro" | `derived`. Maguindanao is one record covering both 2022 provinces (`outdated_names`). Result: 81 provinces + Metro Manila. |
| Singapore | **1** (recommended) | Merge the 5 regions into one "Singapore" polygon | `derived`. Alternative: keep 5 regions (open question Q4). |
| Thailand | 77 | As-is, " Province" suffix removed | `current` |
| Timor-Leste | **14** | Dili polygon minus the "Atauro" ADM2 administrative post; Atauro from that post | `derived`. Licence of the Atauro piece: CC BY 3.0 IGO (ADM2 source WHO/OCHA). |
| Vietnam | **34** | Merge the 64 old polygons per the 2025 mapping (Appendix A) | `derived`. **Verified:** 34 outputs, all 64 inputs used exactly once, all outputs valid. |
| **Total** | **319** | | |

> If the Timor-Leste Atauro split slips, Timor-Leste stays at 13 and the total is 318.

**Stage 2 (after the MVP):**
- Indonesia 34 → 38: merge ADM2 regencies (CC BY 3.0 IGO, BPS 2020, 519 records) into the 4 new provinces once the regency lists are verified against the 2022 laws.
- Philippines: split Maguindanao. This needs ADM3/municipal data, because ADM2 has a single Maguindanao polygon.
- Myanmar: add Naypyidaw. This needs another source (e.g. MIMU or COD-AB), because geoBoundaries ADM2 has no Naypyidaw districts.

### 3.3 Special notes

**Vietnam (largest risk)**
- The National Assembly approved the reorganisation on **12 June 2025**, effective **1 July 2025**, with 34 units.
- The breakdown was reported then as 28 provinces + 6 centrally-run cities.
- Wikipedia's *Provinces of Vietnam* now lists **7 municipalities + 27 provinces**, with **Đồng Nai** as a centrally governed city. The *Subdivisions of Vietnam* article dates this to 30 April 2026.

  **The total (34) and the territory are the same either way. Only Đồng Nai's type label differs.** Store `type` as data so it can be corrected without a migration. **Verify Đồng Nai's status against an official source before seeding** (Q1).
- The dissolve assumes each merger joined **whole** former provinces, which matches all mapping sources consulted. Cross-check the result against OCHA COD-AB Viet Nam v02 (34 provinces, September 2025) when the team can access HDX.
- **Never reuse old ISO codes** (VN-44 etc.) for the new units. The code list changed.

**Philippines**
- Use **provinces**, not regions. 17–18 regions are too coarse for a "your province" alert, and a "State/Province" dropdown matches provinces.
- Metro Manila (NCR) has no provinces, so it is one record.
- PSA PSGC (31 March 2025): 18 regions, 82 provinces.
- The 2024 Supreme Court ruling that removed Sulu from BARMM changes Sulu's *region*, not its province record. It doesn't affect a province-level dataset.

**"State/Province" means different things per country.** The dropdown label should adapt per country, stored as `countries.subdivision_label`:

| Country | Label |
|---|---|
| Brunei | District |
| Cambodia | Province |
| Indonesia | Province |
| Laos | Province |
| Malaysia | State / Federal Territory |
| Myanmar | State / Region |
| Philippines | Province |
| Singapore | — (single record) |
| Thailand | Province |
| Timor-Leste | Municipality |
| Vietnam | Province / City |

---

## 4. Licence comparison

*This is not legal advice. It summarises the licence terms as published.*

| Licence | Used for (Stage 1) | Training project OK? | Attribution | Modify / redistribute | Notes for storing in our database |
|---|---|---|---|---|---|
| **Public Domain** | Brunei ADM1, Vietnam ADM1 | Yes | Not legally required; credit geoBoundaries as good practice | Yes | None |
| **ODbL 1.0** (OpenStreetMap-derived) | Cambodia, Indonesia, Laos, Malaysia, Singapore, Thailand, Timor-Leste ADM1 | Yes | **Required** ("© OpenStreetMap contributors", plus geoBoundaries), for public use of the database or of works produced from it | Yes | Our simplified/merged copy is an **adapted database**. If it is used publicly, the adapted database must be offered under ODbL. Simple compliance: publish the processed seed GeoJSON with an ODbL notice (e.g. in the repository), and show attribution in the app footer/about page. |
| **CC BY 4.0** | Myanmar ADM1 | Yes | **Required** | Yes | Credit "Myanmar Analytics Project / MIMU via geoBoundaries" |
| **CC BY 3.0 IGO** | Philippines ADM2 (NAMRIA); Timor-Leste ADM2 Atauro piece; Indonesia ADM2 (Stage 2) | Yes | **Required** | Yes | Credit the original agency (NAMRIA; WHO/OCHA ROAP; BPS) |
| GADM | Not used | Non-commercial only | Yes | **Redistribution needs permission** | Avoid |
| Natural Earth | Not used | Yes | Not required | Yes | — |
| OCHA COD-AB (HDX) | Cross-check only | **Unverified** | **Unverified** | **Unverified** | Check the licence on each HDX dataset page before using any COD geometry |

geoBoundaries asks users to cite: *Runfola, D. et al. (2020) "geoBoundaries: A global database of political administrative boundaries." PLoS ONE 15(4): e0231866.* Combined with the per-country credits above, this goes in the app's footer or an "About the data" note, and in the repository's data `README`.

---

## 5. Suitability for geographic matching

| Requirement | Finding |
|---|---|
| Polygon geometry | Yes. `Polygon` or `MultiPolygon` for every record. Islands are MultiPolygons, as expected for Indonesia, the Philippines and Malaysia. |
| GeoJSON available | Yes: `geoBoundaries-{ISO}-{ADM}_simplified.geojson` and full-resolution files. |
| Coordinate system | WGS84 lon/lat (`urn:ogc:def:crs:OGC:1.3:CRS84`). The Philippines files omit the `crs` member, which by the GeoJSON standard also means WGS84. This matches USGS and GDACS coordinates, so no reprojection is needed. |
| Validity | **0 invalid geometries** in all 12 checked layers (11 ADM1 + Philippines ADM2). The Vietnam dissolve output is also valid. |
| Size / performance | geoBoundaries "simplified" files total 11.2 MB. After further simplification (Shapely `simplify(0.005°)` ≈ 500 m, coordinates rounded to 4 decimals ≈ 11 m), the total is about **1.7 MB and ~91,000 vertices** for all records. A PHP point-in-polygon plus distance-to-edge check over the few candidate provinces (after a bounding-box prefilter) is well within budget, at milliseconds per event. |
| Accuracy | About ±0.5 km after simplification. That is negligible compared with the impact radii used for alerting (tens of km). Note: simplification can create tiny gaps or overlaps between neighbouring provinces. Matching uses a radius, so this has no practical effect. Do not use the simplified data for area calculations. |
| Borders between countries | Each country's file is drawn independently, so neighbouring countries' borders may not line up exactly and disputed areas may differ. Acceptable for alerting with a radius. Don't try to "fix" borders in the MVP. |

**Conclusion:** the data is suitable for both point-in-polygon and "does the impact circle reach this province" checks, in PHP, without MySQL spatial functions.

---

## 6. Recommendation and justification

**Use geoBoundaries gbOpen as the single source for geometry.** Take ADM1 for 9 countries and ADM2 for the Philippines. Apply the documented derivations for Vietnam, the Philippines, Singapore and Timor-Leste. Verify **names and counts** against official sources (PSA, Vietnamese resolution, Indonesian laws).

Why:
1. It is the only checked source that covers **all 11 countries** in one consistent format with **verified valid geometry**.
2. **Every licence used allows modification and redistribution** with attribution. GADM does not.
3. The data is on GitHub, so the download is scriptable and **pinned to a commit** (`4004e96`), which makes it reproducible.
4. Its biggest weakness (Vietnam 2025) can be fixed from its own data, as verified.
5. It costs nothing.

**Do not claim complete current coverage until Stage 2.** In Stage 1, Indonesia (34 of 38 names) and Myanmar (14 of 15) have name gaps, although every square kilometre is covered by some polygon. Each record carries a `status` value so this is visible in the data and to admins.

---

## 7. Recommended database structure

Keep it simple: two small reference tables and one geometry table. Geometry is stored in MySQL as GeoJSON text, not as separate files, so a fresh install needs only `migrate --seed`. It is also not stored as MySQL spatial types, which keeps local SQLite development and tests working.

```
countries
  id                 bigint PK
  code               char(2)  UNIQUE   ISO 3166-1 alpha-2, e.g. TH
  iso3               char(3)  UNIQUE   e.g. THA (join key for GDACS iso3)
  name               varchar           e.g. Thailand
  subdivision_label  varchar           e.g. "Province", "State / Region"
  capital_name, capital_lat, capital_lng   (used by weather; see the HLD)
  sort_order, is_active

states                                   (one row per selectable State/Province)
  id                 bigint PK
  country_id         FK → countries
  code               varchar(20) UNIQUE  app-owned stable code, never changes once issued
  name               varchar             normalised English display name
  name_local         varchar NULL        e.g. Vietnamese with diacritics
  type               varchar             province / state / region / district / municipality / city / federal_territory / union_territory
  iso_3166_2         varchar NULL        only where currently valid (NOT the pre-2025 Vietnam codes)
  status             varchar             current / derived / outdated_names
  is_active          bool
  UNIQUE (country_id, name)
  UNIQUE (id, country_id)               (optional composite FK target for users)

state_boundaries                         (kept separate so dropdown queries never load geometry)
  state_id           PK, FK → states
  geojson            longText            simplified Polygon / MultiPolygon, WGS84
  min_lat, max_lat, min_lng, max_lng     bounding box for fast prefilter
  vertex_count       int
  source             varchar             e.g. "geoBoundaries gbOpen ADM1"
  source_refs        json                original shapeIDs / names used (several if derived)
  source_version     varchar             e.g. "gbOpen@4004e96 (THA-ADM1-36821470, 2017)"
  license            varchar             e.g. "ODbL-1.0"
  attribution        varchar
  derivation_note    varchar NULL        e.g. "dissolve of Hải Phòng + Hải Dương (VN 2025 reform)"
  imported_at        datetime
```

**Stable identifiers.**
- geoBoundaries `shapeID` values (e.g. `36821470B96343765829948`) are tied to a release. Don't use them as keys.
- Names are not unique across countries and change over time. Never match on name alone.
- The app owns `states.code`:
  - use the **ISO 3166-2 code where it is current** (`TH-50`, `MY-10`, `KH-12`, …);
  - otherwise use `{ISO2}-{SHORT}` assigned once (e.g. `VN-HCM`, `PH-NCR`, `TL-ATR`, `SG-SG`).
- `users.state_id` references `states.id`. Seeders upsert by `code`, so re-seeding never changes IDs.

---

## 8. Recommended import / seeding strategy

This is a one-off offline step. The processed output is committed to the repository, so teammates don't need GIS tools.

1. **Download (pinned).**
   - For each ISO3 in the 11 countries: `https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/4004e96/releaseData/gbOpen/{ISO3}/ADM1/geoBoundaries-{ISO3}-ADM1_simplified.geojson`
   - Plus Philippines ADM2 and Timor-Leste ADM2 (for Atauro).
   - Plus `geoBoundariesOpen-meta.csv` for licence and year.
   - Store the raw files outside the app (e.g. a `tools/geo/raw/` folder that is not deployed) or don't commit them.
2. **Extract.** Filter by `shapeGroup` / ISO3. Take ADM1, except the Philippines (ADM2).
3. **Normalise names.**
   - Trim whitespace (fixes "Hà Nội\t").
   - Unicode NFC.
   - Strip suffixes such as " Province".
   - Title-case ("CENTRAL REGION" → "Central Region").
   - Correct known misspellings ("Saigang" → "Sagaing", "Tanitharyi" → "Tanintharyi", "Bantey Meanchey" → "Banteay Meanchey").
   - Store the original name in `source_refs`.
   - Use a **hand-maintained mapping file** (`state_codes.csv`: `iso3, source_name, code, display_name, name_local, type`) as the single source of truth for names and codes. The script **fails** if any source feature is unmapped, or any mapping row matches nothing. Matching is by (country + source name or shapeISO), never by name across countries.
4. **Derive** the special cases:
   - Vietnam dissolve (Appendix A).
   - Philippines NCR merge, Isabela City → Basilan, Cotabato City → Maguindanao, Compostela Valley → Davao de Oro.
   - Singapore 5 → 1.
   - Timor-Leste Atauro split.
   - Run `make_valid` and check validity after each step.
5. **Simplify** (tolerance ≈ 0.005°, keeping topology) and round coordinates to 4 decimals. Compute bounding boxes and vertex counts.
6. **Validate.**
   - Expected counts per country (§3.2).
   - No duplicate `(country, name)` or `code`.
   - All geometries valid.
   - Spot checks: Bangkok → Bangkok; Chiang Mai city → Chiang Mai; Nay Pyi Taw → Mandalay; Sorong → West Papua. All four pass on the raw data in this research.
7. **Write** `database/seeders/data/asean_countries.json`, `asean_states.json` and `asean_state_boundaries.json` (≈ 2 MB), plus a `README` with sources, licences, versions and the attribution text.
8. **Seed** with a Laravel seeder that upserts by `code` inside a transaction. It is idempotent, and changed boundaries just update rows.
9. **Maintain.**
   - Record `source_version`.
   - Review once a year, and whenever a country announces an administrative change.
   - For a rename, update the name and keep the code.
   - For a split, add new codes, mark the old record `is_active=false`, and require affected users to reselect.

**Tool choice:** a short Python script (Shapely) or `mapshaper` CLI for steps 2–7, kept in `tools/geo/`. No PHP GIS library is needed at runtime: the app only reads the processed GeoJSON.

---

## 9. Known limitations and unresolved questions

| # | Question / limitation | Impact | Suggested handling |
|---|---|---|---|
| Q1 | **Vietnam: Đồng Nai's type** (province vs centrally governed city in 2026) | Label only | Verify officially before seeding; store `type` as data |
| Q2 | **Vietnam whole-province assumption** for the dissolve | Small border differences if communes moved between provinces | Cross-check against OCHA COD-AB v02 (licence **Unverified**) when accessible |
| Q3 | **Indonesia 34 vs 38** | 4 province names missing in Stage 1 | Stage 2 ADM2 derivation after verifying the regency lists |
| Q4 | **Singapore: 1 record or 5 regions?** | UX vs precision | Recommend 1. Any earthquake/cyclone radius covers the whole island anyway. |
| Q5 | **Myanmar: Naypyidaw missing** | Name gap; geometry covered by Mandalay | Stage 2 with another source |
| Q6 | **Philippines: Maguindanao split, independent cities** | Name gap for 2 provinces | Stage 2 |
| Q7 | Age of geoBoundaries data (2008–2020 representations) | Small boundary inaccuracies | Acceptable for radius-based alerting; review yearly |
| Q8 | **ODbL share-alike** for 7 countries | Must publish the adapted dataset under ODbL if the app is public | Publish the processed GeoJSON with a notice; footer attribution |
| Q9 | HDX/COD licences and geoboundaries.org pages couldn't be opened here | Some licence details unverified | Team to confirm on the source pages before release |
| Q10 | Cross-country borders are not harmonised | Tiny overlaps or gaps at national borders | No action for the MVP |

---

## 10. Sources and verification dates (all checked 9 November 2026)

**Datasets**
- geoBoundaries release metadata (gbOpen), verified: https://raw.githubusercontent.com/wmgeolab/geoBoundaries/main/releaseData/geoBoundariesOpen-meta.csv
- geoBoundaries GeoJSON files (pinned commit `4004e96`), verified: `https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/4004e96/releaseData/gbOpen/{ISO3}/{ADM}/geoBoundaries-{ISO3}-{ADM}_simplified.geojson`
- geoBoundaries release types and licensing notes (`geobounds` R package vignette): https://pbil.univ-lyon1.fr/CRAN/web/packages/geobounds/vignettes/geobounds.html
- Viet Nam COD-AB v02 catalogue record (34 provinces, September 2025): https://geo.btaa.org/resources/3cb544a9-9d04-4f54-94e2-93230efd8ceb
- OCHA COD-AB overview: https://humanitarian.atlassian.net/wiki/spaces/imtoolbox/pages/2557378679

**Licences**
- ODbL 1.0 summary: https://www.opendatacommons.org/licenses/odbl/summary/ (full text: https://opendatacommons.org/licenses/odbl/1-0/)
- GADM licence: https://gadm.org/license.html
- Natural Earth licensing note: https://www.naturalearthdata.com/?p=3348

**Administrative changes**
- Vietnam 63 → 34, approved 12 June 2025, effective 1 July 2025: https://vietnamlawmagazine.vn/parliament-passes-historic-resolution-to-cut-provinces-and-centrally-run-cities-from-63-to-34-74425.html ; https://aws.amazon.com/about-aws/whats-new/2025/10/amazon-location-service-new-admin-boundaries-vietnam
- Vietnam unit list and merger mapping: https://en.wikipedia.org/wiki/Provinces_of_Vietnam ; https://en.wikipedia.org/wiki/Subdivisions_of_Vietnam
- Philippines Negros Island Region (RA 12000, 11 June 2024): https://lawphil.net/statutes/repacts/ra2024/ra_12000_2024.html
- Philippines PSGC counts, 31 March 2025 (18 regions, 82 provinces): https://rssomimaropa.psa.gov.ph/system/files/attachment-dir/Press-Release-1Q-2025-PSGC.pdf
- Indonesia's 38th province (Southwest Papua, Law 29/2022, 9 Dec 2022): https://www.medcom.id/english/national/MkMP1jjb-southwest-papua-officially-becomes-indonesia-s-38th-province-minister
- Timor-Leste 14 municipalities, Atauro since 1 Jan 2022: https://en.wikipedia.org/wiki/Municipalities_of_Timor-Leste
- Myanmar 7 regions + 7 states + Naypyidaw Union Territory: https://en.wikipedia.org/wiki/Administrative_divisions_of_Myanmar

---

## Appendix A — Vietnam 2025 dissolve mapping (34 units, verified against the geoBoundaries polygons)

| New unit (2025) | Type | Former geoBoundaries units merged |
|---|---|---|
| Hà Nội | City | Hà Nội |
| Hải Phòng | City | Hải Phòng, Hải Dương |
| Hồ Chí Minh City | City | Ho Chi Minh, Bình Dương, Bà Rịa–Vũng Tàu, Côn Đảo* |
| Đà Nẵng | City | Đà Nẵng, Quảng Nam |
| Huế | City | Thừa Thiên Huế |
| Cần Thơ | City | Cần Thơ, Hậu Giang, Sóc Trăng |
| Đồng Nai | City or Province (Q1) | Đồng Nai, Bình Phước |
| Cao Bằng | Province | Cao Bằng |
| Lạng Sơn | Province | Lạng Sơn |
| Quảng Ninh | Province | Quảng Ninh |
| Điện Biên | Province | Điện Biên |
| Lai Châu | Province | Lai Châu |
| Sơn La | Province | Sơn La |
| Thanh Hóa | Province | Thanh Hóa |
| Nghệ An | Province | Nghệ An |
| Hà Tĩnh | Province | Hà Tĩnh |
| Phú Thọ | Province | Phú Thọ, Hòa Bình, Vĩnh Phúc |
| Thái Nguyên | Province | Thái Nguyên, Bắc Kạn |
| Tuyên Quang | Province | Tuyên Quang, Hà Giang |
| Lào Cai | Province | Lào Cai, Yên Bái |
| Bắc Ninh | Province | Bắc Ninh, Bắc Giang |
| Hưng Yên | Province | Hưng Yên, Thái Bình |
| Ninh Bình | Province | Ninh Bình, Hà Nam, Nam Định |
| Quảng Trị | Province | Quảng Trị, Quảng Bình |
| Quảng Ngãi | Province | Quảng Ngãi, Kon Tum |
| Gia Lai | Province | Gia Lai, Bình Định |
| Đắk Lắk | Province | Đắk Lắk, Phú Yên |
| Khánh Hòa | Province | Khánh Hòa, Ninh Thuận |
| Lâm Đồng | Province | Lâm Đồng, Bình Thuận, Đắk Nông |
| Tây Ninh | Province | Tây Ninh, Long An |
| Đồng Tháp | Province | Đồng Tháp, Tiền Giang |
| Vĩnh Long | Province | Vĩnh Long, Bến Tre, Trà Vinh |
| An Giang | Province | An Giang, Kiên Giang |
| Cà Mau | Province | Cà Mau, Bạc Liêu |

\* Côn Đảo appears as a separate polygon in geoBoundaries but was a district of Bà Rịa–Vũng Tàu, so it joins Hồ Chí Minh City.

Verification result: 34 output units; all 64 source polygons used exactly once; 0 invalid geometries after merging.
