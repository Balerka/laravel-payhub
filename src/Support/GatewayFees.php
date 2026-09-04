<?php

namespace Balerka\LaravelPayhub\Support;

class GatewayFees
{
    public static function fee(float $amount, ?string $gateway = null): float
    {
        $commission = (float) config('payhub.gateways.'.self::configKey($gateway).'.commission', 0);

        return round($amount * $commission, 2);
    }

    public static function vat(float $fee, ?string $gateway = null): float
    {
        return round($fee * max(self::vatMultiplier($gateway) - 1, 0), 2);
    }

    public static function vatMultiplier(?string $gateway = null): float
    {
        return (float) config('payhub.gateways.'.self::configKey($gateway).'.vat', 1);
    }

    private static function configKey(?string $gateway): string
    {
        $resolvedGateway = $gateway === null
            ? GatewayResolver::active()
            : GatewayResolver::forTransaction($gateway);

        return $resolvedGateway ?: 'test';
    }
}
