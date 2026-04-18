<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\GeoEntities;

use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Entities\Addresses\PostalAddresses;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Domain\Common\Entities\GeoEntities\GeoGooglePlace;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Common\Entities\PoliticalEntities\Localities\Locality;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddress;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddresses;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeoGooglePlaceById;
use DDD\Domain\Common\Services\PoliticalEntities\CountriesService;
use DDD\Domain\Common\Services\PoliticalEntities\LocalitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache as InfrastructureCache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AppService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\Service;
use Psr\Log\LogLevel;
use ReflectionException;
use RuntimeException;
use stdClass;
use Throwable;

class PostalAddressService extends Service
{

    /** @var int */
    protected const int GEOCODE_MAX_ATTEMPTS_BEFORE_NO_CACHE = 5;

    /** @var int */
    protected const int GEOCODE_TRACKING_WINDOW_SECONDS = 60;

    /** @var string */
    protected const string GEOCODE_SESSION_KEY_PREFIX = 'geocode_attempts_';

    /**
     * Used to normalize the country name
     * Sometimes the country name received from external sources (e.g., Yext, Facebook)
     * does not match any of our DB records, so we have to normalize it
     * @var string[]
     */
    protected const COUNTRY_NAMES = [
        'us' => 'USA',
        'gb' => 'United Kingdom',
        'united states' => 'USA',
    ];

    /**
     * Used to normalize the country short code
     * Sometimes the country short code received from external sources (e.g., Yext, Facebook)
     * does not match any of our DB records, so we have to normalize it
     * @var string[]
     */
    protected const COUNTRY_SHORTCODE = [
        'uk' => 'gb',
    ];

    // ── Address Creation ──────────────────────────────────────────────

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
     * @param float|null $customerSelectedLat
     * @param float|null $customerSelectedLng
     * @param bool $displayAddress
     * @param string|null $formattedAddress
     * @param bool $createCityIfNotFound
     * @param string|null $langaugeCode
     * @param bool $geocode
     * @param stdClass|null $geoCodeRawResults
     * @return PostalAddress|null
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
            $country = $this->findCountry(
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
            /** @var LocalitiesService $localitiesService */
            $localitiesService = Locality::getService();
            $locality = $localitiesService->find($localityId);
            if ($locality) {
                $address->locality = $locality;
                $address->localityId = $locality->id;
                $address->addChildren($locality);
            }
        }
        if (!isset($address->locality) && $localityName && isset($address->country)) {
            $locality = $this->findOrCreateLocality(
                localityName: $localityName,
                country: $address->country,
                languageCode: $langaugeCode,
                createIfNotFound: $createCityIfNotFound
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
     * @param string|null $languageCode
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

    // ── Geocoding ─────────────────────────────────────────────────────

    /**
     * Geocodes Address based on its content
     * @param PostalAddress $address
     * @param bool $useCache
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
     * Returns an Address by geocoding an input string
     *
     * @param string $inputAddress
     * @param string|null $languageCode
     * @param bool $forceResetCache
     *
     * @return PostalAddress|null
     * @throws InternalErrorException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function geocodeAddressByAddressString(
        string $inputAddress,
        ?string $languageCode = null,
        bool $forceResetCache = false,
    ): ?PostalAddress {
        $argusAddress = new ArgusPostalAddress();
        $argusAddress->inputAddress = $inputAddress;
        $argusAddress->languageCode = $languageCode;
        if ($forceResetCache) {
            $argusAddress->useArgusCacheData = false;
            $argusAddress->argusLoad(false, false);
        } else {
            $argusAddress->argusLoad();
        }

        if (!$argusAddress->getArgusSettings()->isLoadedSuccessfully) {
            if ($this->throwErrors) {
                throw new NotFoundException('The given address could not be geocoded.');
            }
            return null;
        }
        return $argusAddress->toEntity();
    }

    /**
     * Returns all addresses by geocoding an input string
     * @param string $inputAddress
     * @param string|null $languageCode
     * @param string|null $countryShortCode
     * @param bool $returnRawResponse
     * @return PostalAddresses|array|null
     * @throws InternalErrorException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function geocodeAddressByAddressStringAll(
        string $inputAddress,
        ?string $languageCode = null,
        ?string $countryShortCode = null,
        bool $returnRawResponse = false,
    ): PostalAddresses|array|null {
        $argusAddresses = new ArgusPostalAddresses();
        $argusAddresses->inputAddress = $inputAddress;
        if (!empty($countryShortCode)) {
            $countryShortCode = $this->normalizeCountryShortCode($countryShortCode);
            /** @var CountriesService $countriesService */
            $countriesService = Country::getService();
            $country = $countriesService->findByShortCode($countryShortCode);
            if ($country) {
                $argusAddresses->countryId = $country->id;
                $argusAddresses->country = $country;
            }
        }
        $argusAddresses->languageCode = $languageCode;
        $argusAddresses->argusLoad();
        /** @var PostalAddresses $postalAddresses */
        $postalAddresses = $argusAddresses->toEntity();

        $isSuccessful = $argusAddresses->getArgusSettings()->isLoadedSuccessfully;

        if ($returnRawResponse) {
            if (!$isSuccessful) {
                return null;
            }

            $rawResults = [];
            /** @var PostalAddress $postalAddress */
            foreach ($postalAddresses->getElements() as $postalAddress) {
                $rawResults[] = $postalAddress->getRawResults();
            }
            return $rawResults;
        }

        if (!$isSuccessful) {
            if ($this->throwErrors) {
                throw new NotFoundException('The given address could not be geocoded.');
            }
            return null;
        }

        return $postalAddresses;
    }

