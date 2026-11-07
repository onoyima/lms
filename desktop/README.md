# Secure Exam — desktop app

A locked-down exam app for Windows, macOS and Linux. It is one of the three ways into an exam
(web browser, this app, Safe Exam Browser); each exam chooses which ones it allows.

## What it does

- **One locked window.** Full screen (kiosk mode). It only shows your institution's platform. New windows, other sites, downloads, reload, developer tools and the usual escape keys are blocked. While an exam is open the window cannot be closed.
- **Hidden from capture.** Screenshot and screen-recording tools get a black window.
- **Approved computers only.** On first run the app makes an Ed25519 key pair. The private key is protected by the operating system's key store. The app sends the public key to the platform, and the computer stays *pending* until the ICT office approves it (**Halls & devices** page).
- **Signed requests.** Every exam request is signed with the computer's key. An exam started in the app can only continue on that computer: a copied exam token is useless anywhere else.
- **Verifies the platform.** The platform's signed description (`/.well-known/exam-platform.json`) is checked, and its key is pinned the first time, so a look-alike server is refused.
- **Hardware and software checks,** reported to proctors as events:
  - extra screens, and screens added during the exam;
  - HDMI, DisplayPort and wireless (Miracast) displays (Windows);
  - USB storage;
  - virtual machines;
  - remote-access tools (TeamViewer, AnyDesk, Remote Desktop, VNC, Chrome Remote Desktop, RustDesk and others);
  - screen-capture or meeting apps (OBS, Zoom, Teams, Discord and others);
  - virtual cameras.

## Build

You need Node.js 20 or newer.

```
cd desktop
npm install
npm run check        # syntax check + tests (signatures verified with PHP libsodium if PHP is installed)
npm run dist:win     # Windows installer  → dist/
npm run dist:mac     # macOS .dmg (build on a Mac; sign and notarise with your Apple Developer ID)
npm run dist:linux   # Linux AppImage
```

Sign the Windows installer with your institution's code-signing certificate, so Windows SmartScreen and antivirus software trust it.

## Set up a computer

1. Install the app and open it.
2. Enter the platform address, for example `https://exams.your-institution.edu.ng`. Campus IP addresses also work.
3. In the platform, go to **Halls & devices** and approve the computer. Its ID is shown in the app.
4. Open the app again. Students sign in and see their exams.

Developers can run `EXAM_DESKTOP_DEV=1 npm start` for a normal window with developer tools.
