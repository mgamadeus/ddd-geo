<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegion;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for GeoRegion entities
 *
 * @method GeoRegion find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method GeoRegion update(DefaultObject &$entity, int $depth = 1)
 * @property DBGeoRegionModel $ormInstance
 */
class DBGeoRegion extends DBEntity
{
    public const BASE_ENTITY_CLASS = GeoRegion::class;
    public const BASE_ORM_MODEL = DBGeoRegionModel::class;
}
