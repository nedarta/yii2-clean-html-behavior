<?php
namespace nedarta\behaviors;

use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\helpers\HtmlPurifier;
use yii\base\InvalidConfigException;

/**
 * CleanHtmlBehavior automatically sanitizes and formats HTML content in ActiveRecord attributes.
 *
 * Features:
 * - Removes unnecessary spaces and fixes punctuation.
 * - Ensures spaces after `.,;:!?` and around `-` and `–` (excluding `...`).
 * - Converts `<div>` and `<span>` to `<p>`.
 * - Supports keeping or removing emojis.
 * - Allows converting multiple line breaks into paragraphs (`<p>`) or unordered lists (`<ul>`).
 *
 * Usage:
 * ```php
 * public function behaviors()
 * {
 *     return [
 *         [
 *             'class' => \nedarta\behaviors\CleanHtmlBehavior::class,
 *             'attributes' => ['content'],
 *             'keepEmoji' => true,
 *             'preserveLineBreaks' => false,
 *             'convertLineBreaks' => 'p', // Options: 'p', 'ul', or false
 *         ],
 *     ];
 * }
 * ```
 */
class CleanHtmlBehavior extends Behavior
{
    /** @var array List of attributes to clean */
    public $attributes = [];

    /** @var array HtmlPurifier configuration */
    public $htmlPurifierConfig = [
        'HTML.Allowed' => 'p,b,i,u,ul,ol,li,a[href],table,tr,td,th',
        'AutoFormat.RemoveEmpty' => true,
        'AutoFormat.RemoveEmpty.RemoveNbsp' => true,
        'AutoFormat.AutoParagraph' => true,
        'HTML.TargetBlank' => true,
        'Attr.AllowedFrameTargets' => ['_blank'],
        'HTML.Nofollow' => true,
        'CSS.AllowedProperties' => [],
    ];

    /** @var bool Whether to preserve `<br>` tags */
    public $preserveLineBreaks = true;

    /** @var string|false Convert line breaks to 'p' (paragraphs), 'ul' (unordered list), or `false` (remove them) */
    public $convertLineBreaks = false;

    /** @var bool Whether to preserve emoji characters */
    public $keepEmoji = false;

