<?php

namespace Otas\MicroService\Helpers;

class Origin
{
    public static function current(int|null $localPort = null): string
    {
        $origin = self::clean(request()->getSchemeAndHttpHost());

        if ($localPort && \in_array($origin, ['localhost', '127.0.0.1'])) {
            $origin = "{$origin}:{$localPort}";
        }

        return $origin;
    }

    public static function incoming(): string
    {
        return self::clean((string) request()->header('origin'));
    }

    public static function clean(string $origin): string
    {
        return \str_replace(['http://', 'https://'], '', $origin);
    }
}
