<?php

declare(strict_types=1);

namespace RAEK\Docs;

/**
 * Generates OpenAPI 3.1 from the live REST route registry, so documentation is derived from
 * the same argument schemas that validate requests. Docs cannot drift from behaviour.
 */
final class OpenApi
{
    private const ARG_KEYS = ['type', 'enum', 'minimum', 'maximum', 'minLength', 'maxLength', 'format', 'default', 'items', 'minItems', 'maxItems', 'uniqueItems', 'pattern', 'description'];

    /** @param \Closure():array<string,array<string,mixed>> $schemas */
    public function __construct(private \Closure $schemas)
    {
    }

    /** @return array<string,mixed> */
    public function build(?string $only_version = null): array
    {
        $paths = [];
        foreach (rest_get_server()->get_routes() as $route => $handlers) {
            if (!preg_match('#^/raek/(v\d+)/.+#', (string) $route, $m) || ($only_version && $only_version !== $m[1])) {
                continue;
            }
            $path = (string) preg_replace('#\(\?P<(\w+)>[^)]+\)#', '{$1}', (string) $route);
            foreach ($handlers as $handler) {
                if (!is_array($handler) || !isset($handler['methods'])) {
                    continue;
                }
                $methods = array_keys(array_filter((array) $handler['methods']));
                if (in_array('PUT', $methods, true) && in_array('PATCH', $methods, true)) {
                    $methods = array_values(array_diff($methods, ['POST'])); // POST is a legacy alias for update
                }
                foreach ($methods as $method) {
                    $paths[$path][strtolower($method)] = $this->operation($path, $method, $handler, $m[1]);
                }
            }
        }
        ksort($paths);

        $schemas = [];
        foreach (($this->schemas)() as $name => $schema) {
            if (!$only_version || !preg_match('/^v\d+\./', $name) || str_starts_with($name, $only_version . '.')) {
                $schemas[$name] = $this->clean($schema);
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'REST API Engineering Kit',
                'version' => \RAEK\VERSION,
                'description' => 'Versioned REST API. Errors follow RFC 9457 (application/problem+json). Lists expose pagination via X-WP-Total, X-WP-TotalPages and RFC 8288 Link headers. Every response carries X-Request-Id and rate-limit headers.',
            ],
            'servers' => [['url' => untrailingslashit(rest_url())]],
            'paths' => $paths,
            'components' => [
                'schemas' => $schemas,
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'Access token from POST /raek/v1/auth/token.'],
                    'basicAuth' => ['type' => 'http', 'scheme' => 'basic', 'description' => 'WordPress Application Password (username:application-password). HTTPS only.'],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $handler @return array<string,mixed> */
    private function operation(string $path, string $method, array $handler, string $version): array
    {
        $docs = (array) ($handler['raek_docs'] ?? []);
        $parameters = [];
        $body = ['type' => 'object', 'properties' => []];
        $required = [];

        foreach ((array) ($handler['args'] ?? []) as $name => $arg) {
            $schema = $this->arg_schema((array) $arg);
            if (str_contains($path, '{' . $name . '}')) {
                $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
            } elseif (in_array($method, ['GET', 'DELETE'], true)) {
                $parameters[] = ['name' => $name, 'in' => 'query', 'required' => !empty($arg['required']), 'description' => (string) ($arg['description'] ?? ''), 'schema' => $schema];
            } else {
                $body['properties'][$name] = $schema;
                if (!empty($arg['required'])) {
                    $required[] = $name;
                }
            }
        }
        if ($required) {
            $body['required'] = $required;
        }

        $status = (int) ($docs['status'] ?? 200);
        $problem = ['description' => 'Problem details', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
        $responses = [(string) $status => $this->success_response($docs, $version, $status)];
        $responses['429'] = $problem + ['headers' => ['Retry-After' => ['schema' => ['type' => 'integer']]]];
        if ($docs['auth'] ?? true) {
            $responses['401'] = $problem;
        }
        if ($body['properties'] || $parameters) {
            $responses['400'] = $problem;
        }
        if (str_contains($path, '{id}')) {
            $responses['404'] = $problem;
        }
        if ($body['properties']) {
            $responses['422'] = $problem;
        }

        $operation = [
            'summary' => (string) ($docs['summary'] ?? $method . ' ' . $path),
            'tags' => [(string) ($docs['tag'] ?? 'General')],
            'operationId' => strtolower($method) . str_replace(' ', '', ucwords(str_replace(['/', '{', '}', '-'], ' ', $path))),
            'security' => ($docs['auth'] ?? true) ? [['bearerAuth' => []], ['basicAuth' => []]] : [],
            'responses' => $responses,
        ];
        if ($parameters) {
            $operation['parameters'] = $parameters;
        }
        if ($body['properties']) {
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $body]]];
        }
        return $operation;
    }

    /** @param array<string,mixed> $docs @return array<string,mixed> */
    private function success_response(array $docs, string $version, int $status): array
    {
        $name = $docs['response'] ?? null;
        if ($name === null) {
            return ['description' => $status === 204 ? 'No content' : 'Accepted'];
        }
        $ref = str_starts_with((string) $name, '~') ? substr((string) $name, 1) : $version . '.' . $name;
        $response = [
            'description' => 'Success',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $ref]]],
        ];
        if (str_ends_with((string) $name, 'List') && $version === 'v1') {
            $response['headers'] = [
                'X-WP-Total' => ['schema' => ['type' => 'integer'], 'description' => 'Total matching records.'],
                'X-WP-TotalPages' => ['schema' => ['type' => 'integer']],
                'Link' => ['schema' => ['type' => 'string'], 'description' => 'RFC 8288 first/prev/next/last relations.'],
            ];
        }
        return $response;
    }

    /** @param array<string,mixed> $arg @return array<string,mixed> */
    private function arg_schema(array $arg): array
    {
        return array_intersect_key($arg, array_flip(self::ARG_KEYS));
    }

    /** Converts WordPress' draft-4-flavoured schema to what OpenAPI 3.1 / JSON Schema 2020-12 expects. @param array<string,mixed> $schema @return array<string,mixed> */
    private function clean(array $schema): array
    {
        unset($schema['$schema'], $schema['context'], $schema['arg_options']);
        if (isset($schema['readonly'])) {
            $schema['readOnly'] = (bool) $schema['readonly'];
            unset($schema['readonly']);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $required = [];
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property) && ($property['required'] ?? null) === true) {
                    $required[] = $name;
                }
                $schema['properties'][$name] = is_array($property) ? $this->clean(array_diff_key($property, ['required' => 1])) : $property;
            }
            if ($required) {
                $schema['required'] = $required;
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->clean($schema['items']);
        }
        return $schema;
    }
}
