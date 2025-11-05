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
        'AutoFormat.AutoParagraph' => false,
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

        // Store emoji before processing if needed
        if ($this->keepEmoji) {
            $html = $this->storeEmoji($html);
        }

        // Normalize line endings
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Convert divs and spans to paragraphs with proper line breaks
        $html = $this->convertDivsToParagraphs($html);

        // Clean HTML with HtmlPurifier
        $html = HtmlPurifier::process($html, $this->htmlPurifierConfig);

        // Apply formatting
        $html = $this->addSpacesAfterPunctuation($html);
        $html = $this->removeDoubleSpaces($html);

        // Handle line breaks conversion
        if (!$this->preserveLineBreaks) {
            if ($this->convertLineBreaks === 'p') {
                $html = $this->convertToParagraphs($html);
            } elseif ($this->convertLineBreaks === 'ul') {
                $html = $this->convertToList($html);
            } else {
                // Remove all line breaks
                $html = preg_replace('/\s*<br\s*\/?>\s*/i', ' ', $html);
                $html = str_replace("\n", ' ', $html);
            }
        }

        // Restore emoji if they were stored
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
        // More comprehensive emoji pattern covering multiple Unicode ranges
        $pattern = '/[\x{1F000}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F300}-\x{1F5FF}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{231A}-\x{231B}\x{23E9}-\x{23EC}\x{23F0}\x{23F3}\x{25FD}-\x{25FE}\x{2614}-\x{2615}\x{2648}-\x{2653}\x{267F}\x{2693}\x{26A1}\x{26AA}-\x{26AB}\x{26BD}-\x{26BE}\x{26C4}-\x{26C5}\x{26CE}\x{26D4}\x{26EA}\x{26F2}-\x{26F3}\x{26F5}\x{26FA}\x{26FD}\x{2705}\x{270A}-\x{270B}\x{2728}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2795}-\x{2797}\x{27B0}\x{27BF}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}]/u';
        
        return preg_replace_callback($pattern, function ($match) {
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
        // Don't collapse spaces inside tags
        return preg_replace('/(?<=>)\s+(?=<)|(?<=\w)\s{2,}(?=\w)/', ' ', $content);
    }

    /**
     * Adds spaces after punctuation marks, excluding URLs.
     */
    protected function addSpacesAfterPunctuation($content)
    {
        // Skip URLs and numbers
        $pattern = '~\b(?:https?://\S+|www\.\S+)\b(*SKIP)(*FAIL)'
            . '|\d[.,:](?=\d)(*SKIP)(*FAIL)'
            . '|\.{2,}(*SKIP)(*FAIL)'
            . '|([.,;:!?])(?=[^\s])~u';
        return preg_replace($pattern, '$1 ', $content);
    }

    /**
     * Converts `<div>` and `<span>` elements to paragraphs.
     */
    protected function convertDivsToParagraphs($html)
    {
        // Simple regex approach - more reliable than DOMDocument for this use case
        // Convert divs to double line breaks
        $html = preg_replace('~<div[^>]*>\s*~i', "\n\n", $html);
        $html = preg_replace('~\s*</div>~i', "\n\n", $html);
        
        // Convert spans to simple spaces
        $html = preg_replace('~<span[^>]*>~i', '', $html);
        $html = preg_replace('~</span>~i', '', $html);
        
        // Convert br tags to newlines for now
        $html = preg_replace('~<br\s*/?>~i', "\n", $html);
        
        return $html;
    }

    /**
     * Converts line breaks to paragraphs.
     */
    protected function convertToParagraphs($html)
    {
        // Remove existing paragraph tags
        $html = preg_replace('~</?p[^>]*>~i', '', $html);
        
        // Split by double line breaks or existing block elements
        $blocks = preg_split('~\n\s*\n+~', $html);
        
        $paragraphs = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            
            // Don't wrap list items or table elements
            if (preg_match('~^<(ul|ol|li|table|tr|td|th)~i', $block)) {
                $paragraphs[] = $block;
            } else {
                // Remove single line breaks within the block
                $block = preg_replace('~\n~', ' ', $block);
                $paragraphs[] = '<p>' . $block . '</p>';
            }
        }
        
        return implode("\n", $paragraphs);
    }

    /**
     * Converts line breaks to an unordered list.
     */
    protected function convertToList($html)
    {
        // Strip all HTML tags first
        $text = strip_tags($html);
        
        // Split by line breaks
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        
        if (empty($lines)) {
            return '';
        }
        
        $items = array_map(function($line) {
            return '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
        }, $lines);
        
        return '<ul>' . implode('', $items) . '</ul>';
    }
}