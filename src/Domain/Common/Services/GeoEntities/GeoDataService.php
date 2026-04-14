<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\GeoEntities;

use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Entities\Addresses\PostalAddresses;
use DDD\Domain\Common\Entities\GeoEntities\GeoGooglePlace;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Countries;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Common\Entities\PoliticalEntities\Localities\Locality;
use DDD\Domain\Common\Entities\PoliticalEntities\States\State;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddress;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddresses;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeoGooglePlaceById;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeocodableGeoPoint;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeoPoints;
use DDD\Domain\Common\Repo\Argus\PoliticalEntities\ArgusLocality;
use DDD\Domain\Common\Services\IssuesLogService;
use DDD\Domain\Common\Services\LegacyDBCities;
use DDD\Domain\Common\Services\LegacyDBCity;
use DDD\Domain\Common\Services\LegacyDBCountries;
use DDD\Domain\Common\Services\LegacyDBCountry;
use DDD\Domain\Common\Services\LegacyDBState;
use DDD\Domain\Common\Services\LegacyDBStates;
use DDD\Infrastructure\Services\AppService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache as InfrastructureCache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Services\Service;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionException;
use RuntimeException;
use Throwable;

class GeoDataService extends Service
{

    /** @var int  */
    protected const int GEOCODE_MAX_ATTEMPTS_BEFORE_NO_CACHE = 5;

    /** @var int  */
    protected const int GEOCODE_TRACKING_WINDOW_SECONDS = 60;

    /** @var string  */
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

    /**
     * Finds active countries
     * @return Countries|null
     * @throws InternalErrorException
     * @throws ReflectionException
     * @throws BadRequestException
     * @throws NonUniqueResultException
     * @throws InvalidArgumentException
     */
    public function getActiveCountries(): ?Countries
    {
        return (new LegacyDBCountries())->getActiveCountries();
    }

