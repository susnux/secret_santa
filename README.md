<!--
  - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Secret Santa

Organize a Secret Santa gift exchange ("Wichteln") in Nextcloud.

- Create lists of participants, both Nextcloud users and guests by name and email
- Write the email every participant receives
- Draw the partners and notify every participant by email whom to give a gift
- Nobody draws the same partner as in the previous draw
- Exclude partners, e.g. couples, from drawing each other

Emails are sent through the mail server configured in the Nextcloud administration settings.

## Development

```sh
composer install
npm ci
npm run dev
```

- PHP: `composer run cs:check`, `composer run psalm`, `composer run test:unit`
- Frontend: `npm run lint`, `npm run stylelint`, `npm test`
