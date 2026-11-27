---
title: Question bank and importing papers
section: Exams
order: 60
access: perm:questions.view
---
Questions are written once and reused. Objective types: **single answer**, **multiple answers** (choose all that apply), **true / false**, and **multiple true / false** (several statements, each marked true or false; each statement earns its share of the mark, or choose *all or nothing* in the exam's rules). Also matching, numeric, short answer and essay / theory.

Editing creates a new version; papers already sat keep the version candidates saw. A moderator (not the author) approves each question.

## Reading a paper with AI

Question bank › Import › **Read with AI** (once an administrator has set up AI under Settings › AI). Upload a **PDF**, **photos or scans** of a printed paper (several at once, in page order), or a **Word** file, in any layout.

- The AI reads every question with its options, the answer key when the paper shows one, and the marks. Maths and physics come out as real equations, chemistry as formulas and reactions (\ce{…}), tables as tables.
- **Diagrams**, graphs, circuits and structures are cut out of the page and placed with their question (or option).
- A long paper takes a few minutes; the review page fills in by itself. You can leave and come back from Recent imports.
- The review page shows every question **exactly as students will see it**, next to the original pages. Questions the AI was unsure about, and questions with no answer on the paper, are marked so you check them.
- Nothing is saved until you press **Save as drafts**; questions are then moderated as usual. A PDF is turned into page pictures on your own computer before it is sent.

## Importing from Word

Question bank > Import. Upload a Word file laid out like the **Questions (Word)** template:

```
1. Which equation is balanced?
A. H2 + O2 -> H2O
*B. 2H2 + O2 -> 2H2O
Topic: Stoichiometry
Mark: 1
```

- A `*` before the right option, or an `Answer: B` line (several: `Answer: A, C`).
- Multiple true / false: the statements as A, B, C…, then `Type: MTF` and `Answer: TFTT` (one letter per statement) or `Answer: A-T, B-F, C-T, D-T`.
- Word equations, chemical formulas (`\ce{...}`), tables and pictures are kept.
- You see every question before anything is saved, and can fix or drop each one.

### Papers typed in other ways

There is no need to retype a paper into the template. These are read as they are:

- **Files:** Word (.docx, and old .doc or .rtf, whose text is read; save as .docx to keep pictures and equations), PDF with typed text, Excel (.xlsx with columns Question, A, B, C, D, Answer), CSV and text.
- **Options on one line:** `A. Lagos   B. Abuja   C. Kano   D. Ibadan`, also straight after the question.
- **Loose numbering:** `1 What…`, `1: What…`, `(1) What…`, `Question 1: What…`; options `A Lagos`, `A: Lagos`, `(a) Lagos`.
- **Answers:** `Ans: (b)`, `Ans. B`, `Correct option: B`, `Answer: Abuja` (the option's text), the right option in **bold**, underlined or highlighted, or an **answer key at the end** of the paper (`ANSWERS` then `1. B  2. C …`, as lines, a numbered list or a table).
- **Tables:** one question per row with headings such as No | Question | A | B | C | D | Answer.
- Word's automatic numbering (including the *List Number* styles) is read, and the option lettering restarts at A for each question.

If a file is refused, the message says why: too large for the server (compress the pictures in Word with File › Compress Pictures, or split the paper) or a file type that cannot be read.

## Exporting

Question bank > Export (text) or Excel, for moderation meetings or archiving.

## Question banks for one exam, and uploading the old system's files

Each paper has a **Question banks** tab. Make as many banks as you like (for example one per topic); a bank belongs to that paper, to every paper in the exam category, or to the course.

**Upload questions** into a bank from:

- a text file in the old exam system's layout (questions without numbers, each ending with `ANSWER: B`; `TYPE:`, `MARK:`, `IMAGE:` and `SCORING:` lines before a question; blank line between questions) — the old system's files work unchanged, whatever program saved them;
- a spreadsheet saved as CSV (one question per row; the old system's two sheets work too, with pictures);
- Word (.docx), keeping equations and pictures;
- a typed PDF (a scan needs *Read with AI*).

Pictures named on `IMAGE:` lines or in the CSV are uploaded with the file, one by one or as a .zip. You see every question, with the line of the file it came from, before anything is saved. Templates for every format and question type are on the Question banks tab and under Help › Templates.

A bank downloads in the same layout (.txt or .csv, with its pictures in a .zip), so it can be edited and uploaded again, or moved to another exam. **Empty** removes its questions; papers already written keep their copies.
