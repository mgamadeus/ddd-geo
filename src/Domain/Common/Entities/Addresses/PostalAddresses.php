<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Addresses;

use DDD\Domain\Common\Services\GeoEntities\PostalAddressService;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property PostalAddress[] $elements;
 * @method PostalAddress getByUniqueKey(string $uniqueKey)
 * @method PostalAddress[] getElements
 * @method PostalAddress first
 * @method static PostalAddressService getService()
 */
class PostalAddresses extends ObjectSet {
    public const string SERVICE_NAME = PostalAddressService::class;
}