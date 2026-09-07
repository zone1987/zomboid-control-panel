<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Server\Discord\MessageTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Filling an operator's own message text, safely.
 *
 * Each of these is a bug the reference panel shipped and fixed, so the
 * tests are named after the failure rather than after the feature.
 */
final class MessageTemplateTest extends TestCase
{
    public function testSubstitutesWhatTheEventCarries(): void
    {
        self::assertSame(
            'bob was kicked from Test',
            $this->render('{player} was kicked from {server}', ['player' => 'bob', 'server' => 'Test']),
        );
    }

    /**
     * Replacing tokens one at a time let `{player}` be replaced inside
     * `{playerCount}`, producing "bobCount".
     */
    public function testDoesNotSubstituteInsideALongerTokenName(): void
    {
        self::assertSame(
            'bob of 12',
            $this->render('{player} of {playerCount}', ['player' => 'bob', 'playerCount' => 12]),
        );
    }

    /** A value holding `$1` was read as a backreference. */
    public function testAValueIsNotReadAsAReplacementPattern(): void
    {
        self::assertSame('$1 joined', $this->render('{player} joined', ['player' => '$1']));
        self::assertSame('$0 joined', $this->render('{player} joined', ['player' => '$0']));
    }

    /**
     * A value holding a token was substituted a second time. Braces are
     * not markdown, so the value comes through as typed — the point is
     * that "Test" did not appear in its place.
     */
    public function testAValueHoldingATokenIsNotSubstitutedAgain(): void
    {
        $rendered = $this->render(
            '{player} joined {server}',
            ['player' => '{server}', 'server' => 'Test'],
        );

        self::assertSame('{server} joined Test', $rendered);
        self::assertSame(1, substr_count($rendered, 'Test'), 'the value was substituted twice');
    }

    /**
     * The operator's formatting works; a player's does not. The
     * reference escaped neither, so a player named `**x**` wrote bold
     * text into somebody else's sentence.
     */
    public function testTheTemplatesFormattingWorksAndTheValuesDoesNot(): void
    {
        self::assertSame(
            '**\*\*x\*\***',
            $this->render('**{player}**', ['player' => '**x**']),
        );
    }

    /** A mention is structural, not markdown, so it is defused too. */
    public function testAValueCannotRenderAsAMention(): void
    {
        $rendered = $this->render('{player}', ['player' => '<@&12345>']);

        self::assertStringNotContainsString('<@&12345>', $rendered);

        foreach (['@everyone', '@here'] as $broadcast) {
            self::assertStringNotContainsString($broadcast, $this->render('{player}', ['player' => $broadcast]));
        }
    }

    /** Blanking a typo hides it; leaving it visible is how it is found. */
    public function testAnUnknownTokenStaysAsItIs(): void
    {
        self::assertSame('{plyer} joined', $this->render('{plyer} joined', ['player' => 'bob']));
    }

    /** A token the event carries but has nothing for prints nothing. */
    public function testATokenWithNoValuePrintsNothing(): void
    {
        self::assertSame('reason: ', $this->render('reason: {reason}', ['reason' => null]));
    }

    public function testReportsTheTokensATemplateGetsWrong(): void
    {
        $template = new MessageTemplate();

        self::assertSame(
            ['plyer'],
            $template->unknownTokens('{plyer} left {server}', ['player', 'server']),
        );

        self::assertSame([], $template->unknownTokens('{player} left', ['player', 'server']));
    }

    public function testSubstitutesADottedInputToken(): void
    {
        self::assertSame(
            'rain at 70',
            $this->render('rain at {input.intensity}', ['input.intensity' => 70]),
        );
    }

    /** @param array<string, scalar|null> $tokens */
    private function render(string $template, array $tokens): string
    {
        return (new MessageTemplate())->render($template, $tokens);
    }
}
