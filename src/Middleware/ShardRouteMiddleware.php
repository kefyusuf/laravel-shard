<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\RedisShard\Contracts\ShardLocatorInterface;
use Symfony\Component\HttpFoundation\Response;

class ShardRouteMiddleware
{
    /**
     * Create a new middleware instance.
     *
     * @param ShardLocatorInterface $locator
     */
    public function __construct(
        protected ShardLocatorInterface $locator
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $table
     * @param string $paramName
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $table, string $paramName): Response
    {
        $keyValue = $request->route($paramName);

        if ($keyValue !== null) {
            $shardConnection = $this->locator->locate($table, $keyValue);
            
            if ($shardConnection !== null) {
                // Store the shard connection in the request attributes
                $request->attributes->set('shard_connection', $shardConnection);
            }
        }

        return $next($request);
    }
}
