<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

class FileSorter
{
    private array $symbolDeclInFile;
    private array $symbolCallInFile;

    public function __construct(array $symbolDeclInFile, array $symbolCallInFile)
    {
        $this->symbolDeclInFile = $symbolDeclInFile;
        $this->symbolCallInFile = $symbolCallInFile;
    }

    public function sort(): array
    {
        $dependencies = $this->buildDependencies();

        $allFiles = $this->getAllFiles();

        $inDegree = array_fill_keys($allFiles, 0);
        foreach ($dependencies as $deps) {
            foreach ($deps as $dep) {
                $inDegree[$dep]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $file => $degree) {
            if ($degree === 0) {
                $queue[] = $file;
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $current  = array_shift($queue);
            $sorted[] = $current;

            if (isset($dependencies[$current])) {
                foreach ($dependencies[$current] as $dep) {
                    $inDegree[$dep]--;
                    if ($inDegree[$dep] === 0) {
                        $queue[] = $dep;
                    }
                }
            }
        }

        if (count($sorted) !== count($allFiles)) {
            throw new \RuntimeException('Circular dependency of function call detected');
        }

        return array_reverse($sorted);
    }

    private function buildDependencies(): array
    {
        $dependencies = [];

        foreach ($this->symbolCallInFile as $call) {
            $callerFile   = $call['file'];
            $functionName = $call['name'];

            if (isset($this->symbolDeclInFile[$functionName])) {
                $declFile = $this->symbolDeclInFile[$functionName];

                if ($callerFile !== $declFile) {
                    if (!isset($dependencies[$callerFile])) {
                        $dependencies[$callerFile] = [];
                    }

                    if (!in_array($declFile, $dependencies[$callerFile])) {
                        $dependencies[$callerFile][] = $declFile;
                    }
                }
            }
        }

        return $dependencies;
    }

    private function getAllFiles(): array
    {
        $files = array_values($this->symbolDeclInFile);

        foreach ($this->symbolCallInFile as $call) {
            $files[] = $call['file'];
        }

        return array_unique($files);
    }
}
