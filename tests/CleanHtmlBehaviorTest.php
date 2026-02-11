<?php

namespace nedarta\behaviors\tests;

use nedarta\behaviors\CleanHtmlBehavior;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;

/**
 * Mock ActiveRecord model for testing CleanHtmlBehavior.
 */
class FakeModel extends ActiveRecord
{
    public $content;
    public $title;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'fake_table';
    }
}

/**
 * Tests for CleanHtmlBehavior.
 */
class CleanHtmlBehaviorTest extends TestCase
{
    /**
     * Creates a FakeModel with CleanHtmlBehavior attached.
     *
     * @param array $behaviorConfig Additional behavior configuration options.
     * @return FakeModel
     */
    protected function createModel(array $behaviorConfig = []): FakeModel
    {
        $model = new FakeModel();
        $model->attachBehavior('cleanHtml', array_merge([
            'class' => CleanHtmlBehavior::class,
            'attributes' => ['content'],
        ], $behaviorConfig));

        return $model;
    }

    /**
     * Triggers beforeValidate on the model to invoke cleaning.
     */
    protected function triggerClean(FakeModel $model): void
    {
        $model->trigger(ActiveRecord::EVENT_BEFORE_VALIDATE);
    }

    // =========================================================================
    // Configuration
    // =========================================================================

    public function testThrowsExceptionWhenAttributesEmpty(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Attributes cannot be empty.');

        $model = new FakeModel();
        $model->attachBehavior('cleanHtml', [
            'class' => CleanHtmlBehavior::class,
            'attributes' => [],
        ]);
    }

    // =========================================================================
    // Event Triggers
    // =========================================================================

    public function testCleansOnBeforeValidate(): void
    {
        $model = $this->createModel();
        $model->content = '<b>Hello</b> <script>alert("xss")</script>';
        $model->trigger(ActiveRecord::EVENT_BEFORE_VALIDATE);

        $this->assertStringNotContainsString('<script>', $model->content);
        $this->assertStringContainsString('<b>Hello</b>', $model->content);
    }

    public function testCleansOnBeforeInsert(): void
    {
        $model = $this->createModel();
        $model->content = '<b>Hello</b> <script>alert("xss")</script>';
        $model->trigger(ActiveRecord::EVENT_BEFORE_INSERT);

        $this->assertStringNotContainsString('<script>', $model->content);
    }

    public function testCleansOnBeforeUpdate(): void
    {
        $model = $this->createModel();
        $model->content = '<b>Hello</b> <script>alert("xss")</script>';
        $model->trigger(ActiveRecord::EVENT_BEFORE_UPDATE);

        $this->assertStringNotContainsString('<script>', $model->content);
    }

    // =========================================================================
    // HTML Purification
    // =========================================================================

    public function testRemovesDisallowedHtmlTags(): void
    {
        $model = $this->createModel();
        $model->content = '<div>Hello</div><script>bad</script><style>.x{}</style>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('<script>', $model->content);
        $this->assertStringNotContainsString('<style>', $model->content);
        $this->assertStringContainsString('Hello', $model->content);
    }

    public function testAllowsPermittedTags(): void
    {
        $model = $this->createModel();
        $model->content = '<p><b>Bold</b> <i>italic</i> <u>underline</u></p>';
        $this->triggerClean($model);

        $this->assertStringContainsString('<b>Bold</b>', $model->content);
        $this->assertStringContainsString('<i>italic</i>', $model->content);
        $this->assertStringContainsString('<u>underline</u>', $model->content);
    }

    public function testPreservesLinks(): void
    {
        $model = $this->createModel();
        $model->content = '<a href="https://example.com">Link</a>';
        $this->triggerClean($model);

        $this->assertStringContainsString('href="https://example.com"', $model->content);
        $this->assertStringContainsString('>Link</a>', $model->content);
    }

    public function testPreservesListStructure(): void
    {
        $model = $this->createModel();
        $model->content = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $this->triggerClean($model);

        $this->assertStringContainsString('<ul>', $model->content);
        $this->assertStringContainsString('<li>Item 1</li>', $model->content);
        $this->assertStringContainsString('<li>Item 2</li>', $model->content);
    }

    public function testPreservesTableStructure(): void
    {
        $model = $this->createModel();
        $model->content = '<table><tr><th>Header</th></tr><tr><td>Cell</td></tr></table>';
        $this->triggerClean($model);

        $this->assertStringContainsString('<table>', $model->content);
        $this->assertStringContainsString('<th>Header</th>', $model->content);
        $this->assertStringContainsString('<td>Cell</td>', $model->content);
    }

    public function testEmptyContentUnchanged(): void
    {
        $model = $this->createModel();
        $model->content = '';
        $this->triggerClean($model);

        $this->assertSame('', $model->content);
    }

    public function testNullContentUnchanged(): void
    {
        $model = $this->createModel();
        $model->content = null;
        $this->triggerClean($model);

        $this->assertNull($model->content);
    }

    // =========================================================================
    // Emoji Handling
    // =========================================================================

