<?php

namespace DDD\Domain\Common\Entities\GeoEntities;

use DDD\Domain\Common\Entities\Addresses\PostalAddress;
use DDD\Domain\Base\Entities\ValueObject;

class GeoGooglePlace extends ValueObject {
    /** @var string The ID of the Google place used to retrieve the address */
    public string $placeId;

    /** @var PostalAddress The address obtained from the place ID */
    public PostalAddress $geocodedAddress;

    /**
     * Sets the language of the geocodedAddress and creates address if not existent
     * @param string $languageCode
     * @return void
     */
    public function setLanguage(string $languageCode): void
    {
        if (!($this->geocodedAddress ?? null)) {
            $this->geocodedAddress = new PostalAddress();
        }
        $this->geocodedAddress->languageCode = $languageCode;
    }

    public function uniqueKey(): string
    {
        $key = $this->placeId . '_' . ($this->geocodedAddress->languageCode ?? 'en');
        if (isset($this->geocodedAddress)) {
            $key .= '_' . $this->geocodedAddress->uniqueKey();
        }
        return self::uniqueKeyStatic($key);
    }
}