<?php

declare(strict_types=1);

namespace PhpArch;

use PhpArch\Enum\Verbosity;
use ReflectionClass;

class Assert
{
    private array $classes;
    private array $failures = [];
    private static ?Verbosity $verbosity = null;
    private static ?string $testClass = null;
    private static ?string $testMethod = null;
    private static array $coverageData = [];

    private function __construct(array $classes)
    {
        $this->classes = $classes;
    }

    public static function setVerbosity(?Verbosity $verbosity): void
    {
        self::$verbosity = $verbosity;
    }

    public static function setTestContext(?string $testClass, ?string $testMethod): void
    {
        self::$testClass = $testClass;
        self::$testMethod = $testMethod;
    }

    public static function that(array $classes): self
    {
        // Track coverage data if we have test context
        if (self::$testClass !== null && self::$testMethod !== null) {
            $testIdentifier = self::$testClass . '::' . self::$testMethod;
            
            foreach ($classes as $class) {
                $fileName = $class->getFileName();
                $className = $class->getName();
                
                if ($fileName === false || $fileName === '') {
                    continue;
                }

                // Initialize file coverage structure
                if (!isset(self::$coverageData[$fileName])) {
                    self::$coverageData[$fileName] = [
                        'tests' => [],
                        'classes' => [],
                        'coverage' => 0.0
                    ];
                }
                
                // Add test identifier if not already present
                if (!in_array($testIdentifier, self::$coverageData[$fileName]['tests'], true)) {
                    self::$coverageData[$fileName]['tests'][] = $testIdentifier;
                }
                
                // Track class-level coverage
                if (!isset(self::$coverageData[$fileName]['classes'][$className])) {
                    self::$coverageData[$fileName]['classes'][$className] = [
                        'tests' => [],
                        'assertMethods' => []
                    ];
                }
                
                // Add test to class coverage
                if (!in_array($testIdentifier, self::$coverageData[$fileName]['classes'][$className]['tests'], true)) {
                    self::$coverageData[$fileName]['classes'][$className]['tests'][] = $testIdentifier;
                }
            }
        }
        
        // Output matched classes when verbosity is DEBUG
        if (self::$verbosity !== null && self::$verbosity === Verbosity::DEBUG) {
            $testContext = '';
            if (self::$testClass !== null && self::$testMethod !== null) {
                try {
                    $reflection = new \ReflectionClass(self::$testClass);
                    $shortClassName = $reflection->getShortName();
                    $testMethodName = self::$testMethod;
                    $testContext = $shortClassName . '::' . $testMethodName;
                } catch (\ReflectionException $e) {
                    $testContext = self::$testClass . '::' . self::$testMethod;
                }
            }
            
            $count = count($classes);
            if ($testContext !== '') {
                echo "\n  [DEBUG] {$testContext}\n";
            } else {
                echo "\n  [DEBUG]\n";
            }
            
            if ($count === 0) {
                echo "    ⚠ No classes matched the selector\n";
            } else {
                echo "    ✓ Matched {$count} class(es):\n";
                foreach ($classes as $class) {
                    echo "      - " . $class->getName() . "\n";
                }
            }
        }
        
        return new self($classes);
    }

    public function haveNamePrefix(string $prefix): self
    {
        $this->recordAssertMethod('haveNamePrefix', $this->classes);
        
        foreach ($this->classes as $class) {
            $shortName = $class->getShortName();
            if (!str_starts_with($shortName, $prefix)) {
                $this->failures[] = sprintf(
                    'Class %s does not have name prefix "%s"',
                    $class->getName(),
                    $prefix
                );
            }
        }
        return $this;
    }

    public function haveNameSuffix(string $suffix): self
    {
        $this->recordAssertMethod('haveNameSuffix', $this->classes);
        
        foreach ($this->classes as $class) {
            $shortName = $class->getShortName();
            if (!str_ends_with($shortName, $suffix)) {
                $this->failures[] = sprintf(
                    'Class %s does not have name suffix "%s"',
                    $class->getName(),
                    $suffix
                );
            }
        }
        return $this;
    }

    public function matchNamePattern(string $pattern): self
    {
        $this->recordAssertMethod('matchNamePattern', $this->classes);
        
        foreach ($this->classes as $class) {
            $shortName = $class->getShortName();
            if (!preg_match($pattern, $shortName)) {
                $this->failures[] = sprintf(
                    'Class %s does not match name pattern "%s"',
                    $class->getName(),
                    $pattern
                );
            }
        }
        return $this;
    }

    public function areInvokable(): self
    {
        foreach ($this->classes as $class) {
            if (!$class->hasMethod('__invoke')) {
                $this->failures[] = sprintf(
                    'Class %s is not invokable (missing __invoke method)',
                    $class->getName()
                );
                continue;
            }
            
            $invokeMethod = $class->getMethod('__invoke');
            if (!$invokeMethod->isPublic()) {
                $this->failures[] = sprintf(
                    'Class %s has __invoke method but it is not public',
                    $class->getName()
                );
            }
        }
        return $this;
    }

