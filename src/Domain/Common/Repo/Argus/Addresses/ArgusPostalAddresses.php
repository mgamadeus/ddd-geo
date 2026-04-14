<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Addresses;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Addresses\PostalAddresses;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use stdClass;

#[ArgusLoad(loadEndpoint: 'POST:/geocoding/geocode', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: Cache::CACHE_TTL_ONE_MONTH)]
class ArgusPostalAddresses extends PostalAddresses
{
    use ArgusTrait;

    /** @var string Input address for Geocoding operation */
    public ?string $inputAddress;

    /** @var int|null id of Country */
    public ?int $countryId;

    /** @var Country|null Address country */
    #[LazyLoad(repoType: LazyLoadRepo::LEGACY_DB)]
    public ?Country $country;

    /** @var string|null Language code of the address, as geodata can be localized, e.g. Munich vs. München, the language is relevant. If not provided, default native language of country is used. */
    public ?string $languageCode;

    /**
     * Return the input address that is set or empty string otherwise
     * @return string
     */
    public function getInputAddress(): string
    {
        if ($this->inputAddress ?? null) {
            return trim($this->inputAddress, ' ');
        }

        $inputAddress = '';
        $this->inputAddress = trim($inputAddress, ' ');
        return $inputAddress;
    }

    /**
     * Returns language if set, otherwise returns language from country, if country is set
     * @return string|void|null
     */
    public function getLanguageCode()
    {
        if ($this->languageCode ?? false) {
            return $this->languageCode;
        }
        if ($this->country->languageCode ?? false) {
            $this->languageCode = $this->country->getDefaultLanguage()->languageCode;
            return $this->languageCode;
        }
    }

    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        $inputAddress = $this->getInputAddress();
        if (!$inputAddress) {
            return null;
        }
        $loadingData = ['use_cache_data' => true, 'address' => $inputAddress];
        if (isset($this->country) && ($this->country->shortCode ?? null)) {
            $loadingData['country'] = $this->country->shortCode;
        }
        if ($this->getLanguageCode()) {
            $loadingData['language'] = $this->getLanguageCode();
        }
        return ['body' => $loadingData];
    }

    public function uniqueKey(): string
    {
        $country = '';
        $language = '';
        if (isset($this->country) && $this->country?->shortCode) {
            $country = $this->country->shortCode;
        }
        if ($this->getLanguageCode()) {
            $language = $this->getLanguageCode();
        }
        return static::uniqueKeyStatic($this->getInputAddress() . '_' . $country . '_' . $language);
    }

    /**
     * @param mixed|null $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        if (($callResponseData->status ?? null) == 'Not Found') {
            $this->postProcessLoadResponse($callResponseData, true);
        }
        if (!(($callResponseData->status ?? null) == 'OK' && ($callResponseData->data ?? null))) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }

        foreach ($callResponseData->data as $languageCode => $results) {
            if (empty($results)) {
                break;
            }

            // There are cases where the response contains an empty object for a language code so the code will fail
            if (!is_array($results) && is_object($results)) {
                $resultsArray = (array)$results;
                if (empty($resultsArray)) {
                    break;
                }
            }

            foreach ($results as $result) {
                $postalAddress = new ArgusPostalAddress();
                $postalAddress->languageCode = $languageCode;
                if (!isset($postalAddress->geoCodeRawResults)) {
                    $rawResults = new stdClass();
                    $rawResults->{$languageCode} = $result;
                    $postalAddress->geoCodeRawResults = json_encode($rawResults);
                }
                ArgusPostalAddress::applyGeoCodeRawResults($postalAddress, $result);
                $postalAddress->setIsGeoCoded(true);

                $this->add($postalAddress);
            }
        }

        $this->postProcessLoadResponse($callResponseData);
    }
}