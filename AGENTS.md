# mgamadeus/ddd-common-geo -- Geo Module

Addresses, geocoding, reverse geocoding, GeoRegion hierarchy, and Google Places integration for the `mgamadeus/ddd` framework.

**Package:** `mgamadeus/ddd-common-geo` (v1.0.x)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^2.10), `mgamadeus/ddd-common-political` (^1.0)

> **This module follows all DDD Core conventions.** For entity patterns, service patterns, endpoint patterns, QueryOptions, message handlers, and CLI commands, see the skills in `vendor/mgamadeus/ddd`: `ddd-entity-specialist`, `ddd-service-specialist`, `ddd-endpoint-specialist`, `ddd-query-options-specialist`, `ddd-message-handler-specialist`, `ddd-cli-command-specialist`.

## Architecture

```
src/
+-- Domain/
|   +-- Batch/Services/Geo/                  [Google Geocoding + Places API wrappers]
|   |   +-- GoogleGeoService.php
|   |   +-- GooglePlacesService.php
|   +-- Common/
|       +-- Entities/
|       |   +-- Addresses/                   [PostalAddress, AddressComponent]
|       |   +-- GeoEntities/                 [GeocodableGeoPoint, GeoGooglePlace]
|       |       +-- GeoRegions/              [GeoRegion -- hierarchical regions]
|       |       +-- GeoTypes/                [GeoType -- lookup table]
|       |       +-- GeoRegionTypes/          [GeoRegionType -- N:N junction]
|       +-- Repo/
|       |   +-- Argus/                       [Geocoding via Argus API]
|       |   +-- DB/                          [Doctrine persistence for GeoRegion, GeoType, GeoRegionType]
|       +-- Services/GeoEntities/            [GeoDataService, GeoRegionsService, PostalAddressService, etc.]
+-- Modules/Geo/GeoModule.php               [DDDModule entry point]
+-- Presentation/Api/Batch/Common/           [BatchGeocodingController -- geocoding endpoints]
```

## Domain Concepts

### GeoRegion Hierarchy

The core design replaces rigid City/State/County models with a **self-referencing GeoRegion tree**. Each region has:
- A `parentGeoRegionId` pointing to the parent region (null for top-level)
- N:N `GeoRegionTypes` linking to `GeoType` (a region can be both `LOCALITY` and `POLITICAL`)
- Translatable `name` for multilingual support
- `placeId` for Google Places integration
- `slug` derived from hierarchy (e.g., `us-ny-brooklyn`)
- `geoPoint` for center coordinates

**Hierarchy example:** Country > admin_area_level_1 (State) > admin_area_level_2 (County) > locality (City) > sublocality (Borough)

### PostalAddress

A ValueObject containing a full postal address with:
- Structured fields (street, postalCode, addressLine1/2)
- FK references to Locality, State, Country (legacy model) and GeoRegion (new model)
- Raw Google `AddressComponents`
- GeoPoint coordinates with precision tracking (ROOFTOP, APPROXIMATE, etc.)
- Geocoding via Argus API with caching

### Geocoding Flow

```
Address String/Coordinates
    -> ArgusPostalAddress / ArgusGeocodableGeoPoint (Argus repo with caching)
    -> Google Geocoding API (via Batch controller)
    -> PostalAddress with parsed components, GeoPoint, and GeoRegion hierarchy
```

## Entity Overview

| Entity | Type | Persisted | Key Feature |
|--------|------|-----------|-------------|
| **PostalAddress** | ValueObject | Argus cache | Full address with geocoding, fallback chains |
| **GeocodableGeoPoint** | ValueObject | Argus cache | Extends GeoPoint with reverse geocoding |
| **GeoGooglePlace** | ValueObject | No | Google Place ID + geocoded address |
| **GeoRegion** | Entity | DB | Self-referencing hierarchy, translatable, N:N types |
| **GeoType** | Entity | DB | Google component type lookup (40+ constants) |
| **GeoRegionType** | Entity | DB | Junction: GeoRegion <-> GeoType |
| **AddressComponent** | ValueObject | No | Raw Google geocoding component |

## Services

| Service | Purpose |
|---------|---------|
| **GeoDataService** | Orchestrator: geocode/reverse-geocode, country/state/locality lookup, geocode tracking (Redis) |
| **GeoRegionsService** | Hierarchy-aware find-or-create, type assignment, localized name updates |
| **GeoTypesService** | Import from constants, find-or-create by name |
| **GeoPointsService** | Reverse geocoding for GeocodableGeoPoint |
| **PostalAddressService** | Address creation factory, geocoding trigger, formatted address generation |
| **GoogleGeoService** | Google Geocoding API wrapper (batch) |
| **GooglePlacesService** | Google Places API wrapper (batch) |

## Argus Integration

| Argus Repo | Endpoint | Cache TTL |
|-----------|----------|-----------|
| `ArgusPostalAddress` | `POST:/common/geodata/geocodeAddress` | 1 month |
| `ArgusGeocodableGeoPoint` | `POST:/common/geodata/reverseGeocodePoint` | 12 hours |
| `ArgusGeoGooglePlaceById` | `POST:/rc-locations/geocode_by_placeid` | 1 month |
| `ArgusGeoRegion` | `POST:/common/geodata/geocodeGeoRegion` | 1 month |

All require `ARGUS_API_ENDPOINT` environment variable.

## Batch Endpoints

`BatchGeocodingController` at `/api/batch/common/geodata/`:

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/geocodeAddress` | Forward geocode address string |
| POST | `/reverseGeocode` | Reverse geocode coordinates |
| POST | `/reverseGeocodePoint` | Reverse geocode (v4beta) |
| POST | `/geocodeGeoRegion` | Forward geocode any hierarchy level |
| POST | `/geocodeCity` | Forward/reverse geocode city |
| POST | `/geocodeState` | Forward geocode state |

Secured with `ROLE_SUPER_ADMIN`.

## Key Design Patterns

1. **Hierarchical self-referencing** -- GeoRegion with `parentGeoRegionId` replaces rigid City/State/County
2. **N:N type system** -- GeoRegionType junction allows a region to have multiple Google component types
3. **Argus-backed geocoding** -- External API calls cached in memory + DB, abstracted via Argus repos
4. **Fallback chains** -- PostalAddress uses deterministic chains to extract city/district from ambiguous responses
5. **Geocode tracking** -- Redis-based attempt tracking prevents spam (max 5 attempts/60s before cache bypass)
6. **Translatable region names** -- GeoRegion names stored as JSON with language/country/style variants
7. **Precision tracking** -- PostalAddress records geocoding accuracy (ROOFTOP, APPROXIMATE, etc.)

## Coding Conventions

All DDD Core conventions apply. See `vendor/mgamadeus/ddd/AGENTS.md` for the full reference.

Module-specific notes:
- GeoType names are stored UPPERCASE (`GeoType::normalizeFromGoogle()` handles conversion)
- PostalAddress is a ValueObject (not Entity) -- it's embedded in other entities, not persisted standalone
- GeoRegion slugs are derived from hierarchy -- never set manually
- Always use `GeoRegionsService::findOrCreateGeoRegionAndUpdateLocalizedName()` for region creation (handles translation, type assignment, and deduplication)

## Environment Variables

```env
ARGUS_API_ENDPOINT=https://...              # Argus API base URL
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1 # Account with SUPER_ADMIN for Argus auth
GOOGLE_GEOCODING_API_KEY=...                # Google Geocoding API key (used by Batch services)
```
