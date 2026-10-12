# Surveys — How to Use

The ORK can build and run surveys of its own players. Kingdom and park officers put a survey
together from a set of question types. Players answer it from **My Amtgard** (or a share link).
Results come back as charts, a table of responses and a downloadable spreadsheet, all without
leaving the ORK and without asking anyone to type their kingdom or how long they've played.

---

## Who can do what

| Job | Who can do it |
|---|---|
| **Create a survey** | An ORK admin (any scope, including ORK-wide), a kingdom officer with CREATE or ADMIN authority (for their kingdom and its principalities), a park officer with CREATE or ADMIN authority (for their park) |
| **Manage a survey** — build, open/close, see results, export | Anyone with CREATE or ADMIN authority over that survey's kingdom or park. That includes the kingdom's officers for a park or principality survey, ORK admins, and whoever holds those offices in future reigns |
| **Answer a survey** | Any logged-in player the survey's audience settings let in |

An officer with **edit-only** authority cannot create or manage surveys; only CREATE and ADMIN
authority reach the survey tool. There is no separate "survey manager" role.

---

## Building a survey

Open **Surveys** from your kingdom or park's Admin Tasks tab (or the Admin panel, for an ORK-wide
survey), then **+ New Survey**. The Surveys list is a searchable, sortable table. A new survey
starts as a single-page **draft**, and players can't see it until you open it.

The builder has two parts:

- **The canvas** (centre) shows your pages as a player will see them. Click a question to edit it
  in place: type the question, click an option to rename it, use **Add option** or
  **add "Other"**, and use the card's toolbar to change its type, make it required, duplicate or
  delete it. The **⋯** menu holds skip logic, shuffling and answer limits. **+ Add Element** adds a
  question below the last one on a page.
- **Survey settings** (left) are collapsible sections: Basics, Screens (welcome and thank-you),
  Audience, Schedule, Privacy (the data gate), Promotion (site banner and share link) and
  Experience (progress bar, resume, accent colour).

