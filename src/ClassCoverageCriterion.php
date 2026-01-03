<?php

declare(strict_types=1);

namespace PhpArch;

use ReflectionClass;

/**
 * Interface for class coverage criteria.
 * 
 * Each criterion defines how to calculate maximum possible points
 * and current points based on test coverage.
 */
interface ClassCoverageCriterion
{
    /**
     * Calculate the maximum points this criterion can contribute for a class.
     * 
     * @param ReflectionClass $class The class to analyze
     * @return int Maximum points (0 if criterion doesn't apply to this class)
     */
    public function calculateMaxPoints(ReflectionClass $class): int;

    /**
     * Calculate the current points earned for this criterion based on test coverage.
     * 
     * @param ReflectionClass $class The class to analyze
     * @param array $coverageData Coverage data structure: ['filePath' => ['classes' => [className => ['tests' => [...]]]]]
     * @return int Current points earned (0 to max points)
     */
    public function calculateCurrentPoints(ReflectionClass $class, array $coverageData): int;

    /**
     * Get a human-readable name for this criterion.
     * 
     * @return string Criterion name
     */
    public function getName(): string;

    /**
     * Get the list of assert method names that contribute to this criterion.
     * 
     * @return string[] Array of assert method names (e.g., ['haveNamePrefix', 'haveNameSuffix'])
     */
    public function getAssertMethods(): array;
}
