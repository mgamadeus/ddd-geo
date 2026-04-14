<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities;

use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeoPoint;
use DDD\Domain\Common\Services\GeoEntities\GeoPointsService;
use DDD\Infrastructure\Services\AppService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Infrastructure\Exceptions\NotFoundException;

#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusGeoPoint::class)]
class GeoPoint extends \DDD\Domain\Common\Entities\GeoEntities\GeoPoint
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
        $geoPointsService = AppService::instance()->getService(GeoPointsService::class);
        return $geoPointsService->reverseGeocodeGeoPoint($this);
    }

    /**
     * Method is custom implemented for efficiency
     * @param $cached
     * @param bool $returnUniqueKeyInsteadOfContent
     * @param array $path
     * @param bool $ignoreHideAttributes
     * @param bool $ignoreNullValues
     * @param bool $forPersistence
     * @param int $flags
     * @return mixed
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