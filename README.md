# Yii2 Clean HTML Behavior

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/nedarta/yii2-clean-html-behavior)](https://packagist.org/packages/nedarta/yii2-clean-html-behavior)

A Yii2 behavior to clean and sanitize HTML content in ActiveRecord attributes.

## Installation

Install the extension via Composer:

```bash
composer require nedarta/yii2-clean-html-behavior
```

## Usage

Add the behavior to your ActiveRecord model:

```php
use nedarta\behaviors\CleanHtmlBehavior;

public function behaviors()
{
    return [
        [
            'class' => CleanHtmlBehavior::class,
            'attributes' => ['content', 'description'],
            'htmlPurifierConfig' => [
                'HTML.Allowed' => 'p,b,i,u,ul,ol,li',
            ],
            'keepEmoji' => true, // Set to true to preserve emoji characters
        ],
    ];
}
```

## Configuration

- `attributes`: List of attributes to clean.

- `htmlPurifierConfig`: Configuration for HtmlPurifier.

- `keepEmoji`: Whether to preserve emoji characters.

- `preserveLineBreaks`: Whether to preserve line breaks.

## License
MIT