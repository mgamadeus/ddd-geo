<?php

namespace DDD\Domain\Common\Repo\Argus\GeoEntities;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\GeoEntities\GeoGooglePlace;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\MethodNotAllowedException;
use ReflectionException;

#[ArgusLoad(
    loadEndpoint: 'POST:/rc-locations/geocode',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: Cache::CACHE_TTL_ONE_MONTH
)]
class ArgusGeoGooglePlaceByAddress extends GeoGooglePlace
{
    use ArgusTrait;

    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        $params['body'] = [
            'address' => $this->inputAddress,
            'language' => $this->inputLanguageCode ?? 'en',
            'country' => $this->inputCountryShortCode,
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

    ): void
    {
        $languageCode = $this->inputLanguageCode ?? 'en';
        if (!(
            (($callResponseData->status ?? null) == 'OK' || ($callResponseData->status ?? null) == 200) &&
            ($callResponseData->data->$languageCode ?? null)
        )) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }
        // Invalid Google place if receiving multiple Places for an ID
        if (count($callResponseData->data->$languageCode) > 1) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }
        $response = $callResponseData->data->$languageCode[0];

        $this->mapFromGoogleResponse($response);
        $this->language = $languageCode;
        $this->source =  $response->source ?? null;

        $this->postProcessLoadResponse($callResponseData);
    }

}