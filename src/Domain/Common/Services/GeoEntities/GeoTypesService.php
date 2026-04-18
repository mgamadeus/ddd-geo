<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\GeoEntities;

use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType;
use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoTypes;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes\DBGeoType;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes\DBGeoTypes;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Services\EntitiesService;
use ReflectionClass;

/**
 * Service for managing GeoType lookup entities.
 * All type names are stored in UPPERCASE (e.g., "LOCALITY", "ADMINISTRATIVE_AREA_LEVEL_1").
 * Input names from Google (lowercase) are automatically normalized to UPPERCASE.
 *
 * @method GeoType find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method GeoTypes findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method GeoType update(Entity $entity)
 * @method DBGeoType getEntityRepoClassInstance()
 * @method DBGeoTypes getEntitySetRepoClassInstance()
 */
class GeoTypesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = GeoType::class;

    /**
     * Imports all GeoTypes from the TYPE_* constants defined on the GeoType class.
     * For each constant value: if a GeoType with that name already exists, it counts as updated
     * (no changes needed since GeoType only has a name); otherwise a new GeoType is created.
     *
     * @return array{created: int, updated: int, total: int, geoTypes: GeoTypes} Import statistics and imported entities
     */
    public function importGeoTypesFromConstants(): array
    {
        $reflection = new ReflectionClass(GeoType::class);
        $constants = $reflection->getConstants();
        $importedGeoTypes = new GeoTypes();

        $createdCount = 0;
        $updatedCount = 0;

        DDDService::instance()->deactivateEntityRightsRestrictions();

        foreach ($constants as $constantName => $constantValue) {
            // Only process TYPE_* constants (skip non-string values)
            if (!str_starts_with($constantName, 'TYPE_') || !is_string($constantValue)) {
                continue;
            }

            $existingGeoType = $this->findByName($constantValue);

            if ($existingGeoType !== null) {
                $importedGeoTypes->add($existingGeoType);
                $updatedCount++;
            } else {
                $geoType = new GeoType();
                $geoType->name = $constantValue;
                $geoType = $this->update($geoType);
                $importedGeoTypes->add($geoType);
                $createdCount++;
            }
        }

        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();

        return [
            'created' => $createdCount,
            'updated' => $updatedCount,
            'total' => $createdCount + $updatedCount,
            'geoTypes' => $importedGeoTypes,
        ];
    }

    /**
     * Finds a GeoType by its name, or creates it if it doesn't exist.
     * Used during geocoding to ensure all Google address component types are persisted.
     * Input is automatically normalized to UPPERCASE (Google returns lowercase).
     *
     * @param string $name The Google Maps address component type name (e.g., "political", "locality")
     * @return GeoType The found or newly created GeoType
     */
    public function findOrCreateByName(string $name): GeoType
    {
        $normalizedName = GeoType::normalizeFromGoogle($name);

        $geoType = $this->findByName($normalizedName);
        if ($geoType) {
            return $geoType;
        }

        $geoType = new GeoType();
        $geoType->name = $normalizedName;

        DDDService::instance()->deactivateEntityRightsRestrictions();
        $geoType->update();
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();

        return $geoType;
    }

    /**
     * Finds a GeoType by its unique name.
     * Input is automatically normalized to UPPERCASE for consistent lookup.
     *
     * @param string $name The type name (e.g., "POLITICAL", "ADMINISTRATIVE_AREA_LEVEL_1", or Google lowercase "locality")
     * @return GeoType|null
     */
    public function findByName(string $name): ?GeoType
    {
        $normalizedName = GeoType::normalizeFromGoogle($name);
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.name = :name")
            ->setParameter('name', $normalizedName);
        return $repoClass->find($queryBuilder);
    }
}
