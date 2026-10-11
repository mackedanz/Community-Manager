<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Http\Request;
use Hangar\SimpleMarkdown;

final class LicenseTest extends DbTestCase
{
    public function testRendersHeadingsListsParagraphsAndBold(): void
    {
        $html = SimpleMarkdown::render("# Titel\n\nText mit **fett**\nzweite Zeile.\n\n## Abschnitt\n\n- eins\n- zwei\n\nSchluss");
        $this->assertStringContainsString('<h1 class="text-2xl font-bold text-zinc-100">Titel</h1>', $html);
        $this->assertStringContainsString('<p>Text mit <strong>fett</strong> zweite Zeile.</p>', $html);
        $this->assertStringContainsString('<h2 class="text-lg font-semibold text-zinc-100">Abschnitt</h2>', $html);
        $this->assertStringContainsString("<li>eins</li>\n<li>zwei</li>\n</ul>", $html);
        $this->assertStringContainsString('<p>Schluss</p>', $html);
    }

    public function testNeverProducesForeignHtml(): void
    {
        $html = SimpleMarkdown::render("# <script>alert(1)</script>\n\n- <img src=x onerror=alert(1)>\n\n**<b>x</b>**");
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testLicensePageIsPublicShowsTheFileAndIsLinkedInTheFooter(): void
    {
        $res = App::handle(new Request('GET', '/lizenz'));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Nutzungsbedingungen und Lizenzvereinbarung', $res->body);
        $this->assertStringContainsString('Alle Rechte vorbehalten', $res->body);
        $this->assertStringContainsString('inoffizielles Fan-Projekt', $res->body);
        $this->assertStringContainsString('href="/lizenz"', $res->body);   // Link in der Fußzeile
    }

    public function testLicenseFilesShipWithTheProject(): void
    {
        $root = dirname(__DIR__);
        $this->assertFileExists($root . '/LIZENZ.md');
        $this->assertFileExists($root . '/LICENSE.txt');
        $this->assertStringContainsString('LIZENZ.md', (string) file_get_contents($root . '/Dockerfile'));
    }
}
