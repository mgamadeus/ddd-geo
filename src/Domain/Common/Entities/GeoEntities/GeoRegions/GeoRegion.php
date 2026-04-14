<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities\GeoRegions;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes\GeoRegionTypes;
use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions\DBGeoRegion;
use DDD\Domain\Common\Services\GeoEntities\GeoRegionsService;
use DDD\Domain\Base\Entities\Attributes\NoRecursiveUpdate;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/**
 * A generic geographic/political region that replaces the rigid City/State/County model.
 * GeoRegions form a self-referencing hierarchy (e.g., admin_1 → admin_2 → locality → sublocality)
 * using Google Maps address component types as the canonical type system.
 *
 * Types are stored via an N:N relationship through GeoRegionTypes → GeoType, since a single
 * region can have multiple types (e.g., Brooklyn is "political" + "sublocality" + "sublocality_level_1").
 *
 * @method static GeoRegionsService getService()
 * @method static DBGeoRegion getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeoRegion::class)]
#[RolesRequiredForUpdate(Role::ADMIN)]
#[NoRecursiveUpdate]
class GeoRegion extends Entity
{
    use ChangeHistoryTrait, TranslatableTrait;

    /** @var string|null The region's full name (multilingual, e.g., "New York", "Bayern", "Brooklyn") */
    #[Translatable]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT)]
    public ?string $name;

    /** @var string|null URL-safe slug (e.g., "us-ny", "de-by", "us-ny-brooklyn") */
    public ?string $slug;

    /** @var string|null Short code / abbreviation (e.g., "NY", "CA", "BY", "NRW") */
    public ?string $shortCode;

    /** @var int|null FK to the Country this region belongs to */
    public ?int $countryId;

    /** @var Country|null The country this region belongs to */
    #[LazyLoad]
    #[HideProperty]
    public ?Country $country;

    /** @var int|null FK to the parent GeoRegion (null for top-level regions like admin_area_level_1) */
    public ?int $parentGeoRegionId;

    /** @var GeoRegion|null The parent region in the hierarchy */
    #[LazyLoad(addAsParent: true)]
    public ?GeoRegion $parentGeoRegion;

    /** @var GeoRegions|null Child regions */
    #[LazyLoad]
    public ?GeoRegions $childGeoRegions;

    /** @var GeoRegionTypes|null The N:N types for this region (e.g., "political", "sublocality", "sublocality_level_1") */
    #[LazyLoad]
    public ?GeoRegionTypes $geoRegionTypes;

    /** @var string|null Google Place ID for this region */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public ?string $placeId;

    /** @var GeocodableGeoPoint|null Center point of this region */
    #[DatabaseColumn(allowsNull: false)]
    public ?GeocodableGeoPoint $geoPoint;

    /** @var string|null Current language code for geocoding context (not persisted) */
    protected ?string $currentLanguageCode;

    /**
     * Sets the region name
     *
     * @param string $name
     * @return void
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Returns a unique key based on placeId if available, otherwise name + shortCode + country
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        // Prefer placeId as the unique key — it is globally unique across Google
        if (isset($this->placeId)) {
            return self::uniqueKeyStatic($this->placeId);
        }
        $key = '';
        if (isset($this->name)) {
            $key .= $this->name;
        }
        if (isset($this->shortCode)) {
            $key .= '_' . $this->shortCode;
        }
        if (isset($this->currentLanguageCode)) {
            $key .= '_' . $this->currentLanguageCode;
        }
        if (isset($this->countryId) || isset($this->country)) {
            $key .= '_' . ($this->country->shortCode ?? $this->countryId);
        }
        return self::uniqueKeyStatic($key);
    }

    /**
     * Sets the slug based on country short code, parent hierarchy, and region name/shortCode
     *
     * @return void
     */
    public function setSlugBasedOnHierarchy(): void
    {
        $slug = '';
        if (isset($this->countryId) || isset($this->country)) {
            $slug = $this->country->shortCode . '-';
        }
        if (isset($this->shortCode)) {
            $slug .= $this->shortCode;
        } elseif (isset($this->name)) {
            $slug .= Datafilter::slug($this->name);
        }
        $this->slug = Datafilter::slug($slug);
    }

    /**
     * Returns the current language code (used for geocoding context)
     *
     * @return string|null
     */
    public function getCurrentLanguageCode(): ?string
    {
        if (!isset($this->currentLanguageCode)) {
            return null;
        }
        return $this->currentLanguageCode;
    }

    /**
     * Sets the current language code (used for geocoding context)
     *
     * @param string $languageCode
     * @return void
     */
    public function setCurrentLanguageCode(string $languageCode): void
    {
        $this->currentLanguageCode = $languageCode;
    }

    /**
     * Checks whether this GeoRegion has a specific type name via its N:N GeoRegionTypes.
     * Input is normalized to UPPERCASE for consistent comparison against stored UPPERCASE names.
     * Accepts both UPPERCASE constants (e.g., GeoType::TYPE_LOCALITY = 'LOCALITY')
     * and Google's lowercase names (e.g., 'locality').
     *
     * @param string $typeName One of the GeoType::TYPE_* constants or Google's lowercase type name
     * @return bool
     */
    public function hasType(string $typeName): bool
    {
        if (!isset($this->geoRegionTypes)) {
            return false;
        }
        $normalizedTypeName = GeoType::normalizeFromGoogle($typeName);
        foreach ($this->geoRegionTypes->getElements() as $geoRegionType) {
            if (isset($geoRegionType->geoType) && $geoRegionType->geoType->name === $normalizedTypeName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns all type names as a flat UPPERCASE array (e.g., ['POLITICAL', 'SUBLOCALITY', 'SUBLOCALITY_LEVEL_1'])
     *
     * @return string[]
     */
    public function getTypeNames(): array
    {
        if (!isset($this->geoRegionTypes)) {
            return [];
        }
        $names = [];
        foreach ($this->geoRegionTypes->getElements() as $geoRegionType) {
            if (isset($geoRegionType->geoType)) {
                $names[] = $geoRegionType->geoType->name;
            }
        }
        return $names;
    }

    /**
     * Walks up the parent hierarchy to find the first ancestor (or self) that has the given type
     *
     * @param string $typeName One of the GeoType::TYPE_* constants
     * @return GeoRegion|null
     */
    public function findAncestorByType(string $typeName): ?GeoRegion
    {
        $current = $this;
        while ($current !== null) {
            if ($current->hasType($typeName)) {
                return $current;
            }
            $current = $current->parentGeoRegion ?? null;
        }
        return null;
    }
}
