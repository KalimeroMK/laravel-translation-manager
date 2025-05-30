<?php

namespace Barryvdh\TranslationManager;

use Barryvdh\TranslationManager\Events\TranslationsExportedEvent;
use Barryvdh\TranslationManager\Models\Translation;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lang;
use Symfony\Component\Finder\Finder;

use Symfony\Component\Finder\SplFileInfo;

use const PHP_EOL;

class Manager
{
    public const JSON_GROUP = '_json';

    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Filesystem
     */
    protected $files;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var array
     */
    protected $locales = [];

    /**
     * @var mixed
     */
    protected $ignoreLocales;

    /**
     * @var string
     */
    protected $ignoreFilePath;

    /**
     * @throws FileNotFoundException
     */
    public function __construct(Application $app, Filesystem $files, Dispatcher $events)
    {
        $this->app = $app;
        $this->files = $files;
        $this->events = $events;
        $this->config = $app['config']['translation-manager'];
        $this->ignoreFilePath = storage_path('.ignore_locales');
        $this->ignoreLocales = $this->getIgnoredLocales();
    }

    /**
     * @throws FileNotFoundException
     */
    protected function getIgnoredLocales()
    {
        if (! $this->files->exists($this->ignoreFilePath)) {
            return [];
        }
        $result = json_decode($this->files->get($this->ignoreFilePath), false, 512);

        return ($result && is_array($result)) ? $result : [];
    }

    public function importTranslations($replace = false, $base = null, $import_group = false): int
    {
        $counter = 0;
        // allows for vendor lang files to be properly recorded through recursion.
        $vendor = true;
        if ($base === null) {
            $base = $this->app['path.lang'];
            $vendor = false;
        }

        foreach ($this->files->directories($base) as $langPath) {
            $locale = basename($langPath);

            // import langfiles for each vendor
            if ($locale === 'vendor') {
                foreach ($this->files->directories($langPath) as $vendor) {
                    $counter += $this->importTranslations($replace, $vendor);
                }

                continue;
            }
            $vendorName = $this->files->name($this->files->dirname($langPath));
            foreach ($this->files->allfiles($langPath) as $file) {
                $info = pathinfo($file);
                $group = $info['filename'];
                if ($import_group && $import_group !== $group) {
                    continue;
                }

                if (in_array($group, $this->config['exclude_groups'], true)) {
                    continue;
                }
                $subLangPath = str_replace($langPath.DIRECTORY_SEPARATOR, '', $info['dirname']);
                $subLangPath = str_replace(DIRECTORY_SEPARATOR, '/', $subLangPath);
                $langPath = str_replace(DIRECTORY_SEPARATOR, '/', $langPath);

                if ($subLangPath !== $langPath) {
                    $group = $subLangPath.'/'.$group;
                }

                if (! $vendor) {
                    $translations = Lang::getLoader()->load($locale, $group);
                } else {
                    $translations = include $file;
                    $group = 'vendor/'.$vendorName;
                }

                if ($translations && is_array($translations)) {
                    foreach (Arr::dot($translations) as $key => $value) {
                        $importedTranslation = $this->importTranslation($key, $value, $locale, $group, $replace);
                        $counter += $importedTranslation ? 1 : 0;
                    }
                }
            }
        }

        foreach ($this->files->files($this->app['path.lang']) as $jsonTranslationFile) {
            if (! str_contains($jsonTranslationFile, '.json')) {
                continue;
            }
            $locale = basename($jsonTranslationFile, '.json');
            $group = self::JSON_GROUP;
            $translations =
                Lang::getLoader()->load($locale, '*', '*'); // Retrieves JSON entries of the given locale only
            if ($translations && is_array($translations)) {
                foreach ($translations as $key => $value) {
                    $importedTranslation = $this->importTranslation($key, $value, $locale, $group, $replace);
                    $counter += $importedTranslation ? 1 : 0;
                }
            }
        }

        return $counter;
    }

    public function importTranslation($key, $value, $locale, $group, $replace = false): bool
    {
        // process only string values
        if (is_array($value)) {
            return false;
        }
        $value = (string) $value;
        $translation = Translation::firstOrNew([
            'locale' => $locale,
            'group' => $group,
            'key' => $key,
        ]);

        // Check if the database is different from the files
        $newStatus = $translation->value === $value ? Translation::STATUS_SAVED : Translation::STATUS_CHANGED;
        if ($newStatus !== (int) $translation->status) {
            $translation->status = $newStatus;
        }

        // Only replace when empty, or explicitly told so
        if ($replace || ! $translation->value) {
            $translation->value = $value;
        }

        $translation->save();

        return true;
    }

