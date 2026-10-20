<?php

declare(strict_types=1);

namespace RAEK\Api;

use RAEK\Auth\Token_Service;
use RAEK\Docs\OpenApi;
use RAEK\Webhooks\Dispatcher;
use RAEK\Webhooks\Repository;

final class Router
{
    /** @var Base_Controller[]|null */
    private ?array $controllers = null;

    public function __construct(
        private Token_Service $tokens,
        private Repository $repository,
        private Dispatcher $dispatcher
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            foreach ($this->controllers() as $controller) {
                $controller->register_routes();
            }
        });
    }

    /** @return Base_Controller[] */
    private function controllers(): array
    {
        return $this->controllers ??= [
            new V1\Auth_Controller($this->tokens),
            new V1\Items_Controller(),
            new V1\Webhooks_Controller($this->repository, $this->dispatcher),
            new V1\Meta_Controller(new OpenApi(fn(): array => $this->schemas())),
            new V2\Items_Controller(),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function schemas(): array
    {
        $all = [];
        foreach ($this->controllers() as $controller) {
            if (!$controller instanceof Provides_Schemas) {
                continue;
            }
            foreach ($controller->schemas() as $name => $schema) {
                $key = str_starts_with($name, '~') ? substr($name, 1) : $controller->version() . '.' . $name;
                $all[$key] = $schema;
            }
        }
        return $all;
    }
}
