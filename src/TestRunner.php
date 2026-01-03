<?php

declare(strict_types=1);

namespace PhpArch;

use PhpArch\Attribute\TestDescription;
use PhpArch\Attribute\TestGroup;
use PhpArch\Enum\Verbosity;
use PhpArch\Reporter\HtmlReporter;
use PhpArch\Reporter\ReporterInterface;
use PhpArch\Reporter\TerminalReporter;
use ReflectionClass;
use ReflectionMethod;

class TestRunner
{
    private string $testDirectory;
    private ReporterInterface $reporter;
    private Verbosity $verbosity;
    private ClassCoverageCalculator $coverageCalculator;

    public function __construct(string $testDirectory, Verbosity $verbosity = Verbosity::NONE, ?bool $useColors = null, ?string $coverageOutputDir = null)
    {
        $this->testDirectory = rtrim($testDirectory, '/');
        $this->verbosity = $verbosity;
        
        // Create appropriate reporter based on coverage mode
        if ($coverageOutputDir !== null) {
            $this->reporter = new HtmlReporter($coverageOutputDir);
        } else {
            $this->reporter = new TerminalReporter($useColors);
        }

        $this->coverageCalculator = new ClassCoverageCalculator();
    }

    public function run(): ReporterInterface
    {
        // Clear coverage data before running tests
        Assert::clearCoverageData();
        
        // Output the test directory
        $this->verbose('Test directory: ' . $this->testDirectory, Verbosity::VERY_VERBOSE);
        
        $testFiles = $this->discoverTestFiles();
        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile);
        }
        
        // Calculate coverage and add to reporter
        $coverageData = $this->calculateCoverage(Assert::getCoverageData());
        $this->reporter->addCoverageData($coverageData);
        
        return $this->reporter;
    }

    private function calculateCoverage(array $coverageData): array
    {
        foreach ($coverageData as $file => $data) {
            $coverageData[$file]['coverage'] = $this->calculateFileCoverage($file, $data);
        }

        return $coverageData;
    }

    private function calculateFileCoverage(string $file, array $data): float
    {
        if (empty($data['classes'])) {
            return 0.0;
        }

        $totalMaxPoints = 0;
        $totalCurrentPoints = 0;

        foreach ($data['classes'] as $class => $classData) {
            try {
                // Try to create ReflectionClass for accurate analysis
                $reflectionClass = new ReflectionClass($class);

                // Calculate max and current points for this class
                $maxPoints = $this->coverageCalculator->calculateMaxPoints($reflectionClass);
                $currentPoints = $this->coverageCalculator->calculateCurrentPoints($reflectionClass, $classData);
                
                $totalMaxPoints += $maxPoints;
                $totalCurrentPoints += $currentPoints;
            } catch (\ReflectionException $e) {
                $this->verbose('Class ' . $class . ' not found', Verbosity::VERY_VERBOSE);
                continue;
            }
        }

        if ($totalCurrentPoints > $totalMaxPoints) {
            $totalCurrentPoints = $totalMaxPoints;
        }

        return ($totalCurrentPoints / $totalMaxPoints) * 100.0;
    }

    /**
     * Output a message if the current verbosity level is at least the required level
     * 
     * @param string $message The message to output
     * @param Verbosity $minLevel The minimum verbosity level required to show this message
     */
    private function verbose(string $message, Verbosity $minLevel = Verbosity::VERBOSE): void
    {
        if ($this->verbosity->value >= $minLevel->value) {
            echo $message . PHP_EOL;
        }
    }
    
    /**
     * Discover all test files in the test directory
     * 
     * @return string[]
     */
    private function discoverTestFiles(): array
    {
        $testFiles = [];
        
        $this->verbose('Discovering test files in ' . $this->testDirectory, Verbosity::VERY_VERBOSE);

        if (!is_dir($this->testDirectory)) {
            $this->verbose('Test directory is not a directory', Verbosity::VERY_VERBOSE);
            return $testFiles;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->testDirectory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->verbose('Found test file: ' . $file->getFilename(), Verbosity::VERY_VERBOSE);
                $filename = $file->getFilename();
                // Match *Test.php or *TestCase.php
                if (preg_match('/Test(?:Case)?\.php$/', $filename)) {
                    $realPath = $file->getRealPath();
                    if ($realPath !== false) {
                        $testFiles[] = $realPath;
                    }
                } else {
                    $this->verbose('Skipping non-test file: ' . $file->getFilename(), Verbosity::VERY_VERBOSE);
                }
            }
        }
        
        $this->verbose('Found ' . count($testFiles) . ' test files', Verbosity::VERY_VERBOSE);
        
        return $testFiles;
    }

    /**
     * Run all tests in a test file
     */
    private function runTestFile(string $testFile): void
    {
        // Load the test file
        require_once $testFile;
        
        // Get all declared classes
        $declaredClasses = get_declared_classes();
        $fileClasses = [];
        
        foreach ($declaredClasses as $className) {
            try {
                $reflection = new ReflectionClass($className);
                if ($reflection->getFileName() === $testFile) {
                    $fileClasses[] = $reflection;
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        
        // Run tests in each test class
        foreach ($fileClasses as $classReflection) {
            if ($this->isTestCase($classReflection)) {
                $this->runTestClass($classReflection);
            }
        }
    }

    /**
     * Check if a class is a test case
     */
    private function isTestCase(ReflectionClass $class): bool
    {
        // Must not be abstract
        if ($class->isAbstract()) {
            $this->verbose('Class ' . $class->getName() . ' is abstract', Verbosity::VERY_VERBOSE);
            return false;
        }
        
        // Must extend ArchTestCase
        $parent = $class->getParentClass();
        if (!$parent || $parent->getName() !== ArchTestCase::class) {
            $this->verbose('Class ' . $class->getName() . ' does not extend ArchTestCase', Verbosity::VERY_VERBOSE);
            return false;
        }
        
        return true;
    }

    /**
     * Run all test methods in a test class
     */
    private function runTestClass(ReflectionClass $classReflection): void
    {
        $testMethods = $this->getTestMethods($classReflection);
        
        foreach ($testMethods as $method) {
            $this->runTestMethod($classReflection, $method);
        }
    }

    /**
     * Get all test methods from a test class
     * 
     * @return ReflectionMethod[]
     */
    private function getTestMethods(ReflectionClass $classReflection): array
    {
        $testMethods = [];
        
        foreach ($classReflection->getMethods() as $method) {
            // Test methods must be public and start with "test"
            if ($method->isPublic() && str_starts_with($method->getName(), 'test')) {
                // Skip constructor and other special methods
                if ($method->isConstructor() || $method->isDestructor()) {
                    continue;
                }
                $testMethods[] = $method;
            }
        }
        
        return $testMethods;
    }

    /**
     * Run a single test method
     */
    private function runTestMethod(ReflectionClass $classReflection, ReflectionMethod $method): void
    {
        $testClass = $classReflection->getName();
        $testMethod = $method->getName();
        
        // Set verbosity and test context in Assert class before running the test
        Assert::setVerbosity($this->verbosity);
        Assert::setTestContext($testClass, $testMethod);
        
        $startTime = microtime(true);
        $passed = false;
        $failureMessage = null;
        
        try {
            // Instantiate the test class
            $testInstance = $classReflection->newInstance();
            
            // Run the test method
            $method->invoke($testInstance);
            
            $passed = true;
        } catch (AssertionException $e) {
            $failureMessage = $e->getMessage();
        } catch (\Throwable $e) {
            $failureMessage = sprintf(
                "Test threw exception: %s\n%s",
                $e->getMessage(),
                $e->getTraceAsString()
            );
        }
        
        // Clear test context after test execution
        Assert::setTestContext(null, null);
        
        $executionTime = microtime(true) - $startTime;
        
        $group = $this->getTestGroup($classReflection);
        $displayName = $this->getTestDisplayName($classReflection, $method);
        
        $result = new TestResult(
            $testClass,
            $testMethod,
            $passed,
            $failureMessage,
            $executionTime,
            $group,
            $displayName
        );
        
        $this->reporter->addResult($result);
    }

    /**
     * Get the test group name from attribute or derive from namespace
     */
    private function getTestGroup(ReflectionClass $classReflection): string
    {
        // Check for TestGroup attribute
        $attributes = $classReflection->getAttributes(TestGroup::class);
        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();
            return $attribute->getName();
        }

        // Derive from namespace
        $namespace = $classReflection->getNamespaceName();
        return $this->deriveGroupFromNamespace($namespace);
    }

    /**
     * Derive group name from namespace by removing the default test namespace prefix
     */
    private function deriveGroupFromNamespace(string $namespace): string
    {
        // Default test namespace prefix: "App\Tests\Architecture"
        $defaultTestNamespace = 'App\Tests\Architecture';
        
        // If namespace starts with the default prefix, remove it
        if (str_starts_with($namespace, $defaultTestNamespace)) {
            $remaining = substr($namespace, strlen($defaultTestNamespace));
            // Remove leading backslash if present
            $remaining = ltrim($remaining, '\\');
            
            if ($remaining === '') {
                return 'Default';
            }
            
            // Convert namespace parts to dot-separated group name
            return str_replace('\\', '.', $remaining);
        }
        
        // If namespace doesn't match default, use the full namespace
        return str_replace('\\', '.', $namespace);
    }

    /**
     * Get the test display name from attribute or generate from method name
     */
    private function getTestDisplayName(ReflectionClass $classReflection, ReflectionMethod $method): string
    {
        // Check for TestDescription attribute
        $attributes = $method->getAttributes(TestDescription::class);
        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();
            $description = $attribute->getDescription();
        } else {
            // Generate readable description from method name
            $description = $this->methodNameToReadable($method->getName());
        }

        // Get class short name (without namespace)
        $className = $classReflection->getShortName();
        
        return $className . ': ' . $description;
    }

    /**
     * Convert camelCase method name to readable format
     * Example: "testServicesHaveCorrectSuffix" -> "Test Services Have Correct Suffix"
     */
    private function methodNameToReadable(string $methodName): string
    {
        // Convert camelCase to words
        // Insert space before uppercase letters (except the first one)
        $readable = preg_replace('/([a-z])([A-Z])/', '$1 $2', $methodName);
        
        // Capitalize first letter of each word
        $words = explode(' ', $readable);
        $words = array_map('ucfirst', $words);
        
        return implode(' ', $words);
    }
}

