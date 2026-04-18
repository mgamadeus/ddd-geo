<?php

declare(strict_types=1);

namespace DDD\Domain\Batch\Services\Geo;

use DDD\Infrastructure\Exceptions\ExceptionDetails;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use stdClass;
use Throwable;

/**
 * Batch service for Google Places API (New) operations.
 * Called by batch controllers which serve as Argus endpoints.
 *
 * Uses the Google Places API (New):
 * - Text Search: POST https://places.googleapis.com/v1/places:searchText
 * - Autocomplete: POST https://places.googleapis.com/v1/places:autocomplete
 * - Place Details: GET https://places.googleapis.com/v1/places/{placeId}
 */
class GooglePlacesService extends Service
{
    protected Client $client;
    protected string $apiKey;

    /** @var string[] Default fields to request for text search */
    protected const array SEARCH_FIELDS = [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.location',
        'places.types',
        'places.businessStatus',
        'places.rating',
        'places.userRatingCount',
        'places.primaryType',
        'places.photos',
    ];

    /** @var string[] Default fields to request for place details */
    protected const array DETAILS_FIELDS = [
        'id',
        'displayName',
        'formattedAddress',
        'location',
        'types',
        'businessStatus',
        'rating',
        'userRatingCount',
        'internationalPhoneNumber',
        'nationalPhoneNumber',
        'websiteUri',
        'googleMapsUri',
        'primaryType',
        'photos',
        'regularOpeningHours',
    ];

    public function __construct()
    {
        $this->apiKey = Config::getEnv('GOOGLE_PLACES_API_KEY');

        $this->client = new Client([
            'base_uri' => 'https://places.googleapis.com',
            'timeout' => 30,
            'headers' => [
                'X-Goog-Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Searches for Google Places using text search (New API).
     *
     * @param string $searchInput Text query to search for (e.g., 'pizza restaurant Berlin')
     * @param string|null $languageCode Language code for results (e.g., 'de', 'en')
     * @param string|null $countryShortCode Country code to bias results (e.g., 'DE')
     * @return stdClass|null Response with places array
     * @throws InternalErrorException
     */
    public function searchPlaces(
        string $searchInput,
        ?string $languageCode = null,
        ?string $countryShortCode = null,
    ): ?stdClass {
        try {
            $requestBody = [
                'textQuery' => $searchInput,
            ];

            if ($languageCode) {
                $requestBody['languageCode'] = $languageCode;
            }

            if ($countryShortCode) {
                $requestBody['locationRestriction'] = [
                    'rectangle' => $this->getCountryBoundingBox($countryShortCode),
                ];
            }

            $fieldMask = implode(',', self::SEARCH_FIELDS);

            $response = $this->client->post('/v1/places:searchText', [
                'json' => $requestBody,
                'headers' => [
                    'X-Goog-FieldMask' => $fieldMask,
                ],
            ]);

            $responseBody = $response->getBody()->getContents();
            return json_decode($responseBody);
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e, 'searchPlaces');
        } catch (Throwable $t) {
            return $this->handleUnexpectedException($t, 'searchPlaces');
        }
    }

    /**
     * Gets Google Place details by place ID (New API).
     *
     * @param string $placeId Google Place ID
     * @param string|null $languageCode Language code for results
     * @return stdClass|null Response with place details
     * @throws InternalErrorException
     */
    public function getPlaceDetails(
        string $placeId,
        ?string $languageCode = null,
    ): ?stdClass {
        try {
            $fieldMask = implode(',', self::DETAILS_FIELDS);

            $queryParams = [];
            if ($languageCode) {
                $queryParams['languageCode'] = $languageCode;
            }

            $response = $this->client->get("/v1/places/$placeId", [
                'query' => $queryParams,
                'headers' => [
                    'X-Goog-FieldMask' => $fieldMask,
                ],
            ]);

            $responseBody = $response->getBody()->getContents();
            return json_decode($responseBody);
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e, 'getPlaceDetails');
        } catch (Throwable $t) {
            return $this->handleUnexpectedException($t, 'getPlaceDetails');
        }
    }

    /**
     * Gets Google Places autocomplete suggestions (New API).
     *
     * @param string $searchInput Partial text to autocomplete
     * @param string|null $languageCode Language code for results
     * @param string|null $countryShortCode Country code to restrict results
     * @param string[] $types Place types to filter by (e.g., ['restaurant', 'cafe'])
     * @return stdClass|null Response with autocomplete suggestions
     * @throws InternalErrorException
     */
    public function autocompletePlaces(
        string $searchInput,
        ?string $languageCode = null,
        ?string $countryShortCode = null,
        array $types = [],
    ): ?stdClass {
        try {
            $requestBody = [
                'input' => $searchInput,
            ];

            if ($languageCode) {
                $requestBody['languageCode'] = $languageCode;
            }

            if ($countryShortCode) {
                $requestBody['includedRegionCodes'] = [strtoupper($countryShortCode)];
            }

            if (!empty($types)) {
                $requestBody['includedPrimaryTypes'] = $types;
            }

            $response = $this->client->post('/v1/places:autocomplete', [
                'json' => $requestBody,
            ]);

            $responseBody = $response->getBody()->getContents();
            return json_decode($responseBody);
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e, 'autocompletePlaces');
        } catch (Throwable $t) {
            return $this->handleUnexpectedException($t, 'autocompletePlaces');
        }
    }

    /**
     * Returns an approximate bounding box for country restriction.
     * For more precise results, the frontend can pass lat/lng bias instead.
     *
     * @param string $countryShortCode
     * @return array
     */
    protected function getCountryBoundingBox(string $countryShortCode): array
    {
        // Fallback to a wide bounding box; precise boxes should be added as needed
        return [
            'low' => ['latitude' => -90.0, 'longitude' => -180.0],
            'high' => ['latitude' => 90.0, 'longitude' => 180.0],
        ];
    }

    /**
     * Centralized Guzzle exception handling
     *
     * @param GuzzleException $e
     * @param string $operation
     * @return stdClass|null
     * @throws InternalErrorException
     */
    protected function handleGuzzleException(GuzzleException $e, string $operation): ?stdClass
    {
        $exceptionDetails = new ExceptionDetails();
        $errorMessage = "Google Places API Error in $operation";

        if ($e->hasResponse()) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errorBody, true);

            if ($errorData && isset($errorData['error']['message'])) {
                $errorMessage = $errorData['error']['message'];
            }

            $exceptionDetails->addDetail('Google Places Error', [
                'statusCode' => $e->getResponse()->getStatusCode(),
                'response' => $errorBody,
            ]);
        }

        $exceptionDetails->addDetail('Guzzle Exception', ['message' => $e->getMessage()]);

        if ($this->throwErrors) {
            throw new InternalErrorException($errorMessage, $exceptionDetails);
        }
        return null;
    }

    /**
     * Centralized unexpected exception handling
     *
     * @param Throwable $t
     * @param string $operation
     * @return stdClass|null
     * @throws InternalErrorException
     */
    protected function handleUnexpectedException(Throwable $t, string $operation): ?stdClass
    {
        if ($this->throwErrors) {
            $exceptionDetails = new ExceptionDetails();
            $exceptionDetails->addDetail('Unexpected Error', [
                'operation' => $operation,
                'message' => $t->getMessage(),
            ]);
            throw new InternalErrorException("Unexpected error in $operation", $exceptionDetails);
        }
        return null;
    }
}