    public function findTranslations($path = null): int
    {
        $path = $path ?: base_path();
        $groupKeys = [];
        $stringKeys = [];
        $functions = $this->config['trans_functions'];

        $groupPattern =                          // See https://regex101.com/r/WEJqdL/6
            "[^\w|>]".                          // Must not have an alphanum or _ or > before real method
            '('.implode('|', $functions).')'.  // Must start with one of the functions
            "\(".                               // Match opening parenthesis
            "[\'\"]".                           // Match " or '
            '('.                                // Start a new group to match:
            '[\/a-zA-Z0-9_-]+'.                 // Must start with group
            "([.](?! )[^\1)]+)+".               // Be followed by one or more items/keys
            ')'.                                // Close group
            "[\'\"]".                           // Closing quote
            "[\),]";                             // Close parentheses or new parameter

        $stringPattern =
            "[^\w]".                                     // Must not have an alphanum before real method
            '('.implode('|', $functions).')'.             // Must start with one of the functions
            "\(\s*".                                       // Match opening parenthesis
            "(?P<quote>['\"])".                            // Match " or ' and store in {quote}
            "(?P<string>(?:\\\k{quote}|(?!\k{quote}).)*)". // Match any string that can be {quote} escaped
            "\k{quote}".                                   // Match " or ' previously matched
            "\s*[\),]";                                    // Close parentheses or new parameter

        // Find all PHP + Twig files in the app folder, except for storage
        $finder = new Finder;
        $finder->in($path)->exclude('storage')->exclude('vendor')->name('*.php')->name('*.twig')->name('*.vue')->files();

        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            // Search the current file for the pattern
            if (preg_match_all("/$groupPattern/siU", $file->getContents(), $matches)) {
                // Get all matches
                foreach ($matches[2] as $key) {
                    $groupKeys[] = $key;
                }
            }

            if (preg_match_all("/$stringPattern/siU", $file->getContents(), $matches)) {
                foreach ($matches['string'] as $key) {
                    if (preg_match("/(^[\/a-zA-Z0-9_-]+([.][^\1)\ ]+)+$)/siU", $key, $groupMatches)) {
                        // group{.group}.key format, already in $groupKeys but also matched here
                        // do nothing, it has to be treated as a group
                        continue;
                    }

                    // TODO: This can probably be done in the regex, but I couldn't do it.
                    // skip keys which contain namespacing characters, unless they also contain a
                    // space, which makes it JSON.
                    if (Str::contains($key, ' ') || ! (Str::contains($key, '::') && Str::contains($key, '.'))) {
                        $stringKeys[] = $key;
                    }
                }
            }
        }
        // Remove duplicates
        $groupKeys = array_unique($groupKeys);
        $stringKeys = array_unique($stringKeys);

        // Add the translations to the database, if not existing.
        foreach ($groupKeys as $key) {
            // Split the group and item
            [$group, $item] = explode('.', $key, 2);
            $this->missingKey('', $group, $item);
        }

        foreach ($stringKeys as $key) {
            $group = self::JSON_GROUP;
            $item = $key;
            $this->missingKey('', $group, $item);
        }

