# San Jose del Monte reporting coverage

`san-jose-del-monte.geojson` contains the full, unsimplified OpenStreetMap administrative polygon for San Jose del Monte, Bulacan: relation [15662634](https://www.openstreetmap.org/relation/15662634), from the Nominatim boundary snapshot retained on 2026-10-26. It has 2,021 vertices in one outer ring, in GeoJSON longitude/latitude order. It is a community-maintained boundary snapshot, not a certified government survey.

Data © [OpenStreetMap contributors](https://www.openstreetmap.org/copyright), available under the [Open Database License 1.0](https://opendatacommons.org/licenses/odbl/1-0/). Keep this attribution, the source link, and the GeoJSON metadata with redistributed copies.

The backend checks new incident coordinates against this polygon locally, without an external Maps, geocoding, or Nominatim call. Points on its boundary count as covered; a sub-millimeter numerical tolerance handles floating-point calculations. This is not a radius or bounding-box restriction. Polygon validity and representative inside/outside classifications were independently checked with GEOS. Runtime and automated test execution do not require GEOS.

Review any replacement boundary before committing it. Retain its provenance, license, coordinate order and coverage regression tests. Existing report receipts are not rejected retroactively when the coverage dataset changes.
