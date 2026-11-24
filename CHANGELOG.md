<!--
  - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to this project are documented in this file.

## v0.1.0 - 2026-11-24

### Added

- Lists of participants, both Nextcloud accounts and guests with name and email address
- Partners a participant must not draw, e.g. couples, optionally in both directions
- A customizable email with the placeholders `{name}` and `{partner}` and a live preview
- Importing the partners of the latest draw of a list as exclusions, so nobody draws the same partner again. Participants are matched by their account or email address, the imported partners are never shown
- Drawing the partners: nobody draws themselves or an excluded partner, and no two participants draw each other
- Emails with the drawn partner to every participant, sent through the mail server of the instance, and sending them again
- The drawn partners are only sent by email, they are never shown to the organizer
- Archiving lists: archived lists are hidden from the navigation, listed on their own page and can not be changed until they are unarchived, but their partners can still be imported as exclusions
- Draws are refused if they are the only possible one, as everybody could deduce the partners then
- Sharing lists with groups: all members of the groups can change the list and draw the partners, only the owner can delete and share it. Shared lists of deleted owners are kept without owner
- German translation
