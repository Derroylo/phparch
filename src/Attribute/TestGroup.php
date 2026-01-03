<?php

declare(strict_types=1);

namespace PhpArch\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class TestGroup
{
    public function __construct(
        private string $name
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
