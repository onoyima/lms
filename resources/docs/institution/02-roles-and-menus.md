---
title: Roles, permissions and menus
section: Start here
order: 20
access: staff
---
Each person has one or more **roles**; each role carries **permissions**. Menus, buttons and pages follow the permissions — never the name of the role — and every page checks them again on the server.

## Your roles are your own

The roles below are only a **starting set**. Under **Administration → Roles & permissions** (needs the *Roles & permissions* permission) you can:

- **create any role** your institution uses ("Faculty exams coordinator", "Bursary", "Hall marshal") and tick exactly what it may do;
- **rename or change** a starting role — the change applies to everyone holding it straight away, and platform updates never overwrite it;
- **reset** a starting role to its defaults, or **delete** a role once nobody holds it.

Then give any role to any staff member under **People** — one by one, by CSV import (the `roles` column takes your role names), or automatically from **single sign-on** groups. Email addresses play no part: the demo accounts such as `exams@…` only exist in sample data.

Guard rails: nobody can give a role a permission they don't hold themselves, nobody can edit a role they hold, the institution administrator role always keeps every permission, and every change is in the audit log.

## Starting roles

| Role | What it is for |
|---|---|
| Institution administrator | Everything in the institution, including subscription and billing |
| ICT administrator | Settings, people, roles, integrations, audit log — not exams content or billing |
| Registry | People and academic structure |
| Exams officer | Exams, sittings, moderation of questions, publishing results, audit log |
| Dean / Head of department | Approving results and course reviews in their faculty or department |
| Lecturer | Their courses, writing questions, marking |
| Question setter / Moderator | Writing questions / reviewing them |
| Chief invigilator / Invigilator / Proctor | Exam-day consoles for the sittings they are assigned to |
| Student | Their courses, exams, results and requests |

## Roles from single sign-on

*Single sign-on* means people sign in with the account they already have at your institution (Microsoft 365, Google Workspace, your university login) instead of a separate password for this platform. Under **Single sign-on → Roles from your directory**, add rules such as *groups contains "Exams Office" → Exams officer*. Rules are checked at every sign-in, so people who join a group get the role at their next sign-in. You can also name the attribute that says whether someone is staff or a student (for example `eduPersonAffiliation`). Roles that control settings, people, roles, integrations or billing can only be given by hand.

**Separation of duties is built in:** nobody approves their own paper or question, decides their own request, approves a hardware exception they recorded, or verifies their own face. Results are approved by the head of department or dean, not the course lecturer.

**The platform team** (platform administrators) manage your subscription and modules but **cannot open your institution's people, papers, marks, results or courses**.
