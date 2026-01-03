<?php

declare(strict_types=1);

namespace PhpArch\Reporter;

use PhpArch\TestResult;

interface ReporterInterface
{
    public function addResult(TestResult $result): void;

    public function addCoverageData(array $coverageData): void;

    public function report(): void;

    public function hasFailures(): bool;

    public function getTotalTests(): int;

    public function getPassedTests(): int;

    public function getFailedTests(): int;
}
