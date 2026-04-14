<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Addresses;

use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegion;
use DDD\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegions;
use DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType;
use DDD\Domain\Common\Entities\PoliticalEntities\Counties\County;
use DDD\Domain\Common\Entities\PoliticalEntities\Counties\SubCounty;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Common\Entities\PoliticalEntities\Localities\Locality;
use DDD\Domain\Common\Entities\PoliticalEntities\States\State;
use DDD\Domain\Common\Repo\Argus\Addresses\ArgusPostalAddress;
use DDD\Domain\Common\Services\GeoEntities\GeoRegionsService;
use DDD\Domain\Common\Services\GeoEntities\PostalAddressService;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Interfaces\IsEmptyInterface;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\GeoEntities\GeoBounds;
use DDD\Domain\Common\Entities\GeoEntities\GeocodableGeoPoint;
use DDD\Domain\Common\Validators\NotContainingEmail\NotContainingEmailConstraint;
use DDD\Domain\Common\Validators\NotContainingOnlyDigits\NotContainingOnlyDigitsConstraint;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Arr;
use DDD\Infrastructure\Validation\Constraints\Choice;
use ReflectionException;
use stdClass;

#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusPostalAddress::class)]
class PostalAddress extends ValueObject implements IsEmptyInterface
{
    use LazyLoadTrait;

    public const string SCOPE_BILLING = 'billing';

    public const string SCOPE_PHYSICAL = 'physical';

    /** @var string indicates that the returned result reflects a precise geocode */
    public const string PRECISIOIN_ROOFTOP = 'ROOFTOP';

    /** @var string indicates that the returned result reflects an approximation (usually on a road) interpolated between two precise points (such as intersections). Interpolated results are generally returned when rooftop geocodes are unavailable for a street address */
    public const string PRECISIOIN_RANGE_INTERPOLATED = 'RANGE_INTERPOLATED';

    /** @var string indicates that the returned result is the geometric center of a result such as a polyline (for example, a street) or polygon (region) */
    public const string PRECISIOIN_GEOMETRIC_CENTER = 'GEOMETRIC_CENTER';

    /** @var string indicates that the returned result is approximate */
    public const string PRECISIOIN_APPROXIMATE = 'APPROXIMATE';

    /** @var string indicates that the address could not be geocoded / was not found */
    public const string PRECISIOIN_NOT_FOUND = 'NOT_FOUND';

    /** @var array The precision levels considered as valid for PostalAddress Valitation */
    public const array VALID_ADDRESS_PRECISION_LEVELS = [self::PRECISIOIN_ROOFTOP, self::PRECISIOIN_RANGE_INTERPOLATED];

    /** @var array The property names to be mapped */
    protected static array $propertyAllocation = [
        'street_number' => 'streetNo',
        'route' => 'street',
        'postal_code' => 'postalCode',
        'postal_code_suffix' => 'postalCodeSuffix',
        'country' => 'country',
        'administrative_area_level_1' => 'state',
        'administrative_area_level_2' => 'county',
        'administrative_area_level_3' => 'subCounty',
        'locality' => 'locality',
        'postal_town' => 'locality',
        'sublocality' => 'sublocality',
        'premise' => 'premise',
        'subpremise' => 'subpremise',
    ];

    /** @var array The property order to be mapped */
    protected static array $propertyOrder = [
        'country' => 1,
        'administrative_area_level_1' => 2,
        'administrative_area_level_2' => 3,
        'administrative_area_level_3' => 4,
        'administrative_area_level_4' => 5,
        'locality' => 6,
        'postal_town' => 7,
        'sublocality' => 8,
        'sublocality_level_1' => 9,
        'sublocality_level_2' => 10,
        'sublocality_level_3' => 11,
        'neighborhood' => 12,
        'postal_code' => 13,
        'postal_code_suffix' => 14,
        'premise' => 15,
        'subpremise' => 16,
        'route' => 17,
        'street_number' => 18,
    ];

    /**
     * Deterministic fallback chain for extracting the settlement (city-like entity) from address components.
     * "City" is not a Google data type — it's a derived semantic concept. When 'locality' is absent
     * (e.g., NYC boroughs like Brooklyn), we walk this chain to find the best city-equivalent.
     *
     * Order: locality → postal_town → administrative_area_level_3 → sublocality_level_1 → administrative_area_level_2
     *
     * Rationale:
     *  - locality: standard city in most countries
     *  - postal_town: common in UK addresses (replaces locality)
     *  - administrative_area_level_3: real municipality level in many countries (IT: comune, ES: municipio)
     *  - sublocality_level_1: borough-level (e.g., Brooklyn in NYC)
     *  - administrative_area_level_2: county-level, last resort (e.g., Kings County)
     */
    protected static array $settlementFallbackChain = [
        'locality',
        'postal_town',
        'administrative_area_level_3',
        'sublocality_level_1',
        'administrative_area_level_2',
    ];