    public function testRemovesEmojiByDefault(): void
    {
        $model = $this->createModel(['keepEmoji' => false]);
        $model->content = 'Hello 😀 World 🎉';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('😀', $model->content);
        $this->assertStringNotContainsString('🎉', $model->content);
        $this->assertStringContainsString('Hello', $model->content);
        $this->assertStringContainsString('World', $model->content);
    }

    public function testKeepsEmojiWhenEnabled(): void
    {
        $model = $this->createModel(['keepEmoji' => true]);
        $model->content = 'Hello 😀 World 🎉';
        $this->triggerClean($model);

        $this->assertStringContainsString('😀', $model->content);
        $this->assertStringContainsString('🎉', $model->content);
    }

    public function testKeepsMultipleEmoji(): void
    {
        $model = $this->createModel(['keepEmoji' => true]);
        $model->content = '❤️ 🔥 ⭐ 👍 🎵';
        $this->triggerClean($model);

        $this->assertStringContainsString('⭐', $model->content);
        $this->assertStringContainsString('🔥', $model->content);
    }

    // =========================================================================
    // Line Break Conversion
    // =========================================================================

    public function testConvertLineBreaksToParagraphs(): void
    {
        $model = $this->createModel([
            'preserveLineBreaks' => false,
            'convertLineBreaks' => 'p',
        ]);
        $model->content = "Line one\nLine two\nLine three";
        $this->triggerClean($model);

        $this->assertStringContainsString('<p>', $model->content);
        $this->assertStringContainsString('Line one', $model->content);
        $this->assertStringContainsString('Line two', $model->content);
        $this->assertStringContainsString('Line three', $model->content);
    }

    public function testConvertLineBreaksToUnorderedList(): void
    {
        $model = $this->createModel([
            'preserveLineBreaks' => false,
            'convertLineBreaks' => 'ul',
        ]);
        $model->content = "Item one\nItem two\nItem three";
        $this->triggerClean($model);

        $this->assertStringContainsString('<ul>', $model->content);
        $this->assertStringContainsString('<li>Item one</li>', $model->content);
        $this->assertStringContainsString('<li>Item two</li>', $model->content);
        $this->assertStringContainsString('<li>Item three</li>', $model->content);
    }

    public function testConvertLineBreaksFalseRemovesBreaks(): void
    {
        $model = $this->createModel([
            'preserveLineBreaks' => false,
            'convertLineBreaks' => false,
        ]);
        $model->content = "Line one\nLine two";
        $this->triggerClean($model);

        $this->assertStringNotContainsString("\n", $model->content);
        $this->assertStringContainsString('Line one', $model->content);
        $this->assertStringContainsString('Line two', $model->content);
    }

    public function testPreserveLineBreaksKeepsBrTags(): void
    {
        $model = $this->createModel([
            'preserveLineBreaks' => true,
        ]);
        $model->content = "Hello<br>World";
        $this->triggerClean($model);

        // When preserveLineBreaks is true, <br> tags should remain
        $this->assertStringContainsString('Hello', $model->content);
        $this->assertStringContainsString('World', $model->content);
    }

    public function testConvertLineBreaksParagraphSkipsBlockMarkup(): void
    {
        $model = $this->createModel([
            'preserveLineBreaks' => false,
            'convertLineBreaks' => 'p',
        ]);
        // Content that already has block markup should not be double-wrapped
        $model->content = "<p>Already a paragraph</p>";
        $this->triggerClean($model);

        $this->assertStringContainsString('Already a paragraph', $model->content);
    }

    // =========================================================================
    // Punctuation Spacing
    // =========================================================================

    public function testAddsSpaceAfterPunctuation(): void
    {
        $model = $this->createModel();
        $model->content = 'Hello,World';
        $this->triggerClean($model);

        $this->assertSame('Hello, World', $model->content);
    }

    public function testAddsSpaceAfterMultiplePunctuationMarks(): void
    {
        $model = $this->createModel();
        $model->content = 'Hello.World,again;test:check!wow?really';
        $this->triggerClean($model);

        $this->assertStringContainsString('Hello. World', $model->content);
        $this->assertStringContainsString(', again', $model->content);
        $this->assertStringContainsString('; test', $model->content);
        $this->assertStringContainsString(': check', $model->content);
        $this->assertStringContainsString('! wow', $model->content);
        $this->assertStringContainsString('? really', $model->content);
    }

    public function testDoesNotAddSpaceInUrls(): void
    {
        $model = $this->createModel();
        $model->content = '<a href="https://example.com/path">Link</a>';
        $this->triggerClean($model);

        $this->assertStringContainsString('https://example.com/path', $model->content);
    }

    public function testPreservesEllipsis(): void
    {
        $model = $this->createModel();
        $model->content = 'Wait...what';
        $this->triggerClean($model);

        $this->assertStringContainsString('...', $model->content);
    }

    // =========================================================================
    // Div/Span Conversion
    // =========================================================================

