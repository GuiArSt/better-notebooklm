<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind Cloudflare's tunnel and Caddy, PHP sees a private peer, plain http and the proxy's own address in
 * X-Forwarded-For. This runs before Laravel's trusted-proxy handling and, only when the real peer is a private
 * (proxy) address:
 *  - makes the visitor the client: CF-Connecting-IP replaces X-Forwarded-For, so per-IP limits count visitors.
 *    REMOTE_ADDR is left alone, so the peer stays a trusted proxy and X-Forwarded-* are still honoured;
 *  - when APP_URL is https, says the request is https whatever Caddy sent, so signed links validate and the
 *    forms post to https.
 * From a public peer nothing is touched: anyone could send these headers.
 */
class UseCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $peer = (string) $request->server->get('REMOTE_ADDR');

        if (IpUtils::checkIp($peer, $this->proxies())) {
            $client = $request->headers->get('CF-Connecting-IP');
            if ($client && filter_var($client, FILTER_VALIDATE_IP)) {
                $request->headers->set('X-Forwarded-For', $client);
            }

            if (str_starts_with((string) config('app.url'), 'https://')) {
                $request->headers->set('X-Forwarded-Proto', 'https');
                $request->headers->set('X-Forwarded-Port', '443');
                $request->server->set('HTTPS', 'on');
            }
        }

        return $next($request);
    }

    /** @return list<string> the same list bootstrap/app.php trusts */
    private function proxies(): array
    {
        return explode(',', (string) config('fortify.trusted_proxies'));
    }
}
