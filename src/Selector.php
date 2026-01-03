<?php

declare(strict_types=1);

namespace PhpArch;

use ReflectionClass;

class Selector
{
    private array $filters = [];
    private ?string $namespace = null;
    private bool $excludeAbstract = false;
    private bool $excludeInterfaces = false;
    private bool $excludeTraits = false;
    private ?string $namePattern = null;
    private ?string $implementing = null;
    private ?string $extending = null;

    public static function classes(): self
    {
        return new self();
    }

    public function inNamespace(string $namespace): self
    {
        $this->namespace = rtrim($namespace, '\\');
        return $this;
    }

    public function excludingAbstract(): self
    {
        $this->excludeAbstract = true;
        return $this;
    }

    public function excludingInterfaces(): self
    {
        $this->excludeInterfaces = true;
        return $this;
    }

    public function excludingTraits(): self
    {
        $this->excludeTraits = true;
        return $this;
    }

    public function matching(string $pattern): self
    {
        $this->namePattern = $pattern;
        return $this;
    }

    public function implementing(string $interface): self
    {
        $this->implementing = $interface;
        return $this;
    }

    public function extending(string $class): self
    {
        $this->extending = $class;
        return $this;
    }

    /**
     * Execute the selector and return matching classes
     * 
     * @return ReflectionClass[]
     */
    public function get(): array
    {
        $classes = $this->discoverClasses();

        return $this->filterClasses($classes);
    }

    /**
     * Discover all classes in the codebase
     * 
     * @return ReflectionClass[]
     */
    private function discoverClasses(): array
    {
        $classes = [];
        $scannedClasses = [];
        
        // First, try to get classes from autoloader paths
        $autoloaderPaths = $this->getAutoloaderPaths();
        
        foreach ($autoloaderPaths as $path) {
            $scannedClasses = array_merge($scannedClasses, $this->scanDirectory($path));
        }
        
        // Also include already declared classes
        $declaredClasses = get_declared_classes();
        foreach ($declaredClasses as $className) {
            try {
                $reflection = new ReflectionClass($className);
                if (!$reflection->isInternal()) {
                    $scannedClasses[] = $reflection;
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        
        // Remove duplicates
        $uniqueClasses = [];
        $seen = [];
        foreach ($scannedClasses as $reflection) {
            $name = $reflection->getName();
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $uniqueClasses[] = $reflection;
            }
        }
        
        return $uniqueClasses;
    }
    
    /**
     * Get paths from composer autoloader
     * 
     * @return string[]
     */
    private function getAutoloaderPaths(): array
    {
        $paths = [];
        
        // Try to find composer.json in current directory or parent
        $dir = getcwd();
        $maxDepth = 5;
        $depth = 0;
        
        while ($depth < $maxDepth) {
            $composerJson = $dir . '/composer.json';
            if (file_exists($composerJson)) {
                $composer = json_decode(file_get_contents($composerJson), true);
                if (isset($composer['autoload']['psr-4'])) {
                    foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
                        $fullPath = $dir . '/' . rtrim($path, '/');
                        if (is_dir($fullPath)) {
                            $paths[] = $fullPath;
                        }
                    }
                }
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
            $depth++;
        }
        
        return $paths;
    }
    
    /**
     * Scan directory for PHP files and extract class names
     * 
     * @param string $directory
     * @return ReflectionClass[]
     */
    private function scanDirectory(string $directory): array
    {
        $classes = [];

        if (!is_dir($directory)) {
            return $classes;
        }

        // Scan PHP files in the current directory (non-recursive)
        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                $className = $this->extractClassNameFromFile($filePath);

                if ($className !== null) {
                    try {
                        if (
                            !class_exists($className, false)
                            && !interface_exists($className, false)
                            && !trait_exists($className, false)
                        ) {
                            try {
                                require_once $filePath;
                            } catch (\Throwable $e) {
                                continue;
                            }
                        }

                        $fullClassName = $className;
                        if (strpos($className, '\\') === false && !empty($namespace = $this->extractNamespaceFromFile($filePath))) {
                            $fullClassName = $namespace . '\\' . $className;
                        }
                        $reflection = new \ReflectionClass($fullClassName);
                        if (!$reflection->isInternal()) {
                            $classes[] = $reflection;
                        }
                    } catch (\ReflectionException $e) {
                        continue;
                    }
                }
            } elseif (is_dir($filePath)) {
                // Recurse into subdirectories
                $subClasses = $this->scanDirectory($filePath);
                $classes = array_merge($classes, $subClasses);
            }
        }

        return $classes;
    }
    
