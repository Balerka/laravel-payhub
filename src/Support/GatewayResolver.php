<?php

namespace Balerka\LaravelPayhub\Support;

class GatewayResolver
{
    public static function active(): string
    {
        return self::normalize((string) config('payhub.gateway', 'test'));
    }

    public static function forTransaction(?string $gateway): string
    {
        return self::normalize((string) $gateway);
    }

    public static function enabled(?string $gateway = null): bool
    {
        $gateway = $gateway === null ? self::active() : self::normalize($gateway);

        return match ($gateway) {
            'test' => (bool) filter_var(config('payhub.test_mode', false), FILTER_VALIDATE_BOOL),
            'cloud_payments' => filled(config('payhub.gateways.cloud_payments.public_id'))
                && filled(config('payhub.gateways.cloud_payments.secret')),
            default => false,
        };
    }

    private static function normalize(string $gateway): string
    {
        $gateway = strtolower($gateway);

        return array_key_exists($gateway, (array) config('payhub.gateways', []))
            ? $gateway
            : '';
    }
}
