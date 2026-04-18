<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\GeoEntities\GeoRegions;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegion;
use DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes\GeoRegionType;
use DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes\GeoRegionTypes;
use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Infrastructure\Cache\Cache;

/**
 * Argus repository entity for forward geocoding a GeoRegion by name via the geocodeGeoRegion batch endpoint.
 *
 * Works for any hierarchy level (admin_area_level_1, locality, sublocality, neighborhood, etc.).
 * Requires the country relation to be set on the GeoRegion before calling fromEntity().
 * The country->shortCode is used to restrict geocoding results geographically.
 * Optional geoPoint can be set to provide location bias for disambiguation.
 *
 * @method GeoRegion toEntity(array $callPath = [], DefaultObject|null &$entityInstance = null)
 */
#[ArgusLoad(
    loadEndpoint: 'POST:/common/geodata/geocodeGeoRegion',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: Cache::CACHE_TTL_ONE_MONTH
)]
class ArgusGeoRegion extends GeoRegion
{
    use ArgusTrait;

    /**
     * Google Maps types to require on address components when filtering results.
     * When set, only components whose types contain ALL entries in this array will match.
     * Example: ['administrative_area_level_1', 'political'] ensures we pick the state
     * component, not the locality, when geocoding ambiguous names like "New York".
     *
     * @var string[]
     */
    protected array $requiredTypes = [];

    /**
     * Sets the required Google Maps types for address component filtering.
     * Also sent as `types` in the payload so Argus can pre-filter server-side.
     *
     * @param string[] $requiredTypes Google Maps type names (e.g., ['administrative_area_level_1', 'political'])
     * @return void
     */
    public function setRequiredTypes(array $requiredTypes): void
    {
        $this->requiredTypes = $requiredTypes;
    }

    /**
     * Resolves the country short code from the lazy-loaded country relation
     *
     * @return string|null
     */
    protected function resolveCountryShortCode(): ?string
    {
        if (isset($this->country) && ($this->country->shortCode ?? null)) {
            return $this->country->shortCode;
        }

        return null;
    }

    /**
     * Builds the payload for the geocodeGeoRegion batch endpoint.
     * Requires name to be set. Optional geoPoint provides location bias.
     *
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        if (!isset($this->name) || empty($this->name)) {
            return null;
        }

        $body = [
            'name' => $this->name,
        ];

        // Location bias for disambiguation (e.g., "Springfield" in different states)
        if (isset($this->geoPoint->lat) && isset($this->geoPoint->lng)) {
            $body['lat'] = $this->geoPoint->lat;
            $body['lng'] = $this->geoPoint->lng;
        }

        if ($this->currentLanguageCode ?? null) {
            $body['language'] = $this->currentLanguageCode;
        }

        $countryCode = $this->resolveCountryShortCode();
        if ($countryCode) {
            $body['country'] = $countryCode;
        }

        // Send required types so Argus can pre-filter server-side
        if (!empty($this->requiredTypes)) {
            $body['types'] = $this->requiredTypes;
        }

        return ['body' => $body];
    }

    /**
     * Returns a unique cache key based on name, lat/lng, language and country
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        $name = $this->name ?? '';
        $language = $this->currentLanguageCode ?? 'en';
        $country = $this->resolveCountryShortCode() ?? '';
        $types = !empty($this->requiredTypes) ? implode(',', $this->requiredTypes) : '';

        $lat = '';
        $lng = '';
        if (isset($this->geoPoint->lat) && isset($this->geoPoint->lng)) {
            $lat = (string)$this->geoPoint->lat;
            $lng = (string)$this->geoPoint->lng;
        }

        return static::uniqueKeyStatic("{$name}_{$lat}_{$lng}_{$language}_{$country}_{$types}");
    }

    /**
     * Processes the response from the geocodeGeoRegion batch endpoint.
     * Extracts the region name, placeId and geoPoint from the first matching result.
     *
     * Walks the addressComponents to find the best matching component for this GeoRegion,
     * checking eligible types in priority order. Falls back to the top-level result's
     * placeId and location if no addressComponent matches.
     *
     * @param mixed|null $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        if (!(($callResponseData->status ?? null) == 'OK' && ($callResponseData->data ?? null))) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }
        $geoTypesService = GeoType::getService();

        foreach ($callResponseData->data as $languageCode => $results) {
            if (empty($results) || !isset($results[0])) {
                break;
            }

            $result = $results[0];

            // Extract the best matching component from addressComponents (v4 format)
            if (isset($result->addressComponents)) {
                $matchedComponent = $this->findMatchingAddressComponent($result->addressComponents);

                if ($matchedComponent) {
                    $componentTypes = $matchedComponent->types ?? [];
                    $this->name = $matchedComponent->longText ?? null;
                    $this->shortCode = $matchedComponent->shortText ?? null;
                    $this->geoRegionTypes = new GeoRegionTypes();
                    foreach ($componentTypes as $componentType) {
                        $geoType = $geoTypesService->findByName($componentType);
                        if ($geoType) {
                            $geoRegionType = new GeoRegionType();
                            $geoRegionType->geoType = $geoType;
                            $geoRegionType->geoRegion = $this;
                            $geoRegionType->geoTypeId = $geoType->id;
                            $this->geoRegionTypes->add($geoRegionType);
                        }
                    }
                }
            }

            // Extract placeId (v4 format — top-level result placeId)
            if (isset($result->placeId)) {
                $this->placeId = $result->placeId;
            }

            // Extract geoPoint from location (v4: location.latitude / location.longitude)
            if (isset($result->location)) {
                $this->geoPoint = new GeocodableGeoPoint(
                    $result->location->latitude ?? 0.0,
                    $result->location->longitude ?? 0.0
                );
            }

            break;
        }

        $this->postProcessLoadResponse($callResponseData);
    }

    /**
     * Finds the best matching address component from the geocoding response.
     *
     * When requiredTypes is set, matches only components whose types contain ALL required types.
     * This prevents ambiguity where e.g. "New York" could match the locality (city) instead of
     * the administrative_area_level_1 (state).
     *
     * When requiredTypes is empty (default), falls back to the original priority-based matching
     * that picks the first component matching any type in the hierarchy order.
     *
     * @param array $addressComponents The addressComponents array from the geocoding result
     * @return object|null The matched component or null if no match found
     */
    protected function findMatchingAddressComponent(array $addressComponents): ?object
    {
        // When requiredTypes is set, find the component that contains ALL required types
        if (!empty($this->requiredTypes)) {
            foreach ($addressComponents as $component) {
                $componentTypes = $component->types ?? [];
                $allTypesMatch = true;
                foreach ($this->requiredTypes as $requiredType) {
                    if (!in_array($requiredType, $componentTypes)) {
                        $allTypesMatch = false;
                        break;
                    }
                }
                if ($allTypesMatch) {
                    return $component;
                }
            }
            return null;
        }

        // Default: match first component with any eligible type (hierarchy priority order)
        $eligibleTypes = [
            'administrative_area_level_1',
            'administrative_area_level_2',
            'administrative_area_level_3',
            'administrative_area_level_4',
            'locality',
            'postal_town',
            'sublocality',
            'sublocality_level_1',
            'sublocality_level_2',
            'sublocality_level_3',
            'neighborhood',
        ];

        foreach ($addressComponents as $component) {
            $componentTypes = $component->types ?? [];
            foreach ($eligibleTypes as $eligibleType) {
                if (in_array($eligibleType, $componentTypes)) {
                    return $component;
                }
            }
        }

        return null;
    }
}
