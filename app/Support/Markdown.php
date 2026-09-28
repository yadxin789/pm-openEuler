<?php

namespace App\Support;

class Markdown
{
    public static function render(?string $md): string
    {
        if ($md === null || trim($md) === '') {
            return '';
        }

        $html = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

        $html = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $html);

        $html = preg_replace_callback('/```([a-zA-Z0-9]*)\n(.*?)```/s', function ($m) {
            return '<pre class="md-pre"><code>' . trim($m[2]) . '</code></pre>';
        }, $html);

        $html = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)\)/', '<img src="$2" alt="$1" class="md-img">', $html);

        $html = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $html);

        $html = preg_replace('/^(\s*)(-{3,}|\*{3,}|_{3,})(\s*)$/m', '<hr>', $html);

        $html = preg_replace('/^&gt;\s?(.*)$/m', '<blockquote>$1</blockquote>', $html);

        $html = preg_replace('/^\s*[-*+]\s+(.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^\s*\d+\.\s+(.*)$/m', '<li class="md-ol">$1</li>', $html);

        $html = preg_replace('/^######\s+(.*)$/m', '<h6>$1</h6>', $html);
        $html = preg_replace('/^#####\s+(.*)$/m', '<h5>$1</h5>', $html);
        $html = preg_replace('/^####\s+(.*)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^###\s+(.*)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^##\s+(.*)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^#\s+(.*)$/m', '<h1>$1</h1>', $html);

        $html = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/~~([^~\n]+)~~/', '<del>$1</del>', $html);
        $html = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $html);

        if (preg_match('/^\|.*\|$/m', $html)) {
            $html = self::renderTable($html);
        }

        $html = self::wrapParagraphs($html);

        $html = self::wrapLists($html);

        return $html;
    }

    private static function renderTable(string $html): string
    {
        $lines = explode("\n", $html);
        $out = [];
        $inTable = false;
        foreach ($lines as $line) {
            if (preg_match('/^\|.*\|$/', trim($line))) {
                $cells = array_map('trim', explode('|', trim($line, '|')));
                if (!empty($cells) && preg_match('/^:?-{2,}:?$/', trim($cells[0]))) {
                    continue;
                }
                if (!$inTable) {
                    $out[] = '<table class="md-table"><thead><tr>';
                    foreach ($cells as $c) {
                        $out[] = '<th>' . $c . '</th>';
                    }
                    $out[] = '</tr></thead><tbody>';
                    $inTable = true;
                } else {
                    $out[] = '<tr>';
                    foreach ($cells as $c) {
                        $out[] = '<td>' . $c . '</td>';
                    }
                    $out[] = '</tr>';
                }
            } else {
                if ($inTable) {
                    $out[] = '</tbody></table>';
                    $inTable = false;
                }
                $out[] = $line;
            }
        }
        if ($inTable) {
            $out[] = '</tbody></table>';
        }
        return implode("\n", $out);
    }

    private static function wrapParagraphs(string $html): string
    {
        $lines = explode("\n", $html);
        $out = [];
        $inBlock = false;
        $buffer = [];

        $blockTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'pre', 'blockquote', 'ul', 'ol', 'li', 'hr'];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if (!empty($buffer)) {
                    $out[] = '<p>' . implode("\n", $buffer) . '</p>';
                    $buffer = [];
                }
                continue;
            }

            $isBlock = false;
            foreach ($blockTags as $tag) {
                if (preg_match('/^<' . $tag . '(?:\s|>)/i', $trimmed) || preg_match('/^<\/' . $tag . '>/i', $trimmed) || $trimmed === '<hr>') {
                    $isBlock = true;
                    break;
                }
            }

            if ($isBlock) {
                if (!empty($buffer)) {
                    $out[] = '<p>' . implode("\n", $buffer) . '</p>';
                    $buffer = [];
                }
                $out[] = $line;
            } else {
                $buffer[] = $line;
            }
        }

        if (!empty($buffer)) {
            $out[] = '<p>' . implode("\n", $buffer) . '</p>';
        }

        return implode("\n", $out);
    }

    private static function wrapLists(string $html): string
    {
        $html = preg_replace('/((?:<li[^>]*>.*<\/li>\n?)+)/', '<ul>$1</ul>', $html);
        return $html;
    }
}