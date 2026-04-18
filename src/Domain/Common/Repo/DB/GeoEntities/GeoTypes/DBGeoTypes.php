<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes;

use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoTypes;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method GeoTypes find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBGeoTypes extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBGeoType::class;
    public const string BASE_ENTITY_SET_CLASS = GeoTypes::class;
}