    /**
     * Geocodes a Google place ID and returns the address
     * @param string $placeId
     * @param string|null $languageCode
     * @param bool $returnRawResponse
     * @return PostalAddress|array|null
     * @throws InternalErrorException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function geocodeAddressByPlaceId(
        string $placeId,
        ?string $languageCode = null,
        bool $returnRawResponse = false,
    ): PostalAddress|array|null {
        $argusGooglePlace = new ArgusGeoGooglePlaceById($placeId, $languageCode);
        $argusGooglePlace->placeId = $placeId;
        if (!empty($languageCode)) {
            $argusGooglePlace->setLanguage($languageCode);
        }
        $argusGooglePlace->argusLoad();
        /** @var GeoGooglePlace $googlePlace */
        $googlePlace = $argusGooglePlace->toEntity();

        $isSuccessful = $argusGooglePlace->getArgusSettings()->isLoadedSuccessfully;

        if ($returnRawResponse) {
            if (!$isSuccessful) {
                return null;
            }

            $rawResults = [];
            $rawResults[] = $googlePlace->geocodedAddress->getRawResults();
            return $rawResults;
        }

        if (!$isSuccessful) {
            if ($this->throwErrors) {
                throw new NotFoundException('The given address could not be geocoded.');
            }
            return null;
        }

