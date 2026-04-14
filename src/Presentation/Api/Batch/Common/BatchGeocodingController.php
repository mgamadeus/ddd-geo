<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Batch\Common;

use DDD\Domain\Batch\Services\GoogleGeoService;
use DDD\Domain\Common\Entities\Logs\ApiLogs\LogRequest;
use DDD\Presentation\Api\Batch\Base\Dtos\BatchReponseDto;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Base\Router\Routes\Route;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

#[Route('/common/geodata/')]
#[Tag(group: 'Geocoding', name: 'Google Geocoding', description: 'Google Geocoding API Endpoints')]
#[LogRequest(logTemplate: LogRequest::LOG_TEMPLATE_LOG_400_500)]
class BatchGeocodingController extends HttpController
{
    /**
     * Geocode an address string using Google Geocoding API
     *
     * Expects JSON body with:
     * - address (string, required): The address to geocode
     * - country (string, optional): Country short code for component filtering (e.g., 'DE')
     * - language (string, optional): Response language code (e.g., 'de')
     *
     * Returns results keyed by language code for compatibility with ArgusPostalAddress
     *
     * @param Request $request
     * @param GoogleGeoService $googleGeoService
     * @return BatchReponseDto
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Post('geocodeAddress')]
    #[Summary('Geocode Address')]
    public function geocodeAddress(
        Request $request,
        GoogleGeoService $googleGeoService
    ): BatchReponseDto {
        $googleGeoService->throwErrors = true;

        $body = $request->getContent();
        $payload = json_decode($body);

        if ($payload === null) {
            throw new BadRequestException('Invalid JSON payload');
        }

        $address = $payload->address ?? null;
        if (!$address) {
            throw new BadRequestException('Missing required field: address');
        }

        $country = $payload->country ?? null;
        $language = $payload->language ?? null;

        $googleResponse = $googleGeoService->geocodeAddress($address, $country, $language);

        $responseDto = new BatchReponseDto();

        if (!$googleResponse || ($googleResponse->status ?? null) !== 'OK') {
            $responseDto->status = $googleResponse->status ?? 'Not Found';
            $responseDto->responseData = null;
            return $responseDto;
        }

        // Package results keyed by language code for ArgusPostalAddress compatibility
        $languageCode = $language ?? 'en';
        $responseData = new stdClass();
        $responseData->{$languageCode} = $googleResponse->results ?? [];

        $responseDto->status = 'OK';
        $responseDto->responseData = $responseData;

        return $responseDto;
    }

    /**
     * Reverse geocode coordinates using Google Geocoding API
     *
     * Expects JSON body with:
     * - lat (float, required): Latitude
     * - lng (float, required): Longitude
     * - language (string, optional): Response language code (e.g., 'de')
     * - result_type (string, optional): Filter by result type (e.g., 'street_address|locality')
     * - location_type (string, optional): Filter by location type (e.g., 'ROOFTOP')
     *
     * Returns results keyed by language code
     *
     * @param Request $request
     * @param GoogleGeoService $googleGeoService
     * @return BatchReponseDto
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Post('reverseGeocode')]
    #[Summary('Reverse Geocode Coordinates')]
    public function reverseGeocodeCoordinates(
        Request $request,
        GoogleGeoService $googleGeoService
    ): BatchReponseDto {
        $googleGeoService->throwErrors = true;

        $body = $request->getContent();
        $payload = json_decode($body);

        if ($payload === null) {
            throw new BadRequestException('Invalid JSON payload');
        }

        $lat = $payload->lat ?? null;
        $lng = $payload->lng ?? null;

        if ($lat === null || $lng === null) {
            throw new BadRequestException('Missing required fields: lat and lng');
        }

        $language = $payload->language ?? null;
        $resultType = $payload->result_type ?? null;
        $locationType = $payload->location_type ?? null;

        $googleResponse = $googleGeoService->reverseGeocode(
            (float)$lat,
            (float)$lng,
            $language,
            $resultType,
            $locationType
        );

        $responseDto = new BatchReponseDto();

        if (!$googleResponse || ($googleResponse->status ?? null) !== 'OK') {
            $responseDto->status = $googleResponse->status ?? 'Not Found';
            $responseDto->responseData = null;
            return $responseDto;
        }

        // Package results keyed by language code
        $languageCode = $language ?? 'en';
        $responseData = new stdClass();
        $responseData->{$languageCode} = $googleResponse->results ?? [];

        $responseDto->status = 'OK';
        $responseDto->responseData = $responseData;

        return $responseDto;
    }

    /**
     * Geocode a city by name or coordinates using Google Geocoding API
     *
     * Uses result_type=locality|postal_town|administrative_area_level_3|administrative_area_level_2
     * to restrict results to city-level entities.
     *
     * Supports two modes:
     * - Forward geocode: provide name to search by city name
     * - Reverse geocode: provide lat+lng to find city at coordinates
     *
     * Expects JSON body with:
     * - name (string, required if no lat/lng): The city name to geocode (e.g., 'Springfield', 'München')
     * - lat (float, optional): Latitude for reverse geocoding
     * - lng (float, optional): Longitude for reverse geocoding
     * - language (string, optional): Response language code (e.g., 'de', 'en')
     * - country (string, optional): Country short code to restrict results (e.g., 'DE', 'US')
     * - state (string, optional): State/region code for component filtering (e.g., 'CA', 'BY')
     *
     * @param Request $request
     * @param GoogleGeoService $googleGeoService
     * @return BatchReponseDto
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Post('geocodeCity')]
    #[Summary('Geocode City')]
    public function geocodeCity(
        Request $request,
        GoogleGeoService $googleGeoService
    ): BatchReponseDto {
        $googleGeoService->throwErrors = true;

        $body = $request->getContent();
        $payload = json_decode($body);

        if ($payload === null) {
            throw new BadRequestException('Invalid JSON payload');
        }

        $name = $payload->name ?? null;
        $lat = $payload->lat ?? null;
        $lng = $payload->lng ?? null;

        if (!$name && ($lat === null || $lng === null)) {
            throw new BadRequestException('Missing required field: either name or lat+lng must be provided');
        }

        $language = $payload->language ?? null;
        $country = $payload->country ?? null;
        $state = $payload->state ?? null;

        $googleResponse = $googleGeoService->geocodeCity(
            $name,
            $lat !== null ? (float)$lat : null,
            $lng !== null ? (float)$lng : null,
            $country,
            $state,
            $language
        );

        $responseDto = new BatchReponseDto();

        if (!$googleResponse || ($googleResponse->status ?? null) !== 'OK') {
            $responseDto->status = $googleResponse->status ?? 'Not Found';
            $responseDto->responseData = null;
            return $responseDto;
        }

        $languageCode = $language ?? 'en';
        $responseData = new stdClass();
        $responseData->{$languageCode} = $googleResponse->results ?? [];

        $responseDto->status = 'OK';
        $responseDto->responseData = $responseData;

        return $responseDto;
    }

    /**
     * Forward geocode a state/region name using Google Geocoding API
     *
     * Uses result_type=administrative_area_level_1 to restrict results to state-level entities.
     *
     * Expects JSON body with:
     * - name (string, required): The state/region name to geocode (e.g., 'California', 'Bayern')
     * - language (string, optional): Response language code (e.g., 'de', 'en')
     * - country (string, optional): Country short code to restrict results (e.g., 'DE', 'US')
     *
     * @param Request $request
     * @param GoogleGeoService $googleGeoService
     * @return BatchReponseDto
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Post('geocodeState')]
    #[Summary('Geocode State')]
    public function geocodeState(
        Request $request,
        GoogleGeoService $googleGeoService
    ): BatchReponseDto {
        $googleGeoService->throwErrors = true;

        $body = $request->getContent();
        $payload = json_decode($body);

        if ($payload === null) {
            throw new BadRequestException('Invalid JSON payload');
        }

        $name = $payload->name ?? null;

        if (!$name) {
            throw new BadRequestException('Missing required field: name');
        }

        $language = $payload->language ?? null;
        $country = $payload->country ?? null;

        // v4beta does not support result_type filtering for forward geocoding.
        // The state name + country regionCode should yield the correct result.
        $googleResponse = $googleGeoService->geocodeAddress(
            $name,
            $country,
            $language
        );

        $responseDto = new BatchReponseDto();

        if (!$googleResponse || ($googleResponse->status ?? null) !== 'OK') {
            $responseDto->status = $googleResponse->status ?? 'Not Found';
            $responseDto->responseData = null;
            return $responseDto;
        }

        $languageCode = $language ?? 'en';
        $responseData = new stdClass();
        $responseData->{$languageCode} = $googleResponse->results ?? [];

        $responseDto->status = 'OK';
        $responseDto->responseData = $responseData;

        return $responseDto;
    }

    /**
     * Reverse geocode a GeoPoint using Google Geocoding API v4beta
     *
     * Uses the v4beta endpoint: GET /v4beta/geocode/location/{lat},{lng}
     * Designed for ArgusGeoPoint compatibility. Accepts latlng as a comma-separated
     * string (e.g., '48.137154,11.576124') matching ArgusGeoPoint::getLoadPayload() format.
     *
     * Expects JSON body with:
     * - latlng (string, required): Comma-separated lat,lng (e.g., '48.137154,11.576124')
     * - language (string, optional): Response language code (e.g., 'de')
     * - use_cache (bool, optional): Whether to use cached data (ignored at this level, handled by Argus)
     *
     * Returns results keyed by language code for ArgusGeoPoint compatibility
     *
     * @param Request $request
     * @param GoogleGeoService $googleGeoService
     * @return BatchReponseDto
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Post('reverseGeocodePoint')]
    #[Summary('Reverse Geocode GeoPoint')]
    public function reverseGeocodePoint(
        Request $request,
        GoogleGeoService $googleGeoService
    ): BatchReponseDto {
        $googleGeoService->throwErrors = true;

        $body = $request->getContent();
        $payload = json_decode($body);

        if ($payload === null) {
            throw new BadRequestException('Invalid JSON payload');
        }

        $latlng = $payload->latlng ?? null;
        if (!$latlng) {
            throw new BadRequestException('Missing required field: latlng');
        }

        $latlngParts = explode(',', $latlng);
        if (count($latlngParts) !== 2) {
            throw new BadRequestException('Invalid latlng format. Expected "lat,lng" (e.g., "48.137154,11.576124")');
        }

        $lat = (float)trim($latlngParts[0]);
        $lng = (float)trim($latlngParts[1]);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new BadRequestException('Invalid coordinates: lat must be -90..90, lng must be -180..180');
        }

        $language = $payload->language ?? null;

        $googleResponse = $googleGeoService->reverseGeocodeLocation(
            $lat,
            $lng,
            $language
        );

        $responseDto = new BatchReponseDto();

        if (!$googleResponse || ($googleResponse->status ?? null) !== 'OK') {
            $responseDto->status = $googleResponse->status ?? 'Not Found';
            $responseDto->responseData = null;
            return $responseDto;
        }

        // Package results keyed by language code for ArgusGeoPoint compatibility
        $languageCode = $language ?? 'en';
        $responseData = new stdClass();
        $responseData->{$languageCode} = $googleResponse->results ?? [];

        $responseDto->status = 'OK';
        $responseDto->responseData = $responseData;

        return $responseDto;
    }

    /**
     * Forward geocode a geographic/political region name using Google Geocoding API
     *
     * Generic geocoding endpoint for GeoRegion entities at any hierarchy level
     * (admin_area_level_1, locality, sublocality, neighborhood, etc.).
     * Uses the same v4beta forward geocoding as geocodeCity with optional location bias.
     *
     * Expects JSON body with:
     * - name (string, required): The region name to geocode (e.g., 'Brooklyn', 'Bayern', 'Manhattan')
     * - language (string, optional): Response language code (e.g., 'de', 'en')
     * - country (string, optional): Country short code to restrict results (e.g., 'DE', 'US')
     * - lat (float, optional): Latitude for location bias (disambiguates same-name regions)
     * - lng (float, optional): Longitude for location bias (disambiguates same-name regions)
     *
     * @param Request $request
     * @param GoogleGeoService $googleGeoService
     * @return BatchReponseDto
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Post('geocodeGeoRegion')]
    #[Summary('Geocode GeoRegion')]
    public function geocodeGeoRegion(
        Request $request,
        GoogleGeoService $googleGeoService
    ): BatchReponseDto {
        $googleGeoService->throwErrors = true;

        $body = $request->getContent();
        $payload = json_decode($body);

        if ($payload === null) {
            throw new BadRequestException('Invalid JSON payload');
        }

        $name = $payload->name ?? null;

        if (!$name) {
            throw new BadRequestException('Missing required field: name');
        }

        $language = $payload->language ?? null;
        $country = $payload->country ?? null;
        $lat = $payload->lat ?? null;
        $lng = $payload->lng ?? null;

        // Uses geocodeCity internally — it supports forward geocoding by name
        // with optional location bias (lat/lng) and country restriction,
        // which is exactly what GeoRegions at any level need.
        $googleResponse = $googleGeoService->geocodeCity(
            $name,
            $lat !== null ? (float)$lat : null,
            $lng !== null ? (float)$lng : null,
            $country,
            null, // no state filter for generic GeoRegion geocoding
            $language
        );

        $responseDto = new BatchReponseDto();

        if (!$googleResponse || ($googleResponse->status ?? null) !== 'OK') {
            $responseDto->status = $googleResponse->status ?? 'Not Found';
            $responseDto->responseData = null;
            return $responseDto;
        }

        $languageCode = $language ?? 'en';
        $responseData = new stdClass();
        $responseData->{$languageCode} = $googleResponse->results ?? [];

        $responseDto->status = 'OK';
        $responseDto->responseData = $responseData;

        return $responseDto;
    }
}
