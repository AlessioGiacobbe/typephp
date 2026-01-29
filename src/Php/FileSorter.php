<?php

namespace PhpAot\Php;

class FileSorter
{
    private array $functionDeclInFile;
    private array $functionCallInFile;

    public function __construct(array $functionDeclInFile, array $functionCallInFile)
    {
        $this->functionDeclInFile = $functionDeclInFile;
        $this->functionCallInFile = $functionCallInFile;
    }

    public function sort(): array
    {
        $dependencies = $this->buildDependencies();

        $allFiles = $this->getAllFiles();

        $inDegree = array_fill_keys($allFiles, 0);
        foreach ($dependencies as $deps) {
            foreach ($deps as $dep) {
                ++$inDegree[$dep];
            }
        }

        $queue = [];
        foreach ($inDegree as $file => $degree) {
            if (0 === $degree) {
                $queue[] = $file;
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;

            if (isset($dependencies[$current])) {
                foreach ($dependencies[$current] as $dep) {
                    --$inDegree[$dep];
                    if (0 === $inDegree[$dep]) {
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

        foreach ($this->functionCallInFile as $call) {
            $callerFile = $call['file'];
            $functionName = $call['name'];

            if (isset($this->functionDeclInFile[$functionName])) {
                $declFile = $this->functionDeclInFile[$functionName];

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
        $files = array_values($this->functionDeclInFile);

        foreach ($this->functionCallInFile as $call) {
            $files[] = $call['file'];
        }

        return array_unique($files);
    }
}
