<?php

declare(strict_types=1);

namespace PhpArch\CoverageCriteria;

use PhpArch\ClassCoverageCriterion;
use ReflectionClass;

/**
 * Criterion that awards 1 point for a class having a name.
 * 
 * Since all classes have names, this always contributes 1 point to max
 * and awards 1 point if the class has any tests.
 */
class ClassNameCriterion implements ClassCoverageCriterion
{
    public function calculateMaxPoints(ReflectionClass $class): int
    {
        // All classes have names, so this always contributes 1 point
        return 1;
    }

    public function calculateCurrentPoints(ReflectionClass $class, array $coverageData): int
    {
        // Check if any of the associated assert methods were called
        if ($this->hasAssertMethods($coverageData)) {
            return 1;
        }

        return 0;
    }

    public function getName(): string
    {
        return 'Class Name';
    }

    public function getAssertMethods(): array
    {
        return ['haveNamePrefix', 'haveNameSuffix', 'matchNamePattern'];
    }

    /**
     * Check if any of the associated assert methods were called for this class.
     * 
     * @param array $classCoverage Class coverage data structure
     * @return bool True if any associated assert method was called
     */
    private function hasAssertMethods(array $classCoverage): bool
    {
        // Check if assertMethods exists
        if (!isset($classCoverage['assertMethods'])) {
            return false;
        }

        $assertMethods = $classCoverage['assertMethods'];
        $associatedMethods = $this->getAssertMethods();

        // Check if any of the associated assert methods were called
        foreach ($associatedMethods as $method) {
            if (in_array($method, $assertMethods, true)) {
                return true;
            }
        }

        return false;
    }
}
