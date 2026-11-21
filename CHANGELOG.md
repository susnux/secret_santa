<!--
  - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to this project are documented in this file.

## Unreleased

### Added

- Lists of participants, both Nextcloud accounts and guests with name and email address
- Partners a participant must not draw, e.g. couples, optionally in both directions
- A customizable email with the placeholders `{name}` and `{partner}` and a live preview
- Drawing the partners: nobody draws themselves, an excluded partner or their partner of the previous draw, and no two participants draw each other
- Emails with the drawn partner to every participant, sent through the mail server of the instance, and sending them again
- The drawn partners are only sent by email, they are never shown to the organizer
- Draws are refused if they are the only possible one, as everybody could deduce the partners then
- German translation
