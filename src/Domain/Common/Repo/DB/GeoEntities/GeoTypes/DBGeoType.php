<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes;

use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for GeoType entities
 *
 * @method GeoType find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method GeoType update(DefaultObject &$entity, int $depth = 1)
 * @property DBGeoTypeModel $ormInstance
 */
class DBGeoType extends DBEntity
{
    public const BASE_ENTITY_CLASS = GeoType::class;
    public const BASE_ORM_MODEL = DBGeoTypeModel::class;
}
