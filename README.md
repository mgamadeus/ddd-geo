# mgamadeus/ddd-common-geo

Addresses, geocoding, reverse geocoding, GeoRegion hierarchy, and Google Places integration for the [mgamadeus/ddd](https://github.com/mgamadeus/ddd) framework.

## Installation

```bash
composer require mgamadeus/ddd-common-geo
```

This pulls in [ddd-common-political](https://github.com/mgamadeus/ddd-political) automatically.

## What It Does

Full geocoding and address management layer built on DDD patterns:

- **PostalAddress** -- comprehensive address ValueObject with structured fields, geocoding via Argus, precision tracking, and fallback chains for ambiguous Google responses
- **GeoRegion** -- hierarchical administrative regions replacing rigid City/State/County, with self-referencing parent chain, translatable names, and N:N GeoType relationships
- **GeoType** -- Google Maps address component types (40+ types: LOCALITY, ADMINISTRATIVE_AREA_LEVEL_1, etc.) stored UPPERCASE
- **GeoRegionType** -- junction entity linking GeoRegion to GeoType (a region can have multiple types)
- **GeocodableGeoPoint** -- extends DDD Core's GeoPoint with reverse geocoding (coordinates to PostalAddress via Argus)
- **GeoGooglePlace** -- Google Place with placeId and geocoded address
- **AddressComponent** -- raw geocoding response components

## GeoRegion Hierarchy

The core design pattern -- a self-referencing tree replacing rigid models:

```
Country (via countryId FK)
  +-- admin_area_level_1 (State/Province)
      +-- admin_area_level_2 (County/District)
          +-- admin_area_level_3 (Municipality)
              +-- locality (City/Town)
                  +-- sublocality_level_1 (Borough)
                      +-- sublocality_level_2 (Neighborhood)
```

Each GeoRegion has a `parentGeoRegionId`, translatable `name`, `placeId` (Google), `slug` (derived from hierarchy), and N:N types via GeoRegionType junction.

## Services

| Service | Purpose |
|---------|---------|
| **GeoDataService** | Orchestrator: geocode/reverse-geocode, country/state/locality lookup, Redis-based geocode tracking |
| **GeoRegionsService** | Hierarchy-aware find-or-create, type assignment, localized name updates, slug generation |
| **GeoTypesService** | Import 40+ types from constants, find-or-create by name (normalizes to UPPERCASE) |
| **GeoPointsService** | Reverse geocoding for GeocodableGeoPoint |
| **PostalAddressService** | Address creation factory, geocoding trigger, formatted address and addressLine1 generation |
| **GoogleGeoService** | Google Geocoding API wrapper (batch layer) |
| **GooglePlacesService** | Google Places API wrapper (batch layer) |

### Quick Usage

```php
// Forward geocoding
$geoDataService = GeoDataService::instance();
$address = $geoDataService->geocodeAddressByAddressString('42 Main St, New York, NY 10001');
echo $address->formattedAddress;
echo $address->geoPoint->lat . ', ' . $address->geoPoint->lng;
echo $address->precision;  // ROOFTOP

// Reverse geocoding
$address = $geoDataService->reverseGeocodeCoordinates(40.7128, -74.0060, 'en');

// GeoRegion lookup
$geoRegionsService = GeoRegions::getService();
$region = $geoRegionsService->findByPlaceId('ChIJCSF8lBZEwokRhngABHRcdoJ');
$state = $region->findAncestorByType(GeoType::TYPE_ADMINISTRATIVE_AREA_LEVEL_1);

// Address creation
$postalAddressService = PostalAddressService::instance();
$address = $postalAddressService->createAddress(
    street: 'Main St', streetNo: '42', postalCode: '10001',
    localityName: 'New York', countryShortCode: 'US', geocode: true
);
```

## Argus Integration (Geocoding API)

| Argus Repo | Endpoint | Cache TTL |
|-----------|----------|-----------|
| `ArgusPostalAddress` | `POST:/common/geodata/geocodeAddress` | 1 month |
| `ArgusGeocodableGeoPoint` | `POST:/common/geodata/reverseGeocodePoint` | 12 hours |
| `ArgusGeoGooglePlaceById` | `POST:/rc-locations/geocode_by_placeid` | 1 month |
| `ArgusGeoRegion` | `POST:/common/geodata/geocodeGeoRegion` | 1 month |

All require `ARGUS_API_ENDPOINT` to be configured.

## Setup

### Service Registration

The module auto-registers via `DDDModule` when installed as a Composer package. If manual registration is needed, add to `services.yaml`:

```yaml
DDD\Domain\Common\Services\GeoEntities\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-geo/src/Domain/Common/Services/GeoEntities/*'
    public: true

DDD\Domain\Batch\Services\Geo\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-geo/src/Domain/Batch/Services/Geo/*'
    public: true
```

### Batch Controller (Geocoding Endpoints)

Import in `routes.yaml`:

```yaml
batch_geocoding:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-geo/src/Presentation/Api/Batch/Common'
    type: annotation
    prefix: '/api/batch'
```

### Security

Batch endpoints require `ROLE_SUPER_ADMIN`:

```yaml
# security.yaml
security:
    access_control:
        - { path: ^/api/batch, roles: ROLE_SUPER_ADMIN }
```

### Environment Variables

```env
ARGUS_API_ENDPOINT=https://...              # Argus API base URL (required)
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1 # Account with SUPER_ADMIN for Argus auth
GOOGLE_GEOCODING_API_KEY=...                # Google API key (for batch services)
```

## Batch Endpoints

`BatchGeocodingController` at `/api/batch/common/geodata/`:

| Method | Path | Input | Purpose |
|--------|------|-------|---------|
| POST | `/geocodeAddress` | `address`, `country?`, `language?` | Forward geocode address string |
| POST | `/reverseGeocode` | `lat`, `lng`, `language?` | Reverse geocode coordinates |
| POST | `/reverseGeocodePoint` | `latlng` (comma-sep) | Reverse geocode (v4beta) |
| POST | `/geocodeGeoRegion` | `name`, `language?`, `country?`, `lat?`, `lng?` | Geocode any hierarchy level |
| POST | `/geocodeCity` | `name?`, `lat?`, `lng?`, `language` | Forward/reverse geocode city |
| POST | `/geocodeState` | `name`, `language?`, `country?` | Forward geocode state |

## Key Design Patterns

**Hierarchical self-referencing** -- GeoRegion with `parentGeoRegionId` replaces rigid models. Walk the hierarchy with `findAncestorByType()`.

**N:N type system** -- GeoRegionType junction allows a region to have multiple Google component types simultaneously.

**Argus-backed geocoding** -- External API calls cached in memory + DB via Argus repos, abstracted from business logic.

**Settlement fallback chains** -- PostalAddress uses deterministic chains to extract city from ambiguous responses:
```
locality -> postal_town -> admin_area_level_3 -> sublocality_level_1 -> admin_area_level_2
```

**Geocode tracking** -- Redis-based attempt tracking prevents spam (max 5 attempts/60s before cache bypass).

**Precision tracking** -- PostalAddress records geocoding accuracy (ROOFTOP, RANGE_INTERPOLATED, GEOMETRIC_CENTER, APPROXIMATE, NOT_FOUND).

## GeoPoint vs GeocodableGeoPoint

DDD Core provides `GeoPoint` -- lightweight lat/lng with Haversine distance and Doctrine spatial mapping. No external dependencies.

This module adds `GeocodableGeoPoint extends GeoPoint` with:
- `reverseGeocodedAddress` (PostalAddress) via Argus reverse geocoding
- `reverseGeocode(?string $languageCode)` trigger
- `#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusGeocodableGeoPoint::class)]`

Use `GeoPoint` when you only need coordinates. Use `GeocodableGeoPoint` when you need reverse geocoding.

## Overriding Entities

```php
namespace App\Domain\Common\Entities\Addresses;

use DDD\Domain\Common\Entities\Addresses\PostalAddress as BasePostalAddress;

class PostalAddress extends BasePostalAddress
{
    public ?string $apartment = null;
    // Project-specific extensions
}
```

The framework resolves app overrides automatically via `DDDService::getContainerServiceClassNameForClass()`.

## Conventions

All [mgamadeus/ddd conventions](https://github.com/mgamadeus/ddd) apply. Module-specific rules:

- GeoType names are UPPERCASE -- use `GeoType::normalizeFromGoogle()` for conversion
- PostalAddress is a ValueObject (not Entity) -- embedded in other entities, not persisted standalone
- GeoRegion slugs are auto-derived from hierarchy -- never set manually
- Always use `GeoRegionsService::findOrCreateGeoRegionAndUpdateLocalizedName()` for region creation
- GeoRegion is `#[NoRecursiveUpdate]` -- updating a parent region does not cascade to children

## License

MIT
