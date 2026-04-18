<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegionTypes;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes\GeoRegionTypes;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method GeoRegionTypes find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBGeoRegionTypes extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBGeoRegionType::class;
    public const string BASE_ENTITY_SET_CLASS = GeoRegionTypes::class;
}
