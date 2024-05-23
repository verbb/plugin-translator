<?php
namespace modules\plugintranslator\console\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;

use yii\console\Controller;

class TranslateController extends Controller
{
    // Properties
    // =========================================================================

    public ?string $pluginHandle = null;
    public ?string $pluginPath = null;
    public int $batchSize = 500; // Number of strings to translate in each batch

    public array $languages = [
        'zh', // Chinese
        'es', // Spanish
        'ar', // Arabic
        'pt', // Portuguese
        'id', // Indonesian
        'fr', // French
        'ru', // Russian
        'de', // German
        'ja', // Japanese
        'ko', // Korean
        'tr', // Turkish
        'vi', // Vietnamese
        'it', // Italian
        'th', // Thai
        'nl', // Dutch
        'pl', // Polish
        'fa', // Persian
        'uk', // Ukrainian
        'ro', // Romanian
        'hi', // Hindi
    ];


    // Public Methods
    // =========================================================================

    public function actionIndex(string $pluginHandle)
    {
        $plugin = Craft::$app->getPlugins()->getPlugin($pluginHandle);

        if (!$plugin) {
            return;
        }

        $this->pluginHandle = $pluginHandle;
        $this->pluginPath = $plugin->getBasePath();

        // Extract translation strings from PHP, Twig, and JS files
        $translationStrings = $this->extractTranslationStrings();

        // Generate English static translation file
        $this->generateStaticTranslationFile($translationStrings, 'en');

        // Translate strings to other languages and generate translation files
        $this->translateToOtherLanguages($translationStrings);
    }

    private function extractTranslationStrings()
    {
        $translationStrings = [];

        // Recursively scan plugin directory for PHP, Twig, and JS files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->pluginPath)
        );

        foreach ($iterator as $file) {
            // Skip dot files and directories
            if ($file->isDir() && $file->getFilename() === '.' || $file->getFilename() === '..') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            // Parse translation strings from PHP files. Look for `Craft::t('plugin', 'text')`.
            if ($file->getExtension() === 'php') {
                preg_match_all('/Craft::t\(\s*[\'"]?' . preg_quote($this->pluginHandle, '/') . '[\'"]?\s*,\s*([\'"])(.*?)\\1/s', $content, $matches);
            
                foreach ($matches[2] as $string) {
                    $translationStrings[$string] = $string;
                }
            }

            // Parse translation strings from HTML/Twig files. Look for `'text' | t('plugin')`.
            if ($file->getExtension() === 'twig' || $file->getExtension() === 'html') {
                // Use two different patterns to check for strings in Twig. It's far easier to use two patterns to handle single/double quotes
                // mixed in with each other in the string. The point is the start and end quote needs to match.
                // We also check if the plugin handle uses single or double quotes for `t('plugin-handle')`
                $patternSingleQuote = "/'([^']*)'\s*\|\s*(?:t|translate)\(\s*('" . $this->pluginHandle . "'|\"" . $this->pluginHandle . "\")/";
                $patternDoubleQuote = '/"([^"]*)"\s*\|\s*(?:t|translate)\(\s*(\'' . $this->pluginHandle . '\'|"' . $this->pluginHandle . '")/';

                preg_match_all($patternSingleQuote, $content, $matchesSingleQuote);
                preg_match_all($patternDoubleQuote, $content, $matchesDoubleQuote);

                $matches = array_merge($matchesSingleQuote[1], $matchesDoubleQuote[1]);

                foreach ($matches as $string) {
                    $translationStrings[$string] = $string;
                }
            }

            // Parse translation strings from JavaScript files. Look for `Craft.t('plugin', 'text')` or `t('text')`.
            if ($file->getExtension() === 'js' || $file->getExtension() === 'vue') {
                preg_match_all('/(?:Craft\.)?t\(\s*[\'\"](' . preg_quote($this->pluginHandle) . ')[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*(?:,\s*\{[^}]+\})?\)/', $content, $matches);
        
                foreach ($matches[2] as $string) {
                    $translationStrings[$string] = $string;
                }
            }
        }

        // Remove duplicates
        $translationStrings = array_unique($translationStrings);

        // Sort Alphabetical
        uksort($translationStrings, function($a, $b) {
            return strnatcasecmp($a, $b);
        });

        return $translationStrings;
    }

    private function generateStaticTranslationFile($translationStrings, $language)
    {
        $translationsPath = $this->pluginPath . "/translations/{$language}/{$this->pluginHandle}.php";
        $this->ensurePathExists($translationsPath);
        $content = '<?php' . PHP_EOL . PHP_EOL . 'return ' . $this->varExport($translationStrings, true) . ';';
        file_put_contents($translationsPath, $content);
    }

    private function translateToOtherLanguages($translationStrings)
    {
        $client = Craft::createGuzzleClient();

        // Divide the translation strings into batches for performance
        $stringBatches = array_chunk($translationStrings, $this->batchSize);

        foreach ($this->languages as $language) {
            $translations = [];

            // Strings are joined, sent to the API, then un-joined
            foreach ($stringBatches as $batch) {
                $texts = implode("\n", $batch);
                
                // Send translation request to Deepl API
                $response = $client->post('https://api-free.deepl.com/v2/translate', [
                    'form_params' => [
                        'auth_key' => App::parseEnv('$DEEPL_API_KEY'),
                        'text' => $texts,
                        'target_lang' => $language,
                    ],
                ]);

                // Parse Deepl response and add translated strings to translation array
                $translatedBatch = Json::decode($response->getBody()->getContents())['translations'][0]['text'];
                $translatedBatchTexts = explode("\n", $translatedBatch);
                
                foreach ($translatedBatchTexts as $index => $translation) {
                    $translations[$batch[$index]] = $translation;
                }
            }

            $translationsPath = "{$this->pluginPath}/translations/{$language}/{$this->pluginHandle}.php";
            $this->ensurePathExists($translationsPath);

            // Write translations to the language file
            $content = '<?php' . PHP_EOL . PHP_EOL . 'return ' . $this->varExport($translations, true) . ';';
            file_put_contents($translationsPath, $content);
        }
    }


    // Private
    // =========================================================================

    private function varExport(mixed $expression, bool $return = false)
    {
        // A short-hand array syntax version of `var_export()`.
        $export = var_export($expression, true);

        $patterns = [
            "/array \(/" => '[',
            "/^([ ]*)\)(,?)$/m" => '$1]$2',
            "/=>[ ]?\n[ ]+\[/" => '=> [',
            "/([ ]*)(\'[^\']+\') => ([\[\'])/" => '$1$2 => $3',
        ];

        $export = preg_replace(array_keys($patterns), array_values($patterns), $export);

        if ((bool)$return) {
            return $export;
        }

        echo $export;
    }

    private function ensurePathExists($path)
    {
        // Get the directory path
        $directory = pathinfo($path, PATHINFO_DIRNAME);

        // Create directories if they don't exist
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }

        // Create the file if it doesn't exist
        if (!file_exists($path)) {
            touch($path);
        }
    }

}
