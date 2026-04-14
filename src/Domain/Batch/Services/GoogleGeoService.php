<?php

declare(strict_types=1);

namespace DDD\Domain\Batch\Services;

use DDD\Infrastructure\Exceptions\ExceptionDetails;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use stdClass;
use Throwable;

class GoogleGeoService extends Service
{
    /** @var Client HTTP client for legacy v1 Geocoding API (used for legacy reverse geocoding endpoints) */
    protected Client $legacyClient;

    /** @var Client HTTP client for v4beta Geocoding API (used for forward and reverse geocoding) */
    protected Client $v4betaClient;

    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = Config::getEnv('GOOGLE_GEOCODING_API_KEY');

        // Legacy v1 client — used for reverse geocoding (not available in v4beta)
        $this->legacyClient = new Client([
            'base_uri' => 'https://maps.googleapis.com',
            'timeout' => 30,
        ]);

        // v4beta client — used for forward geocoding (address → location)
        $this->v4betaClient = new Client([
            'base_uri' => 'https://geocode.googleapis.com',
            'timeout' => 30,
            'headers' => [
                'X-Goog-Api-Key' => $this->apiKey,
            ],
        ]);
    }

    /**
     * Geocodes an address string using the Google Geocoding API v4beta.
     *
     * The v4beta response has no 'status' field; a synthetic 'status' is added
     * for backward compatibility with callers that check $response->status === 'OK'.
     *
     * @param string $address The address to geocode
     * @param string|null $regionCode Country/region CLDR code for biasing (e.g., 'DE', 'US')
     * @param string|null $languageCode Response language code (e.g., 'de', 'en')
     * @return stdClass|null Google Geocoding API v4beta response (with synthetic status)
     * @throws InternalErrorException
     */
    public function geocodeAddress(
        string $address,
        ?string $regionCode = null,
        ?string $languageCode = null,
    ): ?stdClass {
        try {
            $encodedAddress = rawurlencode($address);

            $queryParams = [];

            if ($regionCode) {
                $queryParams['regionCode'] = strtoupper($regionCode);
            }

            if ($languageCode) {
                $queryParams['languageCode'] = $languageCode;
            }

            $response = $this->v4betaClient->get(
                '/v4beta/geocode/address/' . $encodedAddress,
                ['query' => $queryParams]
            );

            $responseBody = $response->getBody()->getContents();
            $decoded = json_decode($responseBody);

            return $this->addSyntheticStatusToV4betaResponse($decoded);
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e, 'geocodeAddress');
        } catch (Throwable $t) {
            return $this->handleUnexpectedException($t, 'geocodeAddress');
        }
    }

    /**
     * Geocodes a city using forward (v4beta) or reverse (legacy v1) geocoding.
     *
     * Goal:
     * - Avoid duplicate city names by biasing forward geocoding towards a given lat/lng (if provided)
     *
     * Rules applied:
     * - Reverse geocoding (lat/lng only): use legacy v1 API with latlng (+ optional country)
     * - Forward geocoding (name): use v4beta with regionCode and locationBias
     * - If lat/lng are provided together with name: add locationBias rectangle to v4beta request
     *
     * @param string|null $name City name for forward geocoding
     * @param float|null $lat Latitude for reverse geocoding or location bias
     * @param float|null $lng Longitude for reverse geocoding or location bias
     * @param string|null $country Country short code (e.g., 'DE', 'US')
     * @param string|null $state State/region name or code for text hint
     * @param string|null $language Response language code (e.g., 'de', 'en')
     * @return stdClass|null Raw API response (v4beta for forward, v1 for reverse)
     * @throws InternalErrorException
     */
    public function geocodeCity(
        ?string $name = null,
        ?float $lat = null,
        ?float $lng = null,
        ?string $country = null,
        ?string $state = null,
        ?string $language = null
    ): ?stdClass {
        try {
            // -------------------------
            // Reverse geocoding (coords -> place) — stays on legacy v1 API
            // -------------------------
            if ($lat !== null && $lng !== null && ($name === null || $name === '')) {
                $queryParams = [
                    'latlng' => $lat . ',' . $lng,
                    'key' => $this->apiKey,
                ];

                if ($language) {
                    $queryParams['language'] = $language;
                }

                // Country restriction is OK in reverse mode
                if ($country) {
                    $queryParams['components'] = 'country:' . strtoupper($country);
                }

                $response = $this->legacyClient->get('/maps/api/geocode/json', ['query' => $queryParams]);

                return json_decode($response->getBody()->getContents());
            }

            // -------------------------
            // Forward geocoding (name -> place) — v4beta API
            // -------------------------
            if ($name !== null && $name !== '') {
                $address = $name;

                // State as TEXT hint (e.g. "Springfield, IL" or "München, Bayern")
                if ($state) {
                    $address .= ', ' . $state;
                }

                $encodedAddress = rawurlencode($address);

                $queryParams = [];

                if ($language) {
                    $queryParams['languageCode'] = $language;
                }

                if ($country) {
                    $queryParams['regionCode'] = strtoupper($country);
                }

                // Bias to the provided coordinates to avoid duplicates (NOT a hard filter).
                // Uses locationBias.rectangle with a ~50km bounding box around the coordinates.
                if ($lat !== null && $lng !== null) {
                    $radiusMeters = 50000.0;

                    $latDelta = $radiusMeters / 111320.0;
                    $lngDelta = $radiusMeters / (111320.0 * cos(deg2rad($lat)));

                    $queryParams['locationBias.rectangle.low.latitude'] = $lat - $latDelta;
                    $queryParams['locationBias.rectangle.low.longitude'] = $lng - $lngDelta;
                    $queryParams['locationBias.rectangle.high.latitude'] = $lat + $latDelta;
                    $queryParams['locationBias.rectangle.high.longitude'] = $lng + $lngDelta;
                }

                $response = $this->v4betaClient->get(
                    '/v4beta/geocode/address/' . $encodedAddress,
                    ['query' => $queryParams]
                );

                $decoded = json_decode($response->getBody()->getContents());

                return $this->addSyntheticStatusToV4betaResponse($decoded);
            }

            return null;

        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e, 'geocodeCity');
        } catch (Throwable $t) {
            return $this->handleUnexpectedException($t, 'geocodeCity');
        }
    }

    /**
     * Reverse geocodes coordinates using the Google Geocoding API v4beta.
     *
     * Uses the v4beta endpoint: GET /v4beta/geocode/location/{lat},{lng}
     * Response format matches forward geocoding v4beta (camelCase fields, no status).
     * A synthetic 'status' is added for backward compatibility.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param string|null $languageCode Response language code (e.g., 'de', 'en')
     * @return stdClass|null Google Geocoding API v4beta response (with synthetic status)
     * @throws InternalErrorException
     */
    public function reverseGeocodeLocation(
        float $lat,
        float $lng,
        ?string $languageCode = null,
    ): ?stdClass {
        try {
            $queryParams = [];

            if ($languageCode) {
                $queryParams['languageCode'] = $languageCode;
            }

            $response = $this->v4betaClient->get(
                '/v4beta/geocode/location/' . $lat . ',' . $lng,
                ['query' => $queryParams]
            );

            $responseBody = $response->getBody()->getContents();
            $decoded = json_decode($responseBody);

            return $this->addSyntheticStatusToV4betaResponse($decoded);
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e, 'reverseGeocodeLocation');
        } catch (Throwable $t) {
            return $this->handleUnexpectedException($t, 'reverseGeocodeLocation');
        }
    }

    /**
     * Reverse geocodes coordinates using the legacy Google Geocoding API v1.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param string|null $language Response language code (e.g., 'de', 'en')
     * @param string|null $resultType Optional result type filter (e.g., 'street_address|locality')
     * @param string|null $locationType Optional location type filter (e.g., 'ROOFTOP')
     * @param string|null $country Optional country short code for component filtering (e.g., 'DE', 'US')
     * @return stdClass|null Raw Google Geocoding API v1 response as stdClass
     * @throws InternalErrorException
     */
    public function reverseGeocode(
        float $lat,
        float $lng,
        ?string $language = null,
        ?string $resultType = null,
        ?string $locationType = null,
        ?string $country = null
    ): ?stdClass {
        try {
            $queryParams = [
                'latlng' => $lat . ',' . $lng,
                'key' => $this->apiKey,
            ];

            if ($language) {
                $queryParams['language'] = $language;
            }

            if ($resultType) {
                $queryParams['result_type'] = $resultType;
            }

            if ($locationType) {
                $queryParams['location_type'] = $locationType;
            }

            if ($country) {
                $queryParams['components'] = 'country:' . strtoupper($country);
            }

            $response = $this->legacyClient->get('/maps/api/geocode/json', ['query' => $queryParams]);

            $responseBody = $response->getBody()->getContents();
            return json_decode($responseBody);
        } catch (GuzzleException $e) {
            return $this->handleGuzzleException($e, 'reverseGeocode');
        } catch (Throwable $t) {
            return $this->handleUnexpectedException($t, 'reverseGeocode');
        }
    }

    /**
     * Adds a synthetic 'status' field to a v4beta response for backward compatibility.
     *
     * The v4beta API uses HTTP status codes instead of a 'status' field in the response body.
     * This method adds 'OK' or 'ZERO_RESULTS' to unify handling with the legacy v1 API format.
     *
     * @param stdClass|null $v4betaResponse The decoded v4beta response
     * @return stdClass|null The response with synthetic status added
     */
    protected function addSyntheticStatusToV4betaResponse(?stdClass $v4betaResponse): ?stdClass
    {
        if ($v4betaResponse === null) {
            return null;
        }

        $v4betaResponse->status = isset($v4betaResponse->results) && !empty($v4betaResponse->results)
            ? 'OK'
            : 'ZERO_RESULTS';

        return $v4betaResponse;
    }

    /**
     * Centralized Guzzle exception handling.
     * Supports both v1 error format (error_message) and v4beta error format (error.message).
     *
     * @param GuzzleException $e
     * @param string $operation Name of the operation that failed
     * @return stdClass|null
     * @throws InternalErrorException
     */
    protected function handleGuzzleException(GuzzleException $e, string $operation): ?stdClass
    {
        $exceptionDetails = new ExceptionDetails();
        $errorMessage = "Google Geocoding API Error in {$operation}";

        if ($e->hasResponse()) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errorBody, true);

            // v1 error format: {"error_message": "..."}
            if ($errorData && isset($errorData['error_message'])) {
                $errorMessage = $errorData['error_message'];
            }
            // v4beta error format: {"error": {"message": "...", "code": 400, "status": "INVALID_ARGUMENT"}}
            elseif ($errorData && isset($errorData['error']['message'])) {
                $errorMessage = $errorData['error']['message'];
            }

            $exceptionDetails->addDetail('Google Geocoding Error', [
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
     * @param Throwable $t
     * @param string $operation Name of the operation that failed
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
            throw new InternalErrorException("Unexpected error in {$operation}", $exceptionDetails);
        }
        return null;
    }
}
