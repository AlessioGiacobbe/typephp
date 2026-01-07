<?php

namespace PhpAot\Php;

use FilesystemIterator;

class FileScanner
{
    private string $directory;
    private array $excludePatterns;

    const array PHP_EXT = ['php'];
    const array CPP_EXT = ['cpp', 'cxx', 'cc'];

    public function __construct(string $directory)
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: $directory");
        }
        
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->excludePatterns = [];
    }

    public static function getFileName(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    public static function getFileExt(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    static function isPhpFile(string $file): bool
    {
        return in_array(self::getFileExt($file), self::PHP_EXT);
    }

    static function isCppFile(string $file): bool
    {
        return in_array(self::getFileExt($file), self::CPP_EXT);
    }

    public function addExcludePattern(string $pattern): self
    {
        $this->excludePatterns[] = $pattern;
        return $this;
    }

    public function setExcludePatterns(array $patterns): self
    {
        $this->excludePatterns = $patterns;
        return $this;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    private function isExcluded(string $filePath): bool
    {
        $excluded = false;
        foreach ($this->excludePatterns as $pattern) {
            if ($this->matchPattern($pattern, $filePath)) {
                $excluded = true;
                break;
            }
        }
        return $excluded;
    }

    public function scan(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if (self::isPhpFile($file)) {
                    $filePath = $file->getPathname();
                } elseif (self::isCppFile($file)) {
                    $filePath = $file->getPathname();
                } else {
                    continue;
                }
                if (!$this->isExcluded($filePath)) {
                    $files[] = $filePath;
                }
            }
        }
        return $files;
    }

    private function matchPattern(string $pattern, string $path): bool
    {
        return fnmatch($pattern, $path, FNM_PATHNAME);
    }
}