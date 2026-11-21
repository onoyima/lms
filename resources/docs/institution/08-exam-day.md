---
title: Running exam day
section: Exams
order: 80
access: perm:exams.manage|perm:exams.proctor|perm:exams.invigilate
---
## Before the sitting

- Check **System health > Exam-hall network**: every switch, access point and the internet link should be *up*, and the hall's approved computers online.
- Make sure the exam-day contacts in Policy are right for the campus.

## During the sitting

Proctoring > open the sitting's console. You see each candidate's status, answers saved, flags, and (if the exam uses them) live camera, desk camera, phone and screen, each with its **bitrate and latency**.

- **Who to call** and **what to do with a flag** are at the top of the console.
- A flag is a reason to look, never a finding. Dismiss harmless ones with a short reason.
- Message or warn a candidate from the console. Pausing a candidate, or the whole sitting (*Emergency pause*), needs the chief invigilator or exams officer.
- Power or network failure: record a *technical* incident. Answers are kept; time lost is restored (see below).

## Live batches

For large sittings, split the candidates into **batches** (Batch size on the console: 20, 30, 50, 60, 100, 200…). Each batch has its own live-video room, so a supervisor watches one batch at a time and the media server only carries the batches being watched. Switch batches with one click.

- **Give batches to supervisors** (exams office or chief invigilator): tick the batches each supervisor watches. A supervisor with batches sees only those; the chief invigilator and the exams office can open any batch.
- **Recording does not depend on watching.** Every candidate's camera (and screen, where the exam asks) is recorded by their own browser and uploaded in pieces, whether or not their batch is on anyone's screen.
- Candidates added later join the last batch. Re-batching during a sitting moves candidates' live video to their new room without interrupting them.

## AI alerts

Face, looking-away, noise and covered-camera alerts are confirmed over several frames and seconds on the candidate's computer before they are reported, and the picture is kept. They never count as warning strikes, so they cannot lock or submit an exam; they open a review item for a person.

## When the internet or the power goes

- **Internet lost:** the candidate sees a red notice and keeps writing. Every answer is saved on their computer (twice, so even a crash keeps it) and sent automatically, in order, when the connection returns. Their clock keeps running, because they are still writing. If they reload the page or restart the browser while offline, the exam reopens from the computer.
- **Computer switched off** (power cut, crash, flat battery): when they come back to the exam on the same computer, their answers are still there and the time the computer was off is given back, up to the exam's limit (30 minutes by default). The console shows it as *device off* with the minutes given. Closing the page on purpose gives nothing back.
- **Time runs out while offline:** nothing more can be written. The answers already written stay on the computer and are accepted when they arrive, because each carries the moment it was written; anything written after the end is refused. The server does not close an attempt it lost contact with until the *wait for a disconnected computer* time has passed (120 minutes by default), so the answers have time to arrive.
- **Moved to another computer:** answers still on the old computer fill only questions not yet answered on the new one; nothing newer is overwritten.

## After the sitting

Open incidents are reviewed by someone who was not that candidate's proctor. Recordings play side by side on one timeline with the flags marked. The written procedure is in the help centre: *Exam flags, false positives, appeals and accessibility exceptions*.

## Camera and screen recordings

When an exam records the camera or the screen, the candidate is told before starting. Without a media server the recording is made in the candidate's browser and sent in pieces every few seconds; pieces made while the network is down are kept on the computer and sent when it returns, or after the exam. Open a candidate in **Proctoring** to play camera and screen side by side, with warnings marked on the time line ("Play so far" works while the exam is still running). If pieces never arrived, the list says how many are missing. Recordings are encrypted, every viewing is recorded in the audit trail, and they are deleted with other evidence when your retention period ends (not while an incident on that attempt is open).
