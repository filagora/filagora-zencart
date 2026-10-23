<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Tests\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

final class Zips
{
    /**
     * A real ZIP on disk holding the given entries (name => contents).
     *
     * @param  array<string, string>  $entries
     */
    public static function path(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zc-zip-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  array<string, string>  $entries
     */
    public static function upload(array $entries, string $name = 'plugin.zip'): UploadedFile
    {
        return new UploadedFile(self::path($entries), $name, 'application/zip', null, true);
    }

    /**
     * An encapsulated plugin archive: `zc_plugins/<key>/<folder>/manifest.php` declaring a plugin id.
     *
     * @return array<string, string>
     */
    public static function encapsulated(string $key, string $folder = 'v1.0.0', int $pluginId = 0): array
    {
        return [
            "zc_plugins/{$key}/{$folder}/manifest.php" => "<?php\nreturn [\n    'pluginVersion' => '{$folder}',\n    'pluginId' => {$pluginId},\n];\n",
            "zc_plugins/{$key}/{$folder}/readme.txt" => 'hello',
        ];
    }
}
