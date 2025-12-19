# Yii2 Clean HTML Behavior

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/nedarta/yii2-clean-html-behavior)](https://packagist.org/packages/nedarta/yii2-clean-html-behavior)

A Yii2 behavior that sanitizes and normalizes HTML attributes on ActiveRecord models. It runs automatically on
`beforeValidate`, `beforeInsert`, and `beforeUpdate` to strip unsafe markup, fix spacing and punctuation, and optionally
reformat line breaks while preserving emoji when required.

## Features

- Cleans HTML using Yii's `HtmlPurifier` with sensible defaults (nofollow links, `_blank` targets, no inline styles).
- Removes unwanted attributes (`class`, `style`, `id`, `dir`, `role`, `tabindex`, `contenteditable`, `spellcheck`,
  `attributionsrc`, `data-*`, `aria-*`).
- Normalizes punctuation spacing and collapses double spaces.
- Converts `<div>` containers to paragraphs and unwraps `<span>` tags.
- Optional emoji preservation via placeholder storage and restoration.
- Configurable handling for line breaks: keep `<br>`, convert to paragraphs or lists, or strip entirely.

## Installation

Install the package via Composer:

```bash
composer require nedarta/yii2-clean-html-behavior
```

## Basic Usage

Attach the behavior to an ActiveRecord model and configure which attributes should be sanitized:

```php
use nedarta\behaviors\CleanHtmlBehavior;

public function behaviors()
{
    return [
        [
            'class' => CleanHtmlBehavior::class,
            'attributes' => ['content', 'description'],
        ],
    ];
}
```

## Configuration

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `attributes` | `array` | `[]` (required) | List of ActiveRecord attributes to clean. Throws `InvalidConfigException` when empty. |
| `htmlPurifierConfig` | `array` | See below | Configuration passed to `HtmlPurifier::process`. Defaults allow basic formatting tags and disable auto-paragraphing while stripping inline styles and enforcing `rel="nofollow"`/`target="_blank"`. |
| `preserveLineBreaks` | `bool` | `true` | When `false`, replaces `<br>` tags with spaces or newlines before purification. |
| `convertLineBreaks` | `string|false` | `false` | After purification, convert line breaks into paragraphs (`'p'`), unordered lists (`'ul'`), or remove them (`false`). Only applied when `preserveLineBreaks` is `false`. |
| `keepEmoji` | `bool` | `false` | Store emoji as placeholders before processing and restore them afterwards. |

### Default HtmlPurifier configuration

```php
[
    'HTML.Allowed' => 'p,b,i,u,ul,ol,li,a[href],table,tr,td,th',
    'AutoFormat.RemoveEmpty' => true,
    'AutoFormat.RemoveEmpty.RemoveNbsp' => true,
    'AutoFormat.AutoParagraph' => false,
    'HTML.TargetBlank' => true,
    'Attr.AllowedFrameTargets' => ['_blank'],
    'HTML.Nofollow' => true,
    'CSS.AllowedProperties' => [],
]
```

Override only the keys you need:

```php
public function behaviors()
{
    return [
        [
            'class' => CleanHtmlBehavior::class,
            'attributes' => ['content'],
            'htmlPurifierConfig' => [
                'HTML.Allowed' => 'p,b,i,u,ul,ol,li,a[href|title]',
            ],
        ],
    ];
}
```

## Handling line breaks

You can control how `<br>` tags and raw newlines are treated:

- **Preserve** (default): leaves `<br>` tags untouched.
- **Strip**: set `preserveLineBreaks` to `false` and `convertLineBreaks` to `false` to collapse line breaks into spaces.
- **Paragraphs**: set `convertLineBreaks` to `'p'` to wrap newline-separated text into `<p>` tags when no block markup is
  already present.
- **List**: set `convertLineBreaks` to `'ul'` to turn newline-separated lines into a bullet list when no block markup is
  present.

## Emoji support

Set `keepEmoji` to `true` to temporarily replace emoji with placeholders during purification and restore them afterward,
ensuring they are not stripped by the purifier.

## Events

The behavior cleans configured attributes automatically during:

- `ActiveRecord::EVENT_BEFORE_VALIDATE`
- `ActiveRecord::EVENT_BEFORE_INSERT`
- `ActiveRecord::EVENT_BEFORE_UPDATE`

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.