    /**
     * Deterministic fallback chain for extracting the district (neighborhood-like entity) from address components.
     * Used to derive the sub-settlement level (e.g., "Prospect Lefferts Gardens" in Brooklyn).
     *
     * Order: neighborhood → sublocality_level_2 → sublocality → sublocality_level_1
     * Note: sublocality_level_1 is only used if it wasn't already consumed by the settlement chain.
     */
    protected static array $districtFallbackChain = [
        'neighborhood',
        'sublocality_level_2',
        'sublocality',
        'sublocality_level_1',
    ];



    /**
     * @var string|null A premise indicates a named location, usually a building or collection of buildings with a common name.
     * Verry common in UK and british colonies, e.g. "Grand Building" in London
     */
    public ?string $premise;

    /**
     * @var string|null A subpremise  indicates a first-order entity below a named location, usually a singular building within a collection of buildings with a common name
     * Verry common in UK and british colonies, e.g. "Grand Building" in London
     */
    public ?string $subpremise;

    /** @var string|null Address street number */
    public ?string $streetNo;

    /** @var string|null Address street */
    public ?string $street;

    /** @var string|null Address line 1 */
    #[NotContainingEmailConstraint]
    #[NotContainingOnlyDigitsConstraint]
    public ?string $addressLine1;

    /** @var string|null Address line 2 */
    #[NotContainingEmailConstraint]
    public ?string $addressLine2;

    /** @var string|null Address postal code */
    public ?string $postalCode;

    /** @var string|null Address postal code suffix */
    public ?string $postalCodeSuffix;

    /** @var string|null Address sublocality */
    public ?string $sublocality;

    /** @var int|null id of Locality */
    public ?int $localityId;

    /** @var Locality|null Address locality (city-equivalent, derived from Google's locality/postal_town/settlement fallback) */
    #[LazyLoad(repoType: LazyLoadRepo::LEGACY_DB)]
    public ?Locality $locality;

    /** @var SubCounty Address subCounty (administrative area level 3) e.g. Firenze in Italy */
    public SubCounty $subCounty;

    /** @var County Address county (administrative area level 2) e.g. Città Metropolitana di Napoli (NA) */
    public County $county;

    /** @var int|null id of State */
    public ?int $stateId;

    /** @var State|null Address state (administrative area level 1) */
    #[LazyLoad(repoType: LazyLoadRepo::LEGACY_DB)]
    public ?State $state;

    /** @var int|null id of Country */
    public ?int $countryId;

    /** @var Country|null Address country */
    #[LazyLoad(repoType: LazyLoadRepo::LEGACY_DB)]
    public ?Country $country;

    /** @var int|null FK to the most specific (leaf) GeoRegion for this address */
    public ?int $geoRegionId;

    // ── New GeoRegion hierarchy (replaces rigid City/State/County model) ──

    /** @var GeoRegion|null The most specific GeoRegion; ancestors reachable via parentGeoRegion */
    #[LazyLoad]
    public ?GeoRegion $geoRegion;

    /** @var AddressComponents|null All raw address components from geocoding */
    public ?AddressComponents $addressComponents;

    /** @var GeocodableGeoPoint|null GeoPoint representing geographical latitude and longitude */
    public ?GeocodableGeoPoint $geoPoint;

    /** @var GeocodableGeoPoint|null GeoPoint that has been selected by the customer by pin drop */
    public ?GeocodableGeoPoint $customerSelectedGeoPoint;

    /** @var GeoBounds|null Defins the bounds of the geographical Area */
    public ?GeoBounds $geoBounds;

    /** @var string|null Standardized formatted address */
    public ?string $formattedAddress;

    /** @var string|null Language code of the address, as geodata can be localized, e.g. Munich vs. München, the language is relevant. If not provided, default native language of country is used. */
    public ?string $languageCode;

    /** @var string Input address for Geocoding operation */
    public ?string $inputAddress;

    /** @var string Json encoded Raw results from geocode operation used to avoid live geocoding calls */
    public string $geoCodeRawResults;

