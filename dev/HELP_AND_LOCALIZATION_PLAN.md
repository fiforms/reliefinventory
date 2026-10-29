# Help guides and localization plan

Status: **proposal, nothing built.** Needs agreement from both maintainers before any phase starts. Phases run in order:
each one makes the next cheaper, and no translation work starts until phases 1 and 2 are done.

1. Write each help guide once and generate both the screen page and the PDF from it.
2. Review and correct the English help text.
3. Add localization, starting with Spanish, with every translation tracked against the English it was made from.

Spanish was the first language requested. The app changes quickly, so the plan's main constraint is that a translation must
never be silently out of date. When English changes, the matching translation is redone at once and marked for review.

---

## Phase 1: one source per help guide

### Today

Each guide in `HelpReportController::GUIDES` exists twice:

- `resources/js/Pages/Help/<Guide>.vue`: the screen page, wrapped in `Components/HelpPage.vue`.
- `resources/views/reports/help/<slug>.blade.php`: the printable copy, rendered by WeasyPrint through `reports/help/layout.blade.php`.

`dev/notes/FRONTEND.md` says "The PDF is a hand-kept copy of the Vue page: edit both." The reason, given in
`HelpReportController`'s header comment, is that nothing in the codebase shared content between an Inertia page and a
Blade/PDF view. That was a shortcut, not a requirement. As of 2026-10-29 the copies match section for section, but every
help edit has to be made twice, and translation would make it four times.

The PDF can't be made by printing the Vue page: WeasyPrint doesn't run JavaScript, and headless Chrome was removed on
2026-10-19 (`DOCKER_PLAN.md`). Bringing Chrome back for this isn't worth its size.

### Proposal

- **Write each guide as one Markdown file,** `resources/help/en/<slug>.md`. The per-language folder leaves room for
  `resources/help/es/` later. Use plain Markdown: `##` section headings, numbered steps, bold, and links between guides
  (`[Sorting](/help/sorting)`).
- **Render it on the server with CommonMark.** `league/commonmark` is already installed through Laravel (`Str::markdown`).
  Raw HTML in the source is stripped and unsafe links are off. The guides are trusted repo files, but this keeps `v-html`
  safe if that ever changes.
- **Screen page:** the `/help/{guide}` route passes the rendered HTML to one generic `Help/Guide.vue`, which uses
  `HelpPage.vue`. Each `##` section is shown in the current card style. The 9 per-guide Vue pages are removed.
  `Help/Index.vue` stays as it is.
- **PDF:** `HelpReportController::builder` renders the same HTML into `reports/help/layout.blade.php`. "Print all guides"
  does the same, one guide per page. The 9 per-guide Blade views are removed.
- **Intro line:** the first paragraph before the first `##` becomes the `#intro` text under the title, on screen and in print.
- **Titles** stay in `GUIDES`, so the route, the print-queue entries and the `/help` list don't change.
- **Text carries over word for word.** This phase changes the plumbing only. Text edits wait for phase 2, so the PR diff can
  be checked against the old pages.
- **Docs and tests:**
  - Update `dev/notes/FRONTEND.md` ("Help guides": adding a guide becomes a `GUIDES` entry, a Markdown file and a
    `HelpLink`) and the `HelpReportController` header comment.
  - Extend `tests/Feature/HelpGuidePdfTest.php`: every `GUIDES` slug has a Markdown file, and both the page and the PDF render.

The only loss is that links between guides become normal page loads instead of Inertia visits, which doesn't matter for help pages.

---

## Phase 2: English review

Starts once phase 1 is merged, so each guide is reviewed in one place, as plain text.

- One guide at a time, as small PRs. Reviewers read the Markdown, and the PR diff shows exactly what changed.
- Check every quoted button, field and status label against the page it describes. Labels drift as screens change.
- Settle the terms the guides use, such as Partner, Donation Offer, Usable/New and pallet kinds. Phase 3's glossary
  starts from this list.
- Writing the missing guides isn't part of this phase. They're added as they're written, on the same Markdown pattern.

---

## Phase 3: localization (Spanish first)

### Approach: machine translation first, human review tracked against the English

- Every piece of English text has a key and a fingerprint (hash) of its English wording. That covers each UI string and
  each help-guide paragraph.
- When English is added or changed, the Spanish is machine-translated in the same change and marked **unreviewed**.
  Spanish users always see current text, never missing or outdated text.
- When a bilingual reviewer approves a translation, the fingerprint of the English they reviewed is recorded with it.
- If that English changes later, the fingerprints no longer match, so the translation goes back to unreviewed
  automatically. Reviewers work from a queue of only new and changed text.
- A report gives the current status, for example: "Spanish: 94% reviewed, 31 waiting, oldest 6 days." A check on each PR
  reports it without blocking the merge, so translation never holds up a release.
- Help guides are tracked by paragraph, so a one-sentence edit sends only that paragraph back for review.

Rejected: translating on the fly in the browser or through an outside service at page load. That text is never reviewed,
its terms are inconsistent, and on-screen data (names, addresses) could be sent to a third party.

### Where translations and review status live (decide before building)

- **Option A, in the repo:**
  - English and Spanish string files, plus a small review log (key → English fingerprint, reviewer, date), all in git.
  - The machine translation is generated in the same PR that changes the English, following the glossary.
  - Reviewers approve through a simple review page or a PR.
  - Nothing new to host. Recommended to start with.
- **Option B, Weblate:**
  - Open-source translation platform with free hosting for open-source projects.
  - Has review states built in ("Needs rewriting" after a source change), plus a glossary, translator accounts and
    machine-translation suggestions.
  - Its review state is kept in Weblate's database, not in git.
  - Worth it once there are several reviewers or a second language. A move from A to B later is straightforward, since
    both use the same string files.

### Steps

1. **Glossary.** Agree on Spanish terms, or keep the English word, for warehouse vocabulary: pallet, Gaylord, Partner, BOL,
   Receiving, Sorting, Donation Offer, Usable/New and so on. Approved by a bilingual reviewer before any translation.
2. **Make the code translatable, still English only.**
   - Move strings out of the Vue pages, Blade views (emails, PDFs) and validation messages into translation files, using
     Laravel's translation files shared with Vue, e.g. `laravel-vue-i18n`.
   - Write whole sentences with placeholders, never sentences built from pieces. Use real plural rules.
   - Format dates and numbers by locale.
3. **Pseudo-locale test.** A fake language with longer, accented text shows any strings that were missed and any layout that
   breaks. Spanish runs 20–30% longer than English. Phone, kiosk and label layouts matter most.
4. **Spanish on the busiest surfaces first,** likely the volunteer kiosk and the partner order form, then the rest of the app
   and the help guides.
5. **Language preference per person** (with a browser default for signed-out pages such as the kiosk and public forms),
   falling back to English. Language is not a per-warehouse setting.

### Open questions

- Which surfaces get Spanish first, and which documents stay English only? Bills of lading, signed shipping papers and
  reports to partner agencies may need to.
- Who are the bilingual reviewers, and how many are needed for the first pass?
- Translating data that people enter (item names, categories) is a separate later decision. It needs schema changes; this
  plan covers the app's own text only.
- Option A or B for review tracking.
