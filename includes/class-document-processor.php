<?php
defined('ABSPATH') || exit;

class GutenBot_Document_Processor {

    const MAX_SIZE_BYTES = 10485760; // 10 MB — overridden by option.

    public static function parse(string $content, string $extension) {
        $limit = (int) get_option('gutenbot_file_size_limit', self::MAX_SIZE_BYTES);
        if (strlen($content) > $limit) {
            throw new InvalidArgumentException('File content exceeds the maximum allowed size.');
        }

        $extension = strtolower(ltrim($extension, '.'));

        switch ($extension) {
            case 'md':
                return self::parse_md($content);
            case 'txt':
                return self::parse_txt($content);
            default:
                throw new InvalidArgumentException(
                    sprintf('Unsupported file extension: %s', esc_html($extension))
                );
        }
    }

    public static function parse_md(string $content) {
        if ($content === '') {
            return '';
        }

        // Remove ATX headings (# through ######).
        $text = preg_replace('/^#{1,6}\s+/m', '', $content);

        // Remove bold/italic markers.
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text);
        $text = preg_replace('/_{1,3}([^_]+)_{1,3}/', '$1', $text);

        // Remove inline code.
        $text = preg_replace('/`[^`]+`/', '', $text);

        // Remove fenced code blocks.
        $text = preg_replace('/```[\s\S]*?```/', '', $text);

        // Replace links: [text](url) -> text.
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);

        // Remove images: ![alt](url).
        $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $text);

        // Remove blockquotes.
        $text = preg_replace('/^>\s+/m', '', $text);

        // Remove horizontal rules.
        $text = preg_replace('/^[-*_]{3,}\s*$/m', '', $text);

        // Normalize whitespace.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    public static function parse_txt(string $content) {
        if ($content === '') {
            return '';
        }

        // Normalize line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $content);

        // Collapse 3+ blank lines to 2.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim trailing whitespace per line.
        $lines = array_map('rtrim', explode("\n", $text));
        $text  = implode("\n", $lines);

        return trim($text);
    }

    public static function get_extension_from_mime(string $mime) {
        $map = [
            'text/plain'    => 'txt',
            'text/markdown' => 'md',
        ];
        return $map[$mime] ?? null;
    }
}
