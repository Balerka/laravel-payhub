<?php

namespace Balerka\LaravelPayhub\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CloudPaymentsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('payhub.gateways.cloud_payments.secret', '');

        abort_if($secret === '', 403, 'CloudPayments secret is not configured.');

        $contentHmac = (string) $request->header('Content-HMAC', '');
        $expectedHmac = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        abort_unless(hash_equals($expectedHmac, $contentHmac), 403, 'Invalid CloudPayments signature.');

        return $next($request);
    }
}
