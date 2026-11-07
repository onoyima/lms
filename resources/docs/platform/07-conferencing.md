---
title: Conferencing servers (BigBlueButton)
section: Operations
order: 70
---
**Team > Conferencing servers.** Live classes use BigBlueButton servers. Add the shared ones here; an institution may also add its own under *Institution settings > Conferencing*, and its meetings then use those first.

For each server: its address (`https://bbb.example.edu/bigbluebutton/`), the shared secret (`bbb-conf --secret`, stored encrypted and never sent to a browser), the checksum (sha256 on BigBlueButton 2.6 and later) and a weight (bigger = more meetings). **Check** asks the server its version and how busy it is. **Register webhook** asks it to send join, leave, meeting-ended and recording events to the platform; attendance depends on this (the server needs the `bbb-webhooks` package).

Meetings go to the least busy server that is in use. A server can be switched off without deleting it; it cannot be removed while meetings run on it.

The module is **Live classes & meetings** (`conferencing`). Add it to packages under *Plans & prices* or switch it on for one institution.

Server set-up, sizing, Scalelite for several servers and upgrades: `docs/CONFERENCING.md` in the platform folder.
