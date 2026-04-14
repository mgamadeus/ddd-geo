<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegion;
use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegionTypes\DBGeoRegionType;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/**
 * Junction entity linking GeoRegion to GeoType in an N:N relationship.
 * A GeoRegion can have multiple types (e.g., Brooklyn is "political" + "sublocality" + "sublocality_level_1"),
 * and a GeoType can be assigned to many GeoRegions.
 *
 * @method GeoRegionTypes getParent()
 * @method static DBGeoRegionType getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeoRegionType::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['geoRegionId', 'geoTypeId'])]
class GeoRegionType extends Entity
{
    use ChangeHistoryTrait;

    /** @var int|null FK to the GeoRegion */
    public ?int $geoRegionId = null;

    /** @var GeoRegion|null The parent GeoRegion */
    #[LazyLoad(addAsParent: true)]
    #[HideProperty]
    public ?GeoRegion $geoRegion = null;

    /** @var int|null FK to the GeoType */
    public ?int $geoTypeId = null;

    /** @var GeoType|null The associated GeoType */
    #[LazyLoad(addAsParent: true)]
    public ?GeoType $geoType = null;

    /**
     * Returns the unique key for this GeoRegion–GeoType link
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        $key = '';
        if (isset($this->id))
            $key .= $this->id;
        if (isset($this->geoRegionId))
            $key .= '_' . $this->geoRegionId;
        if (isset($this->geoTypeId))
            $key .= '_' . $this->geoTypeId;
        if (!(isset($this->geoRegionId) && isset($this->geoTypeId)) && isset($this->geoRegion) && isset($this->geoType)){
            $key .= '_' . $this->geoRegion->uniqueKey() . '_' . $this->geoType->uniqueKey();
        }
        return self::uniqueKeyStatic($key);
    }
}
