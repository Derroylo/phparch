<?php

namespace PhpArch;

class TestResult
{
    private string $testClass;
    private string $testMethod;
    private bool $passed;
    private ?string $failureMessage = null;
    private float $executionTime;
    private string $group;
    private string $displayName;

    public function __construct(
        string $testClass,
        string $testMethod,
        bool $passed,
        ?string $failureMessage = null,
        float $executionTime = 0.0,
        string $group = '',
        string $displayName = ''
    ) {
        $this->testClass = $testClass;
        $this->testMethod = $testMethod;
        $this->passed = $passed;
        $this->failureMessage = $failureMessage;
        $this->executionTime = $executionTime;
        $this->group = $group;
        $this->displayName = $displayName;
    }

    public function getTestClass(): string
    {
        return $this->testClass;
    }

    public function getTestMethod(): string
    {
        return $this->testMethod;
    }

    public function getFullTestName(): string
    {
        return $this->testClass . '::' . $this->testMethod;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function failed(): bool
    {
        return !$this->passed;
    }

    public function getFailureMessage(): ?string
    {
        return $this->failureMessage;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getGroup(): string
    {
        return $this->group;
    }
}

