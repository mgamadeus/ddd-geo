<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\GeoEntities;

use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeocodableGeoPoint;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\Service;

class GeoPointsService extends Service
{
    /**
     * Reverse geocodes coordinates and returns the corresponding PostalAddress
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param string $language Language code for the response (default: 'en')
     * @return PostalAddress|null The reverse geocoded postal address, or null if not found
     * @throws NotFoundException When the coordinates cannot be geocoded and throwErrors is enabled
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
     * Reverse geocodes a GeoPoint and returns the corresponding PostalAddress
     *
     * Uses ArgusGeoPoint to call the batch reverse geocoding endpoint
     * (POST:/common/geodata/reverseGeocodePoint) via the Argus loading mechanism.
     *
     * @param GeocodableGeoPoint $geoPoint The GeoPoint to reverse geocode
     * @return PostalAddress|null The reverse geocoded postal address, or null if not found
     * @throws NotFoundException When the GeoPoint cannot be geocoded and throwErrors is enabled
     */
    public function reverseGeocodeGeoPoint(GeocodableGeoPoint $geoPoint): ?PostalAddress
    {
        $argusGeoPoint = new ArgusGeocodableGeoPoint();
        $argusGeoPoint->fromEntity($geoPoint);
        $argusGeoPoint->argusLoad(false, false);
        $argusGeoPoint->toEntity();
        $postalAddress = $argusGeoPoint->reverseGeocodedAddress ?? null;
        if (!$postalAddress && $this->throwErrors) {
            throw new NotFoundException('GeoPoint cannot be geocoded');
        }
        return $postalAddress;
    }
}
