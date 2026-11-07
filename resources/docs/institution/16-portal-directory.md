---
title: University portal: records and sign-in
section: Settings
order: 115
access: perm:integrations.manage
---
Institution settings > Integrations > **University portal: records and sign-in**.

Your university portal stays the place where people are admitted, appointed, registered and suspended. The platform reads from it through a small **directory bridge** installed beside the portal, so you never type people or registrations in twice.

## What comes across

| From the portal | Becomes on the platform |
|---|---|
| Faculties, departments, programmes of study, courses | Academic structure (the same course code is one course, even if the portal lists it under several programmes) |
| The semester marked as running | The current semester (the one most students are registered in) |
| Active staff (no ended appointment) | Staff accounts, with department, title and position |
| Active students (normal studentship, good standing or probation) | Student accounts with programme and level |
| Applicants of the current admission session (optional) | Applicant accounts that can only sit the screening exams they are added to |
| Registered courses in the running semester (not deleted or voided) | Enrolments: core, elective or carry-over |
| Courses assigned to lecturers in the running semester | Lecturers and course coordinators on each course |
| Functional roles (HOD, Dean, Exam Officer, …) | Your own roles, through the rules under Single sign-on |

It runs **every hour** and whenever you press **Sync now**. Each run is listed with what changed and any rows that could not be saved.

## Signing in with portal details

Tick **People sign in on the normal sign-in page with their portal ID and password**. Nothing changes on the sign-in page — there is no extra button. Staff type their staff number or e-mail, students their matric number, registration number or e-mail, applicants the e-mail they applied with, and their **portal password**. The portal checks the password; the platform never stores it. Someone new is brought in on the spot (they do not have to wait for the hourly sync); someone the portal no longer lists as active is told why they cannot sign in. Local accounts on this platform (such as administrators) keep signing in with their own password, which is never sent to the portal.

## Roles follow the portal

Under **Single sign-on > Roles from your directory**, rules like *groups contains "HOD" → Head of department* decide which of your roles each portal role gives. Roles named in these rules follow the portal: given when the portal says so, taken away when it no longer does. Roles with institution-wide power (settings, people, roles, integrations, billing) are only ever given by hand. Anyone who teaches a course in the portal can teach it here.

## When someone leaves

Deferred, suspended, withdrawn or graduated students, staff whose appointment ended, and last session's applicants are **switched off** (never deleted); they are switched back on if the portal makes them active again. Someone you suspended by hand on the platform stays suspended until you lift it. Registrations and teaching the portal drops are marked dropped / removed; ones you added by hand on the platform are left alone.

If one sync would switch off an unusual number of people at once (for example the portal database is half-restored), nothing is switched off and you are asked to check; **Sync and switch off anyway** confirms it.

## Setting it up

1. Your ICT team installs the bridge beside the portal database (see the operations guide, "Portal bridge").
2. They give you the bridge address, a client ID and a client secret, and register this return address with the bridge: shown on the settings card.
3. Fill in the card, tick what you want, **Save**, then **Test connection**: it shows the running semester and how many active students and staff the portal has.
4. Switch it on and press **Sync now**.
