<?php

declare(strict_types=1);

namespace RAEK\Api;

interface Provides_Schemas
{
    /**
     * JSON Schemas this controller contributes to the OpenAPI document.
     * Keys are versioned automatically; prefix a key with "~" to publish it unversioned.
     *
     * @return array<string,array<string,mixed>>
     */
    public function schemas(): array;
}
