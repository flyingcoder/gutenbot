<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_Document_Processor extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_option')->justReturn(10485760);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_markdown_stripped_to_plain_text() {
        // Arrange
        $markdown = "## Services\n\n**Fast** installation with [contact us](https://example.com).";

        // Act
        $result = GutenBot_Document_Processor::parse_md($markdown);

        // Assert
        $this->assertStringNotContainsString('##', $result);
        $this->assertStringNotContainsString('**', $result);
        $this->assertStringContainsString('Fast', $result);
        $this->assertStringContainsString('Services', $result);
        $this->assertStringNotContainsString('https://example.com', $result);
    }

    public function test_empty_file_returns_empty_string() {
        // Arrange / Act
        $result = GutenBot_Document_Processor::parse_md('');

        // Assert
        $this->assertSame('', $result);
    }

    public function test_txt_whitespace_normalized() {
        // Arrange
        $txt = "Line one\n\n\n\nLine two";

        // Act
        $result = GutenBot_Document_Processor::parse_txt($txt);

        // Assert
        $this->assertStringNotContainsString("\n\n\n", $result);
        $this->assertStringContainsString('Line one', $result);
        $this->assertStringContainsString('Line two', $result);
    }

    public function test_oversized_file_throws_exception() {
        // Arrange — override size limit to 10 bytes.
        Functions\when('get_option')->justReturn(10);
        $content = str_repeat('a', 11);

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        GutenBot_Document_Processor::parse($content, 'txt');
    }

    public function test_unsupported_extension_throws_exception() {
        // Arrange
        $content = 'binary content';

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        GutenBot_Document_Processor::parse($content, 'exe');
    }

    public function test_txt_parse_preserves_content() {
        // Arrange
        $content = "Hello world\nSecond line";

        // Act
        $result = GutenBot_Document_Processor::parse($content, 'txt');

        // Assert
        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringContainsString('Second line', $result);
    }

    public function test_md_removes_images() {
        // Arrange
        $md = "Some text\n\n![alt text](https://example.com/img.jpg)\n\nMore text";

        // Act
        $result = GutenBot_Document_Processor::parse_md($md);

        // Assert
        $this->assertStringNotContainsString('![', $result);
        $this->assertStringContainsString('Some text', $result);
    }

    public function test_md_removes_code_fences() {
        // Arrange
        $md = "Intro\n\n```php\n<?php echo 'hello'; ?>\n```\n\nOutro";

        // Act
        $result = GutenBot_Document_Processor::parse_md($md);

        // Assert
        $this->assertStringNotContainsString('```', $result);
        $this->assertStringContainsString('Intro', $result);
    }
}
