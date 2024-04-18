# Plugin Translator Module for Craft CMS
A console command for Craft CMS to generate translations for plugins using Deepl.

Run via `./craft plugin-translator/translate plugin-handle`

- It will extract translation strings from PHP, HTML, Twig, JS and Vue files
- Create a `translations/en/plugin-handle.php` file (assumes written language is in English)
- Create language translations (default 20) using Deepl. Add a `DEEPL_API_KEY` .env variable

Extraction will match the following:

#### PHP
```php
Craft::t('plugin-handle', 'Some String');
Craft::t('plugin-handle', 'Some String', ['params' => 'value']);
```

#### HTML/Twig
```twig
'Some String' | t('plugin-handle')
'Some String' | t('plugin-handle', { params: 'value' })
```

#### JS/Vue
```js
Craft.t('plugin-handle', 'Some String')
Craft.t('plugin-handle', 'Some String', { params: 'value' })
t('Some String')
t('Some String', { params: 'value' })
```
