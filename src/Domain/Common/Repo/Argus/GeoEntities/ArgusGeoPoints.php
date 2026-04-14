<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\GeoEntities;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Entities\GeoEntities\GeoPoints;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddress;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

#[ArgusLoad(
    loadEndpoint: 'POST:/rc-locations/reverse_geocode',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY / 2
)]
class ArgusGeoPoints extends GeoPoints
{
    use ArgusTrait;

    /** @var float|int Geographical latitude */
    #[GreaterThanOrEqual(-90)]
    #[LessThanOrEqual(90)]
    public float $lat = 0;

    /** @var float|int Geographical longitude */
    #[GreaterThanOrEqual(-180)]
    #[LessThanOrEqual(180)]
    public float $lng = 0;

    /** @var PostalAddress The reverse geocoded Address from this GeoPoint */
    public PostalAddress $reverseGeocodedAddress;

    public function __construct(float $lat = 0, float $lng = 0, ?string $language = null)
    {
        $this->lat = max(-90, min(90, $lat));
        $this->lng = max(-180, min(180, $lng));
        if ($language) {
            $this->setLanguage($language);
        }
        parent::__construct();
    }

    /**
     * Sets the language of the reverseGeocodedAddress and creates address if not existent
     * This is used for reverseGeocode operation to use the right language
     * @param string $languageCode
     * @return void
     */
    public function setLanguage(string $languageCode): void
    {
        if (!($this->reverseGeocodedAddress ?? null)) {
            $this->reverseGeocodedAddress = new PostalAddress();
        }
        $this->reverseGeocodedAddress->languageCode = $languageCode;
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
            'language' => $this->reverseGeocodedAddress->languageCode ?? 'en',
        ];
        return $params;
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
        $languageCode = $this->reverseGeocodedAddress->languageCode ?? 'en';

        if (!(($callResponseData->status ?? null) == 'OK' && ($callResponseData->data->$languageCode ?? null))) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }

        foreach ($callResponseData->data as $languageCode => $results) {
            if (!isset($results[0])) {
                break;
            }
            foreach ($results as $result) {
                $geoPoint = new ArgusGeoPoint($this->lat, $this->lng, $languageCode);
                if (!($geoPoint->reverseGeocodedAddress ?? null)) {
                    $geoPoint->reverseGeocodedAddress = new ArgusPostalAddress();
                }
                if (!($geoPoint->reverseGeocodedAddress instanceof ArgusPostalAddress)){
                    $argusPostaladdress = new ArgusPostalAddress();
                    $argusPostaladdress->fromEntity($geoPoint->reverseGeocodedAddress);
                    $geoPoint->reverseGeocodedAddress = $argusPostaladdress;
                }
                ArgusPostalAddress::applyGeoCodeRawResults($geoPoint->reverseGeocodedAddress, $result);
                $this->add($geoPoint);
            }
        }

        $this->postProcessLoadResponse($callResponseData);
    }
}