<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Winziger Umsetzer für die mitgelieferten Textdateien (LIZENZ.md): "# " und "## " als Überschriften, "- " als Liste,
 * Leerzeilen trennen Absätze, **fett**. Der Text wird zuerst maskiert, es entsteht nie fremdes HTML.
 */
final class SimpleMarkdown
{
    public static function render(string $md): string
    {
        $inline = static fn (string $t): string => (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($t));
        $out = '';
        $list = false;
        $para = [];
        $flush = static function () use (&$out, &$para, $inline): void {
            if ($para !== []) {
                $out .= '<p>' . $inline(implode(' ', $para)) . "</p>\n";
                $para = [];
            }
        };
        $closeList = static function () use (&$out, &$list): void {
            if ($list) {
                $out .= "</ul>\n";
                $list = false;
            }
        };
        foreach (preg_split('/\R/', trim($md)) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $flush();
                $closeList();
            } elseif (str_starts_with($line, '## ')) {
                $flush();
                $closeList();
                $out .= '<h2 class="text-lg font-semibold text-zinc-100">' . $inline(substr($line, 3)) . "</h2>\n";
            } elseif (str_starts_with($line, '# ')) {
                $flush();
                $closeList();
                $out .= '<h1 class="text-2xl font-bold text-zinc-100">' . $inline(substr($line, 2)) . "</h1>\n";
            } elseif (str_starts_with($line, '- ')) {
                $flush();
                if (!$list) {
                    $out .= '<ul class="list-disc space-y-1 pl-5">' . "\n";
                    $list = true;
                }
                $out .= '<li>' . $inline(substr($line, 2)) . "</li>\n";
            } else {
                $closeList();
                $para[] = $line;
            }
        }
        $flush();
        $closeList();
        return $out;
    }
}