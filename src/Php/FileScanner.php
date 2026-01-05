<?php

namespace PhpAot\Php;

use FilesystemIterator;

class FileScanner
{
    private string $directory;
    private array $extensions;
    private array $excludePatterns;

    public function __construct(string $directory, array $extensions = ['.php', '.cc', '.cpp', '.cxx', '.c', '.h', '.hpp'])
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: $directory");
        }
        
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->extensions = $extensions;
        $this->excludePatterns = [];
    }

    public function addExcludePattern(string $pattern): self
    {
        $this->excludePatterns[] = $pattern;
        return $this;
    }

    public function scan(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = '.' . $file->getExtension();
                if (in_array($extension, $this->extensions)) {
                    $filePath = $file->getPathname();
                    $excluded = false;
                    foreach ($this->excludePatterns as $pattern) {
                        if ($this->matchPattern($pattern, $filePath)) {
                            $excluded = true;
                            break;
                        }
                    }
                    if (!$excluded) {
                        $files[] = $filePath;
                    }
                }
            }
        }
        sort($files);
        return $files;
    }

    private function matchPattern(string $pattern, string $path): bool
    {
        return fnmatch($pattern, $path, FNM_PATHNAME);
    }
}