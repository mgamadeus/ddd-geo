<?php

namespace DDD\Domain\Common\Entities\Addresses;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property AddressComponent[] $elements;
 * @method  AddressComponent first();
 * @method AddressComponent getByUniqueKey(string $uniqueKey)
 * @method AddressComponent[] getElements()
 */
class AddressComponents extends ObjectSet
{

}