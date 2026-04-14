<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\GeoEntities;

use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddress;
use DDD\Domain\Common\Services\GeoEntities\GeoDataService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use stdClass;

class PostalAddressService extends Service
{
    /**
     * @param GeoDataService|null $geoDataService
     */
    public function __construct(public ?GeoDataService $geoDataService = null)
    {
        if (!$geoDataService) {
            $geoDataService = new GeoDataService();
        }
        $this->geoDataService = $geoDataService;
    }

    /**
     * @param string|null $street
     * @param string|null $streetNo
     * @param string|null $addressLine1
     * @param string|null $addressLine2
     * @param string|null $postalCode
     * @param string|null $localityName
     * @param string|int|null $localityId
     * @param string|null $countryName
     * @param string|null $countryShortCode
     * @param string|int|null $countryId
     * @param float|null $lat
     * @param float|null $lng
     * @param bool $displayAddress
     * @param string|null $formattedAddress
     * @param bool $createCityIfNotFound
     * @param string|null $langaugeCode
     * @param bool $geocode
     * @return PostalAddress|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function createAddress(
        ?string $street = null,
        ?string $streetNo = null,
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $postalCode = null,
        ?string $localityName = null,
        string|int|null $localityId = null,
        ?string $countryName = null,
        ?string $countryShortCode = null,
        string|int|null $countryId = null,
        ?float $lat = null,
        ?float $lng = null,
        ?float $customerSelectedLat = null,
        ?float $customerSelectedLng = null,
        bool $displayAddress = true,
        ?string $formattedAddress = null,
        bool $createCityIfNotFound = true,
        ?string $langaugeCode = null,
        bool $geocode = false,
        ?stdClass $geoCodeRawResults = null
    ): ?PostalAddress {
        $address = new PostalAddress();
        if ($street) {
            $address->street = $street;
        }
        if ($streetNo) {
            $address->streetNo = $streetNo;
        }
        if ($lat && $lng) {
            $address->geoPoint = new GeocodableGeoPoint($lat, $lng);
        }
        if ($postalCode) {
            $address->postalCode = $postalCode;
        }
        if ($addressLine1) {
            $address->addressLine1 = $addressLine1;
        }
        if ($addressLine2) {
            $address->addressLine2 = $addressLine2;
        }
        if ($langaugeCode) {
            $address->languageCode = $langaugeCode;
        }
        if ($countryShortCode || $countryName || $countryId) {
            $country = $this->geoDataService->findCountry(
                countryId: $countryId,
                countryShortCode: $countryShortCode,
                countryName: $countryName
            );
            if ($country) {
                $address->country = $country;
                $address->countryId = $country->id;
                $address->addChildren($country);
                if (!$langaugeCode) {
                    $langaugeCode = $country->getDefaultLanguage()->languageCode;
                }
            }
        }
        if ($localityId) {
            $locality = $this->geoDataService->getLocalityById($localityId);
            if ($locality) {
                $address->locality = $locality;
                $address->localityId = $locality->id;
                $address->addChildren($locality);
            }
        }
        if (!isset($address->locality) && $localityName) {
            $locality = $this->geoDataService->findLocality(
                localityName: $localityName,
                countryId: isset($address->country) ? $address->country->id : null,
                languageCode: $langaugeCode,
                address: $address,
                createIfNotFound: true
            );
            if ($locality) {
                $address->locality = $locality;
                $address->localityId = $locality->id;
                $address->addChildren($locality);
            }
        }
        if ($geoCodeRawResults){
            PostalAddress::applyGeoCodeRawResults($address, $geoCodeRawResults);
            $address->setIsGeoCoded(true);
            if (!isset($address->languageCode) && $address->getLanguageCode()) {
                $address->languageCode = $address->getLanguageCode();
            }
            // Geocoding is not required in this case
        }
        else {
            if ($formattedAddress) {
                $address->formattedAddress = $formattedAddress;
                if ($address->addressLine1 ?? null) {
                    $address->addressLine1 = $address->cleanComponentsThatDontBelongToAddressLine1And2FromString(
                        $address->addressLine1
                    );
                    $address->formattedAddress = $address->getFormattedAddressBasedOnAddressLine1And2();
                } else {
                    $address->addressLine1 = $address->extractaddressLine1();
                }
            } else {
                $this->generateFormattedAddress($address);
            }
            if (!isset($address->addressLine1)) {
                $this->generateAddressLine1($address);
            }
            if ($geocode) {
                $address = $this->geoCodeAddress($address);
            }
        }
        if ($customerSelectedLat && $customerSelectedLng) {
            $address->customerSelectedGeoPoint = new GeocodableGeoPoint($customerSelectedLat, $customerSelectedLng);
        }

        return $address;
    }

    /**
     * Creates postalAddress based on raw call response data
     * @param mixed|null $callResponseData
     * @return PostalAddress|null
     */
    public function createAddressFromRawGoogleResponse(
        mixed &$callResponseData = null,
        ?string $languageCode = null
    ): ?PostalAddress {
        if (!isset($callResponseData->addressComponents)) {
            return null;
        }
        $postalAddress = new PostalAddress();
        if ($languageCode) {
            $postalAddress->languageCode = $languageCode;
        }
        ArgusPostalAddress::applyGeoCodeRawResults($postalAddress, $callResponseData);
        $postalAddress->setIsGeoCoded(true);
        return $postalAddress;
    }

    /**
     * Geocodes Address based on it's content
     * @param PostalAddress $address
     * @return PostalAddress
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function geoCodeAddress(PostalAddress &$address, bool $useCache = true): PostalAddress
    {
        $argusPostalAddress = new ArgusPostalAddress();
        $argusPostalAddress->fromEntity($address);
        $argusPostalAddress->argusLoad(useApiACallCache: $useCache, useArgusEntityCache: $useCache);
        /** @var PostalAddress $postalAddress */
        $postalAddress = $argusPostalAddress->toEntity();
        return $postalAddress;
    }

    /**
     * Generate address first line from existing address data
     * @param PostalAddress $address
     * @return void
     */
    protected function generateAddressLine1(PostalAddress &$address): void
    {
        if (!isset($address->street, $address->streetNo, $address->country)) {
            return;
        }
        $address->addressLine1 = $address->country->addressSetting->streetNoIsBeforeStreet ?
            "$address->streetNo $address->street" :
            "$address->street $address->streetNo";
    }

    /**
     * Generate formatted address from existing address data
     * @param PostalAddress $address
     * @return void
     */
    protected function generateFormattedAddress(PostalAddress &$address): void
    {
        $generatedFormattedAddress = $address->street ?? '';
        if (isset($address->addressLine2)) {
            $generatedFormattedAddress .= ($generatedFormattedAddress !== '' ? ' ' : '') . $address->addressLine2;
        }
        if (isset($address->postalCode)) {
            $generatedFormattedAddress .= ($generatedFormattedAddress !== '' ? ', ' : '') . $address->postalCode;
        }
        if (isset($address->locality, $address->locality->name)) {
            $generatedFormattedAddress .= ($generatedFormattedAddress !== '' ? ' ' : '') . $address->locality->name;
        }
        if (isset($address->county->name)) {
            $generatedFormattedAddress .= ($generatedFormattedAddress !== '' ? ', ' : '') . $address->county->name;
        }
        if (isset($address->country, $address->country->name)) {
            $generatedFormattedAddress .= ($generatedFormattedAddress !== '' ? ', ' : '') . $address->country->name;
        }

        if ($generatedFormattedAddress === '') {
            return;
        }
        $address->formattedAddress = $generatedFormattedAddress;
    }
}
