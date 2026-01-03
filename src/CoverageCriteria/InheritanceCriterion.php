<?php

declare(strict_types=1);

namespace PhpArch\CoverageCriteria;

use PhpArch\ClassCoverageCriterion;
use ReflectionClass;

/**
 * Criterion that awards 1 point if a class extends another class or implements interfaces and has tests.
 * 
 * Maximum points: 1 if class extends or implements, 0 otherwise.
 * Current points: 1 if class extends/implements AND has tests, 0 otherwise.
 */
class InheritanceCriterion implements ClassCoverageCriterion
{
    public function calculateMaxPoints(ReflectionClass $class): int
    {
        // Only contribute points if the class extends or implements something
        $hasParent = $class->getParentClass() !== false;
        $hasInterfaces = count($class->getInterfaces()) > 0;

        return ($hasParent || $hasInterfaces) ? 1 : 0;
    }

    public function calculateCurrentPoints(ReflectionClass $class, array $coverageData): int
    {
        // Award 1 point if class extends/implements AND has associated assert methods called
        $hasInheritance = $this->calculateMaxPoints($class) > 0;
        
        if ($hasInheritance && $this->hasAssertMethods($coverageData)) {
            return 1;
        }

        return 0;
    }

    public function getName(): string
    {
        return 'Inheritance';
    }

    public function getAssertMethods(): array
    {
        return ['implement', 'extend'];
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
