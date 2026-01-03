<?php

declare(strict_types=1);

namespace PhpArch;

use ReflectionClass;

/**
 * Calculates class coverage based on configurable criteria.
 * 
 * This class manages a collection of criteria and calculates both
 * maximum possible points and current points for classes.
 */
class ClassCoverageCalculator
{
    /** @var ClassCoverageCriterion[] */
    private array $criteria = [];

    public function __construct()
    {
        // Add default criteria
        $this->addCriterion(new CoverageCriteria\ClassNameCriterion());
        $this->addCriterion(new CoverageCriteria\FinalClassCriterion());
        $this->addCriterion(new CoverageCriteria\InheritanceCriterion());
    }

    /**
     * Add a custom criterion to the calculator.
     * 
     * @param ClassCoverageCriterion $criterion The criterion to add
     */
    public function addCriterion(ClassCoverageCriterion $criterion): void
    {
        $this->criteria[] = $criterion;
    }

    /**
     * Get all registered criteria.
     * 
     * @return ClassCoverageCriterion[]
     */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * Calculate the maximum possible points for a class.
     * 
     * @param ReflectionClass $class The class to analyze
     * @return int Maximum possible points
     */
    public function calculateMaxPoints(ReflectionClass $class): int
    {
        $maxPoints = 0;

        foreach ($this->criteria as $criterion) {
            $maxPoints += $criterion->calculateMaxPoints($class);
        }

        return $maxPoints;
    }

    /**
     * Calculate the current points for a class based on test coverage.
     * 
     * @param ReflectionClass $class The class to analyze
     * @param array $coverageData Coverage data structure from Assert::getCoverageData()
     * @return int Current points earned
     */
    public function calculateCurrentPoints(ReflectionClass $class, array $coverageData): int
    {
        $currentPoints = 0;

        foreach ($this->criteria as $criterion) {
            $currentPoints += $criterion->calculateCurrentPoints($class, $coverageData);
        }

        return $currentPoints;
    }

    /**
     * Calculate coverage percentage for a class.
     * 
     * @param ReflectionClass $class The class to analyze
     * @param array $coverageData Coverage data structure from Assert::getCoverageData()
     * @return float Coverage percentage (0.0 to 100.0)
     */
    public function calculateCoveragePercentage(ReflectionClass $class, array $coverageData): float
    {
        $maxPoints = $this->calculateMaxPoints($class);
        
        if ($maxPoints === 0) {
            return 0.0;
        }

        $currentPoints = $this->calculateCurrentPoints($class, $coverageData);
        
        return ($currentPoints / $maxPoints) * 100.0;
    }
}