    /**
     * Finds country by shortCode
     * @param string $countryShortCode
     * @return Country|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function getCountryByShortCode(string $countryShortCode): ?Country
    {
        $countryShortCode = $this->normalizeCountryShortCode($countryShortCode);
        return (new LegacyDBCountry())->findByShortCode($countryShortCode);
    }

    /**
     * Returns all Countries
     * @param bool $useCache
     * @return Countries
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function getCountries(bool $useCache = true): Countries
    {
        $legacyDBCountries = new LegacyDBCountries();
        $queryBuilder = $legacyDBCountries::createQueryBuilder();
        $modelAlias = $legacyDBCountries::getBaseModelAlias();
        $queryBuilder->andWhere("$modelAlias.is_country = 'y'");
        return $legacyDBCountries->find($queryBuilder, $useCache);
    }

    /**
     * Finds country by name
     * @param string $countryName
     * @return Country|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function getCountryByName(string $countryName): ?Country
    {
        $countryName = $this->normalizeCountryName($countryName);
        return (new LegacyDBCountry())->findByName($countryName);
    }

    /**
     * Finds country by id
     * @param string|int $countryId
     * @return Country|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function getCountryById(string|int $countryId): ?Country
    {
        return (new LegacyDBCountry())->find($countryId);
    }


    /**
     * @param string $stateShortCode
     * @param string|int $countryId
     * @return State|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function getStateByShortCode(string $stateShortCode, string|int $countryId): ?State
    {
        return (new LegacyDBState())->findByShortCode($stateShortCode, $countryId);
    }

    /**
     * Finds country by different means
     * @param string|int|null $countryId
     * @param string|null $countryShortCode
     * @param string|null $countryName
     * @return Country|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function findCountry(
        string|int|null $countryId = null,
        ?string $countryShortCode = null,
        ?string $countryName = null,
    ): ?Country {
        $country = null;
        $countryName = $this->normalizeCountryName($countryName);
        $countryShortCode = $this->normalizeCountryShortCode($countryShortCode);

        if ($countryId) {
            $country = $this->getCountryById($countryId);
        }
        if ($countryShortCode) {
            $country = $this->getCountryByShortCode($countryShortCode);
        }
        if ($countryName) {
            $country = $this->getCountryByName($countryName);
        }
        return $country;
    }

    /**
     * Finds state by different means
     * @param string|int|null $stateId
     * @param string|null $stateShortCode
     * @param string|null $stateName
     * @param string|int|null $countryId
     * @return State|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function findState(
        string|int|null $stateId = null,
        ?string $stateShortCode = null,
        ?string $stateName = null,
        string|int|null $countryId = null,
    ): ?State {
        $state = null;
        if ($stateId) {
            $state = $this->getStateById($stateId);
        } elseif (!$countryId) {
            return null;
        }
        if ($stateName) {
            $state = $this->getStateByName($stateName, $countryId);
        }
        if (!$state && $stateShortCode) {
            $state = $this->getStateByShortCode($stateShortCode, $countryId);
        }
        return $state;
    }

    /**
     * @param string $localityName
     * @param string|int|null $countryId
     * @param string|null $countryShortCode
     * @param string|null $countryName
     * @param Country|null $country
     * @param string|int|null $stateId
     * @param string|null $stateShortCode
     * @param string|null $stateName
     * @param State|null $state
     * @param string|null $languageCode
     * @param PostalAddress|null $address
     * @param bool $createIfNotFound
     * @return Locality|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function findLocality(
        string $localityName,
        string|int|null $countryId = null,
        ?string $countryShortCode = null,
        ?string $countryName = null,
        ?Country &$country = null,
        string|int|null $stateId = null,
        ?string $stateShortCode = null,
        ?string $stateName = null,
        ?State &$state = null,
        ?string $languageCode = null,
        ?PostalAddress &$address = null,
        bool $createIfNotFound = false,
    ): ?Locality {
        $legacyDBCity = new LegacyDBCity();
        $queryBuilder = EntityManagerFactory::createQueryBuilder();
        if (!($countryName || $countryShortCode || $countryId || $country) && $address) {
            $countryId = $address->country?->id ?? null;
            $country = $address->country ?? null;
        }
        $country = $country ?? $this->findCountry($countryId, $countryShortCode, $countryName);

        // without a country we definitely consider a locality not unique and return null
        if (!$country) {
            return null;
        }
        if (!($stateName || $stateShortCode || $stateId || $state) && $address) {
            $stateName = $address->state?->name ?? null;
        }
        $state = $this->findState($stateId, $stateShortCode, $stateName);
        $queryBuilder->andWhere('city.name = :localityname')->setParameter('localityname', $localityName);
        $queryBuilder->andWhere('city.country_id = :country_id')->setParameter('country_id', $country->id);

        if ($state) {
            $queryBuilder->andWhere('city.county_id = :county_id')->setParameter('county_id', $state->id);
        }
        if (!$languageCode && $country?->getDefaultLanguage()?->languageCode) {
            $languageCode = $country?->getDefaultLanguage()?->languageCode;
        }
        if ($languageCode) {
            $queryBuilder->andWhere('city.language_code = :language')->setParameter('language', $languageCode);
        }
        $locality = $legacyDBCity->find($queryBuilder);
        if ($locality || !$createIfNotFound) {
            return $locality;
        }

        //create if not found
        $locality = new Locality();
        if ($localityName) {
            $locality->setName($localityName);
        }
        if ($country) {
            $locality->country = $country;
            $locality->countryId = $country->id;
            if (!$languageCode) {
                $locality->languageCode = $country?->getDefaultLanguage()?->languageCode;
            }
        }
        if ($languageCode) {
            $locality->languageCode = $languageCode;
        }
        if ($state) {
            $locality->state = $state;
            $locality->stateId = $state->id;
        }
        $locality = $this->geocodeLocality($locality);
        $legacyDBCity = new LegacyDBCity();
        // state is new, we need to persist the state as well
        if (isset($locality->state) && !$locality->state->id) {
            $legacyDBState = new LegacyDBState();
            $locality->state->countryId = $country->id;
            AppService::instance()->deactivateEntityRightsRestrictions();
            $state = $legacyDBState->update($locality->state);
            AppService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            $locality->stateId = $state->id;
        }
        AppService::instance()->deactivateEntityRightsRestrictions();
        $updatedLocality = $legacyDBCity->update($locality);
        AppService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        return $updatedLocality;
    }

    /**
     * Updates state
     * @param State $state
     * @return State
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function updateState(State &$state): State
    {
        $legacyDBState = new LegacyDBState();
        $updated = $legacyDBState->update($state);
        return $updated;
    }

    /**
     * Finds locality by id
     * @param string|int $localityId
     * @return Locality|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function getLocalityById(string|int $localityId): ?Locality
    {
        return (new LegacyDBCity())->find($localityId);
    }

    /**
     * Returns an Address by geocoding input
     *
     * @param string $inputAddress
     * @param string|null $languageCode
     * @param bool $forceNoCache
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
     * Geocodes the locality and finds coordinates and additional information
     * @param Locality $locality
     * @return Locality
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function geocodeLocality(Locality &$locality): Locality
    {
        $argusLocality = new ArgusLocality();
        $argusLocality->fromEntity($locality);
        $argusLocality->argusLoad();
        return $argusLocality->toEntity();
    }

    /**
     * Reverse geocodes Coordinates
     * @param float $lat
     * @param float $lng
     * @param string $language
     * @return PostalAddress|null
     * @throws NotFoundException
     */
    public function reverseGeocodeCoordinates(float $lat, float $lng, string $language = 'en'): ?PostalAddress
    {
        $geoPoint = new GeocodableGeoPoint($lat, $lng, $language);
        $postalAddress = $this->reverseGeocodeGeoPoint($geoPoint);
        if (!$postalAddress && $this->throwErrors) {
            throw new NotFoundException('GeoPoint cannot be geocoded');
        }
        return $postalAddress;
    }

