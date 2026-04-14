<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities\GeoTypes;

use DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes\DBGeoTypes;
use DDD\Domain\Common\Services\GeoEntities\GeoTypesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Collection of GeoType entities
 *
 * @property GeoType[] $elements
 * @method GeoType getByUniqueKey(string $uniqueKey)
 * @method GeoType[] getElements()
 * @method GeoType first()
 * @method static GeoTypesService getService()
 * @method static DBGeoTypes getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeoTypes::class)]
class GeoTypes extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = GeoTypesService::class;
}
