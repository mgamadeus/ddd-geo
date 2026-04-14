<?php

namespace DDD\Domain\Common\Entities\Addresses;


use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\StringFuncs;
use ReflectionException;

class AddressComponent extends ValueObject
{
    /** @var string|null The long name value for the address component  */
    public ?string $longName;

    /** @var string|null The short name value for the address component  */
    public ?string $shortName;

    /** @var string[]|null The type of the address component  */
    public ?array $types;

    /** @var string|null ISO language code of this component (e.g., 'en', 'de') */
    public ?string $languageCode;

    public function uniqueKey(): string
    {
        $key = !empty($this->longName) ? StringFuncs::cleanText($this->longName . '_') : '';
        $key .= !empty($this->shortName) ? StringFuncs::cleanText($this->shortName . '_') : '';
        $key .= !empty($this->types[0]) ? '_' . $this->types[0] : '';
        return self::uniqueKeyStatic($key);
    }


    /**
     * @param mixed $object
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function mapFromRepository(mixed $object): void
    {
        parent::mapFromRepository($object);
        // v4 format: longText / shortText
        if (isset($object->longText)) {
            $this->longName = $object->longText;
        }
        if (isset($object->shortText)) {
            $this->shortName = $object->shortText;
        }
        if (isset($object->types)) {
            $this->types = $object->types;
        }
        if (isset($object->languageCode)) {
            $this->languageCode = $object->languageCode;
        }
    }
}