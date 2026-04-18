---
name: geo-module-specialist
description: Work with the ddd-common-geo module -- PostalAddress, GeoRegion hierarchy, geocoding, reverse geocoding, Argus integration, GeoType lookup, and Google Places. Use when creating or modifying geo entities, services, or geocoding logic.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
  module: mgamadeus/ddd-common-geo
---

# Geo Module Specialist

Addresses, geocoding, GeoRegion hierarchy, and Google Places integration within the `mgamadeus/ddd-common-geo` module.

> **Base patterns:** This module follows all DDD Core conventions. For entity templates, service patterns, endpoint patterns, and QueryOptions, see the core skills in `vendor/mgamadeus/ddd`: `ddd-entity-specialist`, `ddd-service-specialist`, `ddd-endpoint-specialist`, `ddd-query-options-specialist`.

## When to Use

- Working with postal addresses and geocoding
- Creating or querying GeoRegion hierarchies
- Integrating Google Geocoding or Places APIs
- Reverse geocoding coordinates to addresses
- Extending the address or region model in a consuming application

## Module Namespace & Structure

All code uses `DDD\` namespace. The module registers via `composer.json` `extra.ddd-module`:

```
src/Domain/Common/Entities/Addresses/          # PostalAddress, AddressComponent
src/Domain/Common/Entities/GeoEntities/        # GeocodableGeoPoint, GeoGooglePlace
src/Domain/Common/Entities/GeoEntities/GeoRegions/     # GeoRegion (hierarchical)
src/Domain/Common/Entities/GeoEntities/GeoTypes/       # GeoType (lookup)
src/Domain/Common/Entities/GeoEntities/GeoRegionTypes/ # GeoRegionType (N:N junction)
src/Domain/Common/Repo/Argus/                  # Geocoding via Argus API
src/Domain/Common/Repo/DB/GeoEntities/         # DB repos for persisted entities
src/Domain/Common/Services/GeoEntities/        # All geo services
src/Domain/Batch/Services/Geo/                 # Google API wrappers
src/Presentation/Api/Batch/Common/             # Batch geocoding endpoints
```

---

## GeoRegion Hierarchy

The core architectural pattern -- replaces rigid City/State/County with a **self-referencing tree**:

```
Country (via countryId FK)
  +-- admin_area_level_1 (State/Province)
      +-- admin_area_level_2 (County/District)
          +-- admin_area_level_3 (Municipality)
              +-- locality (City/Town)
                  +-- sublocality_level_1 (Borough)
                      +-- sublocality_level_2 (Neighborhood)
```

### GeoRegion Entity

```php
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeoRegion::class)]
#[RolesRequiredForUpdate(Role::ADMIN)]
#[NoRecursiveUpdate]
class GeoRegion extends Entity
{
    use ChangeHistoryTrait, TranslatableTrait, QueryOptionsTrait;

    #[Translatable]
    public ?string $name = null;                    // Multilingual region name

    public ?string $slug = null;                     // URL-safe: "us-ny-brooklyn"
    public ?string $shortCode = null;                // "NY", "BY"
    public ?int $countryId = null;
    #[LazyLoad]
    public ?Country $country;

    public ?int $parentGeoRegionId = null;
    #[LazyLoad(addAsParent: true)]
    public ?GeoRegion $parentGeoRegion;               // Self-referencing parent

    #[LazyLoad]
    public ?GeoRegions $childGeoRegions;               // Children

    #[LazyLoad]
    public ?GeoRegionTypes $geoRegionTypes;            // N:N types via junction

    public ?string $placeId = null;                    // Google Place ID (unique)
    public ?GeocodableGeoPoint $geoPoint = null;       // Center coordinates
}
```

**Key methods:**
- `findAncestorByType(string $typeName)` -- walks parent chain to find ancestor with given Google type
- `hasType(string $typeName)` -- checks if region has a specific type
- `getTypeNames()` -- returns all type names as array
- `setSlugBasedOnHierarchy()` -- generates slug from parent chain (never set manually)

### GeoType Entity (Lookup)

40+ Google Maps component types stored UPPERCASE:

```php
GeoType::TYPE_LOCALITY                      // City/town
GeoType::TYPE_ADMINISTRATIVE_AREA_LEVEL_1   // State/province
GeoType::TYPE_ADMINISTRATIVE_AREA_LEVEL_2   // County
GeoType::TYPE_ADMINISTRATIVE_AREA_LEVEL_3   // Municipality
GeoType::TYPE_SUBLOCALITY                   // Sub-area
GeoType::TYPE_POSTAL_TOWN                   // UK postal town
GeoType::TYPE_NEIGHBORHOOD                  // Named neighborhood
GeoType::TYPE_COUNTRY                       // Country
// ... and 30+ more
```

Always use `GeoType::normalizeFromGoogle('locality')` to convert Google's lowercase to UPPERCASE.

### GeoRegionType (Junction)

N:N linking GeoRegion to GeoType with unique composite index `(geoRegionId, geoTypeId)`. A region can have multiple types (e.g., Brooklyn is both `SUBLOCALITY_LEVEL_1` and `POLITICAL`).

---

## PostalAddress (ValueObject)

A comprehensive address with geocoding support. **Not a persisted Entity** -- embedded in other entities as a ValueObject.

### Key Properties

```php
// Structured address fields
public ?string $street, $streetNo, $addressLine1, $addressLine2;
public ?string $postalCode, $postalCodeSuffix, $sublocality;

