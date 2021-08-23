<?php

namespace NextApps\PoeditorSync\Translations;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\VarExporter\VarExporter;

class TranslationManager
{
    /**
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $filesystem;

    /**
     * Create a new manager instance.
     *
     * @param \Illuminate\Filesystem\Filesystem $filesystem
     *
     * @return void
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Get translations of PHP and JSON translation files in the specified locale.
     *
     * @param string $locale
     *
     * @return array
     */
    public function getTranslations(string $locale)
    {
        $translation_namespaces =  app('translator')->getLoader()->namespaces();
        $translations = array_merge(
            $this->getPhpTranslations(resource_path("lang/{$locale}")),
            $this->getJsonTranslations(resource_path("lang/{$locale}.json")),
        );
        foreach ($translation_namespaces as $name=>$namespace){
            $phpTranslations=$this->getPhpTranslations($namespace."/{$locale}");
            $jsonTranslations=$this->getJsonTranslations($namespace."/{$locale}.json");
            $translations = array_merge(
                $translations,
                count($phpTranslations)? [
                    'namespace_'.$name => $phpTranslations
                ] : [],
                count($jsonTranslations)? [
                    'namespace_'.$name => $jsonTranslations
                ] : []
            );
        }

        if (config('poeditor-sync.include_vendor')) {
            $translations += $this->getVendorTranslations($locale);
        }

        return $translations;
    }

    /**
     * Create translation files based on the provided array.
     *
     * @param array $translations
     * @param string $locale
     *
     * @return void
     */
    public function createTranslationFiles(array $translations, string $locale)
    {
        $this->createEmptyLocaleFolder($locale);
        $translation_namespaces =  app('translator')->getLoader()->namespaces();
        foreach ($translation_namespaces as $name => $namespace){
            $this->createPhpTranslationNamespaceFiles($namespace, $translations['namespace_'.$name], $locale);
            $this->createJsonTranslationNamespaceFile($namespace, $translations['namespace_'.$name], $locale);
            unset($translations['namespace_'.$name]);
        }
        $this->createPhpTranslationFiles($translations, $locale);
        $this->createJsonTranslationFile($translations, $locale);

        if (config('poeditor-sync.include_vendor')) {
            $this->createVendorTranslationFiles($translations, $locale);
        }
    }

    /**
     * Get translations of vendor translation files in the specified locale.
     *
     * @param string $locale
     *
     * @return array
     */
    protected function getVendorTranslations(string $locale)
    {
        if (! $this->filesystem->exists(resource_path('lang/vendor'))) {
            return [];
        }

        $directories = collect($this->filesystem->directories(resource_path('lang/vendor')));

        $translations = $directories->mapWithKeys(function ($directory) use ($locale) {
            $phpTranslations = $this->getPhpTranslations("$directory/{$locale}");
            $jsonTranslations = $this->getJsonTranslations("$directory/{$locale}.json");

            return [basename($directory) => array_merge($phpTranslations, $jsonTranslations)];
        })->toArray();

        return ['vendor' => $translations];
    }

    /**
     * Get PHP translations from files in folder.
     *
     * @param string $folder
     *
     * @return array
     */
    protected function getPhpTranslations(string $folder)
    {
        if (!$this->filesystem->exists($folder)){
            return [];
        }
        $files = collect($this->filesystem->files($folder));

        $excludedFiles = collect(config('poeditor-sync.excluded_files', []))
            ->map(function ($excludedFile) {
                if (Str::endsWith($excludedFile, '.php')) {
                    return $excludedFile;
                }

                return "{$excludedFile}.php";
            });

        return $files
            ->reject(function ($file) use ($excludedFiles) {
                return $excludedFiles->contains($file->getFilename());
            })->mapWithKeys(function ($file) {
                $filename = pathinfo($file->getRealPath(), PATHINFO_FILENAME);

                return [$filename => $this->filesystem->getRequire($file->getRealPath())];
            })
            ->toArray();
    }

