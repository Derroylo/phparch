<?php

declare(strict_types=1);

namespace PhpArch\Reporter;

use PhpArch\Assert;
use PhpArch\ClassCoverageCalculator;
use PhpArch\TestResult;

class HtmlReporter implements ReporterInterface
{
    private array $results = [];
    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private float $totalTime = 0.0;
    private string $outputDir;
    private array $coverageData = [];
    private array $allFiles = [];
    private string $projectRoot;
    private ClassCoverageCalculator $coverageCalculator;

    public function __construct(string $outputDir)
    {
        $this->outputDir = rtrim($outputDir, '/');
        $this->projectRoot = $this->findProjectRoot();
        $this->coverageCalculator = new ClassCoverageCalculator();
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

    public function addCoverageData(array $coverageData): void
    {
        $this->coverageData = $coverageData;
    }

    public function report(): void
    {      
        // Discover all PHP files in the project
        $this->allFiles = $this->discoverAllPhpFiles();
        
        // Create output directory
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
        
        // Create assets directories
        $cssDir = $this->outputDir . '/_css';
        $iconsDir = $this->outputDir . '/_icons';
        if (!is_dir($cssDir)) {
            mkdir($cssDir, 0755, true);
        }
        if (!is_dir($iconsDir)) {
            mkdir($iconsDir, 0755, true);
        }
        
        // Copy/generate CSS and icons
        $this->generateAssets($cssDir, $iconsDir);
        
        // Generate HTML files
        $this->generateIndexPages();
        $this->generateFileDetailPages();
        
        echo "\n";
        echo "HTML Coverage Report generated in: {$this->outputDir}\n";
        echo "Open {$this->outputDir}/index.html in your browser to view the report.\n";
        echo "\n";
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

    /**
     * Find project root by looking for composer.json
     */
    private function findProjectRoot(): string
    {
        $dir = getcwd();
        return $dir;
        $maxDepth = 5;
        $depth = 0;
        
        while ($depth < $maxDepth) {
            $composerJson = $dir . '/composer.json';
            if (file_exists($composerJson)) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
            $depth++;
        }
        
        return getcwd();
    }

    /**
     * Discover all PHP files in autoloader paths
     * 
     * @return array<string, string> File path => relative path from project root
     */
    private function discoverAllPhpFiles(): array
    {
        $files = [];
        $autoloaderPaths = $this->getAutoloaderPaths();
        
        foreach ($autoloaderPaths as $path) {
            $this->scanDirectoryForFiles($path, $files);
        }

        return $files;
    }

    /**
     * Get paths from composer autoloader
     * 
     * @return string[]
     */
    private function getAutoloaderPaths(): array
    {
        $paths = [];
        $composerJson = $this->projectRoot . '/composer.json';
        
        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);
            if (isset($composer['autoload']['psr-4'])) {
                foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
                    $fullPath = $this->projectRoot . '/' . rtrim($path, '/');
                    if (is_dir($fullPath)) {
                        $paths[] = $fullPath;
                    }
                }
            }
        }
        
