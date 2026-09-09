<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Entity\ModerationAction;
use App\Server\Discord\MessageTemplate;
use App\Server\Discord\NotifiableEvents;
use PHPUnit\Framework\TestCase;

/** What can be announced, and what it says before anybody edits it. */
final class NotifiableEventsTest extends TestCase
{
    /**
     * Every one of the recorded action types can be announced.
     *
     * The reference panel announced none of them; announcing some but
     * not others would be an arbitrary gap the operator cannot see.
     */
    public function testEveryModerationActionTypeCanBeAnnounced(): void
    {
        $reflection = new \ReflectionClass(ModerationAction::class);
        $missing = [];

        foreach ($reflection->getConstants() as $name => $value) {
            // The action names are the lower-case string constants; the
            // class also holds unrelated ones.
            if (!is_string($value) || $value !== strtolower($value) || str_contains($value, ' ')) {
                continue;
            }

            if (NotifiableEvents::defaultTemplate('moderation.'.$value) === null) {
                $missing[] = $name;
            }
        }

        self::assertSame([], $missing, 'action types with no wording: '.implode(', ', $missing));
    }

    /** Every template must render with the tokens its event carries. */
    public function testEveryDefaultTemplateOnlyUsesTokensItsEventHas(): void
    {
        $renderer = new MessageTemplate();

        foreach (NotifiableEvents::all() as $type) {
            $template = (string) NotifiableEvents::defaultTemplate($type);
            $unknown = $renderer->unknownTokens($template, NotifiableEvents::tokensFor($type));

            self::assertSame([], $unknown, sprintf('%s uses %s', $type, implode(', ', $unknown)));
        }
    }

    /**
     * Joining and leaving are moderation rows but not admin actions:
     * they are what the server did, not what a person chose to do.
     */
    public function testComingAndGoingIsNotAnAdminAction(): void
    {
        self::assertFalse(NotifiableEvents::isAdminAction('moderation.'.ModerationAction::JOIN));
        self::assertFalse(NotifiableEvents::isAdminAction('moderation.'.ModerationAction::LEAVE));
        self::assertFalse(NotifiableEvents::isAdminAction('bridge.quiet'));

        self::assertTrue(NotifiableEvents::isAdminAction('moderation.'.ModerationAction::BAN));
        self::assertTrue(NotifiableEvents::isAdminAction('moderation.'.ModerationAction::CONSOLE));
    }

    public function testEveryServerEventHasWording(): void
    {
        foreach (NotifiableEvents::SERVER_EVENTS as $type) {
            self::assertNotNull(NotifiableEvents::defaultTemplate($type), $type);
        }
    }

    /** An unknown type has no wording rather than an empty one. */
    public function testAnUnknownTypeHasNoTemplate(): void
    {
        self::assertNull(NotifiableEvents::defaultTemplate('moderation.invented'));
    }

    /**
     * A type the route cannot match answers 404, and the switch simply
     * does nothing — no error the operator can act on.
     *
     * Two events were in that state: `mods.update` while it was called
     * `mods.updateAvailable`, and `moderation.access_level`, which has
     * been unswitchable since it was added. Both found by clicking, so
     * this guard exists to make the next one fail here instead.
     */
    public function testEveryEventTypeMatchesTheRouteThatSavesIt(): void
    {
        foreach (NotifiableEvents::all() as $type) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z._]+$/',
                $type,
                sprintf('"%s" cannot be saved: the route requires [A-Za-z._]+', $type),
            );
        }
    }
}
