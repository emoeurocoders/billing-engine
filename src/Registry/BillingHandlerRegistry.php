<?php

namespace Omni\BillingEngine\Registry;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Omni\BillingEngine\Handlers\BillingHandler;

/**
 * Maps a billing_type to its handler class. Defaults come from config; a
 * vertical overrides a single type by calling bind() in a service provider:
 *
 *   BillingHandlerRegistry::bind('cross2', SportsFitCrossHandler::class);
 */
class BillingHandlerRegistry
{
    /** @var array<string,class-string<BillingHandler>> */
    private array $map = [];

    public function __construct(private Container $container, array $types = [])
    {
        foreach ($types as $type => $cfg) {
            if (!empty($cfg['handler'])) {
                $this->map[$type] = $cfg['handler'];
            }
        }
    }

    public function bind(string $type, string $handlerClass): void
    {
        $this->map[$type] = $handlerClass;
    }

    public function resolve(string $type): BillingHandler
    {
        if (!isset($this->map[$type])) {
            throw new InvalidArgumentException("No billing handler registered for type [{$type}].");
        }

        return $this->container->make($this->map[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->map[$type]);
    }
}
