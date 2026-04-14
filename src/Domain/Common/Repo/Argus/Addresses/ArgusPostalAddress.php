<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Addresses;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use stdClass;

/**
 * @method PostalAddress toEntity(array $callPath = [], DefaultObject|null &$entityInstance = null)
 */
#[ArgusLoad(loadEndpoint: 'POST:/common/geodata/geocodeAddress', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: Cache::CACHE_TTL_ONE_MONTH)]
class ArgusPostalAddress extends PostalAddress
{
    use ArgusTrait;

    /** @var bool */
    public bool $useArgusCacheData = true;

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

    public function getInputAddress(): string
    {
        if ($this->inputAddress ?? null) {
            return trim($this->inputAddress, ' ');
        }
        $inputAddress = '';
        if ($this->addressLine1 ?? null) {
            $inputAddress .= $this->addressLine1;
            if ($this->addressLine2 ?? null) {
                $inputAddress .= ' ' . $this->addressLine2;
            }
        } elseif (isset($this->county) && ($this->county->addressSetting->streetNoIsBeforeStreet ?? null)) {
            if ($this->streetNo ?? null) {
                $inputAddress .= ' ' . $this->streetNo;
            }
            if ($this->street ?? null) {
                $inputAddress .= ' ' . $this->street;
            }
        } else {
            if ($this->street ?? null) {
                $inputAddress .= ' ' . $this->street;
            }
            if ($this->streetNo ?? null) {
                $inputAddress .= ' ' . $this->streetNo;
            }
        }
        if ($this->postalCode ?? null) {
            if (!str_contains($inputAddress, $this->postalCode)) {
                $inputAddress .= ' ' . $this->postalCode;
            }
        }
        if (isset($this->locality) && ($this->locality->name ?? null)) {
            if (!str_contains($inputAddress, $this->locality->name)) {
                $inputAddress .= ' ' . $this->locality->name;
            }
        }
        if (isset($this->state) && ($this->state->name ?? null)) {
            if (!str_contains($inputAddress, $this->state->name)) {
                $inputAddress .= ', ' . $this->state->name;
            }
        }
        if (!$inputAddress && ($this->formattedAddress ?? null)) {
            $inputAddress = $this->formattedAddress;
        }
        $this->inputAddress = trim($inputAddress, ' ');
        return $inputAddress;
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
            $this->precision = self::PRECISIOIN_NOT_FOUND;
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

            if (count($results) == 1) {
                $addressObject = $results[0];
            } else {
                $addressMatchPercent = 0;
                $addressMatchKey = 0;
                foreach ($results as $resultKey => $result) {
                    $apiOperationAddress = strtolower(
                        str_replace(',', '', $apiOperation->params['body']['address'] ?? '')
                    );
                    $resultFormattedAddress = strtolower(str_replace(',', '', $result->formattedAddress ?? ''));
                    $currentMatchScore = 1 - (levenshtein($apiOperationAddress, $resultFormattedAddress) / max(
                                strlen($apiOperationAddress),
                                strlen($resultFormattedAddress)
                            ));
                    if ($currentMatchScore > $addressMatchPercent) {
                        $addressMatchPercent = $currentMatchScore;
                        $addressMatchKey = $resultKey;
                    }
                }
                $addressObject = $results[$addressMatchKey];
            }
            $this->languageCode = $languageCode;
            if (!isset($this->geoCodeRawResults)) {
                $rawResults = new stdClass();
                $rawResults->{$languageCode} = $addressObject;
                $this->geoCodeRawResults = json_encode($rawResults);
            }

            self::applyGeoCodeRawResults($this, $addressObject);
            $this->isGeoCoded = true;
            break;
        }
        $this->postProcessLoadResponse($callResponseData);
    }

    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        if (isset($this->geoCodeRawResults)) {
            self::applyGeoCodeRawResults($this, json_decode($this->geoCodeRawResults));
            $this->isGeoCoded = true;
            if ($this->getLanguageCode()) {
                $this->languageCode = $this->getLanguageCode();
            }
            return null;
        }
        $inputAddress = $this->getInputAddress();
        if (!$inputAddress) {
            return null;
        }
        $loadingData = ['use_cache_data' => $this->useArgusCacheData, 'address' => $inputAddress];
        if (isset($this->country) && ($this->country->shortCode ?? null)) {
            $loadingData['country'] = $this->country->shortCode;
        }
        if ($this->getLanguageCode()) {
            $loadingData['language'] = $this->getLanguageCode();
        }
        return ['body' => $loadingData];
    }
}