<?php

declare(strict_types=1);

namespace PhpArch\Reporter;

use PhpArch\TestResult;

class TerminalReporter implements ReporterInterface
{
    private array $results = [];
    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private float $totalTime = 0.0;
    private bool $useColors;

    public function __construct(?bool $useColors = null)
    {
        // Default to auto-detect if not specified
        if ($useColors === null) {
            $this->useColors = function_exists('posix_isatty') && posix_isatty(STDOUT);
        } else {
            $this->useColors = $useColors;
        }
    }

    public function addResult(TestResult $result): void
    {
        $this->results[] = $result;
        $this->totalTests++;
        $this->totalTime += $result->getExecutionTime();
        
        if ($result->passed()) {
            $this->passedTests++;
        } else {
            $this->failedTests++;
        }
    }

    public function report(): void
    {
        $this->printHeader();
        $this->printResults();
        $this->printSummary();
    }

    public function addCoverageData(array $coverageData): void
    {
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "PHP Architecture Validator\n";
        echo str_repeat("=", 50) . "\n";
        echo "\n";
    }

    private function printResults(): void
    {
        // Group results by group name
        $groupedResults = [];
        foreach ($this->results as $result) {
            $group = $result->getGroup();
            if (!isset($groupedResults[$group])) {
                $groupedResults[$group] = [];
            }
            $groupedResults[$group][] = $result;
        }

        // Sort groups alphabetically
        ksort($groupedResults);

        // Print results grouped by group name
        foreach ($groupedResults as $groupName => $results) {
            echo "\n" . $this->colorize($groupName, 'cyan') . "\n";
            echo $this->colorize(str_repeat("-", strlen($groupName)), 'cyan') . "\n";
            
            foreach ($results as $result) {
                $displayName = $result->getDisplayName();
                if ($result->passed()) {
                    echo $this->colorize("✓ ", 'green') . $displayName . "\n";
                } else {
                    echo $this->colorize("✗ ", 'red') . $this->colorize($displayName, 'red') . "\n";
                    if ($result->getFailureMessage()) {
                        $lines = explode("\n", $result->getFailureMessage());
                        foreach ($lines as $line) {
                            if (trim($line) !== '') {
                                echo "  " . $this->colorize($line, 'red') . "\n";
                            }
                        }
                    }
                }
            }
        }
        echo "\n";
    }

    private function printSummary(): void
    {
        echo str_repeat("-", 50) . "\n";
        $summary = sprintf(
            "Tests: %d | Passed: %s | Failed: %s | Time: %.2fs\n",
            $this->totalTests,
            $this->colorize((string)$this->passedTests, 'green'),
            $this->colorize((string)$this->failedTests, $this->failedTests > 0 ? 'red' : 'green'),
            $this->totalTime
        );
        echo $summary;
        echo str_repeat("=", 50) . "\n";
        echo "\n";
    }

    /**
     * Apply ANSI color codes to text if colors are enabled
     */
    private function colorize(string $text, string $color): string
    {
        if (!$this->useColors) {
            return $text;
        }

        $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bold' => '1',
        ];

        if (!isset($colors[$color])) {
            return $text;
        }

        return "\033[" . $colors[$color] . "m" . $text . "\033[0m";
    }

    public function hasFailures(): bool
    {
        return $this->failedTests > 0;
    }

    public function getTotalTests(): int
    {
        return $this->totalTests;
    }

    public function getPassedTests(): int
    {
        return $this->passedTests;
    }

    public function getFailedTests(): int
    {
        return $this->failedTests;
    }
}