    /** @var string Precision of PostalAddress geocodation */
    #[Choice([
        self::PRECISIOIN_APPROXIMATE,
        self::PRECISIOIN_ROOFTOP,
        self::PRECISIOIN_GEOMETRIC_CENTER,
        self::PRECISIOIN_RANGE_INTERPOLATED
    ])]
    public string $precision;

    protected PostalAddressService $postalAddressService;

    /** @var bool If true, the address was geocoded before, and no additional validation is required */
    protected bool $isGeoCoded = false;

    public function __construct()
    {
        $this->postalAddressService = new PostalAddressService();
        parent::__construct();
    }

    /**
     * @param string $formattedAddress
     * @param bool $streetBeforeNumber
     * @return array
     */
    public static function extractStreetInfo(string $formattedAddress, bool $streetBeforeNumber = false): array
    {
        $data = explode(',', $formattedAddress);
        return explode(' ', $data[0], 2);
    }

    /**
     * Applies geocoding results from the Google Geocoding API v4 format to a PostalAddress.
     *
     * Expected v4 field names on $addressObject:
     *  - formattedAddress (string)
     *  - addressComponents[] (longText, shortText, types[], languageCode, placeId)
     *  - postalAddress.addressLines[] (structured street-level address lines, e.g. ["108 Lincoln Rd"])
     *  - location.latitude / location.longitude
     *  - granularity (e.g. ROOFTOP, APPROXIMATE)
     *  - viewport.low.latitude/longitude, viewport.high.latitude/longitude
     *  - placeId
     *
     * addressLine1/addressLine2 are preferentially extracted from postalAddress.addressLines[]
     * when available (more reliable than parsing formattedAddress). Falls back to extractaddressLine1()
     * string manipulation when the v4 postalAddress is absent.
     *
     * @param PostalAddress $postalAddress The address entity to populate
     * @param mixed $addressObject The raw v4 geocoding result object
     * @return void
     */
    public static function applyGeoCodeRawResults(
        PostalAddress &$postalAddress,
        mixed $addressObject
    ): void {
        $postalAddress->geoCodeRawResults = json_encode($addressObject);
        $postalAddress->formattedAddress = $addressObject->formattedAddress ?? null;
        $countriesService = Country::getService();
        $statesService = State::getService();
        $localitiesService = Locality::getService();

        if (isset($postalAddress->addressLine1)) {
            $postalAddress->addressLine1 = trim($postalAddress->addressLine1);
        }
        if (isset($postalAddress->addressLine2)) {
            $postalAddress->addressLine2 = trim($postalAddress->addressLine2);
        }

        $components = $addressObject->addressComponents ?? [];

        // Sort components by $propertyOrder: broadest (country=1) → most specific (street_number=18)
        // e.g. if sublocality_level_1 and sublocality_level_2 are present, we want sublocality_level_2 to be used for sublocality
        usort($components, function ($a, $b) {
            $aPriority = 0;
            foreach ($a->types as $type) {
                if (isset(self::$propertyOrder[$type]) && self::$propertyOrder[$type] > $aPriority) {
                    $aPriority = self::$propertyOrder[$type];
                }
            }
            $bPriority = 0;
            foreach ($b->types as $type) {
                if (isset(self::$propertyOrder[$type]) && self::$propertyOrder[$type] > $bPriority) {
                    $bPriority = self::$propertyOrder[$type];
                }
            }
            return $aPriority - $bPriority;
        });

        foreach ($components as $addressComponent) {
            $longText = $addressComponent->longText ?? null;
            $shortText = $addressComponent->shortText ?? null;

            foreach ($addressComponent->types as $resultType) {
                if (isset(self::$propertyAllocation[$resultType])) {
                    $internalType = self::$propertyAllocation[$resultType];
                    if ($internalType == 'locality') {
                        $postalAddress->locality = new Locality();
                        $postalAddress->locality->name = $longText;
                        if (isset($addressComponent->languageCode)) {
                            $postalAddress->locality->setCurrentLanguageCode($addressComponent->languageCode);
                        }
                    } elseif ($internalType == 'subCounty') {
                        $postalAddress->subCounty = new SubCounty();
                        $postalAddress->subCounty->name = $longText;
                        $postalAddress->subCounty->shortCode = $shortText;
                    } elseif ($internalType == 'county') {
                        $postalAddress->county = new County();
                        $postalAddress->county->name = $longText;
                        $postalAddress->county->shortCode = $shortText;
                    } elseif ($internalType == 'state') {
                        $postalAddress->state = new State();
                        $postalAddress->state->name = $longText;
                        $postalAddress->state->shortCode = $shortText;
                        if (isset($addressComponent->languageCode)) {
                            $postalAddress->state->setCurrentLanguageCode($addressComponent->languageCode);
                        }
                    } elseif ($internalType == 'country' && $shortText) {
                        $postalAddress->country = $countriesService->findByShortCode($shortText);
                        if (isset($postalAddress->country->id)) {
                            $postalAddress->countryId = $postalAddress->country->id;
                        }
                    } else {
                        $postalAddress->$internalType = $longText;
                    }
                }
            }
        }

        // Precision (v4: granularity)
        if ($addressObject->granularity ?? null) {
            $postalAddress->precision = $addressObject->granularity;
        }
        if (!isset($postalAddress->precision)) {
            $postalAddress->precision = PostalAddress::PRECISIOIN_ROOFTOP;
        }

        // GeoPoint (v4: location.latitude / location.longitude)
        if ($addressObject->location ?? null) {
            $postalAddress->geoPoint = new GeocodableGeoPoint(
                $addressObject->location->latitude ?? 0.0, $addressObject->location->longitude ?? 0.0
            );
        }

        // GeoBounds (v4: viewport.low / viewport.high with latitude/longitude)
        if (($addressObject->viewport->low ?? null) && ($addressObject->viewport->high ?? null)) {
            $postalAddress->geoBounds = new GeoBounds();
            $postalAddress->addChildren($postalAddress->geoBounds);
            $postalAddress->geoBounds->southwest->lat = $addressObject->viewport->low->latitude ?? 0.0;
            $postalAddress->geoBounds->southwest->lng = $addressObject->viewport->low->longitude ?? 0.0;
            $postalAddress->geoBounds->northeast->lat = $addressObject->viewport->high->latitude ?? 0.0;
            $postalAddress->geoBounds->northeast->lng = $addressObject->viewport->high->longitude ?? 0.0;
        }

        if (isset($postalAddress->state)) {
            if (isset($postalAddress->country->id)) {
                $stateLanguageCode = $postalAddress->state->getCurrentLanguageCode() ?? $postalAddress->getLanguageCode();
                $postalAddress->state = $statesService->findOrCreateStateAndUpdateLocalizedName(
                    $stateLanguageCode,
                    $postalAddress->country,
                    $postalAddress->state->name,
                    $postalAddress->state->shortCode
                );
                if (isset($postalAddress->state->id)) {
                    $postalAddress->stateId = $postalAddress->state->id;
                }
            }
        }

        // Settlement fallback: when locality/postal_town are absent (e.g., Brooklyn = sublocality_level_1),
        // walk the deterministic settlement chain to find the best city-equivalent component.
        if (!isset($postalAddress->locality)) {
            $settlementComponent = self::extractComponentByTypeFallback(
                $components,
                self::$settlementFallbackChain
            );
            if ($settlementComponent) {
                $postalAddress->locality = new Locality();
                $postalAddress->locality->name = $settlementComponent->longText ?? null;
                if (isset($settlementComponent->languageCode)) {
                    $postalAddress->locality->setCurrentLanguageCode($settlementComponent->languageCode);
                }
            }
        }
        if (isset($postalAddress->locality) && $postalAddress->locality) {
            $localityLanguageCode = $postalAddress->locality->getCurrentLanguageCode() ?? $postalAddress->getLanguageCode();
            $locality = $localitiesService->findOrCreateLocalityAndUpdateLocalizedName(
                $localityLanguageCode,
                $postalAddress->country,
                $postalAddress->state,
                $postalAddress->locality->name,
                null,
                $postalAddress->geoPoint ?? null
            );
            if ($locality) {
                $postalAddress->locality = $locality;
                $postalAddress->localityId = $locality->id;
            }
        }

        // ── Build GeoRegion hierarchy from address components ──
        // Creates a parent→child chain of GeoRegions from broadest (admin_area_level_1)
        // to most specific (neighborhood) and assigns the leaf as the address's geoRegion.
        self::buildGeoRegionHierarchyFromAddressComponents($postalAddress, $components);

        if (($postalAddress->postalCode ?? null) && ($postalAddress->postalCodeSuffix ?? null)) {
            $postalAddress->postalCode .= '-' . $postalAddress->postalCodeSuffix;
        }

        // Address lines are NOT re-extracted when geocoding is imprecise,
        // the customer has pinned a custom geo point, and addressLine1 is already set.
        $skipAddressLineExtraction = !in_array(
                $postalAddress->precision,
                self::VALID_ADDRESS_PRECISION_LEVELS
            ) && ($postalAddress->customerSelectedGeoPoint ?? null) && ($postalAddress->addressLine1 ?? null);

        if (!$skipAddressLineExtraction) {
            // Prefer v4 postalAddress.addressLines[] — structured street-level lines
            // returned directly by the API, avoiding fragile string-parsing of formattedAddress.
            $apiAddressLines = $addressObject->postalAddress->addressLines ?? [];
            if (!empty($apiAddressLines)) {
                $postalAddress->addressLine1 = $apiAddressLines[0];
                if (isset($apiAddressLines[1])) {
                    $postalAddress->addressLine2 = $apiAddressLines[1];
                }
            } else {
                // Fallback: derive addressLine1 from formattedAddress via string manipulation
                $postalAddress->addressLine1 = $postalAddress->extractaddressLine1();
            }
        }
    }

    /**
     * Returns language if set, otherwise returns language from country, if country is set
     * @return string|void|null
     */
    public function getLanguageCode()
    {
        if ($this->languageCode ?? false) {
            return $this->languageCode;
        }
        if (isset($this->country) && isset($this->country->languageCode)) {
            $this->languageCode = $this->country->getDefaultLanguage()->languageCode;
            return $this->languageCode;
        }
    }

    /**
     * Walks a fallback chain of Google address component types and returns the first matching component.
     *
     * Builds a type→component lookup map from the raw addressComponents array, then iterates the
     * given fallback chain in priority order. Returns the first component that matches a type in the chain.
     *
     * @param array $addressComponents Raw addressComponents from Google Geocoding v4 (with longText, shortText, types, languageCode)
     * @param array $fallbackChain Ordered list of Google type strings to try (e.g. $settlementFallbackChain)
     * @param string|null $excludeType Optional type to skip (e.g. if already consumed by another chain)
     * @return object|null The matching address component object (with longText, shortText, types, languageCode), or null
     */
    public static function extractComponentByTypeFallback(
        array $addressComponents,
        array $fallbackChain,
        ?string $excludeType = null
    ): ?object {
        // Build type → component lookup map (first occurrence wins per type)
        $typeMap = [];
        foreach ($addressComponents as $component) {
            foreach ($component->types as $type) {
                if (!isset($typeMap[$type])) {
                    $typeMap[$type] = $component;
                }
            }
        }

        // Walk the fallback chain in priority order
        foreach ($fallbackChain as $type) {
            if ($excludeType !== null && $type === $excludeType) {
                continue;
            }
            if (isset($typeMap[$type])) {
                return $typeMap[$type];
            }
        }

        return null;
    }

    /**
     * Builds a hierarchical chain of GeoRegion entities from the geocoded address components (v4 format).
     *
     * Iterates through the already-sorted addressComponents (broadest → most specific) and
     * creates/finds GeoRegion entities for each component that has at least one eligible type.
     * Each GeoRegion is linked to the previous one as its parent, forming a chain like:
     *   admin_area_level_1 → admin_area_level_2 → locality → sublocality → neighborhood
     *
     * The most specific (leaf) GeoRegion is assigned to `$postalAddress->geoRegion`.
     *
     * Components are already sorted by `$propertyOrder` in `applyGeoCodeRawResults()` before
     * this method is called, so the iteration order is guaranteed broadest-first.
     *
     * @param PostalAddress $postalAddress The address being geocoded
     * @param array $addressComponents The sorted addressComponents from the v4 geocoding response (longText, shortText, types, languageCode, placeId)
     * @return void
     */
    protected static function buildGeoRegionHierarchyFromAddressComponents(
        PostalAddress &$postalAddress,
        array $addressComponents
    ): void {
        if (!isset($postalAddress->country->id)) {
            return;
        }

        /** @var GeoRegionsService $geoRegionsService */
        $geoRegionsService = GeoRegions::getService();
        $parentGeoRegion = null;
        $leafGeoRegion = null;

        // Address components are already sorted by $propertyOrder (broadest → most specific)
        foreach ($addressComponents as $addressComponent) {
            // Check if this component has at least one type eligible for GeoRegion creation
            $eligibleTypes = array_intersect($addressComponent->types ?? [], GeoRegionsService::$geoRegionTypeHierarchy);
            if (empty($eligibleTypes)) {
                continue;
            }

            $componentLongName = $addressComponent->longText ?? null;
            if (!$componentLongName) {
                continue;
            }

            $languageCode = $addressComponent->languageCode ?? $postalAddress->getLanguageCode() ?? 'en';
            $shortCode = $addressComponent->shortText ?? null;
            $placeId = $addressComponent->placeId ?? null;

            $geoRegion = $geoRegionsService->findOrCreateGeoRegionAndUpdateLocalizedName(
                languageCode: $languageCode,
                localizedName: $componentLongName,
                shortCode: $shortCode,
                types: $addressComponent->types ?? [],
                placeId: $placeId,
                country: $postalAddress->country,
                parentGeoRegion: $parentGeoRegion,
                geoPoint: $postalAddress->geoPoint ?? null,
            );

            if ($geoRegion) {
                $parentGeoRegion = $geoRegion;
                $leafGeoRegion = $geoRegion;
            }
        }

        // Assign the most specific (leaf) GeoRegion to the postal address
        if ($leafGeoRegion) {
            $postalAddress->geoRegion = $leafGeoRegion;
            $postalAddress->geoRegionId = $leafGeoRegion->id;
        }
    }

    /**
     * Eliminates all elements from formatted address, that don't belong to addressLine1 and returns addressLine1
     * @return string
     */
    public function extractaddressLine1(bool $useUserInputInsteadOfFormattedAddress = false): string
    {
        $formattedAddress = $useUserInputInsteadOfFormattedAddress ? ($this->inputAddress ?? '') : ($this->formattedAddress ?? '');
        $formattedAddress = $this->cleanComponentsThatDontBelongToAddressLine1And2FromString($formattedAddress);
        // if formattedAddress is empty at the end, we combine some components
        if (!$formattedAddress) {
            if ($this->country?->addressSetting?->streetNoIsBeforeStreet ?? null) {
                $formattedAddress .= $this->streetNo ?? '';
            }
            $formattedAddress .= ($formattedAddress ? ' ' : '') . ($this->street ?? '');
            if (!($this->country?->addressSetting?->streetNoIsBeforeStreet ?? null)) {
                $formattedAddress .= ($formattedAddress ? ' ' : '') . ($this->streetNo ?? '');
            }
            if (!$useUserInputInsteadOfFormattedAddress && !$formattedAddress && ($this->inputAddress ?? null)) {
                return $this->extractaddressLine1(true);
            }
        }
        $formattedAddress = trim(preg_replace('/^\s*,\s*/', '', $formattedAddress));
        $formattedAddress = trim(preg_replace('/,\s*$/i', '', $formattedAddress));
        return $formattedAddress;
    }

    /**
     * Removes from Address string e.g. city name, country name etc.
     * @param string $formattedAddress
     * @return string
     */
    public function cleanComponentsThatDontBelongToAddressLine1And2FromString(string $formattedAddress): string
    {
        $componentsToEliminate = [];
        if ($this->postalCode ?? null) {
            $componentsToEliminate[] = $this->postalCode;
        }
        if (
            isset($this->postalCodeSuffix) && isset($this->postalCode) && strlen($this->postalCode) > strlen($this->postalCodeSuffix)
        ) {
            if (str_ends_with($this->postalCode, '-' . $this->postalCodeSuffix)) {
                $componentsToEliminate[] = str_replace('-' . $this->postalCodeSuffix, '', $this->postalCode);
            }
        }
        if (isset($this->locality) && isset($this->locality->name)) {
            $componentsToEliminate[] = $this->locality->name;
        }
        if (isset($this->country) && isset($this->country->googleLocationName)) {
            $componentsToEliminate[] = $this->country->googleLocationName;
        }
        if (isset($this->country) && isset($this->country->name)) {
            $componentsToEliminate[] = $this->country->name;
            $componentsToEliminate[] = $this->country->shortCode;
            $componentsToEliminate[] = $this->country->tld;
        }
        if (isset($this->county->name)) {
            $componentsToEliminate[] = $this->county->name;
            $componentsToEliminate[] = $this->county->shortCode;
        }
        if (isset($this->subCounty->name)) {
            $componentsToEliminate[] = $this->subCounty->name;
            $componentsToEliminate[] = $this->subCounty->shortCode;
        }
        if (isset($this->state) && isset($this->state->name)) {
            $componentsToEliminate[] = $this->state->name;
            $componentsToEliminate[] = $this->state->shortCode;
        }
        if (isset($this->state) && isset($this->state->shortCode)) {
            $componentsToEliminate[] = $this->state->shortCode;
        }
        if ($this->subpremise ?? null) {
            $componentsToEliminate[] = $this->subpremise;
        }
        foreach ($componentsToEliminate as $componentToEliminate) {
            $componentToEliminateEscaped = preg_quote(
                mb_convert_case($componentToEliminate, MB_CASE_TITLE, 'UTF-8'),
                '/'
            );
            if (preg_match('/[\(\)\[\]]/', $componentToEliminate)) {
                // If the component contains parentheses or brackets, remove without word boundaries
                // avoid cases that Ellwangen \(Jagst\) cannot be replaced, since ) is not considered valid before a boundary
                $formattedAddress = preg_replace('/' . $componentToEliminateEscaped . '/i', '', $formattedAddress);
            } else {
                // escape element only if is not in street
                $street = $this->getStreetFromFormattedAddress($formattedAddress);
                if ($street) {
                    if (!str_contains(strtolower($street), strtolower($componentToEliminateEscaped))) {
                        // Otherwise, use word boundaries
                        // (?:^|\b) - (?:\b|$) - search for start/end of string (^)/($), or a word delimiter (\b) to define word boundaries. However, \b is based on the concept of a "word character" which is defined as [a-zA-Z0-9_] in many regular expression engines. This can lead to unexpected behavior when working with non-ASCII or diacritical characters, as they do not fall under the "word character" category
                        // (?<!\S) - (?!\S) - ensures that there is no non-space character  at (beginning/end of string or a space) before/after $componentToEliminateEscaped. This expression does not rely on \b to define word boundaries, but uses lookbehind and lookahead to check for spaces (or start/end of string). This makes it more robust and suitable for working with non-ASCII or diacritical characters, as it does not rely on the limited definition of "word character".
                        //$formattedAddress = preg_replace('/(?:^|\b)' . $componentToEliminateEscaped . '(?:\b|$)/i', '', $formattedAddress);
                        $formattedAddress = preg_replace(
                            '/(?<!\S)' . $componentToEliminateEscaped . '(?!\S)/i',
                            '',
                            $formattedAddress
                        );
                        // Check if the component has been completely removed
                        if (str_contains($formattedAddress, $componentToEliminate)) {
                            // Remove the component if it is only surrounded by spaces or commas
                            $formattedAddress = preg_replace(
                                '/(?<=\s|,)' . $componentToEliminateEscaped . '(?=\s|,|$)/i',
                                '',
                                $formattedAddress
                            );
                        }
                    }
                }
            }
        }

        // replce double , e.g. "Propsteistraße 1,  , "
        while (preg_match('/,\s*,/', $formattedAddress)) {
            $formattedAddress = preg_replace('/,\s*,/', ',', $formattedAddress);
        }
        $formattedAddress = trim(preg_replace('/,\s*$/i', '', $formattedAddress));
        $formattedAddress = trim(preg_replace('/,$/i', '', $formattedAddress));
        // remove special characters at the end of the string, e.g. "R. Antônio Parreiras, 30 - ,  -"
        $formattedAddress = preg_replace('/[^\\p{L}0-9]*$/u', '', $formattedAddress);
        return $formattedAddress;
    }

    /**
     * Returns street from formatted address
     * @param string|null $formattedAddress
     * @param bool|null $streetBeforeNumber
     * @return string|null
     */
    public function getStreetFromFormattedAddress(?string $formattedAddress, ?bool $streetBeforeNumber = null): ?string
    {
        if (empty($formattedAddress)) {
            return null;
        }

        // Pattern to match street number at the beginning or end of the address part
        $pattern = '/^(?P<number>\d+)\s+(?P<street>[^\d,]+)|(?P<street2>[^\d,]+)\s+(?P<number2>\d+),/';
        if (preg_match($pattern, $formattedAddress, $matches)) {
            // Check which part of the pattern matched
            $street = !empty($matches['street']) ? $matches['street'] : $matches['street2'];
            //$number = !empty($matches['number']) ? $matches['number'] : $matches['number2'];

        }
        return $street ?? null;
    }

    /**
     * @return stdClass|null Raw results from geocode operation used to avoid live geocoding calls
     */
    public function getRawResults(): ?stdClass
    {
        if (isset($this->geoCodeRawResults)) {
            return json_decode($this->geoCodeRawResults);
        }
        return null;
    }

    /**
     * Returns Address GeoPoint, if not available, geocodes address before
     * @return GeocodableGeoPoint|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function getGeoPoint(): ?GeocodableGeoPoint
    {
        if (!isset($this->geoPoint)) {
            $this->geocode();
        }
        return $this->geoPoint ?? null;
    }

    public function geocode(bool $useCache = true): void
    {
        $geocodedAddress = $this->postalAddressService->geoCodeAddress($this, $useCache);
        $geocodedAddressObject = Arr::toObject($geocodedAddress->toObject());
        $this->setPropertiesFromObject($geocodedAddressObject);
        // if geocoded address is different from current one, we set isGeoCoded to true
        if ($this->isGeoCoded() !== $geocodedAddress->isGeoCoded()) {
            $this->setIsGeoCoded($geocodedAddress->isGeoCoded());
        }
    }

    /**
     * @return bool
     */
    public function isGeoCoded(): bool
    {
        return $this->isGeoCoded;
    }

    /**
     * @param bool $isGeoCoded
     */
    public function setIsGeoCoded(bool $isGeoCoded): void
    {
        $this->isGeoCoded = $isGeoCoded;
    }

    /**
     * @return string|null Returns formatted address replaced with street and streetno part replaced by addressLine1 and addressLine2
     */
    public function getFormattedAddressBasedOnAddressLine1And2(): ?string
    {
        if (!($formattedAddress = ($this->formattedAddress ?? null)) || !($this->addressLine1 ?? null)) {
            return null;
        }
        $addressLine1 = $this->addressLine1 ?? $this->extractaddressLine1();
        $formattedAddress = str_replace([$addressLine1, $this->addressLine2 ?? ''], '', $formattedAddress);
        $formattedAddress = preg_replace('/,\s+,/i', ',', $formattedAddress);
        $formattedAddress = trim(preg_replace('/^,\s*/i', '', $formattedAddress));
        $formattedAddress = trim(preg_replace('/,$/i', '', $formattedAddress));
        $formattedAddress = preg_replace('/,\s*,/', ',', $formattedAddress);

        return trim(
            $addressLine1 . ', ' . (($this->addressLine2 ?? null) ? $this->addressLine2 . ', ' : '') . $formattedAddress,
            ' '
        );
    }

    /**
     * @param PostalAddress $other
     * @return bool
     */
    public function isEqualTo(?DefaultObject $other = null): bool
    {
        if (!$other) {
            return false;
        }
        if (($this->postalCode ?? null) && ($other->postalCode ?? null) && ($this->postalCode ?? null) != ($other->postalCode ?? null)) {
            return false;
        }
        if (($this->street ?? null) && ($other->street ?? null) && ($this->street ?? null) != ($other->street ?? null)) {
            return false;
        }

        if (($this->streetNo ?? null) && ($other->streetNo ?? null) && ($this->streetNo ?? null) != ($other->streetNo ?? null)) {
            return false;
        }
        $thisAddressLine2 = $this->addressLine2 ?? null;
        $otherAddressLine2 = $other->addressLine2 ?? null;
        if ($thisAddressLine2 !== $otherAddressLine2) {
            return false;
        }

        if (isset($this->country) && isset($this->country->id) && isset($other->country) && isset($other->country->id) && $this->country->id != $other->country->id) {
            return false;
        }

        if (isset($this->locality) && isset($this->locality->id) && isset($other->locality) && isset($other->locality->id) && $this->locality->id != $other->locality->id) {
            return false;
        }
        if (isset($this->state) && isset($this->state->id) && isset($other->state) && isset($other->state->id) && $this->state->id != $other->state->id) {
            return false;
        }
        // e.g. GEOMETRIC_CENTER cs ROOFTOP
        if (($this->precision ?? null) && ($other->precision ?? null) && ($this->precision ?? null) != ($other->precision ?? null)) {
            return false;
        }
        return true;
    }

    public function isEmpty(): bool
    {
        return !(($this->addressLine1 ?? null) || ($this->addressLine2 ?? null) || ($this->street ?? null) || ($this->locality ?? null) || ($this->country ?? null) || ($this->formattedAddress ?? null) || ($this->state ?? null));
    }

    /**
     * @return string Uses Country Address format definitions to compose formatted address, it takes into account
     * if addressLine1 and addressLine2 are set or not
     */
    public function getComposedFormattedAddressFromAddressComponentsUsingDefaultFormatFromCountry(): string
    {
        $addressFormat = isset($this->country) && isset($this->country->addressSetting->addressFormat) ? $this->country->addressSetting->addressFormat : '';

        if (isset($this->addressLine1) || isset($this->addressLine2)) {
            $lineAddress = isset($this->addressLine1) ? trim($this->addressLine1, ' ') : '';
            if (isset($this->addressLine2)) {
                $lineAddress .= ($lineAddress ? ', ' : '') . trim($this->addressLine2, ' ');
            }
            $addressFormat = preg_replace(
                '/%street%\s*,?\s*%streetNo%|%streetNo%\s*,?\s*%street%/i',
                $lineAddress,
                $addressFormat
            );
        } else {
            $addressFormat = str_replace('%street%', isset($this->street) ? $this->street : '', $addressFormat);
            $addressFormat = str_replace('%streetNo%', isset($this->streetNo) ? $this->streetNo : '', $addressFormat);
        }

        $addressFormat = str_replace(
            '%postalCode%',
            isset($this->postalCode) ? (!str_contains($addressFormat, $this->postalCode) ? $this->postalCode : '') : '',
            $addressFormat
        );
        $addressFormat = str_replace(
            '%locality%',
            isset($this->locality) && isset($this->locality->name) ? (!str_contains($addressFormat, $this->locality->name) ? $this->locality->name : '') : '',
            $addressFormat
        );
        $addressFormat = str_replace(
            '%state.shortCode%',
            isset($this->state) && isset($this->state->shortCode) ? (!str_contains(
                $addressFormat,
                $this->state->shortCode
            ) ? $this->state->shortCode : '') : '',
            $addressFormat
        );
        $addressFormat = str_replace(
            '%state.name%',
            isset($this->state) && isset($this->state->name) ? (!str_contains($addressFormat, $this->state->name) ? $this->state->name : '') : '',
            $addressFormat
        );
        $addressFormat = str_replace(
            '%country.name%',
            isset($this->country) && isset($this->country->name) ? (!str_contains(
                $addressFormat,
                $this->country->name
            ) ? $this->country->name : '') : '',
            $addressFormat
        );

        // Remove leading, trailing and duplicate commas, and replacement variables that may remain.
        $addressFormat = preg_replace('/,\s*,/', ',', $addressFormat);
        $addressFormat = trim($addressFormat, ', ');
        $addressFormat = preg_replace('/%.*?%/', '', $addressFormat);
        $addressFormat = preg_replace('/,\s*,/', ',', $addressFormat);
        $addressFormat = trim($addressFormat, ' ');

        return $addressFormat;
    }

    public function uniqueKey(): string
    {
        $key = '';
        if (isset($this->formattedAddress)) {
            $key .= $this->formattedAddress;
        }
        if (isset($this->country) && isset($this->country->shortCode)) {
            $key .= '_' . $this->country->shortCode;
        }
        if (isset($this->locality) && isset($this->locality->name)) {
            $key .= '_' . $this->locality->name;
        }
        if (isset($this->state) && isset($this->state->name)) {
            $key .= '_' . $this->state->name;
        }
        if (isset($this->streetNo)) {
            $key .= '_' . $this->streetNo;
        }
        if (isset($this->street)) {
            $key .= '_' . $this->street;
        }
        if (isset($this->addressLine1)) {
            $key .= '_' . $this->addressLine1;
        }
        if (isset($this->addressLine2)) {
            $key .= '_' . $this->addressLine2;
        }
        $key = md5($key);
        return self::uniqueKeyStatic($key);
    }

    /**
     * Returns the administrative_area_level_1 GeoRegion (equivalent to the legacy "State")
     *
     * @return GeoRegion|null
     */
    public function getAdministrativeArea(): ?GeoRegion
    {
        return $this->getGeoRegionByType(GeoType::TYPE_ADMINISTRATIVE_AREA_LEVEL_1);
    }

    /**
     * Returns the GeoRegion matching the given type by walking up the hierarchy from the leaf geoRegion.
     * Convenience shortcut for `$this->geoRegion->findAncestorByType($type)`.
     *
     * @param string $type One of the GeoRegion::TYPE_* constants
     * @return GeoRegion|null
     */
    public function getGeoRegionByType(string $type): ?GeoRegion
    {
        if (!isset($this->geoRegion)) {
            return null;
        }
        return $this->geoRegion->findAncestorByType($type);
    }

    /**
     * Returns the locality GeoRegion (equivalent to the legacy "City")
     *
     * @return GeoRegion|null
     */
    public function getLocality(): ?GeoRegion
    {
        return $this->getGeoRegionByType(GeoType::TYPE_LOCALITY);
    }

    /**
     * Returns the settlement GeoRegion by walking the settlement fallback chain on the GeoRegion hierarchy.
     * This is the semantic "city" — the most meaningful city-like entity for this address,
     * using the same deterministic chain as the raw component extraction in applyGeoCodeRawResults().
     *
     * @return GeoRegion|null The first matching GeoRegion ancestor, or null if none found
     */
    public function getSettlement(): ?GeoRegion
    {
        if (!isset($this->geoRegion)) {
            return null;
        }
        foreach (self::$settlementFallbackChain as $typeName) {
            $region = $this->geoRegion->findAncestorByType($typeName);
            if ($region) {
                return $region;
            }
        }
        return null;
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        parent::mapFromRepository($repoObject);
        // Preload entitites
        if (isset($this->countryId)) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->country;
        }
        if (isset($this->localityId)) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->locality;
        }
        if (isset($this->stateId)) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->state;
        }
        if (isset($this->geoRegionId)) {
            /** @noinspection PhpExpressionResultUnusedInspection */
            $this->geoRegion;
        }
    }
}