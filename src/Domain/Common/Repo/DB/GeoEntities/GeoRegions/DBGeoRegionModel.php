<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\PoliticalEntities\Countries\DBCountryModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegionTypes\DBGeoRegionTypeModel;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'GeoRegions')]
class DBGeoRegionModel extends DoctrineModel
{
	public const MODEL_ALIAS = 'GeoRegion';

	public const TABLE_NAME = 'GeoRegions';

	public const ENTITY_CLASS = 'App\Domain\Common\Entities\GeoEntities\GeoRegions\GeoRegion';

	#[DatabaseColumn(isMergableJSONColumn: true)]
	#[ORM\Column(type: 'json')]
	public mixed $name;

	#[ORM\Column(type: 'string')]
	public ?string $slug;

	#[ORM\Column(type: 'string')]
	public ?string $shortCode;

	#[ORM\Column(type: 'integer')]
	public ?int $countryId;

	#[ORM\Column(type: 'integer')]
	public ?int $parentGeoRegionId;

	#[ORM\Column(type: 'string')]
	public ?string $placeId;

	#[ORM\Column(type: 'point')]
	public mixed $geoPoint;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBCountryModel::class)]
	#[ORM\JoinColumn(name: 'countryId', referencedColumnName: 'id')]
	public ?DBCountryModel $country;

	#[ORM\ManyToOne(targetEntity: DBGeoRegionModel::class)]
	#[ORM\JoinColumn(name: 'parentGeoRegionId', referencedColumnName: 'id')]
	public ?DBGeoRegionModel $parentGeoRegion;

	#[ORM\OneToMany(targetEntity: DBGeoRegionModel::class, mappedBy: 'parentGeoRegion')]
	public PersistentCollection $childGeoRegions;

	#[ORM\OneToMany(targetEntity: DBGeoRegionTypeModel::class, mappedBy: 'geoRegion')]
	public PersistentCollection $geoRegionTypes;

}