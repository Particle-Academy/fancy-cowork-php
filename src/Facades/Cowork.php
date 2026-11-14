<?php

declare(strict_types=1);

namespace FancyCowork\Facades;

use FancyCowork\CoworkManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static CoworkManager identify(\Closure $resolver)
 * @method static CoworkManager members(\Closure $resolver)
 * @method static CoworkManager authorize(\Closure $policy)
 * @method static CoworkManager config(\Closure $resolver)
 * @method static CoworkManager middleware(array|\Closure $stack)
 * @method static CoworkManager predicates(array $predicates)
 * @method static CoworkManager clock(\Closure $clock)
 * @method static array contracts()
 *
 * @see CoworkManager
 */
final class Cowork extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CoworkManager::class;
    }
}
