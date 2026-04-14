<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities\GeoRegions;

use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions\DBGeoRegions;
use DDD\Domain\Common\Services\GeoEntities\GeoRegionsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * Collection of GeoRegion entities
 *
 * @property GeoRegion[] $elements
 * @method GeoRegion getByUniqueKey(string $uniqueKey)
 * @method GeoRegion[] getElements()
 * @method GeoRegion first()
 * @method static GeoRegionsService getService()
 * @method static DBGeoRegions getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeoRegions::class)]
class GeoRegions extends EntitySet
{
    public const string SERVICE_NAME = GeoRegionsService::class;
}
