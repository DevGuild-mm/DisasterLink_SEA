# ASEAN country boundaries (ADM0)

`asean_country_boundaries.json` holds the country outlines used by `CountryResolver` to find an
event's country from its coordinates (HLD §12). It is loaded by `CountryBoundarySeeder`.

| Item | Value |
|---|---|
| Dataset | Natural Earth, 1:10m Cultural Vectors, **Admin 0 – Countries** |
| Version | Natural Earth vector **v5.1.2** (GeoJSON from the official `nvkelso/natural-earth-vector` repository, tag `v5.1.2`) |
| Source file | `https://raw.githubusercontent.com/nvkelso/natural-earth-vector/v5.1.2/geojson/ne_10m_admin_0_countries.geojson` (SHA-256 `239eec57ac17f100a11e2536cffc56752c318b50ae765b0918ff7aab4ce8f255`, 258 countries) |
| Downloaded | 10 November 2026 |
| License | **Public domain.** Natural Earth places no restrictions on use, including commercial use. |
| Attribution | "Made with Natural Earth. Free vector and raster map data at naturalearthdata.com." Not required, but shown in the app's data credits as a courtesy. |

Note: the shapefile download from the Natural Earth site (`naciscdn.org/naturalearth/10m/cultural/ne_10m_admin_0_countries.zip`)
reports `VERSION.txt` = 5.1.1. The two files were not compared feature by feature.

## What was done to the source file

1. Kept only the 11 features whose `ADM0_A3` is one of `BRN KHM IDN LAO MYS MMR PHL SGP THA TLS VNM`.
   (Matching on `ADM0_A3`, not on names. Natural Earth calls Timor-Leste "East Timor".)
2. Dropped every attribute except the code, and wrote each geometry as a GeoJSON `MultiPolygon`.
3. Rounded coordinates to **4 decimal places** (about 11 m) and removed consecutive duplicate points.
   **No line simplification was applied**, so `simplify_tolerance` is `null`.
4. Wrote the result as `{"meta": {...}, "countries": {"<ISO3>": <geometry>}}`.

Result: 11 countries, 45,048 vertices, about 810 KB. No other country or global data is included.

## Format facts

- WGS84 longitude/latitude (EPSG:4326). GeoJSON order is **[longitude, latitude]**.
- Every ring is closed and has at least 4 points. Holes are rings after the first in a polygon.

## Known limits

- 1:10m is a generalised dataset. A coastal point can fall 1–3 km outside its country's outline
  (for example Kota Kinabalu is about 2.5 km off the outline). Such an event stays `unresolved`
  unless its source gives a country code. See HLD §12.5.
- Points within 2 km of another country's outline are deliberately left unresolved (border guard).
- Natural Earth draws some disputed borders in a particular way. Resolution follows its lines and
  makes no political statement.

## Updating

Replace the file with a newer extract made the same way, update this README (version, date, hash),
then run `php artisan db:seed --class=CountryBoundarySeeder`. The seeder is idempotent.
