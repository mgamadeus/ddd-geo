<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegionTypes;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes\GeoRegionType;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for GeoRegionType entities
 *
 * @method GeoRegionType find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method GeoRegionType update(DefaultObject &$entity, int $depth = 1)
 * @property DBGeoRegionTypeModel $ormInstance
 */
class DBGeoRegionType extends DBEntity
{
    public const BASE_ENTITY_CLASS = GeoRegionType::class;
    public const BASE_ORM_MODEL = DBGeoRegionTypeModel::class;
}
