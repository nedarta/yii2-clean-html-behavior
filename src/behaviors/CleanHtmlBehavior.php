<?php
namespace nedarta\behaviors;

use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\helpers\HtmlPurifier;
use yii\base\InvalidConfigException;

/**
 * CleanHtmlBehavior automatically cleans HTML content in ActiveRecord attributes
 * before saving to the database.
 *
 * Usage:
 * ```php
 * public function behaviors()
 * {
 *     return [
 *         [
 *             'class' => \nedarta\behaviors\CleanHtmlBehavior::class,
 *             'attributes' => ['content', 'description'],
 *             'htmlPurifierConfig' => [
 *                 'HTML.Allowed' => 'p,b,i,u,ul,ol,li',
 *             ],
 *             'keepEmoji' => true, // Set to true to preserve emoji characters
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
        'Attr.AllowedFrameTargets' => ['_blank'], // Required for HTML.TargetBlank
        'HTML.Nofollow' => true, // Add nofollow to external links
        'CSS.AllowedProperties' => [], // Disable inline styles
    ];

    /** @var bool Whether to preserve line breaks */
    public $preserveLineBreaks = true;

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
            ActiveRecord::EVENT_BEFORE_SAVE => 'beforeSave',
        ];
    }

    /**
     * Clean HTML before validation to ensure clean content is validated
     */
    public function beforeValidate($event)
    {
        $this->cleanAttributes();
    }

    /**
     * Clean HTML before save as a safeguard
     */
    public function beforeSave($event)
    {
        $this->cleanAttributes();
    }

    /**
     * Clean all configured attributes
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
     * Clean HTML content using HtmlPurifier and additional formatting
     */
    protected function cleanHtml($html)
    {
        if (empty($html)) {
            return $html;
        }

        if ($this->keepEmoji) {
            // Store emoji characters temporarily
            $html = $this->storeEmoji($html);
        }

        // Normalize line endings
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        
        // Convert divs to paragraphs before purification
        $html = $this->convertDivsToParagraphs($html);
        
        // Clean HTML with HtmlPurifier
        $html = HtmlPurifier::process($html, $this->htmlPurifierConfig);
        
        // Apply additional formatting
        $html = $this->addSpacesAfterPunctuation($html);
        $html = $this->removeDoubleSpaces($html);
        
        if (!$this->preserveLineBreaks) {
            $html = $this->removeDoubleLineBreaks($html);
        }

        if ($this->keepEmoji) {
            // Restore emoji characters
            $html = $this->restoreEmoji($html);
        }
        
        return trim($html);
    }

    /**
     * Store emoji characters by replacing them with placeholders
     */
    protected function storeEmoji($content)
    {
        $this->emojiMap = [];
        return preg_replace_callback('/[\x{1F000}-\x{1F9FF}]/u', function($match) {
            $placeholder = '###EMOJI_' . count($this->emojiMap) . '###';
            $this->emojiMap[$placeholder] = $match[0];
            return $placeholder;
        }, $content);
    }

    /**
     * Restore emoji characters by replacing placeholders
     */
    protected function restoreEmoji($content)
    {
        return str_replace(
            array_keys($this->emojiMap),
            array_values($this->emojiMap),
            $content
        );
    }

    /**
     * Remove multiple consecutive spaces
     */
    protected function removeDoubleSpaces($content)
    {
        return preg_replace('/\s+/', ' ', $content);
    }

    /**
     * Remove consecutive line breaks
     */
    protected function removeDoubleLineBreaks($content)
    {
        return preg_replace('/<br\s*\/?>\s*<br\s*\/?>/i', '<br>', $content);
    }

    /**
     * Add spaces after punctuation marks, excluding URLs
     */
    protected function addSpacesAfterPunctuation($content)
    {
        $pattern = '~\b(?:https?://\S+|www\.\S+)\b(*SKIP)(*FAIL)|([.,;:!?])([^ ]|\s(?!href="))~';
        return preg_replace($pattern, '$1 $2', $content);
    }

    /**
     * Convert div and span elements to paragraphs while preserving nested content
     */
    protected function convertDivsToParagraphs($html)
    {
        // Load the HTML into a DOMDocument for proper parsing
        $doc = new \DOMDocument();
        
        // Preserve special characters and encoding
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        
        // Disable libxml errors temporarily
        $previousLibXmlUseErrors = libxml_use_internal_errors(true);
        
        // Load HTML, even if it's not well-formed
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        // Re-enable libxml errors
        libxml_use_internal_errors($previousLibXmlUseErrors);

        // Find all div and span elements
        $xpath = new \DOMXPath($doc);
        $elements = $xpath->query('//div | //span');
        
        // Process elements in reverse order to avoid issues with DOM modifications
        $elementsToProcess = [];
        foreach ($elements as $element) {
            $elementsToProcess[] = $element;
        }
        
        for ($i = count($elementsToProcess) - 1; $i >= 0; $i--) {
            $element = $elementsToProcess[$i];
            
            // Create a new paragraph element
            $p = $doc->createElement('p');
            
            // Move all child nodes from the element to the new paragraph
            while ($element->firstChild) {
                $p->appendChild($element->firstChild);
            }
            
            // Replace the element with the new paragraph
            $element->parentNode->replaceChild($p, $element);
        }
        
        // Get the processed HTML
        $processedHtml = $doc->saveHTML();
        
        // Clean up the output by removing added DOCTYPE and HTML/BODY tags
        $processedHtml = preg_replace(
            [
                '/^<!DOCTYPE.*?>\n/',
                '/<html><body>/',
                '/<\/body><\/html>/'
            ],
            '',
            $processedHtml
        );
        
        return trim($processedHtml);
    }
}