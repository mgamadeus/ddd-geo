<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\GeoEntities;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegion;
use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegions;
use DDD\Domain\Common\Entities\GeoEntities\GeoRegionTypes\GeoRegionType;
use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType;
use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoTypes;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Common\Repo\Argus\GeoEntities\GeoRegions\ArgusGeoRegion;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions\DBGeoRegion;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions\DBGeoRegions;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use ReflectionException;

/**
 * Service for managing GeoRegion entities.
 * Handles find-or-create logic with localized name support and N:N type assignment.
 *
 * @method GeoRegion find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method GeoRegions findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method GeoRegion update(Entity $entity)
 * @method DBGeoRegion getEntityRepoClassInstance()
 * @method DBGeoRegions getEntitySetRepoClassInstance()
 */
class GeoRegionsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = GeoRegion::class;

    /**
     * Address component types eligible for GeoRegion hierarchy creation,
     * ordered from broadest (administrative_area_level_1) to most specific (neighborhood).
     *
     * Components with at least one of these types will be persisted as GeoRegion entities
     * in a parent→child hierarchy during geocoding. Types like 'political' are excluded
     * because they are generic markers — when Google returns ['administrative_area_level_1', 'political'],
     * only 'administrative_area_level_1' is hierarchy-relevant.
     *
     * Excluded types:
     *  - 'political': generic marker, not a hierarchy level
     *  - 'country': has its own dedicated Country entity
     *  - 'postal_code', 'postal_code_suffix': not geographic/political regions
     *  - 'route', 'street_number', 'premise', 'subpremise': street-level, not regions
     */
    public static array $geoRegionTypeHierarchy = [
        'administrative_area_level_1',
        'administrative_area_level_2',
        'administrative_area_level_3',
        'administrative_area_level_4',
        'locality',
        'postal_town',
        'sublocality',
        'sublocality_level_1',
        'sublocality_level_2',
        'sublocality_level_3',
        'neighborhood',
    ];

    /**
     * Extracts the single most specific hierarchy-relevant type from a Google address component's types array.
     *
     * Google returns multiple types per component (e.g., ['administrative_area_level_1', 'political']).
     * Only hierarchy-relevant types matter for GeoRegion disambiguation — 'political' is noise.
     * This method intersects the given types with $geoRegionTypeHierarchy and returns the first match
     * (which is the most specific one due to the ordering of $geoRegionTypeHierarchy).
     *
     * @param string[] $googleTypes Array of Google Maps type names (e.g., ['administrative_area_level_1', 'political'])
     * @return string|null The hierarchy-relevant type (e.g., 'administrative_area_level_1'), or null if none found
     */
    public static function extractHierarchyRelevantType(array $googleTypes): ?string
    {
        $relevantTypes = array_intersect(self::$geoRegionTypeHierarchy, $googleTypes);
        if (empty($relevantTypes)) {
            return null;
        }
        // Return first match (array_intersect preserves key order from first array = $geoRegionTypeHierarchy)
        return reset($relevantTypes);
    }

    /**
     * Finds an existing GeoRegion or creates a new one, then ensures the localized name
     * translation is up to date for the given language code.
     *
     * Lookup order:
     *  1. By placeId (globally unique across Google)
     *  2. By name + country + parent + hierarchy type (type-aware to prevent same-name collisions
     *     e.g., "New York" state vs. "New York" city)
     *
     * If not found, a new GeoRegion is created with all provided properties and N:N type
     * assignments. The localized name is stored via the Translatable system.
     *
     * With Google Geocoding API v4, the languageCode is always available per component.
     *
     * @param string $languageCode ISO language code from the geocoding response (e.g., 'en', 'de')
     * @param string $localizedName The region name in the given language (e.g., 'Bayern', 'Bavaria', 'Brooklyn')
     * @param string|null $shortCode Short code / abbreviation (e.g., 'NY', 'BY')
     * @param string[] $types Array of Google Maps type names (e.g., ['political', 'sublocality', 'sublocality_level_1'])
     * @param string|null $placeId Google Place ID for this region
     * @param Country|null $country The country this region belongs to
     * @param GeoRegion|null $parentGeoRegion The parent region in the hierarchy (null for top-level)
     * @param GeocodableGeoPoint|null $geoPoint Center point of this region
     * @return GeoRegion|null The found or created GeoRegion, or null on failure
     */
    public function findOrCreateGeoRegionAndUpdateLocalizedName(
        string $languageCode,
        string $localizedName,
        ?string $shortCode = null,
        array $types = [],
        ?string $placeId = null,
        ?Country $country = null,
        ?GeoRegion $parentGeoRegion = null,
        ?GeocodableGeoPoint $geoPoint = null,
    ): ?GeoRegion {
        $geoRegion = null;

        // Extract the single hierarchy-relevant type from Google's type array
        // e.g., ['administrative_area_level_1', 'political'] → 'administrative_area_level_1'
        $hierarchyType = self::extractHierarchyRelevantType($types);

        // 1. Try to find by placeId (most reliable — globally unique)
        if ($placeId) {
            $geoRegion = $this->findByPlaceId($placeId);
        }

        // 2. Fallback: find by name + country + parent + hierarchy type
        //    (hierarchy-aware + type-aware to avoid same-name collisions
        //    e.g., "New York" state vs. "New York" city in the same country)
        if (!$geoRegion && $country) {
            $geoRegion = $this->findByNameAndCountryAndParentAndType(
                $localizedName,
                $country,
                $parentGeoRegion,
                $hierarchyType
            );
        }

        if (!$geoRegion) {
            // ── Create new GeoRegion ──
            $geoRegion = new GeoRegion();
            $geoRegion->name = $localizedName;
            $geoRegion->shortCode = $shortCode;
            $geoRegion->placeId = $placeId;
            $geoRegion->geoPoint = $geoPoint;

            if ($country) {
                $geoRegion->country = $country;
                $geoRegion->countryId = $country->id;
            }
            if ($parentGeoRegion && isset($parentGeoRegion->id)) {
                $geoRegion->parentGeoRegion = $parentGeoRegion;
                $geoRegion->parentGeoRegionId = $parentGeoRegion->id;
            }

            // ── Geocode to resolve proper center geoPoint and placeId ──
            // Similar to StatesService::geoCodeStateUsingDefaultLocale() and
            // LocalitiesService::geoCodeLocalityUsingDefaultLocale(), this resolves
            // the region's own center geoPoint (not the street address's geoPoint)
            // and the Google placeId for the region itself.
            // Pass $types so the Argus repo filters for the correct address component
            // (e.g., 'administrative_area_level_1' instead of 'locality' for "New York").
            $geocodedGeoRegion = $this->geoCodeGeoRegionUsingDefaultLocale($geoRegion, $types);
            if ($geocodedGeoRegion) {
                // Merge geocoded data back — prefer geocoded geoPoint and placeId
                if (isset($geocodedGeoRegion->geoPoint)) {
                    $geoRegion->geoPoint = $geocodedGeoRegion->geoPoint;
                }
                if (isset($geocodedGeoRegion->placeId) && !isset($geoRegion->placeId)) {
                    $geoRegion->placeId = $geocodedGeoRegion->placeId;
                }
                if (isset($geocodedGeoRegion->shortCode) && !isset($geoRegion->shortCode)) {
                    $geoRegion->shortCode = $geocodedGeoRegion->shortCode;
                }
                if (isset($geocodedGeoRegion->geoRegionTypes) && !isset($geoRegion->geoRegionTypes)) {
                    $geoRegion->geoRegionTypes = $geocodedGeoRegion->geoRegionTypes;
                }
            }

            $geoRegion->setSlugBasedOnHierarchy();

            // Persist with Translatable settings for the given language
            DDDService::instance()->deactivateEntityRightsRestrictions();
            Translatable::setTranslationSettingsSnapshot();
            Translatable::setCurrentCountryCode(null);
            Translatable::setCurrentLanguageCode($languageCode);
            $geoRegion->setTranslationForProperty('name', $localizedName, $languageCode);
            $geoRegion->update();

            // ── Self-referencing parent guard ──
            // After persist (when we have an id), verify parentGeoRegionId is not the same as id.
            // This can happen if a fallback incorrectly matched the region to itself.
            if (isset($geoRegion->id) && isset($geoRegion->parentGeoRegionId)
                && $geoRegion->parentGeoRegionId === $geoRegion->id
            ) {
                $geoRegion->parentGeoRegionId = null;
                $geoRegion->parentGeoRegion = null;
                $geoRegion->update();
            }

            Translatable::restoreTranslationSettingsSnapshot();

            // ── Create N:N type assignments ──
            //$this->assignTypesToGeoRegion($geoRegion, $types);

            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();

            $this->expandGeoRegionWithParentsAndTypes($geoRegion);
            return $geoRegion;
        }

        // ── Existing GeoRegion found — update localized name, placeId, and parent if needed ──
        $currentLocalizedName = $geoRegion->getTranslationForProperty('name', $languageCode);
        $nameChanged = !$currentLocalizedName || $currentLocalizedName !== $localizedName;

        // Also update placeId if it was previously missing
        $placeIdChanged = $placeId && !isset($geoRegion->placeId);

        // Also update parentGeoRegion if it was previously missing (hierarchical backfill)
        // Guard: never set parent to self — this would cause infinite recursion
        $parentChanged = $parentGeoRegion
            && isset($parentGeoRegion->id)
            && !isset($geoRegion->parentGeoRegionId)
            && $parentGeoRegion->id !== $geoRegion->id;

        if ($nameChanged || $placeIdChanged || $parentChanged) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
            if ($nameChanged) {
                $geoRegion->setTranslationForProperty('name', $localizedName, $languageCode);
            }
            if ($placeIdChanged) {
                $geoRegion->placeId = $placeId;
            }
            if ($parentChanged) {
                $geoRegion->parentGeoRegion = $parentGeoRegion;
                $geoRegion->parentGeoRegionId = $parentGeoRegion->id;
            }
            $geoRegion->update();
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        $this->expandGeoRegionWithParentsAndTypes($geoRegion);
        return $geoRegion;
    }

    /**
     * Eagerly loads the full parent hierarchy and GeoRegionTypes (including GeoType)
     * for a GeoRegion and all its ancestors.
     *
     * Walks up the parentGeoRegion chain, triggering lazy-load on each level, and
     * for every GeoRegion in the chain forces loading of geoRegionTypes and their
     * associated geoType entities. This ensures the returned GeoRegion is fully
     * expanded without relying on deferred lazy-load access by the caller.
     *
     * Includes a visited-set guard to prevent infinite loops if a GeoRegion
     * accidentally references itself as parent.
     *
     * @param GeoRegion $geoRegion The leaf GeoRegion to expand
     * @return void
     */
    protected function expandGeoRegionWithParentsAndTypes(GeoRegion $geoRegion): void
    {
        $current = $geoRegion;
        $visitedIds = [];
        while ($current !== null) {
            // Guard: break if we've already visited this GeoRegion (self-referencing loop)
            if (isset($current->id) && in_array($current->id, $visitedIds, true)) {
                break;
            }
            if (isset($current->id)) {
                $visitedIds[] = $current->id;
            }

            // Force lazy-load geoRegionTypes and each geoType within
            $geoRegionTypes = $current->geoRegionTypes;
            if ($geoRegionTypes) {
                foreach ($geoRegionTypes->getElements() as $geoRegionType) {
                    // Force lazy-load geoType on each junction entity
                    /** @noinspection PhpExpressionResultUnusedInspection */
                    $geoRegionType->geoType;
                }
            }
            // Walk up to parent — accessing parentGeoRegion triggers lazy load
            $current = $current->parentGeoRegion ?? null;
        }
    }

    /**
     * Creates GeoRegionType junction entries for each type name, linking them to the given GeoRegion.
     * Uses GeoTypesService to find-or-create the GeoType lookup entries.
     *
     * @param GeoRegion $geoRegion The GeoRegion to assign types to
     * @param string[] $typeNames Array of Google Maps type names (e.g., ['political', 'sublocality'])
     * @return void
     */
    protected function assignTypesToGeoRegion(GeoRegion $geoRegion, array $typeNames): void
    {
        if (empty($typeNames)) {
            return;
        }

        /** @var GeoTypesService $geoTypesService */
        $geoTypesService = GeoTypes::getService();

        foreach ($typeNames as $typeName) {
            $geoType = $geoTypesService->findOrCreateByName($typeName);

            $geoRegionType = new GeoRegionType();
            $geoRegionType->geoRegionId = $geoRegion->id;
            $geoRegionType->geoTypeId = $geoType->id;
            $geoRegionType->update();
        }
    }

    /**
     * Finds a GeoRegion by its Google Place ID
     *
     * @param string $placeId The Google Place ID
     * @return GeoRegion|null
     */
    public function findByPlaceId(string $placeId): ?GeoRegion
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.placeId = :placeId")
            ->setParameter('placeId', $placeId);
        return $repoClass->find($queryBuilder);
    }

    /**
     * Finds a GeoRegion by name within a specific country and matching parent hierarchy,
     * optionally filtered by hierarchy-relevant type via N:N JOIN through GeoRegionTypes → GeoType.
     *
     * Uses FULLTEXT search in BOOLEAN MODE for the name and filters by parentGeoRegionId.
     * When a hierarchy type is provided (e.g., 'administrative_area_level_1'), the query JOINs
     * through the GeoRegionTypes junction and GeoTypes lookup to ensure the matched GeoRegion
     * actually has that type — preventing same-name collisions at different hierarchy levels
     * (e.g., "New York" state vs. "New York" city).
     *
     * @param string $name The localized region name to search for
     * @param Country $country The country to search within
     * @param GeoRegion|null $parentGeoRegion The expected parent (null = top-level)
     * @param string|null $hierarchyType The hierarchy-relevant type to filter by (e.g., 'administrative_area_level_1'),
     *                                    already extracted via extractHierarchyRelevantType(). Null = no type filter.
     * @return GeoRegion|null
     */
    public function findByNameAndCountryAndParentAndType(
        string $name,
        Country $country,
        ?GeoRegion $parentGeoRegion = null,
        ?string $hierarchyType = null
    ): ?GeoRegion {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere(
            "MATCH ($alias.name) AGAINST (:searchName IN BOOLEAN MODE) > 0
            AND $alias.countryId = :countryId"
        )->setParameter('searchName', $name)->setParameter('countryId', $country->id);

        if ($parentGeoRegion && isset($parentGeoRegion->id)) {
            $queryBuilder->andWhere("$alias.parentGeoRegionId = :parentId")
                ->setParameter('parentId', $parentGeoRegion->id);
        } else {
            $queryBuilder->andWhere("$alias.parentGeoRegionId IS NULL");
        }

        // Type-aware filter: JOIN through GeoRegionTypes → GeoType to match the hierarchy-relevant type
        if ($hierarchyType) {
            $normalizedTypeName = GeoType::normalizeFromGoogle($hierarchyType);
            $queryBuilder
                ->innerJoin("$alias.geoRegionTypes", 'grt')
                ->innerJoin('grt.geoType', 'gt')
                ->andWhere('gt.name = :hierarchyTypeName')
                ->setParameter('hierarchyTypeName', $normalizedTypeName);
        }

        return $repoClass->find($queryBuilder);
    }

    /**
     * Finds a GeoRegion by shortCode within a specific country
     *
     * @param string $shortCode The region's short code (e.g., 'NY', 'BY')
     * @param Country $country The country to search within
     * @return GeoRegion|null
     */
    public function findByShortCodeAndCountry(string $shortCode, Country $country): ?GeoRegion
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere(
            "$alias.shortCode = :shortCode AND $alias.countryId = :countryId"
        )->setParameter('shortCode', $shortCode)->setParameter('countryId', $country->id);
        return $repoClass->find($queryBuilder);
    }

    /**
     * Geocodes a GeoRegion using the default locale of its Country.
     *
     * Creates an ArgusGeoRegion, sets the language from the country's default language,
     * performs forward geocoding via the geocodeGeoRegion batch endpoint,
     * and returns the populated GeoRegion entity with resolved geoPoint, placeId, and shortCode.
     *
     * This is analogous to StatesService::geoCodeStateUsingDefaultLocale() and
     * LocalitiesService::geoCodeLocalityUsingDefaultLocale() but works for any hierarchy level.
     *
     * @param GeoRegion $geoRegion GeoRegion with name and country set
     * @param string[] $requiredTypes Google Maps types to filter for in the response
     *                                (e.g., ['administrative_area_level_1', 'political']).
     *                                When set, only address components containing ALL types will match.
     * @return GeoRegion|null The geocoded GeoRegion with geoPoint and placeId, or null if geocoding fails
     * @throws BadRequestException If GeoRegion has no country
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function geoCodeGeoRegionUsingDefaultLocale(GeoRegion $geoRegion, array $requiredTypes = []): ?GeoRegion
    {
        if (!(isset($geoRegion->countryId) || isset($geoRegion->country))) {
            if ($this->throwErrors) {
                throw new BadRequestException('GeoRegion has to contain a Country in order to get default Locale');
            }
            return null;
        }
        $geoRegion->setCurrentLanguageCode($geoRegion->country->getDefaultLanguage()->languageCode);
        $argusGeoRegion = new ArgusGeoRegion();
        $argusGeoRegion = $argusGeoRegion->fromEntity($geoRegion);

        // Set required types for address component filtering (must be set after fromEntity
        // since requiredTypes is not a GeoRegion property and would not be copied)
        if (!empty($requiredTypes)) {
            $argusGeoRegion->setRequiredTypes($requiredTypes);
        }

        $argusGeoRegion->argusLoad();
        if ($argusGeoRegion->getArgusSettings()->isLoadedSuccessfully) {
            $argusGeoRegion->setSlugBasedOnHierarchy();
            return $argusGeoRegion->toEntity();
        }
        return null;
    }
}
