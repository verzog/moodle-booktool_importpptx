<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Converts vector images (WMF/EMF) to a web-renderable raster format.
 *
 * @package    booktool_importpptx
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace booktool_importpptx\graphics;

/**
 * Optional, injection-safe wrapper that turns WMF/EMF vector images into PNG
 * using whichever external converter is installed (ImageMagick, LibreOffice or
 * Inkscape). Browsers cannot display WMF/EMF, so PowerPoint clip-art stored in
 * those formats is lost without this. It is strictly optional: when no converter
 * is available the caller drops the image rather than emit a broken one.
 */
class converter {
    /** @var bool Whether converter detection has run for this request. */
    private static bool $checked = false;

    /** @var string|null The detected converter binary, or null if none works. */
    private static ?string $tool = null;

    /**
     * Whether a usable WMF/EMF converter is available.
     *
     * @return bool True if one of the supported converters can be run.
     */
    public static function is_available(): bool {
        return self::detect() !== null;
    }

    /**
     * Converts WMF/EMF image bytes to PNG bytes.
     *
     * @param string $bytes The source vector image bytes.
     * @param string $ext The source extension ('wmf' or 'emf').
     * @return string|null The PNG bytes, or null if no converter is available or it failed.
     */
    public static function to_png(string $bytes, string $ext): ?string {
        $tool = self::detect();
        if ($tool === null || $bytes === '') {
            return null;
        }

        $dir = make_request_directory();
        $source = $dir . '/source.' . (strtolower($ext) === 'emf' ? 'emf' : 'wmf');
        if (file_put_contents($source, $bytes) === false) {
            return null;
        }
        $out = $dir . '/source.png';

        if ($tool === 'soffice') {
            // Give LibreOffice a private profile so it runs without a real home.
            self::run([
                'soffice', '--headless', '-env:UserInstallation=file://' . $dir . '/loprofile',
                '--convert-to', 'png', '--outdir', $dir, $source,
            ]);
        } else if ($tool === 'inkscape') {
            self::run(['inkscape', $source, '--export-type=png', '--export-filename=' . $out]);
        } else {
            // ImageMagick: "convert"/"magick" <input> <output>.
            self::run([$tool, $source, $out]);
        }

        if (is_file($out) && filesize($out) > 0) {
            $png = file_get_contents($out);
            return ($png === false || $png === '') ? null : $png;
        }
        return null;
    }

    /**
     * Detects (once per request) the first supported converter that can run.
     *
     * @return string|null The converter binary name, or null if none is usable.
     */
    private static function detect(): ?string {
        if (self::$checked) {
            return self::$tool;
        }
        self::$checked = true;
        self::$tool = null;

        $candidates = [
            'convert' => ['-version', 'ImageMagick'],
            'magick' => ['-version', 'ImageMagick'],
            'soffice' => ['--version', 'LibreOffice'],
            'inkscape' => ['--version', 'Inkscape'],
        ];
        foreach ($candidates as $binary => $probe) {
            [$flag, $needle] = $probe;
            $result = self::run([$binary, $flag]);
            if ($result['started'] && stripos($result['out'] . $result['err'], $needle) !== false) {
                self::$tool = $binary;
                break;
            }
        }
        return self::$tool;
    }

    /**
     * Runs a command with arguments passed as an array (no shell, so no injection).
     *
     * @param string[] $command The command and its arguments.
     * @return array{started:bool,out:string,err:string} The run result.
     */
    private static function run(array $command): array {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['started' => false, 'out' => '', 'err' => ''];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return ['started' => true, 'out' => (string) $out, 'err' => (string) $err];
    }
}