    /**
     * Get JSON translations from file.
     *
     * @param string $filename
     *
     * @return array
     */
    protected function getJsonTranslations(string $filename)
    {
        if (! $this->filesystem->exists($filename)) {
            return [];
        }

        return json_decode($this->filesystem->get($filename), true);
    }

    /**
     * Create PHP translation files containing arrays.
     *
     * @param array $translations
     * @param string $locale
     *
     * @return void
     */
    protected function createPhpTranslationFiles(array $translations, string $locale)
    {
        $this->createPhpFiles(resource_path("lang/{$locale}"), $translations);
    }

    /**
     * Create PHP translation files containing arrays for namespace.
     *
     * @param array $translations
     * @param string $locale
     *
     * @return void
     */
    protected function createPhpTranslationNamespaceFiles(string $namespace, array $translations, string $locale)
    {
        $this->createPhpFiles($namespace."/{$locale}", $translations);
    }

    /**
     * Create JSON translation file containing arrays.
     *
     * @param array $translations
     * @param string $locale
     *
     * @return void
     */
    protected function createJsonTranslationFile(array $translations, string $locale)
    {
        $this->createJsonFile(resource_path("lang/{$locale}.json"), $translations);
    }

    /**
     * Create JSON translation file containing arrays for namespace.
     *
     * @param array $translations
     * @param string $locale
     *
     * @return void
     */
    protected function createJsonTranslationNamespaceFile(string $namespace, array $translations, string $locale)
    {
        $this->createJsonFile($namespace."/{$locale}.json", $translations);
    }

    /**
     * Create vendor translation files.
     *
     * @param array $translations
     * @param string $locale
     *
     * @return void
     */
    protected function createVendorTranslationFiles(array $translations, string $locale)
    {
        if (! Arr::has($translations, 'vendor')) {
            return;
        }

        foreach ($translations['vendor'] as $package => $packageTranslations) {
            $path = resource_path("lang/vendor/{$package}/{$locale}");

            if (! $this->filesystem->exists($path)) {
                $this->filesystem->makeDirectory($path, 0755, true);
            } else {
                $this->filesystem->cleanDirectory($path);
            }

            $this->createPhpFiles($path, $packageTranslations);
            $this->createJsonFile("{$path}.json", $packageTranslations);
        }
    }

    /**
     * Create PHP translation files in folder.
     *
     * @param string $filename
     * @param array $translations
     *
     * @return void
     */
    protected function createPhpFiles(string $folder, array $translations)
    {

        $translations = Arr::where($translations, function ($translation) {
            return is_array($translation);
        });

        foreach ($translations as $filename => $fileTranslations) {
            $array = VarExporter::export($fileTranslations);

            if ($filename === 'vendor') {
                continue;
            }

            $this->filesystem->put(
                "{$folder}/{$filename}.php",
                '<?php'.PHP_EOL.PHP_EOL."return {$array};".PHP_EOL,
            );
        }
    }

    /**
     * Create JSON translation file on filename.
     *
     * @param string $filename
     * @param array $translations
     *
     * @return void
     */
    protected function createJsonFile(string $filename, array $translations)
    {
        $translations = Arr::where($translations, function ($translation) {
            return is_string($translation);
        });

        if (empty($translations)) {
            return;
        }

        $this->filesystem->put($filename, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Create empty folder for locale in "lang" resources folder (if folder does not exist yet).
     *
     * @param string $locale
     *
     * @return void
     */
    protected function createEmptyLocaleFolder(string $locale)
    {
        if (! $this->filesystem->exists(resource_path('lang'))) {
            $this->filesystem->makeDirectory(resource_path('lang'));
        }

        $path = resource_path("lang/{$locale}/");

        if (file_exists($path)) {
            $this->filesystem->deleteDirectory($path);
        }

        $this->filesystem->makeDirectory($path);
    }
}
