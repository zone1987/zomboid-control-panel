<!--
The title follows Conventional Commits: type(scope): summary
  feat fix docs style refactor perf test build ci chore
Lower case, imperative, no full stop. A `!` before the colon for a
breaking change.
-->

## What changed

<!-- What this does, and why. The diff already says what; say why. -->

## How it was verified

<!--
A change is done when it has been demonstrated, not when it looks right.
State what you actually ran and saw — and say plainly what you did not
check, which is more useful than silence.

  Backend work    a test, and the proof is a revert: say you watched it
                  fail with the fix removed
  Interface work  a real browser, the actual control clicked, the
                  network entry read
  Bridge work     fired at a running game server, because the local
                  checks cannot see what Lua does at run time
-->

- [ ] `ddev exec -d /var/www/html/backend "php bin/phpunit"`
- [ ] `ddev exec -d /var/www/html/frontend "npm run lint && npm run test && npm run build"`
- [ ] Clicked through in a browser
- [ ] Not verified: <!-- say what, and why -->

## Anything an operator has to do

<!--
Delete the ones that do not apply. These are the things that reach
somebody else's server.
-->

- [ ] Nothing — this upgrades by itself
- [ ] **The bridge changed**: `BRIDGE_VERSION` is bumped, and the
      operator has to upload it and restart their game server
- [ ] A migration runs at start
- [ ] A new setting or environment variable — documented in both READMEs
- [ ] Something behaves differently than before

## Notes for review

<!-- Anything you are unsure about, or a decision worth arguing with. -->
