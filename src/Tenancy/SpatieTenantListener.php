<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tenancy;

/**
 * Listener for spatie/laravel-multitenancy's TenantFound event. Duck-typed
 * so the bridge loads even when the package is absent; the provider only
 * registers the listener when the package is installed and the driver is set.
 */
class SpatieTenantListener
{
    public function __construct(protected TenancyShardBridge $bridge)
    {
    }

    /**
     * @param object $event Spatie\Multitenancy\Events\TenantFound
     */
    public function handle(object $event): void
    {
        $tenant = $event->tenant ?? null;

        if ($tenant === null) {
            return;
        }

        $this->bridge->makeCurrent($tenant->getKey());
    }
}
