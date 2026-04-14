<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeocodableGeoPoint;
use DDD\Domain\Common\Services\GeoEntities\GeoPointsService;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;

/**
 * Extended GeoPoint with reverse geocoding capabilities.
 * Extends the base GeoPoint from DDD Core (lat/lng value object with Haversine distance and spatial mapping).
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusGeocodableGeoPoint::class)]
class GeocodableGeoPoint extends GeoPoint
{
    /** @var PostalAddress Reverse geocoded PostalAddress */
    public PostalAddress $reverseGeocodedAddress;

    /** @var string|null Language code used for reverse geocoding PostalAddress */
    public ?string $languageCode;

    public function uniqueKey(): string
    {
        $key = $this->lat . ',' . $this->lng . (isset($this->languageCode) ? $this->languageCode : 'en');
        return self::uniqueKeyStatic($key);
    }

    /**
     * Reverse geocodes GeoPoint and returns PostalAddress
     * @param string|null $languageCode
     * @return PostalAddress|null
     * @throws NotFoundException
     */
    public function reverseGeocode(?string $languageCode = null): ?PostalAddress
    {
        if ($languageCode) {
            $this->languageCode = $languageCode;
        }
        if (!isset($this->languageCode)) {
            $this->languageCode = Translatable::getDefaultLanguageCode();
        }
        /** @var GeoPointsService $geoPointsService */
        $geoPointsService = DDDService::instance()->getService(GeoPointsService::class);
        return $geoPointsService->reverseGeocodeGeoPoint($this);
    }

    /**
     * Method is custom implemented for efficiency
     */
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed {
        $return = [
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
        if (isset($this->reverseGeocodedAddress)) {
            $return['reverseGeocodedAddress'] = $this->reverseGeocodedAddress->toObject($cached, $returnUniqueKeyInsteadOfContent);
        }
        return $return;
    }
}
