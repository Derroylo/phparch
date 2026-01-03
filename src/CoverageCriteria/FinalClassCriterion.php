<?php

declare(strict_types=1);

namespace PhpArch\CoverageCriteria;

use PhpArch\ClassCoverageCriterion;
use ReflectionClass;

/**
 * Criterion that awards 1 point if a class is final and has tests.
 * 
 * Maximum points: 1 if class is final, 0 otherwise.
 * Current points: 1 if class is final AND has tests, 0 otherwise.
 */
class FinalClassCriterion implements ClassCoverageCriterion
{
    public function calculateMaxPoints(ReflectionClass $class): int
    {
        // Only contribute points if the class is final
        return $class->isFinal() ? 1 : 0;
    }

    public function calculateCurrentPoints(ReflectionClass $class, array $coverageData): int
    {
        // Award 1 point if class is final AND has tests
        if ($class->isFinal() && $this->hasAssertMethods($coverageData)) {
            return 1;
        }

        return 0;
    }

    public function getName(): string
    {
        return 'Final Class';
    }

    public function getAssertMethods(): array
    {
        // No specific assert method for final classes - any test covers it
        return [];
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