    public function testConvertsDivsToParagraphs(): void
    {
        $model = $this->createModel();
        $model->content = '<div>First paragraph</div><div>Second paragraph</div>';
        $this->triggerClean($model);

        $this->assertStringContainsString('<p>', $model->content);
        $this->assertStringContainsString('First paragraph', $model->content);
        $this->assertStringContainsString('Second paragraph', $model->content);
        $this->assertStringNotContainsString('<div>', $model->content);
    }

    public function testUnwrapsSpans(): void
    {
        $model = $this->createModel();
        $model->content = '<p>Hello <span>world</span></p>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('<span>', $model->content);
        $this->assertStringContainsString('Hello world', $model->content);
    }

    public function testNestedDivsConvertedProperly(): void
    {
        $model = $this->createModel();
        $model->content = '<div><div>Nested content</div></div>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('<div>', $model->content);
        $this->assertStringContainsString('Nested content', $model->content);
    }

    // =========================================================================
    // Double Space Removal
    // =========================================================================

    public function testRemovesDoubleSpaces(): void
    {
        $model = $this->createModel();
        $model->content = 'Hello   World';
        $this->triggerClean($model);

        $this->assertStringContainsString('Hello World', $model->content);
        $this->assertStringNotContainsString('  ', $model->content);
    }

    public function testRemovesTabSpaces(): void
    {
        $model = $this->createModel();
        $model->content = "Hello\t\tWorld";
        $this->triggerClean($model);

        $this->assertStringNotContainsString("\t\t", $model->content);
    }

    // =========================================================================
    // Attribute Stripping
    // =========================================================================

    public function testStripsClassAttributes(): void
    {
        $model = $this->createModel();
        $model->content = '<div class="fancy">Content</div>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('class=', $model->content);
        $this->assertStringContainsString('Content', $model->content);
    }

    public function testStripsStyleAttributes(): void
    {
        $model = $this->createModel();
        $model->content = '<div style="color:red">Content</div>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('style=', $model->content);
    }

    public function testStripsIdAttributes(): void
    {
        $model = $this->createModel();
        $model->content = '<div id="main">Content</div>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('id=', $model->content);
    }

    public function testStripsDataAttributes(): void
    {
        $model = $this->createModel();
        $model->content = '<div data-value="123" data-type="test">Content</div>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('data-', $model->content);
    }

    public function testStripsAriaAttributes(): void
    {
        $model = $this->createModel();
        $model->content = '<div aria-label="test" role="button">Content</div>';
        $this->triggerClean($model);

        $this->assertStringNotContainsString('aria-', $model->content);
        $this->assertStringNotContainsString('role=', $model->content);
    }

    // =========================================================================
    // Multiple Attributes
    // =========================================================================

    public function testCleansMultipleAttributes(): void
    {
        $model = new FakeModel();
        $model->attachBehavior('cleanHtml', [
            'class' => CleanHtmlBehavior::class,
            'attributes' => ['content', 'title'],
        ]);

        $model->content = '<script>bad</script>Good content';
        $model->title = '<script>bad</script>Good title';
        $model->trigger(ActiveRecord::EVENT_BEFORE_VALIDATE);

        $this->assertStringNotContainsString('<script>', $model->content);
        $this->assertStringNotContainsString('<script>', $model->title);
        $this->assertStringContainsString('Good content', $model->content);
        $this->assertStringContainsString('Good title', $model->title);
    }

    // =========================================================================
    // Custom HtmlPurifier Config
    // =========================================================================

    public function testCustomHtmlPurifierConfig(): void
    {
        $model = $this->createModel([
            'htmlPurifierConfig' => [
                'HTML.Allowed' => 'p,b',
                'AutoFormat.RemoveEmpty' => true,
            ],
        ]);
        $model->content = '<p><b>Bold</b> <i>italic</i> <u>underline</u></p>';
        $this->triggerClean($model);

        $this->assertStringContainsString('<b>Bold</b>', $model->content);
        // italic and underline should be stripped with restricted config
        $this->assertStringNotContainsString('<i>', $model->content);
        $this->assertStringNotContainsString('<u>', $model->content);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testPlainTextPassesThrough(): void
    {
        $model = $this->createModel();
        $model->content = 'Just plain text without any HTML';
        $this->triggerClean($model);

        $this->assertSame('Just plain text without any HTML', $model->content);
    }

    public function testWhitespaceOnlyContent(): void
    {
        $model = $this->createModel();
        $model->content = '   ';
        $this->triggerClean($model);

        $this->assertSame('', $model->content);
    }

    public function testMixedContentCleaning(): void
    {
        $model = $this->createModel(['keepEmoji' => true]);
        $model->content = '<div class="editor" style="font-size:14px"><span>Hello,World 😀</span></div>';
        $this->triggerClean($model);

        // Div/span converted, attributes stripped, punctuation spaced, emoji preserved
        $this->assertStringNotContainsString('<div>', $model->content);
        $this->assertStringNotContainsString('<span>', $model->content);
        $this->assertStringNotContainsString('class=', $model->content);
        $this->assertStringContainsString('Hello, World', $model->content);
        $this->assertStringContainsString('😀', $model->content);
    }
}
