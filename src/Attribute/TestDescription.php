<?php

declare(strict_types=1);

namespace PhpArch\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class TestDescription
{
    public function __construct(
        private string $description
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