// Legacy FK references (from ddd-common-political)
public ?int $localityId, $stateId, $countryId;
#[LazyLoad] public ?Locality $locality;
#[LazyLoad] public ?State $state;
#[LazyLoad] public ?Country $country;

// New GeoRegion hierarchy
public ?int $geoRegionId;
#[LazyLoad] public ?GeoRegion $geoRegion;

// Geocoding data
public ?GeocodableGeoPoint $geoPoint;
public ?GeocodableGeoPoint $customerSelectedGeoPoint;
public ?AddressComponents $addressComponents;
public ?string $formattedAddress;
public string $precision;  // ROOFTOP, RANGE_INTERPOLATED, GEOMETRIC_CENTER, APPROXIMATE, NOT_FOUND
```

### Geocoding

```php
// Via PostalAddressService
$postalAddressService = PostalAddressService::instance();
$address = $postalAddressService->createAddress(
    street: 'Main Street',
    streetNo: '42',
    postalCode: '10001',
    localityName: 'New York',
    stateName: 'New York',
    countryShortCode: 'US',
    geocode: true
);

// Direct geocoding
$address->geocode(useCache: true);

// Via GeoDataService (orchestrator)
$geoDataService = GeoDataService::instance();
$address = $geoDataService->geocodeAddressByAddressString('42 Main St, New York, NY 10001');
$address = $geoDataService->geocodeAddressByPlaceId('ChIJd8BlQ2BZwokRAFUEcm_qrcA', 'en');
```

### Settlement Fallback Chain

PostalAddress uses deterministic fallback chains to extract the city from ambiguous Google responses:

```
locality -> postal_town -> admin_area_level_3 -> sublocality_level_1 -> admin_area_level_2
```

This handles cases like Brooklyn (which Google returns as `sublocality_level_1`, not `locality`).

### Precision Constants

| Constant | Meaning |
|----------|---------|
| `PRECISION_ROOFTOP` | Exact location |
| `PRECISION_RANGE_INTERPOLATED` | Between two precise points |
| `PRECISION_GEOMETRIC_CENTER` | Center of a shape (street, block) |
| `PRECISION_APPROXIMATE` | Approximate area |
| `PRECISION_NOT_FOUND` | Geocoding failed |

---

## GeocodableGeoPoint (ValueObject)

Extends DDD Core's `GeoPoint` (lat/lng) with reverse geocoding:

```php
$geoPoint = new GeocodableGeoPoint();
$geoPoint->lat = 40.7128;
$geoPoint->lng = -74.0060;
$geoPoint->reverseGeocode('en');  // Populates $geoPoint->reverseGeocodedAddress
echo $geoPoint->reverseGeocodedAddress->formattedAddress;
```

Use `GeoPoint` (from DDD Core) when you only need coordinates. Use `GeocodableGeoPoint` when you need reverse geocoding.

---

## Services

### GeoRegionsService -- Hierarchy Management

**Always use this service for region creation** -- handles translation, type assignment, and deduplication:

```php
$geoRegionsService = GeoRegions::getService();

// Find or create with localized name update
$region = $geoRegionsService->findOrCreateGeoRegionAndUpdateLocalizedName(
    languageCode: 'en',
    localizedName: 'Brooklyn',
    shortCode: null,
    types: ['SUBLOCALITY_LEVEL_1', 'POLITICAL'],
    placeId: 'ChIJCSF8lBZEwokRhngABHRcdoJ',
    country: $country,
    parentGeoRegion: $parentRegion,
    geoPoint: $geoPoint
);

// Type assignment
$geoRegionsService->assignTypesToGeoRegion($region, ['SUBLOCALITY_LEVEL_1', 'POLITICAL']);

// Find by Google Place ID
$region = $geoRegionsService->findByPlaceId('ChIJCSF8lBZEwokRhngABHRcdoJ');

// Walk hierarchy for specific type
$state = $region->findAncestorByType(GeoType::TYPE_ADMINISTRATIVE_AREA_LEVEL_1);
```

**Hierarchy-relevant types** (ordered most to least specific):
```
admin_area_level_1, admin_area_level_2, admin_area_level_3, admin_area_level_4,
locality, postal_town, sublocality, sublocality_level_1, sublocality_level_2,
neighborhood, political
```

### GeoTypesService -- Type Management

```php
$geoTypesService = GeoTypes::getService();

