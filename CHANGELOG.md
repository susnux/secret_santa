<!--
  - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to this project are documented in this file.

## Unreleased
### Fixed
- The Nextcloud log contained the name and email address of the partner of a participant when their email could not be sent, as the stack trace logged the arguments of the functions. The partners, drawn and imported, are now hidden from stack traces.

## v0.2.0 - 2026-11-25
### Added
- Members of the groups a list is shared with can take over a list whose owner was deleted and become its owner, so it can be shared and deleted again and the emails have a sender name and reply address again.
- A user guide, linked from the app info

### Fixed
- Users could not add themselves as participant, as the account search never offered the current user
- Members of a list could change the email address of a guest and send the email of the guest with their partner to themselves unnoticed. When an email is sent again to a different address, the previous address now gets a notice naming who sent it, and the email says that it was sent to a different address before.

### Changed
- The app is listed in the "Games" category


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
