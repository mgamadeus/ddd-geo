<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities\GeoTypes;

use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes\DBGeoType;
use DDD\Domain\Common\Services\GeoEntities\GeoTypesService;
use DDD\Domain\Base\Entities\Attributes\NoRecursiveUpdate;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Lookup entity for Google Maps address component type names.
 * Each GeoRegion can have multiple GeoTypes (e.g., "political", "sublocality", "sublocality_level_1")
 * linked via the GeoRegionType junction entity.
 *
 * @method static GeoTypesService getService()
 * @method static DBGeoType getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeoType::class)]
#[RolesRequiredForUpdate(Role::ADMIN)]
#[NoRecursiveUpdate]
class GeoType extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    // ── Google Maps address component types (stored UPPERCASE, normalized from Google's lowercase) ──

    /** @var string Top-level administrative division (US: state, DE: Bundesland, IT: regione) */
    public const string TYPE_ADMINISTRATIVE_AREA_LEVEL_1 = 'ADMINISTRATIVE_AREA_LEVEL_1';

    /** @var string Second-level administrative division (US: county, DE: Regierungsbezirk, IT: provincia) */
    public const string TYPE_ADMINISTRATIVE_AREA_LEVEL_2 = 'ADMINISTRATIVE_AREA_LEVEL_2';

    /** @var string Third-level administrative division (IT: comune, ES: municipio) */
    public const string TYPE_ADMINISTRATIVE_AREA_LEVEL_3 = 'ADMINISTRATIVE_AREA_LEVEL_3';

    /** @var string Fourth-level administrative division (rare) */
    public const string TYPE_ADMINISTRATIVE_AREA_LEVEL_4 = 'ADMINISTRATIVE_AREA_LEVEL_4';

    /** @var string City or town */
    public const string TYPE_LOCALITY = 'LOCALITY';

    /** @var string Generic sublocality */
    public const string TYPE_SUBLOCALITY = 'SUBLOCALITY';

    /** @var string First-level sub-division of a locality (e.g., Brooklyn in NYC) */
    public const string TYPE_SUBLOCALITY_LEVEL_1 = 'SUBLOCALITY_LEVEL_1';

    /** @var string Second-level sub-division of a locality */
    public const string TYPE_SUBLOCALITY_LEVEL_2 = 'SUBLOCALITY_LEVEL_2';

    /** @var string Third-level sub-division of a locality */
    public const string TYPE_SUBLOCALITY_LEVEL_3 = 'SUBLOCALITY_LEVEL_3';

    /** @var string Postal town (common in UK addresses) */
    public const string TYPE_POSTAL_TOWN = 'POSTAL_TOWN';

    /** @var string A neighborhood (e.g., "Lefferts Manor Historic District") */
    public const string TYPE_NEIGHBORHOOD = 'NEIGHBORHOOD';

    /** @var string Generic "political" type — most regions include this as a secondary type */
    public const string TYPE_POLITICAL = 'POLITICAL';

    /** @var string Country */
    public const string TYPE_COUNTRY = 'COUNTRY';

    /** @var string Postal code */
    public const string TYPE_POSTAL_CODE = 'POSTAL_CODE';

    /** @var string Postal code suffix */
    public const string TYPE_POSTAL_CODE_SUFFIX = 'POSTAL_CODE_SUFFIX';

    /** @var string Street route */
    public const string TYPE_ROUTE = 'ROUTE';

    /** @var string Street number */
    public const string TYPE_STREET_NUMBER = 'STREET_NUMBER';

    /** @var string A premise (named building or complex) */
    public const string TYPE_PREMISE = 'PREMISE';

    /** @var string A subpremise (unit within a building) */
    public const string TYPE_SUBPREMISE = 'SUBPREMISE';

    /** @var string Street address */
    public const string TYPE_STREET_ADDRESS = 'STREET_ADDRESS';

    /**
     * @var string The Google Maps address component type name in UPPERCASE format
     * (e.g., "POLITICAL", "LOCALITY", "ADMINISTRATIVE_AREA_LEVEL_1").
     * Unique within the system — acts as the canonical identifier.
     * Google returns lowercase names; use normalizeFromGoogle() to convert.
     */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public string $name;

    /**
     * Normalizes a Google Maps type name (lowercase) to our UPPERCASE convention.
     * Use this when converting raw Google API type strings to the canonical format
     * used in GeoType constants and stored in the database.
     *
     * Example: normalizeFromGoogle('administrative_area_level_1') → 'ADMINISTRATIVE_AREA_LEVEL_1'
     *
     * @param string $googleTypeName The raw type name from Google Geocoding API (e.g., 'locality', 'political')
     * @return string The UPPERCASE canonical type name
     */
    public static function normalizeFromGoogle(string $googleTypeName): string
    {
        return strtoupper($googleTypeName);
    }

    /**
     * Normalizes an array of Google Maps type names to UPPERCASE.
     *
     * @param string[] $googleTypeNames Array of raw type names from Google Geocoding API
     * @return string[] Array of UPPERCASE canonical type names
     */
    public static function normalizeArrayFromGoogle(array $googleTypeNames): array
    {
        return array_map(static fn(string $name) => strtoupper($name), $googleTypeNames);
    }

    /**
     * Returns a unique key based on the type name
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name ?? ($this->id ?? spl_object_id($this)));
    }
}
