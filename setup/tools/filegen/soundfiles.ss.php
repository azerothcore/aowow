<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

if (!CLI)
    die('not in cli mode');


CLISetup::registerSetup("build", new class extends SetupScript
{
    protected $info = array(
        'soundfiles' => [[], CLISetup::ARGV_PARAM, 'Links converted sound files to database and moves them to destination.']
    );

    protected $requiredDirs = ['static/wowsounds/'];
    protected $setupAfter   = [['sounds'], []];

    private function safeCopy(string $src, string $dst) : bool
    {
        $tmp = $dst . '.tmp.' . getmypid() . '.' . uniqid('', true);
        if (!@copy($src, $tmp))
        {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $dst))
        {
            // Windows cannot rename over an existing file
            if (!@unlink($dst) || !@rename($tmp, $dst))
            {
                @unlink($tmp);
                return false;
            }
        }

        return true;
    }

    private function linkOrCopy(string $relTarget, string $absTarget, string $linkPath) : bool
    {
        $tmp = $linkPath . '.tmp.' . getmypid() . '.' . uniqid('', true);

        if (!@symlink($relTarget, $tmp) && !$this->safeCopy($absTarget, $tmp))
        {
            @unlink($tmp);
            return false;
        }

        if (@rename($tmp, $linkPath))
            return true;

        // Windows cannot rename over an existing file
        if (!@unlink($linkPath) || !@rename($tmp, $linkPath))
        {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    public function generate() : bool
    {
        $commonDir = 'static/wowsounds/common';
        if (!CLISetup::writeDir($commonDir))
            return false;

        // Build target locale list (always ensure EN fallback directory exists)
        $targetLocales = CLISetup::$locales;
        $searchLocales = $targetLocales;
        if (!isset($searchLocales[Locale::EN->value]))
            $searchLocales[Locale::EN->value] = Locale::EN;

        // URLs use every config-enabled locale, even when --locales limits which sources are built
        $outputLocales = [];
        foreach (Locale::cases() as $loc)
            if ($loc->validate())
                $outputLocales[$loc->value] = $loc;
        foreach ($searchLocales as $loc)
            $outputLocales[$loc->value] = $loc;

        foreach ($outputLocales as $loc)
        {
            if (!CLISetup::writeDir('static/wowsounds/' . $loc->json()))
                return false;
        }

        // Clean up legacy flat files in static/wowsounds/ from older single-locale builds
        foreach (glob('static/wowsounds/*') ?: [] as $legacy)
        {
            if (is_file($legacy) && ctype_digit(basename($legacy)))
                @unlink($legacy);
        }

        // A zero mask means "all supported locales" - same as Locale::validate()
        $cfgMask       = (int)Cfg::get('LOCALES');
        $targetMask    = array_reduce($targetLocales, fn(int $m, Locale $l) => $m | $l->maskBit(), 0);
        $supportedMask = array_reduce(array_filter(Locale::cases(), fn(Locale $l) => $l->validate()), fn(int $m, Locale $l) => $m | $l->maskBit(), 0);
        $effectiveMask = ($cfgMask ?: $supportedMask) & $supportedMask;
        $isFullRun     = ($effectiveMask & $targetMask) === $effectiveMask;

        // ALL files
        $files  = DB::Aowow()->selectCol('SELECT ABS(`id`) AS ARRAY_KEY, CONCAT(`path`, "/", `file`) FROM ?_sounds_files');
        $nFiles = count($files);
        $qtLen  = strlen($nFiles);
        $sum    = 0;
        $time   = new Timer(500);

        foreach ($files as $fileId => $filePath)
        {
            $sum++;
            if ($time->update())
            {
                CLI::write(sprintf('[soundfiles] * %'.$qtLen.'d / %d (%4.1f%%)', $sum,  $nFiles, round(100 * $sum / $nFiles, 1)), CLI::LOG_BLANK, true, true);
                DB::Aowow()->selectCell('SELECT 1');        // keep mysql busy or it may go away
            }

            // expect converted files as file.wav_ or file.mp3_
            $filePath .= '_';

            // Locate source audio file per locale
            $foundByLoc = [];
            foreach ($searchLocales as $loc)
            {
                $locKey = $loc->json();
                foreach ($loc->gameDirs() as $dir)
                {
                    $p       = CLI::nicePath($filePath, CLISetup::$srcDir, $dir);
                    $lower_p = CLI::nicePath(strtolower($filePath), CLISetup::$srcDir, $dir);

                    if (CLISetup::fileExists($p))
                    {
                        $foundByLoc[$locKey] = $p;
                        break;
                    }
                    if (CLISetup::fileExists($lower_p))
                    {
                        $foundByLoc[$locKey] = $lower_p;
                        break;
                    }
                }
            }

            if (!$foundByLoc)
            {
                CLI::write('[soundfiles]  - did not find file: '.CLI::bold(CLI::nicePath($filePath, CLISetup::$srcDir, '['.implode(',', array_map(fn($x) => $x->json(), CLISetup::$locales)).']')), CLI::LOG_WARN);
                $time->reset();
                // flag as unusable in DB
                DB::Aowow()->query('UPDATE ?_sounds_files SET `id` = ?d WHERE ABS(`id`) = ?d', -$fileId, $fileId);
                continue;
            }

            $fileOk        = true;
            $uniqueSources = array_values($foundByLoc);

            // identical audio may ship in several locale directories; on a full run deduplicate by content, not path
            if ($isFullRun && count($uniqueSources) > 1)
            {
                $byContent = [];
                foreach ($uniqueSources as $source)
                {
                    $size = @filesize($source);
                    $hash = $size !== false ? @hash_file('sha256', $source) : false;
                    $byContent[$hash !== false ? $size . ':' . $hash : $source] = $source;
                }

                $uniqueSources = array_values($byContent);
            }

            // Case 1: Language-neutral sound (only deduplicate into common/ on full locale builds)
            if (count($uniqueSources) === 1 && $isFullRun)
            {
                $srcFile    = reset($uniqueSources);
                $commonFile = $commonDir . '/' . $fileId;

                if (!$this->safeCopy($srcFile, $commonFile))
                {
                    $this->success = false;
                    $fileOk        = false;
                    CLI::write('[soundfiles]  - could not copy '.CLI::bold($srcFile).' into '.CLI::bold($commonFile), CLI::LOG_ERROR);
                    $time->reset();
                }
                else
                {
                    foreach ($outputLocales as $loc)
                    {
                        $linkPath = 'static/wowsounds/' . $loc->json() . '/' . $fileId;
                        if (!$this->linkOrCopy('../common/' . $fileId, $commonFile, $linkPath))
                        {
                            $this->success = false;
                            $fileOk        = false;
                            CLI::write('[soundfiles]  - could not link '.CLI::bold($commonFile).' into '.CLI::bold($linkPath), CLI::LOG_ERROR);
                            $time->reset();
                        }
                    }
                }
            }
            // Case 2: Localized voice-overs (different files exist per locale)
            else
            {
                $fallbackKey = isset($foundByLoc['enus']) ? 'enus' : array_key_first($foundByLoc);
                $fallbackAbs = 'static/wowsounds/' . $fallbackKey . '/' . $fileId;

                // Ensure fallback locale file is copied first so other locales can symlink to it if missing
                if (!$this->safeCopy($foundByLoc[$fallbackKey], $fallbackAbs))
                {
                    $this->success = false;
                    $fileOk        = false;
                    CLI::write('[soundfiles]  - could not copy '.CLI::bold($foundByLoc[$fallbackKey]).' into '.CLI::bold($fallbackAbs), CLI::LOG_ERROR);
                    $time->reset();
                }
                else
                {
                    foreach ($outputLocales as $loc)
                    {
                        $locKey  = $loc->json();
                        $dstFile = 'static/wowsounds/' . $locKey . '/' . $fileId;

                        if ($locKey === $fallbackKey)
                            continue;

                        // partial rebuild must not replace existing audio in locales that were not searched
                        if (!isset($searchLocales[$loc->value]) && (file_exists($dstFile) || is_link($dstFile)))
                            continue;

                        if (isset($foundByLoc[$locKey]))
                        {
                            if (!$this->safeCopy($foundByLoc[$locKey], $dstFile))
                            {
                                $this->success = false;
                                $fileOk        = false;
                                CLI::write('[soundfiles]  - could not copy '.CLI::bold($foundByLoc[$locKey]).' into '.CLI::bold($dstFile), CLI::LOG_ERROR);
                                $time->reset();
                            }
                        }
                        else
                        {
                            // Locale did not localize this specific line -> link to fallback locale
                            if (!$this->linkOrCopy('../' . $fallbackKey . '/' . $fileId, $fallbackAbs, $dstFile))
                            {
                                $this->success = false;
                                $fileOk        = false;
                                CLI::write('[soundfiles]  - could not link '.CLI::bold($fallbackAbs).' into '.CLI::bold($dstFile), CLI::LOG_ERROR);
                                $time->reset();
                            }
                        }
                    }
                }
            }

            // Keep DB flag in sync with the result of this run
            if ($fileOk)
                DB::Aowow()->query('UPDATE ?_sounds_files SET `id` = ?d WHERE `id` = ?d', $fileId, -$fileId);
            else
                DB::Aowow()->query('UPDATE ?_sounds_files SET `id` = ?d WHERE `id` = ?d', -$fileId, $fileId);
        }

        return $this->success;
    }
});

?>
