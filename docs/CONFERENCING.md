# Live classes on BigBlueButton — server guide

The platform is the front end: scheduling, access, attendance, recordings and reports. BigBlueButton (open source, https://github.com/bigbluebutton/bigbluebutton) runs the meeting room. The platform calls BigBlueButton only from the server, signing every call with the server's shared secret; browsers receive a one-time join address.

## 1. A BigBlueButton server

- Ubuntu 22.04 64-bit, a public IPv4 address and a domain name (e.g. `bbb.example.edu`). BigBlueButton 3.0.
- Sizing to start: 8 cores, 16 GB RAM, 500 GB disk, 250 Mbit/s or better. Roughly 150–250 people with webcams in total per server; measure with your own classes.
- Install with the official script (run as root):

  ```
  wget -qO- https://raw.githubusercontent.com/bigbluebutton/bbb-install/v3.0.x-release/bbb-install.sh | bash -s -- -v jammy-300 -s bbb.example.edu -e ops@example.edu -w
  ```

  `-w` opens the firewall. Do not add `-g` (Greenlight): the platform replaces it.
- Webhooks for attendance: `apt-get install bbb-webhooks`.
- Get the address and secret: `bbb-conf --secret`.

## 2. Connect it

1. Platform console > Team > **Conferencing servers** > Add server: name, address (`https://bbb.example.edu/bigbluebutton/`), secret, checksum `sha256`.
2. **Check**: shows "online" and the version.
3. **Register webhook**: BigBlueButton then posts events to `https://<platform>/api/conferencing/webhooks/<server id>`, signed with `sha(callback address + body + secret)` (or the secret as a bearer token). Unsigned or wrongly signed posts are refused.
4. Switch the **Live classes & meetings** module on (Plans & prices, or per institution).

The platform must be reachable from the BigBlueButton server over HTTPS for webhooks and the end-of-meeting and recording-ready callbacks.

## 3. More than one server

Add each server; meetings go to the least busy one by weight. For a load balancer in front of many servers, install Scalelite (https://github.com/blindsidenetworks/scalelite) and add Scalelite's address and secret as a single server.

## 4. Recordings

Recordings are processed on the BigBlueButton server after each meeting. The platform lists them (webhook `rap-publish-ended`, the recording-ready callback, or "Look for new recordings"), keeps their playback address server-side and hands each viewer a personal 30-minute link. Retention is managed on the BigBlueButton server (`/etc/bigbluebutton/bbb-conf/...` and `bbb-record --delete`); deleting a recording from the platform deletes it on the server.

To keep recordings from being opened by guessing their address, enable protected recordings on the server (`bigbluebutton.properties`: `defaultRecordingsProtected=true` on BigBlueButton 2.6+).

## 5. Look and feel

Each join passes the institution's colours as a style sheet (`userdata-bbb_custom_style_url` → `/conferencing/theme/<institution>.css`), its logo (`logo`) and name (`bannerText`). Only colours are overridden, so BigBlueButton upgrades are not affected. Deeper changes to the meeting screen belong in BigBlueButton 3.0 plugins or a documented patch set kept next to this file, rebuilt and re-applied on each upgrade.

## 6. Upgrades

Upgrade BigBlueButton by re-running the install script with the new version, during a quiet period. Then Check the server in the console and start a test meeting. The platform depends only on the documented BigBlueButton API (create, join, end, getMeetingInfo, getMeetings, getRecordings, publishRecordings, deleteRecordings, hooks/create).

## 7. What needs a real server to verify

The platform's own side is covered by automated tests against recorded copies of BigBlueButton's replies. Before first use, verify on your server: start a meeting, join as a student (waiting room on and off), leave and rejoin, end, and check the attendance and the recording appear.
