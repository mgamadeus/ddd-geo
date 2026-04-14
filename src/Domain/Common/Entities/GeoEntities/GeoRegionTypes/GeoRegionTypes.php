<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes;

use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegionTypes\DBGeoRegionTypes;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * Collection of GeoRegionType junction entities
 *
 * @property GeoRegionType[] $elements
 * @method GeoRegionType getByUniqueKey(string $uniqueKey)
 * @method GeoRegionType[] getElements()
 * @method GeoRegionType first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeoRegionTypes::class)]
class GeoRegionTypes extends EntitySet
{
}