        return $paths;
    }

    /**
     * Recursively scan directory for PHP files
     */
    private function scanDirectoryForFiles(string $directory, array &$files): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $realPath = $file->getRealPath();
                if ($realPath !== false) {
                    // Skip test files and vendor directory
                    if (strpos($realPath, '/vendor/') !== false || 
                        strpos($realPath, '/tests/') !== false ||
                        preg_match('/Test(?:Case)?\.php$/', $file->getFilename())) {
                        continue;
                    }
                    
                    // Calculate relative path from project root
                    $relativePath = str_replace($this->projectRoot . '/', '', $realPath);
                    $files[$realPath] = $relativePath;
                }
            }
        }
    }

    /**
     * Generate CSS and icon assets
     */
    private function generateAssets(string $cssDir, string $iconsDir): void
    {
        // Generate minimal Bootstrap-like CSS
        $css = $this->getBootstrapCss();
        file_put_contents($cssDir . '/bootstrap.min.css', $css);
        
        // Generate custom CSS
        $customCss = $this->getCustomCss();
        file_put_contents($cssDir . '/custom.css', $customCss);
        
        // Generate icons (simple SVG)
        $this->generateIcon($iconsDir . '/file-directory.svg', 'directory');
        $this->generateIcon($iconsDir . '/file-code.svg', 'file');
    }

    /**
     * Generate index pages for directories
     */
    private function generateIndexPages(): void
    {
        // Build directory tree structure
        $dirStructure = $this->buildDirectoryStructure();
        
        // Generate root index
        $this->generateDirectoryIndex('', $dirStructure);
        
        // Generate subdirectory indices
        foreach ($dirStructure['subdirs'] as $dirName => $subdir) {
            $this->generateDirectoryIndexRecursive($dirName, $subdir, '');
        }
    }

    /**
     * Build directory structure from files
     */
    private function buildDirectoryStructure(): array
    {
        $structure = [
            'files' => [],
            'subdirs' => [],
        ];
        
        foreach ($this->allFiles as $fullPath => $relativePath) {
            $parts = explode('/', $relativePath);
            $filename = array_pop($parts);
            
            $current = &$structure;
            $currentPath = '';
            
            foreach ($parts as $part) {
                $currentPath .= ($currentPath ? '/' : '') . $part;
                if (!isset($current['subdirs'][$part])) {
                    $current['subdirs'][$part] = [
                        'files' => [],
                        'subdirs' => [],
                        'path' => $currentPath,
                    ];
                }
                $current = &$current['subdirs'][$part];
            }
            
            $current['files'][$filename] = [
                'fullPath' => $fullPath,
                'relativePath' => $relativePath,
            ];
        }
        
        return $structure;
    }

    /**
     * Generate directory index page
     */
    private function generateDirectoryIndex(string $relativeDir, array $structure): void
    {
        $isRoot = $relativeDir === '';
        $outputPath = $isRoot 
            ? $this->outputDir . '/index.html'
            : $this->outputDir . '/' . $relativeDir . '/index.html';
        
        // Create directory if needed
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $breadcrumb = $this->generateBreadcrumb($relativeDir, $isRoot);
        $tableRows = $this->generateDirectoryTableRows($relativeDir, $structure);
        $summary = $this->calculateDirectorySummary($structure);
        
        $html = $this->renderIndexPage($relativeDir, $breadcrumb, $tableRows, $summary, $isRoot);
        file_put_contents($outputPath, $html);
    }

    /**
     * Recursively generate directory indices
     */
    private function generateDirectoryIndexRecursive(string $dirName, array $structure, string $parentPath): void
    {
        $relativeDir = $parentPath ? $parentPath . '/' . $dirName : $dirName;
        $this->generateDirectoryIndex($relativeDir, $structure);
        
        foreach ($structure['subdirs'] as $subDirName => $subDir) {
            $this->generateDirectoryIndexRecursive($subDirName, $subDir, $relativeDir);
        }
    }

    /**
     * Generate table rows for directory index
     */
    private function generateDirectoryTableRows(string $relativeDir, array $structure): string
    {
        $rows = '';
        // Calculate depth: number of directory levels (slashes + 1 for the directory itself)
        $depth = $relativeDir ? substr_count($relativeDir, '/') + 1 : 0;
        $basePath = $relativeDir ? str_repeat('../', $depth) : '';
        
        // Add summary row
        $summary = $this->calculateDirectorySummary($structure);
        $rows .= $this->renderSummaryRow($summary, 'Total');
        
        // Sort subdirectories by name
        ksort($structure['subdirs']);

        // Sort files by name
        ksort($structure['files']);

        // Add subdirectory rows
        foreach ($structure['subdirs'] as $dirName => $subdir) {
            $subSummary = $this->calculateDirectorySummary($subdir);
            // Link should be relative to current directory, not absolute from project root
            if ($relativeDir) {
                // We're in a subdirectory, link should be relative (just the dir name)
                $link = $dirName . '/index.html';
            } else {
                // We're at root, use full path
                $link = $dirName . '/index.html';
            }
            $rows .= $this->renderDirectoryRow($dirName, $subSummary, $link, $basePath);
        }
        
        // Add file rows
        foreach ($structure['files'] as $filename => $fileInfo) {
            $filePath = $fileInfo['relativePath'];
            $classes = $this->extractClassesFromFile($fileInfo['fullPath']);
            $coveragePercent = $this->getFileCoverage($fileInfo['fullPath']);
            
            $isCovered = $coveragePercent > 0;
            $tests = $this->getFileTests($fileInfo['fullPath']);
            
            // Link should be relative to current directory
            // If we're in a subdirectory and the file is in that directory, use just the filename
            if ($relativeDir && str_starts_with($filePath, $relativeDir . '/')) {
                // File is in current directory - remove the directory prefix
                $link = substr($filePath, strlen($relativeDir) + 1) . '.html';
            } elseif ($relativeDir === '') {
                // We're at root, use full path
                $link = $filePath . '.html';
            } else {
                // File is in a different branch - this shouldn't happen in our structure,
                // but if it does, use the full path (shouldn't occur with our directory structure)
                $link = '../' . $filePath . '.html';
            }
            $rows .= $this->renderFileRow($filename, $isCovered, count($tests), $link, $basePath, $coveragePercent);
        }
        
        return $rows;
    }

    private function getFileCoverage(string $fullPath): float
    {
        if (!isset($this->coverageData[$fullPath])) {
            return 0.0;
        }
        
        return $this->coverageData[$fullPath]['coverage'];
    }

    /**
     * Get tests covering a file (backward compatible)
     */
    private function getFileTests(string $fullPath): array
    {
        if (!isset($this->coverageData[$fullPath])) {
            return [];
        }
        
        $fileCoverage = $this->coverageData[$fullPath];
        
        // Handle backward compatibility
        if (isset($fileCoverage[0]) && is_string($fileCoverage[0])) {
            return $fileCoverage;
        }
        
        return $fileCoverage['tests'] ?? [];
    }

    /**
     * Calculate summary statistics for a directory
     */
    private function calculateDirectorySummary(array $structure): array
    {
        $totalFiles = count($structure['files']);
        $totalCoverage = 0.0;
        $filesWithCoverage = 0;
        
        foreach ($structure['files'] as $fileInfo) {
            $classes = $this->extractClassesFromFile($fileInfo['fullPath']);
            $coverage = $this->getFileCoverage($fileInfo['fullPath']);
            if ($coverage > 0) {
                $filesWithCoverage++;
            }
            $totalCoverage += $coverage;
        }
        
        // Recursively count subdirectories
        foreach ($structure['subdirs'] as $subdir) {
            $subSummary = $this->calculateDirectorySummary($subdir);
            $totalFiles += $subSummary['totalFiles'];
            $filesWithCoverage += $subSummary['coveredFiles'];
            $totalCoverage += $subSummary['totalCoverage'];
        }
        
        $coveragePercent = $totalFiles > 0 ? ($totalCoverage / $totalFiles) : 0;
        
        return [
            'totalFiles' => $totalFiles,
            'coveredFiles' => $filesWithCoverage,
            'coveragePercent' => $coveragePercent,
            'totalCoverage' => $totalCoverage,
        ];
    }

    /**
     * Generate file detail pages
     */
    private function generateFileDetailPages(): void
    {
        foreach ($this->allFiles as $fullPath => $relativePath) {
            $this->generateFileDetailPage($fullPath, $relativePath);
        }
    }

    /**
     * Generate a single file detail page
     */
    private function generateFileDetailPage(string $fullPath, string $relativePath): void
    {
        $outputPath = $this->outputDir . '/' . $relativePath . '.html';
        $outputDir = dirname($outputPath);
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $classes = $this->extractClassesFromFile($fullPath);
        $coveragePercent = $this->getFileCoverage($fullPath);
        $tests = $this->getFileTests($fullPath);
        $isCovered = $coveragePercent > 0;
        
        // Calculate depth: number of directory levels (number of slashes)
        $depth = substr_count($relativePath, '/');
        $basePath = $depth > 0 ? str_repeat('../', $depth) : '';
        $breadcrumb = $this->generateBreadcrumbForFile($relativePath, $basePath);
        
        $html = $this->renderFileDetailPage($relativePath, $isCovered, $tests, $classes, $breadcrumb, $basePath, $coveragePercent);
        file_put_contents($outputPath, $html);
    }

    /**
     * Extract classes/interfaces/traits from a PHP file
     */
    private function extractClassesFromFile(string $filePath): array
    {
        $classes = [];
        $content = file_get_contents($filePath);
        if ($content === false) {
            return $classes;
        }
        
        $tokens = token_get_all($content);
        $namespace = '';
        $inNamespace = false;
        $tokenCount = count($tokens);
        
        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            
            if (is_array($token)) {
                [$id, $text] = $token;
                
                if ($id === \T_NAMESPACE && !$inNamespace) {
                    $inNamespace = true;
                    $namespace = '';
                } elseif ($inNamespace && $id === \T_NAME_QUALIFIED) {
                    $namespace .= $text;
                    $inNamespace = false;
                } elseif ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT) {
                    $type = $id === T_CLASS ? 'class' : ($id === T_INTERFACE ? 'interface' : 'trait');
                    
                    // Skip abstract keyword if present
                    $j = $i + 1;
                    while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                    
                    // Find the class name (next T_STRING after class/interface/trait)
                    while ($j < $tokenCount) {
                        if (is_array($tokens[$j])) {
                            if ($tokens[$j][0] === T_STRING) {
                                $className = $tokens[$j][1];
                                $fullName = $namespace ? $namespace . '\\' . $className : $className;
                                $classes[] = [
                                    'name' => $className,
                                    'fullName' => $fullName,
                                    'type' => $type,
                                ];
                                break;
                            } elseif ($tokens[$j][0] === T_WHITESPACE) {
                                $j++;
                                continue;
                            }
                        }
                        break;
                    }
                }
            } else {
                // Handle non-array tokens (like ';' and '{')
                if ($token === ';' && $inNamespace) {
                    $inNamespace = false;
                    $namespace = trim($namespace);
                } elseif ($token === '{' && $inNamespace) {
                    $inNamespace = false;
                    $namespace = trim($namespace);
                }
            }
        }
        
        return $classes;
    }

    /**
     * Generate breadcrumb navigation
     */
    private function generateBreadcrumb(string $relativeDir, bool $isRoot): string
    {
        if ($isRoot) {
            $displayPath = basename($this->projectRoot);
            return "<li class=\"breadcrumb-item active\">{$displayPath}</li>\n        <li class=\"breadcrumb-item\">(<a href=\"dashboard.html\">Dashboard</a>)</li>";
        }
        
        $parts = explode('/', $relativeDir);
        $breadcrumb = "<li class=\"breadcrumb-item\"><a href=\"" . str_repeat('../', count($parts)) . "index.html\">" . basename($this->projectRoot) . "</a></li>\n";
        
        $currentPath = '';
        foreach ($parts as $index => $part) {
            $currentPath .= ($currentPath ? '/' : '') . $part;
            $depth = count($parts) - $index - 1;
            $link = str_repeat('../', $depth) . 'index.html';
            if ($index === count($parts) - 1) {
                $breadcrumb .= "        <li class=\"breadcrumb-item active\">{$part}</li>\n";
            } else {
                $breadcrumb .= "        <li class=\"breadcrumb-item\"><a href=\"{$link}\">{$part}</a></li>\n";
            }
        }
        
        $breadcrumb .= "        <li class=\"breadcrumb-item\">(<a href=\"dashboard.html\">Dashboard</a>)</li>";
        
        return $breadcrumb;
    }

    /**
     * Generate breadcrumb for file detail page
     */
    private function generateBreadcrumbForFile(string $relativePath, string $basePath): string
    {
        $parts = explode('/', $relativePath);
        $filename = array_pop($parts);
        $depth = count($parts);
        
        $breadcrumb = "<li class=\"breadcrumb-item\"><a href=\"{$basePath}index.html\">" . basename($this->projectRoot) . "</a></li>\n";
        
        $currentPath = '';
        foreach ($parts as $index => $part) {
            $currentPath .= ($currentPath ? '/' : '') . $part;
            $linkDepth = $depth - $index;
            $link = str_repeat('../', $linkDepth) . 'index.html';
            $breadcrumb .= "        <li class=\"breadcrumb-item\"><a href=\"{$link}\">{$part}</a></li>\n";
        }
        
        $breadcrumb .= "        <li class=\"breadcrumb-item active\">{$filename}</li>";
        
        return $breadcrumb;
    }

    /**
     * Render summary row
     */
    private function renderSummaryRow(array $summary, string $label): string
    {
        $coverageClass = $this->getCoverageClass($summary['coveragePercent']);
        $percent = number_format($summary['coveragePercent'], 2);
        $progressWidth = min(100, max(0, $summary['coveragePercent']));
        
        return <<<HTML
      <tr>
       <td class="{$coverageClass}">{$label}</td>
       <td class="{$coverageClass} big">
         <div class="progress">
           <div class="progress-bar bg-{$coverageClass}" role="progressbar" aria-valuenow="{$progressWidth}" aria-valuemin="0" aria-valuemax="100" style="width: {$progressWidth}%">
             <span class="visually-hidden">{$percent}% covered ({$coverageClass})</span>
           </div>
         </div>
       </td>
       <td class="{$coverageClass} small"><div align="right">{$percent}%</div></td>
       <td class="{$coverageClass} small"><div align="right">{$summary['coveredFiles']}&nbsp;/&nbsp;{$summary['totalFiles']}</div></td>
      </tr>

HTML;
    }

    /**
     * Render directory row
     */
    private function renderDirectoryRow(string $dirName, array $summary, string $link, string $basePath): string
    {
        $coverageClass = $this->getCoverageClass($summary['coveragePercent']);
        $percent = number_format($summary['coveragePercent'], 2);
        $progressWidth = min(100, max(0, $summary['coveragePercent']));
        
        return <<<HTML
      <tr>
       <td class="{$coverageClass}"><img src="{$basePath}_icons/file-directory.svg" class="octicon" /><a href="{$link}">{$dirName}</a></td>
       <td class="{$coverageClass} big">
         <div class="progress">
           <div class="progress-bar bg-{$coverageClass}" role="progressbar" aria-valuenow="{$progressWidth}" aria-valuemin="0" aria-valuemax="100" style="width: {$progressWidth}%">
             <span class="visually-hidden">{$percent}% covered ({$coverageClass})</span>
           </div>
         </div>
       </td>
       <td class="{$coverageClass} small"><div align="right">{$percent}%</div></td>
       <td class="{$coverageClass} small"><div align="right">{$summary['coveredFiles']}&nbsp;/&nbsp;{$summary['totalFiles']}</div></td>
      </tr>

HTML;
    }

    /**
     * Render file row
     */
    private function renderFileRow(string $filename, bool $isCovered, int $testCount, string $link, string $basePath, float $coveragePercent = 0.0): string
    {
        // Use calculated coverage if available, otherwise fall back to binary
        $percent = $coveragePercent > 0 ? number_format($coveragePercent, 2) : ($isCovered ? '100.00' : '0.00');
        $progressWidth = $coveragePercent > 0 ? min(100, max(0, $coveragePercent)) : ($isCovered ? 100 : 0);
        $coverageClass = $this->getCoverageClass($coveragePercent > 0 ? $coveragePercent : ($isCovered ? 100 : 0));
        
        return <<<HTML
      <tr>
       <td class="{$coverageClass}"><img src="{$basePath}_icons/file-code.svg" class="octicon" /><a href="{$link}">{$filename}</a></td>
       <td class="{$coverageClass} big">
         <div class="progress">
           <div class="progress-bar bg-{$coverageClass}" role="progressbar" aria-valuenow="{$progressWidth}" aria-valuemin="0" aria-valuemax="100" style="width: {$progressWidth}%">
             <span class="visually-hidden">{$percent}% covered ({$coverageClass})</span>
           </div>
         </div>
       </td>
       <td class="{$coverageClass} small"><div align="right">{$percent}%</div></td>
       <td class="{$coverageClass} small"><div align="right">{$testCount}&nbsp;/&nbsp;1</div></td>
      </tr>

HTML;
    }

    /**
     * Get coverage CSS class based on percentage
     */
    private function getCoverageClass(float $percent): string
    {
        if ($percent >= 80) {
            return 'success';
        } elseif ($percent >= 50) {
            return 'warning';
        } else {
            return 'danger';
        }
    }

    /**
     * Render index page HTML
     */
    private function renderIndexPage(string $relativeDir, string $breadcrumb, string $tableRows, array $summary, bool $isRoot): string
    {
        $displayPath = $isRoot ? basename($this->projectRoot) : $relativeDir;
        // Calculate depth: number of directory levels (slashes + 1 for the directory itself)
        $depth = $isRoot ? 0 : substr_count($relativeDir, '/') + 1;
        $basePath = $isRoot ? '' : str_repeat('../', $depth);
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="UTF-8">
  <title>Code Coverage for {$displayPath}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="{$basePath}_css/bootstrap.min.css" rel="stylesheet" type="text/css">
  <link href="{$basePath}_css/custom.css" rel="stylesheet" type="text/css">
 </head>
 <body>
  <header>
   <div class="container-fluid">
    <div class="row">
     <div class="col-md-12">
      <nav aria-label="breadcrumb">
       <ol class="breadcrumb">
        {$breadcrumb}
       </ol>
      </nav>
     </div>
    </div>
   </div>
  </header>
  <div class="container-fluid">
   <div class="table-responsive">
    <table class="table table-bordered">
     <thead>
      <tr>
       <td>&nbsp;</td>
       <td colspan="3"><div align="center"><strong>Code Coverage</strong></div></td>
      </tr>
      <tr>
       <td>&nbsp;</td>
       <td colspan="2"><div align="center"><strong>Files</strong></div></td>
       <td><div align="center"><strong>Coverage</strong></div></td>
      </tr>
     </thead>
     <tbody>
{$tableRows}
     </tbody>
    </table>
   </div>
  </div>
 </body>
</html>
HTML;
    }

    /**
     * Render file detail page HTML
     */
    private function renderFileDetailPage(string $relativePath, bool $isCovered, array $tests, array $classes, string $breadcrumb, string $basePath, float $coveragePercent = 0.0): string
    {
        // Use calculated coverage if available
        $percent = $coveragePercent > 0 ? $coveragePercent : ($isCovered ? 100.0 : 0.0);
        $coverageClass = $this->getCoverageClass($percent);
        $coveragePercentFormatted = number_format($percent, 2);
        $testCount = count($tests);
        
        $classesHtml = '';
        if (empty($classes)) {
            $classesHtml = '<p>No classes, interfaces, or traits found in this file.</p>';
        } else {
            $classesHtml = '<ul>';
            foreach ($classes as $class) {
                $typeLabel = ucfirst($class['type']);
                $classesHtml .= "<li><strong>{$typeLabel}</strong>: <code>{$class['fullName']}</code></li>";
            }
            $classesHtml .= '</ul>';
        }
        
        $testsHtml = '';
        if (empty($tests)) {
            $testsHtml = '<p class="text-danger">No tests cover this file.</p>';
        } else {
            $testsHtml = '<ul>';
            foreach ($tests as $test) {
                $testsHtml .= "<li><code>{$test}</code></li>";
            }
            $testsHtml .= '</ul>';
        }
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="UTF-8">
  <title>Code Coverage for {$relativePath}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="{$basePath}_css/bootstrap.min.css" rel="stylesheet" type="text/css">
  <link href="{$basePath}_css/custom.css" rel="stylesheet" type="text/css">
 </head>
 <body>
  <header>
   <div class="container-fluid">
    <div class="row">
     <div class="col-md-12">
      <nav aria-label="breadcrumb">
       <ol class="breadcrumb">
        {$breadcrumb}
       </ol>
      </nav>
     </div>
    </div>
   </div>
  </header>
  <div class="container-fluid">
   <div class="table-responsive">
    <table class="table table-bordered">
     <thead>
      <tr>
       <td>&nbsp;</td>
       <td colspan="2"><div align="center"><strong>Code Coverage</strong></div></td>
      </tr>
     </thead>
     <tbody>
      <tr>
       <td class="{$coverageClass}">Total</td>
       <td class="{$coverageClass} big">
         <div class="progress">
           <div class="progress-bar bg-{$coverageClass}" role="progressbar" aria-valuenow="{$coveragePercentFormatted}" aria-valuemin="0" aria-valuemax="100" style="width: {$percent}%">
             <span class="visually-hidden">{$coveragePercentFormatted}% covered ({$coverageClass})</span>
           </div>
         </div>
       </td>
       <td class="{$coverageClass} small"><div align="right">{$coveragePercentFormatted}%</div></td>
      </tr>
     </tbody>
    </table>
   </div>
   
   <div class="row" style="margin-top: 2rem;">
    <div class="col-md-12">
     <h3>Classes in File</h3>
     {$classesHtml}
    </div>
   </div>
   
   <div class="row" style="margin-top: 2rem;">
    <div class="col-md-12">
     <h3>Tests Covering This File</h3>
     {$testsHtml}
    </div>
   </div>
  </div>
 </body>
</html>
HTML;
    }

    /**
     * Get minimal Bootstrap CSS with dark mode support
     */
    private function getBootstrapCss(): string
    {
        // Return minimal Bootstrap-like CSS for table styling with dark mode
        return <<<CSS
:root {
    --bg-primary: #0d1117;
    --bg-secondary: #161b22;
    --bg-tertiary: #21262d;
    --text-primary: #c9d1d9;
    --text-secondary: #8b949e;
    --border-color: #30363d;
    --success-bg: #1a472a;
    --success-color: #3fb950;
    --warning-bg: #3d2817;
    --warning-color: #d29922;
    --danger-bg: #3d1f1f;
    --danger-color: #f85149;
}

* { box-sizing: border-box; }

body {
    background-color: var(--bg-primary);
    color: var(--text-primary);
    margin: 0;
    padding: 0;
}

.container-fluid { padding: 15px; }
.table { width: 100%; margin-bottom: 1rem; background-color: var(--bg-secondary); }
.table-bordered { border: 1px solid var(--border-color); }
.table-bordered td { border: 1px solid var(--border-color); padding: 0.75rem; background-color: var(--bg-secondary); }
.table thead td { background-color: var(--bg-tertiary); font-weight: bold; color: var(--text-primary); }
.breadcrumb { padding: 0.75rem 1rem; margin-bottom: 1rem; list-style: none; background-color: var(--bg-tertiary); border-radius: 0.25rem; border: 1px solid var(--border-color); }
.breadcrumb-item { display: inline-block; }
.breadcrumb-item + .breadcrumb-item::before { content: "/"; padding: 0 0.5rem; color: var(--text-secondary); }
.breadcrumb-item a { color: #58a6ff; text-decoration: none; }
.breadcrumb-item a:hover { text-decoration: underline; }
.breadcrumb-item.active { color: var(--text-secondary); }
.progress { display: flex; height: 1rem; overflow: hidden; background-color: var(--bg-tertiary); border-radius: 0.25rem; }
.progress-bar { display: flex; flex-direction: column; justify-content: center; color: #fff; text-align: center; white-space: nowrap; transition: width 0.6s ease; }
.bg-success { background-color: var(--success-color) !important; }
.bg-warning { background-color: var(--warning-color) !important; }
.bg-danger { background-color: var(--danger-color) !important; }
.success { background-color: var(--success-bg) !important; color: var(--text-primary); }
.warning { background-color: var(--warning-bg) !important; color: var(--text-primary); }
.danger { background-color: var(--danger-bg) !important; color: var(--text-primary); }
.text-end { text-align: right; }
.text-right { text-align: right; }
.small { font-size: 0.875rem; }
.big { width: 60%; }
.octicon { width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 4px; display: inline-block; }
.table-responsive { display: block; width: 100%; overflow-x: auto; }
a { color: #58a6ff; text-decoration: none; }
a:hover { text-decoration: underline; }
.visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0; }
CSS;
    }

    /**
     * Get custom CSS with dark mode support
     */
    private function getCustomCss(): string
    {
        return <<<CSS
body { 
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
    background-color: var(--bg-primary);
    color: var(--text-primary);
}
h3 { 
    margin-top: 1.5rem; 
    margin-bottom: 1rem; 
    color: var(--text-primary);
}
code { 
    background-color: var(--bg-tertiary); 
    color: var(--text-primary);
    padding: 2px 6px; 
    border-radius: 3px; 
    font-size: 0.9em; 
    border: 1px solid var(--border-color);
}
ul { 
    margin-top: 0.5rem; 
    color: var(--text-primary);
}
li { 
    margin-bottom: 0.25rem; 
}
.text-danger {
    color: var(--danger-color) !important;
}
CSS;
    }

    /**
     * Generate SVG icon
     */
    private function generateIcon(string $path, string $type): void
    {
        if ($type === 'directory') {
            // Directory icon with visible fill color
            $svg = '<svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path fill="#8b949e" d="M1.75 1A1.75 1.75 0 000 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0016 13.25V4.75A1.75 1.75 0 0014.25 3H7.5a.25.25 0 01-.2-.1l-.9-1.2C6.07 1.26 5.55 1 5 1H1.75z"></path></svg>';
        } else {
            // File icon with visible fill color
            $svg = '<svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path fill="#8b949e" fill-rule="evenodd" d="M3.75 1.5a.25.25 0 00-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 00.25-.25V6H9.75A1.75 1.75 0 018 4.25V1.5H3.75zm5.75.56v2.19c0 .138.112.25.25.25h2.19L9.5 2.06zM2 1.75C2 .784 2.784 0 3.75 0h5.086c.464 0 .909.184 1.237.513l3.414 3.414c.329.328.513.773.513 1.237v8.086A1.75 1.75 0 0113.25 15h-9.5A1.75 1.75 0 012 13.25V1.75z"></path></svg>';
        }
        file_put_contents($path, $svg);
    }
}
