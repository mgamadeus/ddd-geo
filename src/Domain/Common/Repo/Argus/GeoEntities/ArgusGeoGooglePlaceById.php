<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\GeoEntities;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\GeoEntities\GeoGooglePlace;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddress;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\MethodNotAllowedException;
use ReflectionException;

/**
 * Returns geocode details from Google on placeId search
 */
#[ArgusLoad(
    loadEndpoint: 'POST:/rc-locations/geocode_by_placeid',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: Cache::CACHE_TTL_ONE_MONTH
)]
class ArgusGeoGooglePlaceById extends GeoGooglePlace
{
    use ArgusTrait;

    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        if (!(($this->placeId ?? false))) {
            return null;
        }

        $params['body'] = [
            'place_id' => $this->placeId,
            'language' => $this->geocodedAddress->languageCode ?? 'en',
            'use_cache' => true
        ];

        return $params;
    }

    /**
     * @param mixed|null $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     * @throws MethodNotAllowedException
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        if (!((($callResponseData->status ?? null) == 'OK') && ($callResponseData->data->results ?? null))) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }

        $response = $callResponseData->data->results[0];

        if (!($this->geocodedAddress ?? null)) {
            $this->geocodedAddress = new ArgusPostalAddress();
        }
        if (!($this->geocodedAddress instanceof ArgusPostalAddress)) {
            $argusPostalAddress = new ArgusPostalAddress();
            $argusPostalAddress->fromEntity($this->geocodedAddress);
            $this->geocodedAddress = $argusPostalAddress;
        }
        ArgusPostalAddress::applyGeoCodeRawResults($this->geocodedAddress, $response);

        $this->postProcessLoadResponse($callResponseData);
    }
}