    /**
     * Reverse geocodes GeoPoting
     * @param GeocodableGeoPoint $geoPoint
     * @return PostalAddress|null
     * @throws NotFoundException
     */
    public function reverseGeocodeGeoPoint(GeocodableGeoPoint $geoPoint): ?PostalAddress
    {
        $argusGeopoint = new ArgusGeocodableGeoPoint();
        $argusGeopoint->fromEntity($geoPoint);
        $argusGeopoint->argusLoad(false, false);
        $argusGeopoint->toEntity();
        $postalAddress = $argusGeopoint->reverseGeocodedAddress ?? null;
        if (!$postalAddress && $this->throwErrors) {
            throw new NotFoundException('GeoPoint cannot be geocoded');
        }
        return $postalAddress;
    }

    /**
     * Match country names with COUNTRIES table in our DB
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

    /**
     * Removes locality, state and country names from keyword and returns cleaned keyword
     * @param string $keyword
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function removePoliticalEntitiesFromKeyword(string $keyword): string
    {
        $excluded = ['haar', 'keller', 'essen'];
        $excluded = array_flip($excluded);

        $queryElements = preg_split('/\s+/', $keyword);
        $legacyDBLocalities = new LegacyDBCities();
        $localityQueryBuilder = $legacyDBLocalities::createQueryBuilder();
        $localityAlias = $legacyDBLocalities::getBaseModelAlias();
        $localityQueryBuilder->where("{$localityAlias}.name IN (:queryElements)");
        $localityQueryBuilder->setParameter('queryElements', $queryElements);
        $localities = $legacyDBLocalities->find($localityQueryBuilder);
        $legacyDBStates = new LegacyDBStates();
        $stateQueryBuilder = $legacyDBStates::createQueryBuilder();
        $stateAlias = $legacyDBStates::getBaseModelAlias();
        $stateQueryBuilder->where("{$stateAlias}.name IN (:queryElements)");
        $stateQueryBuilder->setParameter('queryElements', $queryElements);
        $states = $legacyDBStates->find($stateQueryBuilder);

        $legacyDBCountries = new LegacyDBCountries();
        $countryQueryBuilder = $legacyDBCountries::createQueryBuilder();
        $countryAlias = $legacyDBCountries::getBaseModelAlias();
        $countryQueryBuilder->where("{$countryAlias}.name IN (:queryElements) and {$countryAlias}.is_country = 'y'");
        $countryQueryBuilder->setParameter('queryElements', $queryElements);
        $countries = $legacyDBCountries->find($countryQueryBuilder);

        $localComponents = [];
        foreach ($localities->getElements() as $localityElement) {
            $current = mb_strtolower($localityElement->name);
            if (!empty($excluded[$current])) {
                continue;
            }
            $localComponents[] = $current;
        }
        foreach ($states->getElements() as $state) {
            $current = mb_strtolower($state->name);
            if (!empty($excluded[$current])) {
                continue;
            }
            $localComponents[] = $current;
        }
        foreach ($countries->getElements() as $country) {
            $current = mb_strtolower($country->name);
            if (!empty($excluded[$current])) {
                continue;
            }
            $localComponents[] = $current;
        }

        $text = implode(' ', Datafilter::breakwords($keyword));
        $text_combinations = Datafilter::getCombinations($text);
        foreach ($localComponents as $localComponent) {
            $localComponent = implode(' ', Datafilter::breakwords($localComponent, 1));
            $localComponent = Datafilter::filter_diacritics($localComponent);
            if (!$localComponent || !is_string($localComponent)) {
                continue;
            }
            foreach ($text_combinations as $text_combination) {
                $text_combination_original = $text_combination;
                $text_combination = Datafilter::filter_diacritics($text_combination);
                similar_text($text_combination, $localComponent, $percent);
                //echo "$text_combination - $element = $percent <br/>";
                if ($percent >= 90) {
                    //$text = str_ireplace($text_combination, '', $text);
                    $text = str_ireplace($text_combination_original, '', $text);
                }
            }
            //echo $element . '<br/>';
        }
        return Datafilter::textwords($text);
    }

    /**
     * Reverse geocodes a geopoint and returns all addresses
     * @param float $lat
     * @param float $lng
     * @param string|null $language
     * @param bool $returnRawResponse
     * @return PostalAddresses|array|null
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function reverseGeocodeAll(
        float $lat,
        float $lng,
        ?string $language = null,
        bool $returnRawResponse = false,
    ): PostalAddresses|array|null {
        $argusGeopoints = new ArgusGeoPoints($lat, $lng, $language);
        $argusGeopoints->argusLoad(false, false);
        $geopoints = $argusGeopoints->toEntity();

        if ($returnRawResponse) {
            $isSuccessful = $argusGeopoints->getArgusSettings()->isLoadedSuccessfully;
            if (!$isSuccessful) {
                return null;
            }

            $rawResults = [];
            foreach ($geopoints->getElements() as $geopoint) {
                $rawResults[] = $geopoint->reverseGeocodedAddress->getRawResults();
            }
            return $rawResults;
        }

        $postalAddresses = new PostalAddresses();
        foreach ($geopoints->getElements() as $geopoint) {
            $postalAddress = $argusGeopoint->reverseGeocodedAddress ?? null;
            if ($postalAddress !== null) {
                $postalAddresses->add($postalAddress);
            }
        }
        return $postalAddresses;
    }

    /**
     * Returns all addresses by geocoding input
     * @param string $inputAddress
     * @param string|null $languageCode
     * @param string|null $countryShortCode
     * @param bool $returnRawResponse
     * @return PostalAddresses|array|null
     * @throws InternalErrorException
     * @throws ReflectionException
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
            $country = $this->getCountryByShortCode($countryShortCode);
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
            // Generate unique identifier for this user (IP + User Agent hash)
            $userIdentifier = $this->getUserIdentifier();
            $cacheKey = self::GEOCODE_SESSION_KEY_PREFIX . $userIdentifier . ':' . $addressHash;
            // Get existing tracking data from Redis
            $attemptData = $cache->get($cacheKey);
            $currentTime = time();

            // Check if we need to reset tracking (no data or window expired)
            $shouldReset = !$attemptData ||
                !isset($attemptData['firstAttempt']) ||
                ($currentTime - $attemptData['firstAttempt']) > self::GEOCODE_TRACKING_WINDOW_SECONDS;

            if ($shouldReset) {
                // Reset: This IS the first attempt in new window
                $attemptData = [
                    'count' => 1,
                    'firstAttempt' => $currentTime,
                    'lastAttempt' => $currentTime
                ];
            } else {
                // Window is still valid: increment existing count
                $attemptData['count']++;
                $attemptData['lastAttempt'] = $currentTime;
            }

            // Save to Redis with TTL (tracking window + buffer)
            $ttl = self::GEOCODE_TRACKING_WINDOW_SECONDS + 60;
            $cache->set($cacheKey, $attemptData, $ttl);

            // Determine if we need to force reset cache
            $forceResetCache = $attemptData['count'] >= self::GEOCODE_MAX_ATTEMPTS_BEFORE_NO_CACHE;
            if ($forceResetCache) {
                // Log cache bypass when it happens
                $this->logGeocodeReset(
                    inputAddress: $inputAddress,
                    languageCode: $languageCode,
                    userIdentifier: $userIdentifier,
                    attemptCount: $attemptData['count'],
                    firstAttemptAt: $attemptData['firstAttempt'],
                    lastAttemptAt: $attemptData['lastAttempt']
                );
                // Delete tracking key to reset the counter for next attempts, this allows the user to try again without being blocked
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
     *
     * @return void
     */
    public function logGeocodeReset(
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
        // Normalize the address for a consistent hash
        $normalizedAddress = mb_strtolower(trim($inputAddress));
        $normalizedLanguage = $languageCode ? mb_strtolower(trim($languageCode)) : '';

        return hash('sha256', $normalizedAddress . '_' . $normalizedLanguage);
    }

    /**
     * Generates a unique identifier for the current user based on IP and User Agent
     *  Uses $_SERVER superglobals which are available in all HTTP contexts
     *  Falls back to 'cli' for CLI context or 'anonymous' if data is unavailable
     *
     * @return string
     */
    protected function getUserIdentifier(): string
    {
        // Check if running in CLI context
        if (php_sapi_name() === 'cli') {
            return 'cli';
        }

        // Try to get IP address from various sources (considering proxies and load balancers)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ??
            $_SERVER['HTTP_X_REAL_IP'] ??
            $_SERVER['REMOTE_ADDR'] ??
            'unknown';

        // Take the first one (original client IP)
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        // Get user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        // If both are unknown, return anonymous
        if ($ip === 'unknown' && $userAgent === 'unknown') {
            return 'anonymous';
        }

        // Create a hash of IP + User Agent for privacy
        return hash('sha256', $ip . '|' . $userAgent);
    }

}
