<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\GeoEntities\GeoTypes;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'GeoTypes')]
class DBGeoTypeModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'GeoType';

	public const string TABLE_NAME = 'GeoTypes';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\GeoEntities\GeoTypes\GeoType';

	#[ORM\Column(type: 'string')]
	public ?string $name;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

}