<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegions;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method GeoRegions find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBGeoRegions extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBGeoRegion::class;
    public const string BASE_ENTITY_SET_CLASS = GeoRegions::class;
}
