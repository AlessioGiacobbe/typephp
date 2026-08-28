<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves native include, library, linker, and output configuration.
 */

namespace TypePhp\Build;

use TypePhp\Platform\Windows;

trait NativeBuildConfigurationTrait
{
    protected function getIncludePaths(): array
    {
        $platform = $this->getPlatform();
        $includePaths = [
            $this->getPhpxDir() . '/include',
            $this->getBuildDir() . '/include',
            $this->getPhpxDir() . '/src/misc',
        ];

        // Add the platform-specific PHP include paths
        if ($platform instanceof Windows) {
            $phpSdkPaths = $platform->buildPhpSdkIncludePaths($this->getPhpDir());
            $includePaths = array_merge($includePaths, $phpSdkPaths);
        } else {
            // Linux/macOS
            $phpPaths = $platform->buildPhpIncludePaths($this->getPhpDir());
            $includePaths = array_merge($includePaths, $phpPaths);
            // Bundled mpdecimal header directories
            $includePaths[] = $this->getPhpxDir() . '/thirdparty/mpdecimal/libmpdec';
            $includePaths[] = $this->getPhpxDir() . '/thirdparty/mpdecimal/libmpdec++';
        }

        return $includePaths;
    }

    protected function getLibraryPaths(): array
    {
        $platform = $this->getPlatform();
        $libraryPaths = [
            $this->getPhpxDir() . '/lib',
        ];

        // Add the platform-specific PHP library paths
        if ($platform instanceof Windows) {
            $phpLibPaths = $platform->buildPhpSdkLibPaths($this->getPhpDir());
            $libraryPaths = array_merge($libraryPaths, $phpLibPaths);
        } else {
            // Linux/macOS
            $phpLibPaths = $platform->buildPhpLibPaths($this->getPhpDir());
            $libraryPaths = array_merge($libraryPaths, $phpLibPaths);
        }

        return $libraryPaths;
    }

    /**
     * Get the library files to link against
     */
    protected function getLibraries(): array
    {
        $platform = $this->getPlatform();
        $libraries = [];

        // phpx library (file name format differs by platform)
        $phpxLibPath = $this->findPhpxLibrary();
        if ($phpxLibPath === null) {
            $this->error($this->getPhpxLibraryErrorMessage());
        }
        $libraries[] = $phpxLibPath;

        // Both extension and bin modes need to link the PHP library
        if ($platform instanceof Windows) {
            // Windows: pick different libraries based on the build mode
            if ($this->isBuildModeEmbed()) {
                // bin mode: link both php8ts.lib and php8embed.lib
                // Note: php8ts.lib must come before php8embed.lib because embed depends on core
                // php8ts.lib provides the PHP core global symbols (executor_globals, compiler_globals, sapi_globals)
                if (!empty($this->windowsPhpCoreLib)) {
                    $libraries[] = $this->windowsPhpCoreLib;  // do not quote
                }
                // php8embed.lib provides the embed API
                if (!empty($this->windowsPhpEmbedLib)) {
                    $libraries[] = $this->windowsPhpEmbedLib;  // do not quote
                }
            } else {
                // ext mode: use only php8ts.lib or php8.lib (PHP extension)
                if (!empty($this->windowsPhpCoreLib)) {
                    $libraries[] = $this->windowsPhpCoreLib;  // do not quote
                }
            }
            
            // Add the Windows API libraries (required by Win32 GUI programs)
            $libraries[] = 'user32.lib';   // Windows UI functions (CreateWindow, MessageBox, etc.)
            $libraries[] = 'gdi32.lib';    // GDI graphics functions
            $libraries[] = 'kernel32.lib'; // Core Windows API
            $libraries[] = 'gmp.lib';
            $libraries[] = 'gmpxx.lib';
            $libraries[] = 'mpfr.lib';
            $libraries[] = 'libmpdec-4.0.1.dll.lib';
            $libraries[] = 'libmpdec++-4.0.1.dll.lib';
        } else {
            // Unix PHP extensions resolve Zend/PHP symbols from the host SAPI.
            // Linking libphp.so here would load a second ZendVM and give PHPX a
            // different set of compiler/executor globals from the host process.
            if (!$this->isBuildModeExt()) {
                $libraries[] = 'php';
            }
            $libraries[] = 'gmp';
            $libraries[] = 'gmpxx';
            $libraries[] = 'mpfr';
        }

        return $libraries;
    }

    /**
     * Resolve the phpx library file path, returning null when the library does
     * not exist.
     *
     * Windows uses phpx.lib (no lib prefix); other platforms prefer the shared
     * library (libphpx.so / libphpx.dylib) and fall back to the static library
     * libphpx.a when it is not found.
     */
    protected function findPhpxLibrary(): ?string
    {
        $platform = $this->getPlatform();

        if ($platform instanceof Windows) {
            $phpxLibPath = $this->getPhpxDir() . '\\lib\\phpx.lib';
            return is_file($phpxLibPath) ? $phpxLibPath : null;
        }

        // Linux/macOS: prefer the shared library, fall back to the static library
        // getSharedLibraryExtension() may or may not include a leading dot, so normalize it
        $sharedLibExt = ltrim($platform->getSharedLibraryExtension(), '.');
        $phpxLibPath = $this->getPhpxDir() . '/lib/libphpx.' . $sharedLibExt;
        if (is_file($phpxLibPath)) {
            return $phpxLibPath;
        }

        // Stateful PHPX runtime facilities (global Zend handlers and internal
        // classes) must have one process-wide owner when a native module is
        // loaded into another process. Statically linking PHPX into each
        // extension/library would duplicate that state.
        if (!$this->isWasiTarget() && ($this->isBuildModeExt() || $this->isBuildModeLib())) {
            return null;
        }

        $phpxStaticPath = $this->getPhpxDir() . '/lib/libphpx.a';
        return is_file($phpxStaticPath) ? $phpxStaticPath : null;
    }

    /**
     * Generate the error message shown when the phpx library is missing
     */
    protected function getPhpxLibraryErrorMessage(): string
    {
        $platform = $this->getPlatform();
        if ($platform instanceof Windows) {
            $expected = $this->getPhpxDir() . '\\lib\\phpx.lib';
            $buildHint = 'Build PHPX first (for example, run `nmake phpx` in ' . $this->getPhpxDir() . '\\build)';
        } else {
            $sharedLibExt = ltrim($platform->getSharedLibraryExtension(), '.');
            $expected = $this->getPhpxDir() . '/lib/libphpx.' . $sharedLibExt;
            if ($this->isWasiTarget() || (!$this->isBuildModeExt() && !$this->isBuildModeLib())) {
                $expected .= ' or ' . $this->getPhpxDir() . '/lib/libphpx.a';
            }
            $buildHint = 'Build phpx first (e.g. run `cmake --build ' . $this->getPhpxDir() . '/build`)';
        }

        return 'phpx library not found at: ' . $expected . PHP_EOL .
            $buildHint . PHP_EOL .
            'or set PHPX_HOME to a phpx installation that provides the library.';
    }

    /**
     * Verify the phpx library is available up front and fail before compilation
     * starts, rather than only failing at link time after all source files have
     * been compiled.
     */
    protected function validatePhpxLibrary(): void
    {
        if ($this->findPhpxLibrary() === null) {
            $this->error($this->getPhpxLibraryErrorMessage());
        }
    }

}
