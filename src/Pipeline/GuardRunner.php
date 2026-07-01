<?php

namespace Omni\BillingEngine\Pipeline;

use Illuminate\Contracts\Container\Container;
use Omni\BillingEngine\Contracts\BillingGuard;
use Omni\BillingEngine\Support\BillingContext;
use Omni\BillingEngine\Support\GuardResult;

/**
 * Runs the ordered guard chain for a billing type. Guards are registered by
 * key; the per-type config lists which keys run and in what order, so a
 * vertical reshapes its rules without code changes.
 */
class GuardRunner
{
    /** @var array<string,class-string<BillingGuard>> */
    private array $registry = [];

    public function __construct(private Container $container) {}

    public function register(string $key, string $guardClass): void
    {
        $this->registry[$key] = $guardClass;
    }

    /**
     * @param string[] $keys ordered guard keys from the type config
     */
    public function run(BillingContext $ctx, array $keys): GuardResult
    {
        foreach ($keys as $key) {
            if (!isset($this->registry[$key])) {
                continue; // unknown key — ignore rather than fail the charge
            }

            /** @var BillingGuard $guard */
            $guard  = $this->container->make($this->registry[$key]);
            $result = $guard->check($ctx);

            if (!$result->passed()) {
                return $result; // first SKIP/DEAD short-circuits
            }
        }

        return GuardResult::pass();
    }
}
