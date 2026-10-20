<?php

declare(strict_types=1);

namespace RAEK;

use RAEK\Api\Router;
use RAEK\Auth\Authenticator;
use RAEK\Auth\Token_Service;
use RAEK\Content\Item_Post_Type;
use RAEK\Http\Rate_Limiter;
use RAEK\Http\Request_Lifecycle;
use RAEK\Webhooks\Dispatcher;
use RAEK\Webhooks\Repository;

/**
 * Composition root: the only place that wires concrete services together.
 */
final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        Installer::maybe_upgrade();

        $tokens = new Token_Service();
        $repository = new Repository();
        $dispatcher = new Dispatcher($repository);

        (new Item_Post_Type())->register();
        (new Authenticator($tokens))->register();
        (new Request_Lifecycle(new Rate_Limiter()))->register();
        $dispatcher->register();
        (new Router($tokens, $repository, $dispatcher))->register();
    }
}