    private function extractNamespaceFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $tokens = token_get_all($content);
        $namespace = '';
        $gettingNamespace = false;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_NAMESPACE) {
                    $gettingNamespace = true;
                    continue;
                }
                if ($gettingNamespace) {
                    if ($token[0] === 265) {
                        $namespace .= $token[1];
                    } elseif ($token[0] === T_WHITESPACE) {
                        continue;
                    } else {
                        // Break when we reach something that's not part of the namespace (usually '{' or ';')
                        break;
                    }
                }
            } elseif ($gettingNamespace && ($token === ';' || $token === '{')) {
                break;
            }
        }

        return $namespace !== '' ? $namespace : null;
    }

    /**
     * Extract class name from PHP file using token parsing
     * 
     * @param string $filePath
     * @return string|null
     */
    private function extractClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        
        $tokens = token_get_all($content);
        $namespace = '';
        $className = null;
        $inNamespace = false;
        $inClass = false;
        $classType = null; // class, interface, trait
        
        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                
                if ($id === T_NAMESPACE && !$inNamespace) {
                    $inNamespace = true;
                } elseif ($inNamespace && ($id === T_STRING || $id === T_NS_SEPARATOR)) {
                    $namespace .= $text;
                } elseif ($inNamespace && $id === T_WHITESPACE && $namespace !== '') {
                    $inNamespace = false;
                } elseif (($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT) && !$inClass) {
                    $inClass = true;
                    $classType = $id;
                } elseif ($inClass && $id === T_STRING && $className === null) {
                    $className = $text;
                    break;
                }
            } elseif ($token === ';' && $inNamespace) {
                $inNamespace = false;
            } elseif ($token === '{' && $inClass) {
                break;
            }
        }
        
        if ($className === null) {
            return null;
        }
        
        if ($namespace !== '') {
            return trim($namespace) . '\\' . $className;
        }
        
        return $className;
    }

    /**
     * Filter classes based on selector criteria
     * 
     * @param ReflectionClass[] $classes
     * @return ReflectionClass[]
     */
    private function filterClasses(array $classes): array
    {
        $filtered = [];
        
        foreach ($classes as $class) {
            // Namespace filter
            if ($this->namespace !== null) {
                $classNamespace = $class->getNamespaceName();
                if (str_starts_with($classNamespace, $this->namespace) === false) {
                    continue;
                }
            }
            
            // Exclude abstract
            if ($this->excludeAbstract && $class->isAbstract()) {
                continue;
            }
            
            // Exclude interfaces
            if ($this->excludeInterfaces && $class->isInterface()) {
                continue;
            }
            
            // Exclude traits
            if ($this->excludeTraits && $class->isTrait()) {
                continue;
            }
            
            // Name pattern filter
            if ($this->namePattern !== null) {
                $shortName = $class->getShortName();
                if (!preg_match($this->namePattern, $shortName)) {
                    continue;
                }
            }
            
            // Implementing interface filter
            if ($this->implementing !== null) {
                if (!$class->implementsInterface($this->implementing)) {
                    continue;
                }
            }
            
            // Extending class filter
            if ($this->extending !== null) {
                $parent = $class->getParentClass();
                if (!$parent || $parent->getName() !== $this->extending) {
                    continue;
                }
            }
            
            $filtered[] = $class;
        }
        
        return $filtered;
    }
}