    /** @var array Temporary storage for emoji placeholders */
    private $emojiMap = [];

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if (empty($this->attributes)) {
            throw new InvalidConfigException('Attributes cannot be empty.');
        }
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
        ];
    }

    /**
     * Cleans HTML content before validation.
     */
    public function beforeValidate($event)
    {
        $this->cleanAttributes();
    }

    /**
     * Cleans HTML content before saving.
     */
    public function beforeSave($event)
    {
        $this->cleanAttributes();
    }

    /**
     * Cleans all configured attributes.
     */
    protected function cleanAttributes()
    {
        foreach ($this->attributes as $attribute) {
            if ($this->owner->hasProperty($attribute)) {
                $value = $this->owner->$attribute;
                if (is_string($value)) {
                    $this->owner->$attribute = $this->cleanHtml($value);
                }
            }
        }
    }

    /**
     * Cleans and formats HTML content.
     *
     * @param string $html The HTML content to clean.
     * @return string The cleaned HTML content.
     */
    protected function cleanHtml($html)
    {
        if (empty($html)) {
            return $html;
        }

        if ($this->keepEmoji) {
            $html = $this->storeEmoji($html);
        }

        // Normalize line endings
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Normalize container tags and strip unwanted attributes
        $html = $this->convertDivsToParagraphs($html);

        // Clean HTML with HtmlPurifier
        $html = HtmlPurifier::process($html, $this->htmlPurifierConfig);

        // Apply formatting
        $html = $this->addSpacesAfterPunctuation($html);
        $html = $this->removeDoubleSpaces($html);

        if (!$this->preserveLineBreaks) {
            if ($this->convertLineBreaks === 'p') {
                $html = nl2br($html);
                $html = preg_replace('/(<br\s*\/?>\s*)+/', "</p><p>", $html);
                $html = "<p>" . trim($html, "<p></p>") . "</p>";
            } elseif ($this->convertLineBreaks === 'ul') {
                $lines = array_filter(array_map('trim', explode("\n", $html)));
                if (!empty($lines)) {
                    $html = "<ul><li>" . implode("</li><li>", $lines) . "</li></ul>";
                }
            } else {
                $html = str_replace(["\n", "<br>", "<br/>", "<br />"], ' ', $html);
            }
        }

        if ($this->keepEmoji) {
            $html = $this->restoreEmoji($html);
        }

        return trim($html);
    }

    /**
     * Stores emoji characters by replacing them with placeholders.
     */
    protected function storeEmoji($content)
    {
        $this->emojiMap = [];
        return preg_replace_callback('/[\x{1F000}-\x{1F9FF}]/u', function ($match) {
            $placeholder = '###EMOJI_' . count($this->emojiMap) . '###';
            $this->emojiMap[$placeholder] = $match[0];
            return $placeholder;
        }, $content);
    }

    /**
     * Restores emoji characters from placeholders.
     */
    protected function restoreEmoji($content)
    {
        return str_replace(array_keys($this->emojiMap), array_values($this->emojiMap), $content);
    }

    /**
     * Removes multiple consecutive spaces.
     */
    protected function removeDoubleSpaces($content)
    {
        return preg_replace('/\s+/', ' ', $content);
    }

    /**
     * Adds spaces after punctuation marks, excluding URLs.
     */
    protected function addSpacesAfterPunctuation($content)
    {
        $pattern = '~\b(?:https?://\S+|www\.\S+)\b(*SKIP)(*FAIL)'
            . '|&[#\w]+;(*SKIP)(*FAIL)'
            . '|(?<=\d)(?:[.,:])(?=(?:\s|&nbsp;|&#160;|</?[^>]+>)*\d)(*SKIP)(*FAIL)'
            . '|(?<=\d)(?:[-\x{2013}])(?=(?:\s|&nbsp;|&#160;|</?[^>]+>)*\d)(*SKIP)(*FAIL)'
            . '|([.,;:!?])([^ \n])~u';
        return preg_replace($pattern, '$1 $2', $content);
    }

    /**
     * Converts `<div>` and `<span>` elements to paragraphs.
     */
    protected function convertDivsToParagraphs($html)
    {
        $doc = new \DOMDocument();
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors(false);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            $this->normalizeContainerNodes($body);
            $cleanHtml = '';
            foreach ($body->childNodes as $child) {
                $cleanHtml .= $doc->saveHTML($child);
            }
            return trim($cleanHtml);
        }

        return trim($doc->saveHTML());
    }

    /**
     * Recursively normalizes container tags and removes unwanted attributes.
     */
    protected function normalizeContainerNodes(\DOMNode $node)
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $this->normalizeContainerNodes($child);
            }
        }

        if ($node instanceof \DOMElement) {
            $this->removeUnwantedAttributes($node);

            $tagName = strtolower($node->tagName);
            if ($tagName === 'span') {
                $this->unwrapElement($node);
            } elseif ($tagName === 'div') {
                if ($this->containsBlockElement($node)) {
                    $this->unwrapElement($node);
                } else {
                    $replacement = $node->ownerDocument->createElement('p');
                    while ($node->firstChild) {
                        $replacement->appendChild($node->firstChild);
                    }
                    $node->parentNode->replaceChild($replacement, $node);
                    $this->removeUnwantedAttributes($replacement);
                }
            }
        }
    }

    /**
     * Removes container element but preserves its children.
     */
    protected function unwrapElement(\DOMElement $element)
    {
        $parent = $element->parentNode;
        if (!$parent) {
            return;
        }

        while ($element->firstChild) {
            $child = $element->firstChild;
            $element->removeChild($child);
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * Detects whether the element contains nested block-level elements.
     */
    protected function containsBlockElement(\DOMElement $element)
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $tagName = strtolower($child->tagName);
                if (in_array($tagName, ['p', 'div', 'ul', 'ol', 'table', 'tr', 'td', 'th'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Removes class-like attributes that should not survive purification.
     */
    protected function removeUnwantedAttributes(\DOMElement $element)
    {
        if (!$element->hasAttributes()) {
            return;
        }

        $attributesToStrip = ['class', 'style', 'id', 'dir', 'role', 'tabindex', 'contenteditable', 'spellcheck', 'attributionsrc'];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (in_array($name, $attributesToStrip, true)
                || strpos($name, 'data-') === 0
                || strpos($name, 'aria-') === 0
            ) {
                $element->removeAttributeNode($attribute);
            }
        }
    }
}
