# mgamadeus/ddd-common-geo

Addresses, geocoding, reverse geocoding, GeoRegion hierarchy, and Google Places integration for the [mgamadeus/ddd](https://github.com/mgamadeus/ddd) framework.

## Installation

```bash
composer require mgamadeus/ddd-common-geo
```

This pulls in [ddd-common-political](https://github.com/mgamadeus/ddd-political) and [ddd-argus](https://github.com/mgamadeus/ddd-argus) automatically.

## What it does

Full geocoding and address management layer:

- **PostalAddress** — full address with geocoding, references Country/State/Locality/GeoPoint/GeoRegion
- **GeocodableGeoPoint** — extends DDD Core's `GeoPoint` with reverse geocoding (lat/lng → PostalAddress via Argus)
- **GeoGooglePlace** — Google Place with placeId and geocoded address
- **GeoRegion** — hierarchical administrative regions with N:N GeoType relationship
- **GeoType** — Google Maps address component types (LOCALITY, ADMINISTRATIVE_AREA_LEVEL_1, etc.)
- **GeoRegionType** — junction entity linking GeoRegion ↔ GeoType
- **AddressComponent** — raw geocoding response components

### Services

- `GeoDataService` — orchestrator for all geo operations (country/state/locality lookup, geocode tracking)
- `GeoPointsService` — reverse geocoding via ArgusGeocodableGeoPoint
- `GeoRegionsService` — hierarchy-aware lookup, find-or-create, type assignment
- `GeoTypesService` — import from constants, findOrCreateByName
- `PostalAddressService` — address creation, locality lookup

### Geocoding Argus endpoints

| Argus repo | Endpoint | Cache |
|---|---|---|
| `ArgusPostalAddress` | `POST:/common/geodata/geocodeAddress` | 1 month |
| `ArgusPostalAddresses` | `POST:/geocoding/geocode` | 1 month |
| `ArgusGeocodableGeoPoint` | `POST:/common/geodata/reverseGeocodePoint` | 12 hours |
| `ArgusGeoPoints` | `POST:/rc-locations/reverse_geocode` | 12 hours |
| `ArgusGeoGooglePlaceByAddress` | `POST:/rc-locations/geocode` | 1 month |
| `ArgusGeoGooglePlaceById` | `POST:/rc-locations/geocode_by_placeid` | 1 month |
| `ArgusGeoRegion` | `POST:/common/geodata/geocodeGeoRegion` | 1 month |

All require `ARGUS_API_ENDPOINT` to be configured (see [ddd-argus](https://github.com/mgamadeus/ddd-argus)).

## Service registration

Add to your project's `services.yaml`:

```yaml
# DDD Module: ddd-common-geo
DDD\Domain\Common\Services\GeoEntities\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-geo/src/Domain/Common/Services/GeoEntities/*'
    public: true

DDD\Domain\Batch\Services\Geo\:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-geo/src/Domain/Batch/Services/Geo/*'
    public: true
```

## Batch controllers

The module ships `BatchGeocodingController` providing the server-side geocoding endpoints. Import in your project's `routes.yaml`:

```yaml
batch_geocoding:
    resource: '%kernel.project_dir%/vendor/mgamadeus/ddd-common-geo/src/Presentation/Api/Batch/Common'
    type: annotation
    prefix: '/api/batch'
```

Or extend in your app to customize.

### Security

Batch endpoints must be secured with ROLE_SUPER_ADMIN. Ensure your `security.yaml` includes:

```yaml
# config/symfony/default/packages/security.yaml
security:
    access_control:
        - { path: ^/api/batch, roles: ROLE_SUPER_ADMIN }
```

Argus clients authenticate automatically using the account specified by:

```env
# Must have SUPER_ADMIN role — bearer token is sent with every Argus API call
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1
```

See [ddd-argus](https://github.com/mgamadeus/ddd-argus) for the full security configuration example.

### Batch services

- `GoogleGeoService` — Google Geocoding API integration
- `GooglePlacesService` — Google Places API integration

## GeoPoint vs GeocodableGeoPoint

DDD Core provides `GeoPoint` — a lightweight value object with lat/lng, Haversine distance calculation, and Doctrine spatial mapping. It has no external dependencies and is used directly by the Political module (State, Locality entities).

This module adds `GeocodableGeoPoint extends GeoPoint` — which adds:
- `reverseGeocodedAddress` (PostalAddress) — populated via Argus reverse geocoding
- `reverseGeocode(?string $languageCode)` — triggers reverse geocoding via `GeoPointsService`
- `#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusGeocodableGeoPoint::class)]` — Argus integration

Use `GeoPoint` when you only need coordinates. Use `GeocodableGeoPoint` when you need reverse geocoding to a PostalAddress.

## Overriding entities

```php
// App\Domain\Common\Entities\Addresses\PostalAddress
namespace App\Domain\Common\Entities\Addresses;

use DDD\Domain\Common\Entities\Addresses\PostalAddress as BasePostalAddress;

class PostalAddress extends BasePostalAddress
{
    // Project-specific extensions
}
```