Everything saves as you go. The pill in the header says **Saved**, **Saving…** or **Not saved**
(a field was left blank where it can't be, or the change was refused; fix the field and it saves). If
you ever see *"Your security token expired. Reload the page and try again."*, reload the page.
It means your login session changed, and it protects your survey from forged changes.

**Images.** Each image can be up to 2 MB (JPEG or PNG); large pictures are shrunk to 1600 pixels
on the longest side. A survey can hold up to 40 images and 40 MB in total. When you reach either
limit, the ORK first clears out images that no question, screen or text uses any more (anything
uploaded in the last hour is kept, in case you're still placing it). If the survey is still full,
the upload is refused and the message says which limit you hit.

### Question types

| Type | What it collects |
|---|---|
| Multiple choice, Dropdown, Yes / No | One pick from a list |
| Checkboxes | Several picks, with an optional minimum/maximum |
| Rating | A 1–5 (or custom range) star or number scale |
| NPS | The standard 0–10 "how likely are you to recommend…" scale |
| Matrix | A grid: several rows, each answered against the same set of columns |
| Ranking | Put a list of options in order. Shuffled for each player by default, so nobody is nudged toward the order you typed. A list the player never moved isn't counted as an answer; on a required ranking they can press **Keep this order** |
| Pairwise | Two options at a time: the player picks one or calls a tie. Results rank every option by how often it wins. See **Pairwise questions** below |
| Short text / Paragraph | Free text, one line or several |
| Number | A numeric answer, with optional min/max/step/unit |
| Date | A calendar date |
| Section | A heading with no question, to break up a long page |
| Image | A picture with an optional caption, and no question |

If you turn on **Shuffle the options for each respondent**, an "Other (please specify)" option
always stays at the bottom.

Any Multiple choice, Dropdown, Yes / No or Checkboxes question can drive **skip logic**: a later
question or page can be set to show only when a chosen option was picked. Skip logic is one level
deep, so a question that depends on another can't itself be a dependency.

### Pairwise questions

Pairwise asks "this or that?" over and over, instead of making anyone sort a long list at once. Type or
paste the options into the box, one per line. The (?) beside the box explains everything below with
your question's own numbers.

- **Scoring.** A win is 1 point, a tie is ½ to each option, a loss is 0. An option's **win %** is its
  points divided by the matchups it appeared in, and results rank options by win %.
- **Random for everyone.** Each player gets their own random order of matchups, with sides picked at
  random.
- **How many matchups.** N options make N × (N − 1) ÷ 2 matchups: 10 options is 45, 30 options is 435.
  Players aren't asked to do them all. A progress bar shows how far they've got and cheers them on at
  four marks:

| Matchups | Can continue | Even better | Awesome | Fantastic |
|---|---|---|---|---|
| 30 or fewer | every matchup, if required (a plain bar) | | | |
| 31–105 | 30% | 40% | 50% | 60% |
| 106–200 | 20% | 30% | 40% | 50% |
| 201–300 | 10% | 20% | 30% | 40% |
| 301 or more | 10% | 15% | 20% | 25% |

- **Required or optional.** A required pairwise question won't let the player move on until they reach
  "Can continue". An optional one can be skipped, and whatever matchups the player did still count.
- **Keep it short.** Over 30 options the builder warns you. It still works, but each player covers a
  small slice of the matchups, so you need more players for the ranking to settle.
- **Results** show the possible matchups, the average share of matchups each player did, and every
  option's win % as a chart and a sortable table. The CSV lists each player's matchups, winner first.

### Structure lock

Once a survey has been **opened**, its structure is frozen. You can no longer add, delete, retype
or reorder questions, options or pages, so every response answers the same survey. Wording stays
editable: prompts, help text, option labels, welcome and thank-you screens, audience, schedule and
the banner setting can all be changed after opening.

A survey can't be opened until it has at least one question that records an answer (a Section or
Image alone won't do) and every question's settings are valid.

**Clone** copies a survey's full definition, images included, into a new draft. The copy starts
with no open/close dates, no event audience and the banner off, so set its schedule before opening
it. **Delete** (a row action on the survey list) only works on a draft that has never collected a
response. Once responses exist, close or archive the survey instead.

---

## Who can answer: audience and schedule

Set these in the **Audience** and **Schedule** sections:

- **Scope.** A park survey reaches players whose home park is that park. A kingdom survey
  reaches the kingdom and its principalities. An ORK-wide survey can be limited to a list of
  **Kingdoms**; select none to invite every kingdom.
- **Exclude retired accounts.** Leaves out accounts marked retired. It does *not* check whether
  someone still plays; use the next option for that.
- **Attended in the last … months.** Only players with a sign-in at your park (for a park
  survey), in your kingdom or its principalities (for a kingdom survey), or anywhere (for an
  ORK-wide survey) within that many months, up to 120. Leave it at 0 to turn it off.
- **Attended an event.** Pick one of your park's or kingdom's published events (the list shows
  events from the past 12 months and the next 6). Anyone signed in at that event can answer,
  **including visitors** from other parks and kingdoms. This replaces the home park or kingdom
  rule, so it suits Coronation or event feedback surveys. You can also set *Attended in the last
  … months*: the sign-in at the event itself counts as a recent sign-in in your kingdom (and
  usually your park), so visitors still qualify as long as the event falls inside that window.
  Make the window long enough to reach back to the event. On a park survey, a visitor whose
  event credit was recorded against their own park won't pass the months rule, so leave it at 0
  if you want every attendee.
- **Minimum months played.** Players who have been playing at least this many months, counted
  from their first sign-in or the "playing since" date on their profile.
- **Opens and Closes.** A future opening date does not open the survey by itself; you still
  press **Open**. A past closing date closes an open survey automatically. Players see the
  closing date on the survey's first screen.

A player the survey can't reach is told why when they open it, for example *"This survey is open
to players who attended the event it asks about."* or *"This survey is open to players who have
attended recently."* A player from outside your park or kingdom (who didn't attend the event, on
an event survey) sees *"Survey not found."* instead, so the survey isn't advertised to people it
was never meant for.

A player can never answer the same survey twice. When they finish, the ORK keeps a note that
"this player finished this survey". That note has no date and no link to their answers.

---

## Surveys from other levels

The Surveys list is organized by level: **Amtgard**, your **Kingdom**, and your **Park** each get
their own table. A kingdom officer sees every Amtgard-wide survey that reaches the kingdom, every
survey the kingdom or its principalities run, and every survey any park in the kingdom runs. A
park officer sees Amtgard-wide surveys that reach them, kingdom surveys that reach their park, and
their own park's surveys.

Surveys you didn't create show up alongside your own, with fewer actions: no Build, Preview,
Clone, or Archive, since you don't own the structure. You can still **Take** an open one, and
you'll see **Results** on it when its owner has shared them with you (see the next section) or
**Credits** when it offers an attendance credit. A shared row's response count shows as **—**
unless the owner shared results with everyone, since you can't see counts you weren't given
access to.

---

## Sharing results with kingdoms and parks

An Amtgard-wide survey's owner, or a kingdom survey's owner, can share its results one level down
from the survey's own **Privacy** section: **Don't share**, **each kingdom (or park) sees only its
own players**, or **everyone sees everyone's results**. A park survey has no one to share with, so
this option doesn't appear for one. Sharing is one level only — a park never gets results shared
down from an Amtgard-wide survey.

You also choose **when** the shared levels see results:

- **Ongoing** — as results come in, while the survey is still open.
- **After close** (the default) — 24 hours after the survey ends. A survey ends when you close it
  or when its closing date passes, whichever comes first. If you reopen it, shared results are
  hidden again until it ends again.

Until then, a shared kingdom or park sees a clock where its Results button would be, saying when
results open. Your own results are always live.

Shared results always show charts and stats, never the responses table, the individual-response
view, or exported spreadsheets, and the include-test-responses option is turned off for a shared
viewer. Nobody's name ever appears to a shared viewer, no matter what a respondent chose.

What counts toward a kingdom's or park's own view depends on what a respondent chose:

- A **kingdom's** shared view counts respondents who chose **Any ORK Data** and are in that
  kingdom, plus respondents who chose **My Kingdom and How Long I've Been Playing** and named that
  kingdom. Anonymous responses can't be attributed to a kingdom, so they're left out.
- A **park's** shared view counts respondents who chose **Any ORK Data** and whose home park at the
  time was that park. Only Any ORK Data records a park, so that's the only choice a park's view
  can count.

The usual "fewer than 5 responses" protection still applies to a shared view — a chart, or a group
within it, that would show fewer than 5 people is withheld the same way it is for the survey's own
managers.

---

## Attendance credits

A survey can post an automatic attendance credit to everyone who answers it and chooses to link
their answers to their profile.

**Who can turn it on.** The survey's own officers, and officers of any kingdom or park the survey
reaches — for an Amtgard-wide survey, any kingdom or park in its audience; for a kingdom survey,
the kingdom, its principalities, or any park in them; for a park survey, that park only. Each
eligible org can turn on its own credit from the **Credits** action on the survey list, or the
survey's own officers from the **Attendance credit** card in the builder.

**The two modes.**

- **At the player's home park, on the day they took the survey.** Credits use each respondent's
  home park at the time they answered and the date they submitted.
- **At a new event**, created automatically and named after the survey, on the day the survey
  first opened (or the day it opens, if it hasn't yet). Credits use that event's date and park.

**Only Any ORK Data earns a credit.** A respondent who chooses My Kingdom and How Long I've Been
Playing or Anonymous Only is never credited, because there's no profile to attach it to. The
consent screen always tells them before they choose: *"This survey gives an attendance credit…"*
when a credit covers them, or *"This survey may later give an attendance credit…"* when none does
yet. A credit is public, so only respondents who were shown one of those lines are ever credited.

**One credit per player per survey.** If more than one org turns credits on for the same survey —
say, a kingdom and one of its parks — a player only ever gets one credit for it. Whichever org
turned its credit on first covers that player, even if a later org's credit would have covered
them too.

**It can't be turned off.** Once an org turns its credit on, that's permanent: no disabling it,
no switching modes, no deleting the config. It isn't retroactive to anyone the survey already
credited under an earlier config, either.

**Credits post automatically**, both for people who already answered when the credit turns on and
for everyone who answers afterward. The one exception: someone who chose Any ORK Data before the
consent screen said anything about credits never gets one, and the Credits panel tells you how
many that is before you turn a credit on. An individual credit, once posted, is an ordinary attendance
entry — an officer can edit or delete it the same way they would any other attendance credit.

**Known effects to keep in mind:**

- A survey credit still only counts as one attendance for the day, the same as any other
  attendance entry — it doesn't stack with a park sign-in on the same day.
- Survey credits count toward a player's park attendance the same as any other credit.
- A survey credit is public on the player's attendance record, the same as any other credit —
  it isn't hidden the way the survey's own answers are.

---

## The data gate: what players choose, and who sees what

Every survey ends with a **data gate** unless you turn it off under Privacy. Its wording is fixed.
You can't edit it, so every player in every survey is promised the same thing.

On the first screen, players are told the choice is coming:

> At the end you'll choose whether your answers are linked to your profile, kept to your kingdom
> and years played, or fully anonymous.

On the last screen they choose:

> **Help us understand these results**
>
> Your answers are recorded either way. Choose what the ORK may attach to them:
> - **Any ORK Data** — Link my answers to my ORK profile. The *(every org that can manage the
>   survey: the park, its kingdom, and any parent kingdom)* officers and ORK administrators who run
>   this survey, now and in future reigns, will see my name beside my answers, including in
>   exported spreadsheets.
> - **My Kingdom and How Long I've Been Playing** — Record only my kingdom and a years-played
>   range, such as 3–5 years. No name, no profile link.
> - **Anonymous Only** — Record nothing about me.

On a park survey that reads, for example, *"The Rivermoor officers, the Kingdom of the Wetlands
officers, and ORK administrators…"*: kingdom officers can manage every park survey in their kingdom,
and a parent kingdom's officers can manage its principality's surveys, so the copy names them too.

On an ORK-wide survey the first option says *"The ORK administrators who run this survey, now and
in future administrations, will see my name…"*.

### What each choice stores

| | Any ORK Data | My Kingdom and How Long I've Been Playing | Anonymous Only |
|---|---|---|---|
| Name and profile link | Yes | No | No |
| Kingdom | Yes | Yes | No |
| How long they've played | Exact years | A range: Under 1 year, 1–2, 3–5, 6–10 or Over 10 years | No |
| When they submitted | Date and time | Date only | Date only |
| How long the survey took them | Yes | No | No |

**Who sees it:** the survey's managers (see *Who can do what* above), now and in future reigns;
for an ORK-wide survey, the ORK administrators. Nobody else, and never other players. The
kingdom and years-played range of a *My Kingdom and How Long I've Been Playing* response are
further protected by the small-group rule below. Answers linked with **Any ORK Data** show the player's name
in the results table, the individual-response view and the exported spreadsheet, so treat those
views as confidential.

If you turn the data gate off, **every** response is stored as Anonymous Only; there is no
"always link" setting. The one exception: **Submit as test** (your own trial run from the builder)
is always stored linked, and test responses are left out of results unless you tick *Include test
responses*.

---

## Reading the results

**Results** shows, for each survey:

- **Headline numbers.** Responses, **response rate** (how many of the players your audience
  settings let in *today* have answered), **completion** (how many of the players who opened the
  survey finished it), the median time to finish (Any ORK Data responses only, since the other
  choices don't keep it), and the split between the three data gate choices.
- **One chart per question**, shaped to its type. Each card says how many answered out of how
  many were shown the question ("n = 42 of 60"), which matters for optional and skip-logic
  questions. Percentages are of those who answered.
- **Filters:** kingdom (only kingdoms that actually appear in the responses, with counts; the
  filter is hidden when there's only one), consent level (the data gate choice), date range, test
  responses, and one **cross-tab** question (a Multiple choice, Dropdown or Yes / No question) that
  splits every chart by that question's answer. Press **Apply** to use them. The filters you apply are kept in the page address, so a
  reload keeps them and you can send the link to a co-officer. They still need survey-manager
  access to open it.
- **Responses table:** one row per response, in a shuffled order rather than the order people
  answered, so nobody can work out who wrote what from where it sits. Responses that didn't choose
  Any ORK Data show the day they were submitted but not the time. Click a row to read that whole
  response as question-and-answer pairs, and step through with Prev/Next.
- **Export CSV** downloads exactly the rows your filters show, with the same protections.
- **Analysis CSV** and **Codebook** are for statistics tools: the same rows, one coded column per answer (a 0/1 column per multi-select option, one per matrix row, ranking position, pairwise wins), `-99` where skip logic never showed the question and blank where it was skipped; the codebook explains every column code and option value.

### Why a number shows "—"

Completion and response rate show **—** when they can't be measured honestly:

- **A kingdom, consent level or date filter is applied.** The ORK knows who *opened* a survey,
  but not their kingdom, choice or date, so there's nothing to divide a filtered count by. (Test
  responses and the cross-tab don't count as filters here.)
- **The survey collected responses before the ORK started counting openings.** Completion would
  come out over 100%.
- **Nobody has opened it yet**, or no one currently fits the audience.

### Why some results say "Too few responses to show"

A chart about three people isn't anonymous: anyone who knows who answered can work out what each
of them said. So the results page won't show a group smaller than **5**:

- If your filters leave **fewer than 5 responses**, every chart says *Too few responses to show*.
  Widen the date range or remove a filter.
- In a **cross-tab**, any group with 1–4 answers gets no bar, is marked "(fewer than 5)",
  and listed under *Too few responses to show* in the note below the chart. (A group nobody
  answered is marked "(no responses)" instead; that isn't hiding anything.) Because the
  question's overall chart sits right beside the groups, a hidden group could be worked out by
  subtracting the visible groups from it. So while the hidden groups add up to fewer than 5
  answers, the page **also hides the smallest remaining group**, and keeps going until they reach 5.
  A single small group therefore always takes at least one other group with it.
- For **My Kingdom and How Long I've Been Playing** responses, the kingdom and years-played range
  only appear when at least 5 such responses share them. Otherwise they're withheld ("Withheld
  (small group)" in the spreadsheet). Sometimes a larger group is withheld too, so a hidden one
  can't be worked out by elimination.
- Filtering by kingdom leaves out that kingdom's *My Kingdom and How Long I've Been Playing*
  responses when there are fewer than 5 of them, so the filter can't reveal them either.

Filtering by kingdom always leaves out **Anonymous Only** responses, which carry no kingdom. The
page tells you how many were left out, so the numbers don't look like they've simply gone missing.

---

## Sharing results at court

Press **Summary for sharing** at the top of the results page, then **Print**:

- The page switches to a summary with the **charts only**. The responses table and the
  individual-response view are left off, and written comments are replaced by a count ("12
  written comments"; "3 “Other” answers written in"). Press the button again to go back.
- A caption at the top states the filters in use, the total number of responses, the data gate
  split and the date, so a filtered slice can't be passed off as the whole kingdom's view.
- The same *Too few responses to show* protection applies.
- The page address changes to include the summary, so you can send that link to a co-officer and
  it opens straight into the summary view.

Printing without Summary for sharing prints everything on the page, names included.

Keep the responses table, the individual-response view and exported spreadsheets off shared
screens and out of group chats. They can carry players' names beside their answers, and
players were told only the officers of every level that manages the survey and ORK
administrators would see them. If you want
to quote a written comment, read it first and make sure nothing in it identifies the writer.

---

## Clearing results

Survey managers see a **Clear Results** button at the top of the results page. Officers who can
only see shared results don't get it. It permanently deletes **every** response, test and real,
along with their answers, the record of who has completed the survey, who has started it, and
any answers still in progress. Everyone can then take the survey again.

- A confirmation window says how many responses will be deleted. The **Clear results** button
  stays greyed out for a 5-second countdown before you can press it.
- This can't be undone. Export the spreadsheet first if you might need the answers later.
- **Attendance credits already posted stay in place.** A player who retakes the survey doesn't
  earn a second credit.
- The questions stay locked if the survey has been opened before, and the survey keeps its open
  or closed status.
- The clear is recorded in the activity log with the number of responses deleted.

---

## What the ORK records about managing a survey

Each survey keeps an **activity log** of who did what: creating, editing (grouped, not one entry
per keystroke), opening and closing, cloning, deleting, clearing results, viewing the responses table, and exporting
the spreadsheet, along with the filters used. The responses table loads whenever you open the
results page, so an ordinary visit is recorded; opening straight into Summary for sharing is not.
Viewing only Anonymous Only responses isn't logged either, because that view carries no names.
The log is kept even if the survey is deleted. There's no screen for it yet; an ORK administrator
can read it for you.