        // Return the number of found translations
        return count($groupKeys + $stringKeys);
    }

    public function missingKey($namespace, $group, $key): void
    {
        if (! in_array($group, $this->config['exclude_groups'], true)) {
            Translation::firstOrCreate([
                'locale' => $this->app['config']['app.locale'],
                'group' => $group,
                'key' => $key,
            ]);
        }
    }

    public function exportTranslations($group = null, $json = false): void
    {
        $group = basename($group);
        $basePath = $this->app['path.lang'];

        if (! $json && ! in_array($group, $this->config['exclude_groups'], true)) {
            $vendor = false;
            if ($group === '*') {
                $this->exportAllTranslations();

                return;
            }
            if (Str::startsWith($group, 'vendor')) {
                $vendor = true;
            }
            $tree = $this->makeTree(Translation::ofTranslatedGroup($group)
                ->orderByGroupKeys(Arr::get($this->config, 'sort_keys', false))
                ->get());
            foreach ($tree as $locale => $groups) {
                $locale = basename($locale);
                if (isset($groups[$group])) {
                    $translations = $groups[$group];
                    $path = $this->app['path.lang'];

                    $locale_path = $locale.DIRECTORY_SEPARATOR.$group;
                    if ($vendor) {
                        $path = $basePath.'/'.$group.'/'.$locale;
                        $locale_path = Str::after($group, '/');
                    }
                    $subFolders = explode(DIRECTORY_SEPARATOR, $locale_path);
                    array_pop($subFolders);

                    $subFolder_level = '';
                    foreach ($subFolders as $subFolder) {
                        $subFolder_level .= $subFolder.DIRECTORY_SEPARATOR;

                        $temp_path = rtrim($path.DIRECTORY_SEPARATOR.$subFolder_level, DIRECTORY_SEPARATOR);
                        if (! is_dir($temp_path)) {
                            mkdir($temp_path, 0777, true);
                        }
                    }

                    $path .= DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$group.'.php';

                    $output = "<?php\n\nreturn ".var_export($translations, true).';'.PHP_EOL;
                    $this->files->put($path, $output);
                }
            }
            Translation::ofTranslatedGroup($group)->update(['status' => Translation::STATUS_SAVED]);
        }

        if ($json) {
            $tree = $this->makeTree(Translation::ofTranslatedGroup(self::JSON_GROUP)
                ->orderByGroupKeys(Arr::get($this->config, 'sort_keys', false))
                ->get(), true);

            foreach ($tree as $locale => $groups) {
                if (isset($groups[self::JSON_GROUP])) {
                    $translations = $groups[self::JSON_GROUP];
                    $path = $this->app['path.lang'].'/'.$locale.'.json';
                    $output = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $this->files->put($path, $output);
                }
            }

            Translation::ofTranslatedGroup(self::JSON_GROUP)->update(['status' => Translation::STATUS_SAVED]);
        }

        $this->events->dispatch(new TranslationsExportedEvent);
    }

    public function exportAllTranslations(): void
    {
        $groups = Translation::whereNotNull('value')->selectDistinctGroup()->get('group');

        foreach ($groups as $group) {
            if ($group->group === self::JSON_GROUP) {
                $this->exportTranslations(null, true);
            } else {
                $this->exportTranslations($group->group);
            }
        }

        $this->events->dispatch(new TranslationsExportedEvent);
    }

    protected function makeTree($translations, $json = false): array
    {
        $array = [];
        foreach ($translations as $translation) {
            if ($json) {
                $this->jsonSet(
                    $array[$translation->locale][$translation->group],
                    $translation->key,
                    $translation->value
                );
            } else {
                Arr::set(
                    $array[$translation->locale][$translation->group],
                    $translation->key,
                    $translation->value
                );
            }
        }

        return $array;
    }

    public function jsonSet(array &$array, $key, $value)
    {
        if (is_null($key)) {
            return $array = $value;
        }
        $array[$key] = $value;

        return $array;
    }

    public function cleanTranslations(): void
    {
        Translation::whereNull('value')->delete();
    }

    public function truncateTranslations(): void
    {
        Translation::truncate();
    }

    public function getLocales(): array
    {
        if ($this->locales === []) {
            $locales = array_merge(
                [config('app.locale')],
                Translation::groupBy('locale')->pluck('locale')->toArray()
            );
            foreach ($this->files->directories($this->app->langPath()) as $localeDir) {
                if (($name = $this->files->name($localeDir)) !== 'vendor') {
                    $locales[] = $name;
                }
            }

            $this->locales = array_unique($locales);
            sort($this->locales);
        }

        return array_diff($this->locales, $this->ignoreLocales);
    }

    /**
     * @throws FileNotFoundException
     */
    public function addLocale($locale): bool
    {
        $localeDir = $this->app->langPath().'/'.basename($locale);

        $this->ignoreLocales = array_diff($this->ignoreLocales, [$locale]);
        $this->saveIgnoredLocales();
        $this->ignoreLocales = $this->getIgnoredLocales();

        if (! $this->files->exists($localeDir) || ! $this->files->isDirectory($localeDir)) {
            return $this->files->makeDirectory($localeDir);
        }

        return true;
    }

    /**
     * @return bool|int
     */
    protected function saveIgnoredLocales()
    {
        return $this->files->put($this->ignoreFilePath, json_encode($this->ignoreLocales));
    }

    /**
     * @throws FileNotFoundException
     */
    public function removeLocale($locale)
    {
        if (! $locale) {
            return false;
        }
        $this->ignoreLocales = array_merge($this->ignoreLocales, [$locale]);
        $this->saveIgnoredLocales();
        $this->ignoreLocales = $this->getIgnoredLocales();

        Translation::where('locale', $locale)->delete();

        return null;
    }

    public function getConfig($key = null)
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key];
    }
}
