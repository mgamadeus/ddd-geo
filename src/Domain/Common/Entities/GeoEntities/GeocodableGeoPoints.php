<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities;

/**
 * @property GeocodableGeoPoint[] $elements;
 * @method GeocodableGeoPoint getByUniqueKey(string $uniqueKey)
 * @method GeocodableGeoPoint[] getElements
 * @method GeocodableGeoPoint first
 */
class GeocodableGeoPoints extends GeoPoints
{
}
