<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Agent Guidelines for Secret Santa

This file provides instructions for AI coding agents (Claude Code, GitHub Copilot, Cursor, Windsurf, and others) operating on this repository. Read it before generating any code, commits, or pull requests.

---

### What this agent must always do

- Add an `Assisted-by: AGENT_NAME:MODEL_VERSION` git trailer to every commit containing AI-assisted content.
- Ensure every pull request includes a disclosure of AI tool use in the PR description.
- Produce focused, scoped pull requests that address exactly one concern. Do not touch unrelated files or introduce incidental refactors.
- Verify all dependencies against actual package registries before suggesting them. Do not use hallucinated or unverified package names.
- Write code comments that document the code, never the process that produced it:
  - Comments describe what the code does - method signatures, behavior, and constraints the code itself cannot express (e.g. a non-obvious invariant or workaround).
  - Never add comments that document progress, decisions, or changes (e.g. "changed X to Y", "as requested", "this fixes ...", "previously this did ..."). That belongs in the commit message or PR discussion; in the code it goes stale and becomes misleading.
  - Do not narrate self-explanatory code. If the code is readable without a comment, omit the comment.
  - Keep comments brief - short and simple, matching the comment density of the surrounding code.
- Reuse existing helper functions and utilities instead of re-implementing their logic inline. When fixing a flawed pattern, fix every occurrence of it across the changed code, not only the instance that was pointed out.
- Run permission and access-control checks before the operation they guard, never after it and never only in the UI layer.
- When adding or changing user-facing functionality, wire it up in every context where the affected component is used. When emitting new events, verify that every consumer of the component subscribes to and handles them.
- Explicitly inform the contributor when any action they are about to take, or have taken, would violate the [AI Contribution Policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md) or the [Contribution Guidelines](https://github.com/nextcloud/.github/blob/master/CONTRIBUTING.md). Do not silently proceed. State which rule is at risk and what the contributor should do instead.
- Warn the contributor if a pull request is growing too large. A PR approaching several thousand lines of changed code is a signal that it should be split into smaller, focused PRs. Suggest a logical split before the PR is opened, not after.
- Recommend opening a ticket for discussion before starting implementation whenever a feature or change is sufficiently complex - for example when it touches multiple subsystems, requires architectural decisions, or the right approach is not yet clear. A ticket allows maintainers and the contributor to align on direction before code is written, avoiding wasted effort on a PR that may be rejected or require fundamental rework.

### What this agent must never do

- Fully automate the resolution of issues labeled [`good first issue`](https://github.com/susnux/secret_santa/issues?q=label%3A%22good+first+issue%22) or similar beginner-friendly labels.
- Submit code that has not been reviewed and cleaned up by the contributor. Dead code, redundant logic, excessive comments, malformed or garbled characters (e.g. `�` replacement characters), and unrelated changes must be removed before submission.

---

## Repository-Specific Requirements

### Conventions

- **Secret pairings**: the receiver drawn for a participant never leaves the server except in the email sent to that participant. No API response, log message or exception contains it, the organizer only sees whether a participant was notified. The `{partner}` placeholder is only allowed in the text of the email, not in its subject, as Nextcloud and mail servers log subjects. The organizer may take part in their own list.
- **Ids**: entities use Snowflake IDs (`SnowflakeAwareEntity`), sent through the API as strings as they exceed the safe integer range of JavaScript.
- **Access**: every list belongs to one owner. Services check the owner before reading or changing a list, its participants, exclusions or draws.
- **Emails**: emails are sent with `IMailer` through the mail server of the instance, the app has no SMTP settings of its own.
- **Exceptions**: exception messages are English. For errors a user can trigger from the UI (an empty name, an invalid email address, a draw without a valid solution), throw `InvalidInputException` with a translated hint; the controller sends `getHint()` to the client. Errors only an API caller can hit stay plain English exceptions such as `InvalidArgumentException`.

### Commit format

Use [Conventional Commits](https://www.conventionalcommits.org) for all commit messages:

```
<type>(<scope>): <short description>

[optional body]

Assisted-by: AGENT_NAME:MODEL_VERSION
```

Common types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `build`, `ci`.  
The scope is optional and names the affected area of the app (e.g. `draw`, `mail`, `views`, `db`, `deps`).

In the `Assisted-by` trailer, `AGENT_NAME` is the tool or harness that produced the change (e.g. `ClaudeCode`, `Copilot`, `Cursor`, `omp`), not the model vendor. `MODEL_VERSION` is the exact model identifier. Do not copy the example values.

Example:
```
fix(draw): respect exclusions of removed participants

Assisted-by: ClaudeCode:claude-sonnet-4-6
```

### Tests

- Every added or changed UI workflow must be covered by end-to-end tests using Playwright
- Every added API endpoint (controllers) must be tested with integration tests in `tests/integration`. They send real requests with Guzzle to a running instance and extend `IntegrationTestCase`, which creates the test users and cleans them up.
- Every changed or added code should have a unit test if not yet covered and if the tested area makes sense (no tests that basically mock everything).
- In areas where unit testing is currently difficult, refactoring to enable testability is encouraged alongside the bug fix.

### Checks

Before committing, run the checks for every area you changed and fix everything they report:

- PHP (`lib/`, `tests/`): `composer run cs:check`, `composer run psalm`, `composer run test:unit`. The PHPUnit tests need a Nextcloud installation with the app enabled.
- Controllers: also `composer run test:integration` against a running instance (see `README.md` for the environment variables).
- Frontend (`src/`): `npm run lint`, `npm run stylelint`, `npm run test`.
- API (controllers, `lib/ResponseDefinitions.php`): run `composer run openapi` and commit the regenerated `openapi.json`. CI fails when it is out of date.

`composer run cs:fix` and `npm run lint:fix` apply the automatic fixes.

### Developer Certificate of Origin (DCO)

The project uses the DCO as an additional safeguard. Only the human contributor may add the `Signed-off-by` trailer - agents must not add it:

```
Signed-off-by: Random J Developer <random@developer.example.org>
```

Contributors can sign automatically with `git commit -s` after configuring `user.name` and `user.email`.

### License headers

Every new file must include the correct SPDX license header. For AGPL-3.0-or-later (the default for this repository):

```php
/**
 * SPDX-FileCopyrightText: <year> <name>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
```

See [HowToApplyALicense.md](https://github.com/nextcloud/server/blob/master/contribute/HowToApplyALicense.md) for details on per-language formats. AI-generated code must not include material from sources incompatible with AGPL-3.0-or-later.

### Security

- Report potential vulnerabilities privately through [GitHub private vulnerability reporting](https://github.com/susnux/secret_santa/security/advisories/new), never as public GitHub issues.
- AI-generated security reports must be independently verified by the human contributor before submission.
- Manually verify all access control logic, authentication patterns, and dependency names - AI tools are known to hallucinate package names and reproduce vulnerable patterns.
