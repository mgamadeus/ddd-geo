<?php

declare(strict_types=1);

namespace DDD\Modules\Geo;

use DDD\Infrastructure\Modules\DDDModule;

class GeoModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__;
    }

    public static function getPublicServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\Common\\Services\\GeoEntities\\',
            'DDD\\Domain\\Batch\\Services\\',
        ];
    }

    public static function getControllerPaths(): array
    {
        return [
            '/api/batch' => __DIR__ . '/Presentation/Api/Batch',
        ];
    }
}
