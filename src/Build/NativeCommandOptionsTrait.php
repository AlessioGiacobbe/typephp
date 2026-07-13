<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use TypePhp\Metadata\Constants;

trait NativeCommandOptionsTrait
{
    /** @var null|array{header: string, artifact: string} */
    protected ?array $precompiledHeader = null;

    protected function getCommonCompileCommandOptions(): CompileOptions
    {
        $includePaths = $this->getIncludePaths();
        if (!empty($this->userIncludePaths)) {
            $includePaths = array_merge($includePaths, $this->userIncludePaths);
        }

        $userDefines = $this->userDefines;
        if ($this->isBuildModeLib()) {
            $userDefines[] = 'TYPEPHP_NO_MAIN=1';
        }

        return new CompileOptions([
            'include_paths' => $includePaths,
            'optimize' => $this->optimizeLevel,
            'debug' => $this->debug,
            'sanitize' => $this->sanitize,
            'march' => $this->march,
            'target_platform' => $this->targetPlatform,
            'is_zts' => $this->isPhpZts,
            'build_mode' => $this->buildMode,
            'enable_profiler' => $this->enableProfiler,
            'prof_output' => $this->targetName . '.prof',
            'user_defines' => $userDefines,
            'lto' => $this->enableLto,
        ]);
    }

    protected function getCompileCommandOptions(): CompileOptions
    {
        $options = $this->getCommonCompileCommandOptions();
        $options = $options
            ->with('cpp_std', $this->cxxStd)
            ->with('cxxflags', $this->cxxFlags)
            ->with('suppressed_warnings', Constants::MSVC_SUPPRESSED_WARNINGS ?? []);

        if ($this->precompiledHeader !== null) {
            $options = $options->with('precompiled_header', $this->precompiledHeader);
        }

        return $options;
    }

    protected function getCCompileCommandOptions(): CompileOptions
    {
        $options = $this->getCommonCompileCommandOptions();
        return $options->with('suppressed_warnings', ['4244', '4146']);
    }

    protected function getNativeCompileCommandOptions(string $language = ''): CompileOptions
    {
        $options = $this->getCommonCompileCommandOptions();
        $options = $options->with('suppressed_warnings', Constants::MSVC_SUPPRESSED_WARNINGS ?? []);

        if ($language === 'objective-c++') {
            $options = $options->with('cpp_std', $this->cxxStd)->with('cxxflags', $this->cxxFlags);
        }

        return $options;
    }

    protected function getLinkCommandOptions(): LinkOptions
    {
        $libraryPaths = array_merge($this->getLibraryPaths(), $this->linkPaths);
        $libraries = $this->getLibraries();
        if ($this->enableProfiler) {
            $libraries[] = 'profiler';
        }
        $libraries = array_merge($libraries, $this->linkLibs);

        $options = [
            'library_paths' => $libraryPaths,
            'libraries' => $libraries,
            'ldflags' => $this->ldflags,
            'debug' => $this->debug,
            'no_console' => $this->noConsole,
            'build_mode' => $this->buildMode,
            'sanitize' => $this->sanitize,
            'lto' => $this->enableLto,
            'target_platform' => $this->targetPlatform,
        ];

        $rpaths = $this->getPlatform()->getDefaultRpaths($this->getPhpxDir(), $this->getPhpDir());
        if (!empty($rpaths)) {
            $options['rpath'] = $rpaths;
        }

        return new LinkOptions($options);
    }
}
