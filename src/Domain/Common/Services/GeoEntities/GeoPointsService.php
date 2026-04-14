<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\GeoEntities;

use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Common\Entities\GeoEntities\GeoPoint;
use DDD\Domain\Common\Repo\Argus\GeoEntities\ArgusGeoPoint;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\Service;

class GeoPointsService extends Service
{
    /**
     * Reverse geocodes a GeoPoint and returns the corresponding PostalAddress
     *
     * Uses ArgusGeoPoint to call the batch reverse geocoding endpoint
     * (POST:/common/geodata/reverseGeocodePoint) via the Argus loading mechanism.
     *
     * @param GeoPoint $geoPoint The GeoPoint to reverse geocode
     * @return PostalAddress|null The reverse geocoded postal address, or null if not found
     * @throws NotFoundException When the GeoPoint cannot be geocoded and throwErrors is enabled
     */
    public function reverseGeocodeGeoPoint(GeoPoint $geoPoint): ?PostalAddress
    {
        $argusGeoPoint = new ArgusGeoPoint();
        $argusGeoPoint->fromEntity($geoPoint);
        $argusGeoPoint->argusLoad();
        $argusGeoPoint->toEntity();
        $postalAddress = $argusGeoPoint->reverseGeocodedAddress ?? null;
        if (!$postalAddress && $this->throwErrors) {
            throw new NotFoundException('GeoPoint cannot be geocoded');
        }
        return $postalAddress;
    }
}