    public function haveAtMostPublicMethods(int $maxCount): self
    {
        foreach ($this->classes as $class) {
            $publicMethods = array_filter(
                $class->getMethods(),
                fn($method) => $method->isPublic() && !$method->isConstructor() && $method->getDeclaringClass()->getName() === $class->getName()
            );
            
            $count = count($publicMethods);
            if ($count > $maxCount) {
                $this->failures[] = sprintf(
                    'Class %s has %d public methods, but at most %d are allowed',
                    $class->getName(),
                    $count,
                    $maxCount
                );
            }
        }
        return $this;
    }

    public function haveAtLeastPublicMethods(int $minCount): self
    {
        foreach ($this->classes as $class) {
            $publicMethods = array_filter(
                $class->getMethods(),
                fn($method) => $method->isPublic() && !$method->isConstructor()
            );
            
            $count = count($publicMethods);
            if ($count < $minCount) {
                $this->failures[] = sprintf(
                    'Class %s has %d public methods, but at least %d are required',
                    $class->getName(),
                    $count,
                    $minCount
                );
            }
        }
        return $this;
    }

    public function haveExactlyPublicMethods(int $count): self
    {
        foreach ($this->classes as $class) {
            $publicMethods = array_filter(
                $class->getMethods(),
                fn($method) => $method->isPublic() && !$method->isConstructor()
            );
            
            $actualCount = count($publicMethods);
            if ($actualCount !== $count) {
                $this->failures[] = sprintf(
                    'Class %s has %d public methods, but exactly %d are required',
                    $class->getName(),
                    $actualCount,
                    $count
                );
            }
        }
        return $this;
    }

    public function implement(string $interface): self
    {
        $this->recordAssertMethod('implement', $this->classes);
        
        foreach ($this->classes as $class) {
            if (!$class->implementsInterface($interface)) {
                $this->failures[] = sprintf(
                    'Class %s does not implement interface %s',
                    $class->getName(),
                    $interface
                );
            }
        }
        return $this;
    }

    public function extend(string $class): self
    {
        $this->recordAssertMethod('extend', $this->classes);
        
        foreach ($this->classes as $classReflection) {
            $parent = $classReflection->getParentClass();
            if (!$parent || $parent->getName() !== $class) {
                $this->failures[] = sprintf(
                    'Class %s does not extend class %s',
                    $classReflection->getName(),
                    $class
                );
            }
        }
        return $this;
    }

    public function orFail(string $message): void
    {
        // Check if no classes matched the selector
        if (empty($this->classes)) {
            $failureMessage = "Details:\n";
            $failureMessage .= "  - No classes matched the selector\n";
            throw new AssertionException($failureMessage);
        }
        
        // Check for assertion failures
        if (!empty($this->failures)) {
            $failureMessage = $message . "\n";
            $failureMessage .= "Details:\n";
            foreach ($this->failures as $failure) {
                $failureMessage .= "  - " . $failure . "\n";
            }
            throw new AssertionException($failureMessage);
        }
    }

    public function getFailures(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return !empty($this->failures);
    }

    /**
     * Get coverage data: array of file paths => array of test identifiers
     * 
     * @return array<string, array<string>>
     */
    public static function getCoverageData(): array
    {
        return self::$coverageData;
    }

    /**
     * Clear coverage data (useful for testing or resetting)
     */
    public static function clearCoverageData(): void
    {
        self::$coverageData = [];
    }

    /**
     * Record that an assert method was called for the given classes.
     * 
     * @param string $methodName The name of the assert method (e.g., 'haveNamePrefix')
     * @param array $classes Array of ReflectionClass instances
     */
    private function recordAssertMethod(string $methodName, array $classes): void
    {
        // Only record if we have test context
        if (self::$testClass === null || self::$testMethod === null) {
            return;
        }

        foreach ($classes as $class) {
            $fileName = $class->getFileName();
            $className = $class->getName();

            if ($fileName === false || $fileName === '') {
                continue;
            }

            // Ensure coverage data structure exists
            if (!isset(self::$coverageData[$fileName])) {
                continue;
            }

            // Handle backward compatibility: if it's a simple array, skip
            if (isset(self::$coverageData[$fileName][0]) && is_string(self::$coverageData[$fileName][0])) {
                continue;
            }

            // Ensure class entry exists
            if (!isset(self::$coverageData[$fileName]['classes'][$className])) {
                self::$coverageData[$fileName]['classes'][$className] = [
                    'tests' => [],
                    'assertMethods' => []
                ];
            }

            // Initialize assertMethods array if not present (backward compatibility)
            if (!isset(self::$coverageData[$fileName]['classes'][$className]['assertMethods'])) {
                self::$coverageData[$fileName]['classes'][$className]['assertMethods'] = [];
            }

            // Add assert method if not already present
            if (!in_array($methodName, self::$coverageData[$fileName]['classes'][$className]['assertMethods'], true)) {
                self::$coverageData[$fileName]['classes'][$className]['assertMethods'][] = $methodName;
            }
        }
    }
}