// Import all 40+ types from constants
$result = $geoTypesService->importGeoTypesFromConstants();
// Returns: ['created' => 5, 'updated' => 35, 'total' => 40, 'geoTypes' => GeoTypes]

// Find or create (normalizes Google names to UPPERCASE)
$type = $geoTypesService->findOrCreateByName('locality');  // Stored as "LOCALITY"
```

### GeoDataService -- Orchestrator

```php
$geoDataService = GeoDataService::instance();

// Forward geocoding
$address = $geoDataService->geocodeAddressByAddressString('42 Main St, New York');
$address = $geoDataService->geocodeAddressByPlaceId($placeId, 'en');

// Reverse geocoding
$address = $geoDataService->reverseGeocodeCoordinates(40.7128, -74.0060, 'en');
$address = $geoDataService->reverseGeocodeGeoPoint($geoPoint);

// Multiple results
$addresses = $geoDataService->geocodeAddressByAddressStringAll('Main Street');
$addresses = $geoDataService->reverseGeocodeAll(40.7128, -74.0060);
```

### PostalAddressService -- Address Factory

```php
$postalAddressService = PostalAddressService::instance();

// Create from components
$address = $postalAddressService->createAddress(
    street: 'Main St', streetNo: '42',
    postalCode: '10001', localityName: 'New York',
    countryShortCode: 'US', geocode: true
);

// Create from raw Google response
$address = $postalAddressService->createAddressFromRawGoogleResponse($apiResponse, 'en');

// Geocode existing address
$postalAddressService->geoCodeAddress($address, useCache: true);
```

---

## Argus Integration

Geocoding is performed via Argus repos (external API with caching):

| Repo | What | Cache |
|------|------|-------|
| `ArgusPostalAddress` | Forward geocode address string | 1 month (memory + DB) |
| `ArgusGeocodableGeoPoint` | Reverse geocode coordinates | 12 hours |
| `ArgusGeoGooglePlaceById` | Geocode by Google Place ID | 1 month |
| `ArgusGeoRegion` | Forward geocode any GeoRegion level | 1 month |

Argus repos use `#[ArgusLoad(loadEndpoint: '...', cacheLevel: MEMORY_AND_DB, cacheTtl: ...)]`.

### Geocode Tracking (Anti-Spam)

`GeoDataService` tracks geocoding attempts in Redis. After 5 attempts within 60 seconds for the same address, cache is bypassed to force a fresh API call. This prevents stale cache from blocking legitimate re-geocoding.

---

## Batch Endpoints

`BatchGeocodingController` at `/api/batch/common/geodata/`:

| Method | Path | Input | Purpose |
|--------|------|-------|---------|
| POST | `/geocodeAddress` | `address`, `country?`, `language?` | Forward geocode |
| POST | `/reverseGeocode` | `lat`, `lng`, `language?` | Reverse geocode |
| POST | `/reverseGeocodePoint` | `latlng` (comma-sep) | Reverse geocode (v4beta) |
| POST | `/geocodeGeoRegion` | `name`, `language?`, `country?`, `lat?`, `lng?` | Geocode any hierarchy level |
| POST | `/geocodeCity` | `name?`, `lat?`, `lng?`, `language`, `country?` | Geocode city |
| POST | `/geocodeState` | `name`, `language?`, `country?` | Geocode state |

All secured with `ROLE_SUPER_ADMIN`. Results keyed by language code.

---

## Extending in Consuming Applications

Override entities by extending and registering in `services.yaml`:

```php
namespace App\Domain\Common\Entities\Addresses;

use DDD\Domain\Common\Entities\Addresses\PostalAddress as BasePostalAddress;

class PostalAddress extends BasePostalAddress
{
    // Project-specific extensions
    public ?string $apartment = null;
}
```

The framework's `DDDService::getContainerServiceClassNameForClass()` resolves app overrides automatically.

---

## Environment Variables

```env
ARGUS_API_ENDPOINT=https://...              # Argus API base URL (required)
CLI_DEFAULT_ACCOUNT_ID_FOR_CLI_OPERATIONS=1 # SUPER_ADMIN account for Argus auth
GOOGLE_GEOCODING_API_KEY=...                # Google API key (for batch services)
```

---

## Checklist (New Geo Feature)

- [ ] Use `GeoRegionsService::findOrCreateGeoRegionAndUpdateLocalizedName()` for region creation (not direct `new GeoRegion()`)
- [ ] Normalize Google type names via `GeoType::normalizeFromGoogle()`
- [ ] Use `PostalAddressService::createAddress()` for address creation
- [ ] Consider precision level when using geocoded coordinates
- [ ] GeoRegion slugs are auto-generated -- never set manually
- [ ] PostalAddress is a ValueObject, not an Entity -- don't try to persist standalone
- [ ] All DDD Core conventions apply (never `private`, always `protected`, etc.)