        return $googlePlace->geocodedAddress;
    }

    // ── Geocode Anti-Spam Tracking ────────────────────────────────────

    /**
     * Determines whether we should force no-cache based on the number of recent attempts
     *
     * @param string $inputAddress
     * @param string|null $languageCode
     * @return bool
     */
    public function shouldForceNoCache(
        string $inputAddress,
        ?string $languageCode
    ): bool {
        try {
            $cache = InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_REDIS);
            $addressHash = $this->generateAddressHash($inputAddress, $languageCode);
            $userIdentifier = $this->getUserIdentifier();
            $cacheKey = self::GEOCODE_SESSION_KEY_PREFIX . $userIdentifier . ':' . $addressHash;
            $attemptData = $cache->get($cacheKey);
            $currentTime = time();

            $shouldReset = !$attemptData ||
                !isset($attemptData['firstAttempt']) ||
                ($currentTime - $attemptData['firstAttempt']) > self::GEOCODE_TRACKING_WINDOW_SECONDS;

            if ($shouldReset) {
                $attemptData = [
                    'count' => 1,
                    'firstAttempt' => $currentTime,
                    'lastAttempt' => $currentTime
                ];
            } else {
                $attemptData['count']++;
                $attemptData['lastAttempt'] = $currentTime;
            }

            $ttl = self::GEOCODE_TRACKING_WINDOW_SECONDS + 60;
            $cache->set($cacheKey, $attemptData, $ttl);

            $forceResetCache = $attemptData['count'] >= self::GEOCODE_MAX_ATTEMPTS_BEFORE_NO_CACHE;
            if ($forceResetCache) {
                $this->logGeocodeReset(
                    inputAddress: $inputAddress,
                    languageCode: $languageCode,
                    userIdentifier: $userIdentifier,
                    attemptCount: $attemptData['count'],
                    firstAttemptAt: $attemptData['firstAttempt'],
                    lastAttemptAt: $attemptData['lastAttempt']
                );
                $cache->delete($cacheKey);
            }

            return $forceResetCache;
        } catch (Throwable $e) {
            // If Redis fails, don't break the application - just skip tracking
            return false;
        }
    }

    /**
     * Logs a geocode cache reset event for monitoring and reporting
     *
     * @param string $inputAddress
     * @param string|null $languageCode
     * @param string $userIdentifier
     * @param int $attemptCount
     * @param int $firstAttemptAt
     * @param int $lastAttemptAt
     * @return void
     */
    protected function logGeocodeReset(
        string $inputAddress,
        ?string $languageCode,
        string $userIdentifier,
        int $attemptCount,
        int $firstAttemptAt,
        int $lastAttemptAt
    ): void {
        $now = new DateTime();
        $truncatedAddress = mb_substr($inputAddress, 0, 255);

        $exception = new RuntimeException(
            sprintf(
                'Geocode cache bypass triggered | Address: %s | Language: %s | User: %s | Attempts: %d',
                $truncatedAddress,
                $languageCode ?? 'null',
                $userIdentifier,
                $attemptCount
            )
        );
        /** @var IssuesLogService $logService */
        $logService = AppService::instance()->getService(IssuesLogService::class);

        $additionalContext = [];
        $additionalContext['additionalInformation'] = json_encode([
            'geocode.reset_address' => $truncatedAddress,
            'geocode.reset_language_code' => $languageCode ?? 'null',
            'geocode.reset_user_identifier' => $userIdentifier,
            'geocode.reset_timestamp' => $now->format('Y-m-d H:i:s'),
            'geocode.attempt_count' => $attemptCount,
            'geocode.first_attempt_at' => date('Y-m-d H:i:s', $firstAttemptAt),
            'geocode.last_attempt_at' => date('Y-m-d H:i:s', $lastAttemptAt),
            'geocode.cache_bypass' => true,
        ]);

        $logService->logThrowable($exception, LogLevel::WARNING, $additionalContext);
    }

    /**
     * Generates a consistent hash for an address and its associated language
     *
     * @param string $inputAddress
     * @param string|null $languageCode
     * @return string
     */
    public function generateAddressHash(string $inputAddress, ?string $languageCode): string
    {
        $normalizedAddress = mb_strtolower(trim($inputAddress));
        $normalizedLanguage = $languageCode ? mb_strtolower(trim($languageCode)) : '';

        return hash('sha256', $normalizedAddress . '_' . $normalizedLanguage);
    }

    /**
     * Generates a unique identifier for the current user based on IP and User Agent
     *
     * @return string
     */
    protected function getUserIdentifier(): string
    {
        if (php_sapi_name() === 'cli') {
            return 'cli';
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ??
            $_SERVER['HTTP_X_REAL_IP'] ??
            $_SERVER['REMOTE_ADDR'] ??
            'unknown';

        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        if ($ip === 'unknown' && $userAgent === 'unknown') {
            return 'anonymous';
        }

        return hash('sha256', $ip . '|' . $userAgent);
    }

    // ── Country/Locality Resolution ───────────────────────────────────

    /**
     * Finds a country by ID, short code, or name using CountriesService
     *
     * @param string|int|null $countryId
     * @param string|null $countryShortCode
     * @param string|null $countryName
     * @return Country|null
     */
    protected function findCountry(
        string|int|null $countryId = null,
        ?string $countryShortCode = null,
        ?string $countryName = null,
    ): ?Country {
        /** @var CountriesService $countriesService */
        $countriesService = Country::getService();

        if ($countryId) {
            return $countriesService->find($countryId);
        }
        if ($countryShortCode) {
            $countryShortCode = $this->normalizeCountryShortCode($countryShortCode);
            return $countriesService->findByShortCode($countryShortCode);
        }
        if ($countryName) {
            $countryName = $this->normalizeCountryName($countryName);
            $dbCountry = $countriesService->getEntityRepoClassInstance();
            $queryBuilder = $dbCountry::createQueryBuilder();
            $alias = $dbCountry::getBaseModelAlias();
            $queryBuilder->andWhere("{$alias}.name = :name")
                ->setParameter('name', $countryName);
            return $dbCountry->find($queryBuilder);
        }
        return null;
    }

    /**
     * Finds or creates a Locality using LocalitiesService
     *
     * @param string $localityName
     * @param Country $country
     * @param string|null $languageCode
     * @param bool $createIfNotFound
     * @return Locality|null
     */
    protected function findOrCreateLocality(
        string $localityName,
        Country $country,
        ?string $languageCode = null,
        bool $createIfNotFound = true,
    ): ?Locality {
        /** @var LocalitiesService $localitiesService */
        $localitiesService = Locality::getService();

        $languageCode = $languageCode ?? $country->getDefaultLanguage()?->languageCode ?? 'en';

        if ($createIfNotFound) {
            return $localitiesService->findOrCreateLocalityAndUpdateLocalizedName(
                currentLanguageCode: $languageCode,
                country: $country,
                localizedName: $localityName,
            );
        }

        return $localitiesService->findByNameAndState($localityName);
    }

    // ── Normalization Utilities ───────────────────────────────────────

    /**
     * Normalizes country names from external sources to match DB records
     * @param string|null $countryName
     * @return string|null
     */
    public function normalizeCountryName(?string $countryName = null): ?string
    {
        if (!$countryName) {
            return $countryName;
        }
        return self::COUNTRY_NAMES[strtolower($countryName)] ?? $countryName;
    }

    /**
     * Normalizes country short codes from external sources to match DB records
     * @param string|null $countryShortCode
     * @return string|null
     */
    public function normalizeCountryShortCode(?string $countryShortCode = null): ?string
    {
        if (!$countryShortCode) {
            return $countryShortCode;
        }
        return self::COUNTRY_SHORTCODE[strtolower($countryShortCode)] ?? $countryShortCode;
    }

    // ── Address Formatting ────────────────────────────────────────────

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
