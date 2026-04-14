<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegionTypes;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoRegions\DBGeoRegionModel;
use DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes\DBGeoTypeModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'GeoRegionTypes')]
class DBGeoRegionTypeModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'GeoRegionType';

	public const string TABLE_NAME = 'GeoRegionTypes';

	public const string ENTITY_CLASS = 'App\Domain\Common\Entities\GeoEntities\GeoRegionTypes\GeoRegionType';

	#[ORM\Column(type: 'integer')]
	public ?int $geoRegionId;

	#[ORM\Column(type: 'integer')]
	public ?int $geoTypeId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBGeoRegionModel::class)]
	#[ORM\JoinColumn(name: 'geoRegionId', referencedColumnName: 'id')]
	public ?DBGeoRegionModel $geoRegion;

	#[ORM\ManyToOne(targetEntity: DBGeoTypeModel::class)]
	#[ORM\JoinColumn(name: 'geoTypeId', referencedColumnName: 'id')]
	public ?DBGeoTypeModel $geoType;

}