<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\GeoEntities;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddress;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

#[ArgusLoad(loadEndpoint: 'POST:/common/geodata/reverseGeocodePoint', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY / 2)]
class ArgusGeocodableGeoPoint extends GeocodableGeoPoint
{
    use ArgusTrait;

    /**
     * We need to override parent method as in DDD\Domain\Common\Entities\GeoEntities\GeoPoint for performance reasons,
     * the parent::__construct(); is ommited and in this case the afterConstruct attachment of
     * #[AfterConstruct]
     * public function initArgusLoad()
     * is not executed
     * @param float $lat
     * @param float $lng
     */
    public function __construct(float $lat = 0, float $lng = 0)
    {
        parent::__construct($lat, $lng);
        // Redo what is missing from ValueObject in DDD GeoPoint contructor call
        $this->objectType = static::class;
        $this->afterConstruct();
    }

    /**
     * @param mixed|null $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws NonUniqueResultException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $languageCode = $this->languageCode ?? 'en';

        if (!(($callResponseData->status ?? null) == 'OK' && ($callResponseData->data->$languageCode ?? null))) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }

        foreach ($callResponseData->data as $languageCode => $result) {
            if (!isset($result[0])) {
                break;
            }
            if (!($this->reverseGeocodedAddress ?? null)) {
                $this->reverseGeocodedAddress = new ArgusPostalAddress();
            }
            elseif ($this->reverseGeocodedAddress instanceof PostalAddress) {
                $argusPostaladdress = new ArgusPostalAddress();
                $argusPostaladdress->fromEntity($this->reverseGeocodedAddress);
                $this->reverseGeocodedAddress = $argusPostaladdress;
            }
            $this->reverseGeocodedAddress->languageCode = $languageCode;
            ArgusPostalAddress::applyGeoCodeRawResults($this->reverseGeocodedAddress, $result[0]);
            break;
        }

        $this->postProcessLoadResponse($callResponseData);
    }



    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        if (!(($this->lat ?? false) && ($this->lng ?? false))) {
            return null;
        }
        $params['body'] = [
            'use_cache' => true,
            'latlng' => $this->lat . ',' . $this->lng,
            'language' => $this->languageCode ?? Translatable::getDefaultLanguageCode(),
        ];
        return $params;
    }
}