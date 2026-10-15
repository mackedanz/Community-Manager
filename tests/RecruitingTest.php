<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\App;
use Hangar\Auth;
use Hangar\BotSettings;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Recruiting;
use Hangar\Time;

/** Recruiting im Web: Kanal, Rollen und Fragenkatalog für /recruit; nur Orga-Admins. */
final class RecruitingTest extends DbTestCase
{
    private string $orgId;
    /** @var array<string,array{token:string,csrf:string}> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'alpha', 'name' => 'Alpha', 'discord_guild_id' => '900', 'created_by_id' => 'x']);
        foreach (['admin' => 'ADMIN', 'member' => 'MEMBER'] as $key => $role) {
            $user = $this->mkUser(['membership_checked_at' => Time::nowDb(), 'membership_status' => 'OK']);
            Db::insert('org_memberships', ['user_id' => $user['id'], 'org_id' => $this->orgId, 'role' => $role, 'can_plan' => $role === 'ADMIN' ? 1 : 0]);
            $s = Auth::createSession($user['id']);
            $this->u[$key] = ['token' => $s['token'], 'csrf' => (string) Db::val('SELECT csrf_token FROM sessions WHERE user_id = ?', [$user['id']])];
        }
        Env::set('DISCORD_BOT_TOKEN', 'bot-token');
        Client::fake(function (string $method, string $url): array {
            $json = static fn (mixed $b): array => ['status' => 200, 'body' => json_encode($b), 'headers' => []];
            if (str_ends_with($url, '/guilds/900/channels')) {
                return $json([['id' => '11', 'type' => 0, 'name' => 'recruiting', 'position' => 1], ['id' => '12', 'type' => 0, 'name' => 'chat', 'position' => 2]]);
            }
            if (str_ends_with($url, '/guilds/900/roles')) {
                return $json([['id' => '900', 'name' => '@everyone', 'position' => 0], ['id' => '20', 'name' => 'Recruiter', 'position' => 2], ['id' => '21', 'name' => 'Moderator', 'position' => 1]]);
            }
            throw new \LogicException("Unerwarteter HTTP-Aufruf: $method $url");
        });
    }

    /** @param array<string,mixed> $post */
    private function save(array $post, string $as = 'admin'): Response
    {
        return App::handle(new Request('POST', '/o/alpha/bot/save', [], ['section' => 'recruiting'] + $post + ['_csrf' => $this->u[$as]['csrf']], [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    private function page(string $as = 'admin'): Response
    {
        return App::handle(new Request('GET', '/o/alpha/bot', ['m' => 'recruiting'], [], [], [Auth::COOKIE => $this->u[$as]['token']], ''));
    }

    public function testPageStartsWithOneExampleQuestionAndALiveBuilder(): void
    {
        $html = $this->page()->body;
        $this->assertSame(200, $this->page()->status);
        $this->assertSame(1, substr_count($html, 'name="q[0][label]"'));
        $this->assertStringContainsString('RSI-Handle', $html);
        $this->assertStringContainsString('id="tpl-q"', $html);
        $this->assertStringContainsString('data-q-add', $html);
        $this->assertStringContainsString('<option value="11">', $html);
        $this->assertStringContainsString('@Recruiter', $html);
        $this->assertSame(404, $this->page('member')->status);
    }

    public function testSavesChannelRolesAndQuestionsInTheSubmittedOrder(): void
    {
        $this->save([
            'recruitChannel' => '11', 'recruitRoles' => ['20', '20', '21'],
            'q' => [
                '1002' => ['label' => 'Zweite Frage', 'hint' => 'Tipp', 'required' => '1'],
                '5' => ['label' => 'Erste in der Liste, lang', 'long' => '1'],
                '9' => ['label' => '', 'hint' => ''],   // leere Zeile zählt nicht
                '0' => ['label' => '  Dritte  ', 'required' => '1', 'long' => '1'],
            ],
        ]);
        $all = BotSettings::all($this->orgId);
        $this->assertSame(['11', '20,21'], [$all[BotSettings::RECRUIT_CHANNEL], $all[BotSettings::RECRUIT_ROLES]]);
        $this->assertSame([
            ['label' => 'Zweite Frage', 'hint' => 'Tipp', 'required' => true, 'long' => false],
            ['label' => 'Erste in der Liste, lang', 'hint' => '', 'required' => false, 'long' => true],
            ['label' => 'Dritte', 'hint' => '', 'required' => true, 'long' => true],
        ], Recruiting::questions($this->orgId));
        $this->assertTrue(BotSettings::status($all)['recruiting']);

        // Die Seite zeigt die gespeicherten Fragen statt der Vorlage; die Rollen sind angehakt
        $html = $this->page()->body;
        $this->assertSame(3, preg_match_all('/name="q\[\d+\]\[label\]"/', $html)); // ohne die Vorlage für neue Fragen
        $this->assertStringNotContainsString('RSI-Handle', $html);
        $this->assertMatchesRegularExpression('/name="recruitRoles\[\]" value="20" class="peer sr-only" checked/', $html);

        // Neu speichern ersetzt alles; ohne Kanal und Rollen werden sie entfernt
        $this->save(['q' => ['0' => ['label' => 'Nur eine']]]);
        $this->assertSame(['Nur eine'], array_column(Recruiting::questions($this->orgId), 'label'));
        $this->assertArrayNotHasKey(BotSettings::RECRUIT_CHANNEL, BotSettings::all($this->orgId));
        $this->assertArrayNotHasKey(BotSettings::RECRUIT_ROLES, BotSettings::all($this->orgId));
    }

    public function testRefusesBadInputAndKeepsTheOldCatalog(): void
    {
        Recruiting::save($this->orgId, [['label' => 'Alt', 'hint' => '', 'required' => true, 'long' => false]]);
        $this->save(['q' => ['0' => ['label' => str_repeat('x', 46)]]]);
        $this->save(['q' => ['0' => ['label' => '', 'hint' => 'Hilfe ohne Frage']]]);
        $this->save(['q' => ['0' => ['label' => 'Gut', 'hint' => str_repeat('h', 101)]]]);
        $this->save(['recruitChannel' => '999', 'q' => ['0' => ['label' => 'Neu']]]);
        $this->save(['recruitRoles' => ['777'], 'q' => ['0' => ['label' => 'Neu']]]);
        $many = [];
        for ($i = 0; $i < Recruiting::MAX_QUESTIONS + 1; $i++) {
            $many[(string) $i] = ['label' => "Frage $i"];
        }
        $this->save(['q' => $many]);
        $this->assertSame(['Alt'], array_column(Recruiting::questions($this->orgId), 'label'));
        $this->assertSame([], BotSettings::all($this->orgId));

        // Genau 25 sind erlaubt
        array_pop($many);
        $this->save(['q' => $many]);
        $this->assertCount(Recruiting::MAX_QUESTIONS, Recruiting::questions($this->orgId));
        $this->assertSame(5, Recruiting::parts(Recruiting::MAX_QUESTIONS));
    }

    public function testOnlyAdminsCanSave(): void
    {
        $this->assertSame(404, $this->save(['q' => ['0' => ['label' => 'x']]], 'member')->status);
        $this->assertSame([], Recruiting::questions($this->orgId));
    }
}
