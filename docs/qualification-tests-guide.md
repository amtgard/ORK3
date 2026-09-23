# Qualification Tests — How to Use

The ORK can run your Kingdom's **Reeve's Test** and **Corpora Test**: you build the question
bank, players take the test on their own time, it grades itself, and a pass writes straight back
into the player's official qualifications. Every report that already reads those qualifications
keeps working — the record-keeping just does itself.

Both tests are **opt-in per Kingdom**. Nothing appears for players until your Kingdom turns the
test on *and* a version of the test has been published.

---

## Who can do what

There are three distinct jobs here, and they need three different levels of access. This trips
people up, so it's worth being precise.

| Job | Who can do it |
|---|---|
| **Turn the test on** for the Kingdom | Anyone who can open the Kingdom page's **Admin** panel — in practice the monarchy (Monarch, Regent, Prime Minister) |
| **Run the test** — settings, questions, versions, publishing | Monarch, Regent, Prime Minister, **GMR**, anyone with Kingdom edit authority, and any **Test Manager** appointed below |
| **Take the test** | Any logged-in player, once the Kingdom has turned it on and published a version |

**The GMR can run the tests but cannot turn them on.** The Corpora makes the GMR the test
administrator, so they have full control over settings, questions and versions — but the on/off
switch lives behind the Kingdom page's **Admin** button, which needs Kingdom management authority
the GMR is not automatically granted. The button isn't merely disabled for them; it isn't shown at
all. In practice this means the GMR builds the test and the monarchy flips the switch. Either order
works (see below); they just need to talk to each other once.

**Test Managers** let you hand the job to a subject-matter expert without making them an officer.
On the **Configure Tests** page, add a player as a Test Manager and they get full test-management
access to that Kingdom's tests — and no other Kingdom powers.

---

## Where everything lives

Two different places on the Kingdom page, and they are easy to confuse:

| What | Where |
|---|---|
| **Configure Tests**, **Reeve's / Corpora Test Questions**, **Reeve's / Corpora Test Results** | Kingdom page → **Admin Tasks** tab → **Tests** |
| The **on/off switch** for each test | Kingdom page → **Admin** button (cog, top of the page) → **Configuration** |

The **Test Results** reports — who passed, who failed, and what each player answered — live under
**Admin Tasks → Tests** with everything else, not in the Reports tab. A results link only appears
once that test is switched on.

GMRs and Test Managers see the **Admin Tasks → Tests** group. Only the monarchy sees the **Admin**
button.

---

## Getting started: your first test

### 1. Turn the test on (monarchy)

Kingdom page → **Admin** (cog, top of the page) → **Configuration** → set **Reeve's Test** and/or
**Corpora Test** to **Yes** → **Save Configuration**.

This switch alone does *not* make a test takeable. It only means "this Kingdom participates."
Players still can't take anything until a version is published — so it is perfectly safe to turn
it on before the GMR has written a single question. Nothing leaks out early.

### 2. Save your settings (GMR / test manager)

**Admin Tasks → Tests → Configure Tests** → set the options for each test → **Save Settings**.

Do this even if the defaults look right. Until you press Save, **nothing is stored** — the values
you see are just defaults, and the page will tell you so with a "Never saved" banner. This matters
most for question sharing, which is off until you explicitly opt in.

### 3. Write your questions

**Admin Tasks → Tests → Reeve's Test Questions** (or **Corpora Test Questions**) → add questions.
You can:

- **Add them one at a time**, single-answer or "select all that apply".
- **Bulk import** a whole batch pasted as text.
- **Add from Library** — pull ready-made questions other Kingdoms have shared (Reeve's Test only;
  see [Sharing](#sharing-reeve-questions-between-kingdoms) below).

The moment you save your first question, the ORK creates your **first version** for you, as a
**draft**. Nothing is live yet.

### 4. Set the rules / Corpora version

On the draft, fill in the **Rules / Corpora version** box — e.g. `V8.7.0.260102.2256` for the
Reeve's Test, or `Corpora 5.1 2026` for the Corpora Test.

This is **required before you can publish**. It is stamped onto every attempt, so years from now
you can still tell which edition of the rules a player was tested on.

### 5. Publish

Press **Publish**. The test goes live immediately (assuming the Kingdom switch from step 1 is on).

**Publish stays disabled until the version is actually ready.** Hover it and it will tell you what
is missing. You need all three:

- a rules/Corpora version,
- at least as many active questions as **Questions per test** (a version with fewer cannot draw a
  test at all — players would just get an error),
- every question answerable: at least 2 answers, at least 1 marked correct.

That's it. Players will now see the test on their profile.

---

## What the settings mean

### Scoring

**Questions per test** — how many questions each player is asked. They're drawn at random from the
published version, so every player gets a different mix. Your bank should be comfortably larger
than this number.

**Pass % required** — the score needed to pass. A pass writes the qualification to the player's
record; a fail does not, but is still kept (see *Retakes* below).

**Validity** — how long a pass lasts. Two modes:

- **Days from passing** — a rolling window, e.g. 365 days from the day they passed.
- **Until date** — everyone's qualification expires on the same fixed date, typically an officer
  changeover.

> If you use **Until date** to line up with a changeover, give yourself **1–2 weeks of slack**.
> Expiring everyone the instant the new GMR takes office leaves them with a Kingdom of unqualified
> reeves and no test configured yet.

**Max retakes** — how many attempts a player gets. **0 means unlimited.** If a player hits the
limit they're blocked and told to contact the monarchy; you can reset the counter for a single
player from their profile, or for the whole Kingdom at once from Configure Tests (handy after you
rewrite a batch of questions).

### Player experience

**Display correct answer on incorrect** — when on, a player who answers wrong sees the correct
answer highlighted. When off, they only see that they were wrong. This setting also governs what
they can see later when reviewing a past attempt — turn it off and the review won't reveal the
answers either.

**Instructions** — free text shown as the first card before the test begins. Line breaks are
preserved. A good place for "you may consult the rulebook" or "contact your GMR with questions."

### Rules of Play version / Based on

**Read-only.** It shows the label from the currently live version, because that is where the
version label lives now — you set it on the version itself, and publishing requires it.

It's shown to players as a footer on every test card ("Based on Amtgard Rules of Play Version …"),
and stamped permanently onto every attempt.

---

## Versions: changing the test without breaking the running one

The rules change. The Corpora changes. New GMRs want to rewrite the bank. Versions let you do all
of that **without disturbing the test players are currently taking**.

A version is a named set of questions. At any moment your Kingdom has:

- **one published version** — the live test. This, and only this, is what players are asked.
- **at most one draft** — the next version, being built. Invisible to players.
- **any number of retired versions** — everything you've run before, kept forever.

### Building the next version

On the questions page, press **Start next version**. This clones the live version's questions into
a new draft — carried-over questions are *not* duplicated; both versions simply point at the same
question, so its history and stats stay intact.

While a draft exists:

- **New questions go into the draft**, not the live test. Adding, bulk import, and library imports
  all land there.
- **The live test keeps running, unchanged**, until you press Publish.
- **Removing a question from the draft does not archive it** — it stays in the live version until
  the draft is published.

One caution: **editing a question edits it everywhere.** Questions are shared between versions by
reference, so if you edit a question that's also in the live test, the live test changes
immediately. The question form warns you when this is the case.

### Publishing

Publishing swaps the draft in as the live test and retires the outgoing one. The same three guards
from step 5 apply. Players start being asked the new questions right away.

### Naming versions

Versions are numbered for you — "Version 1", "Version 2" — but you can rename any of them with the
pencil icon. Give them names that will still mean something later: *"Autumn 2026"*, *"Corpora 5.1
rewrite"*. The name is stamped onto every attempt and shown in players' test history for good.

### Looking back at old versions

**Previous versions** (collapsed, under the current one) lists every version you've ever run, with
its rules/Corpora label, question count, and the date it went live. Click **View** to read the
whole thing back.

Two records exist, and they answer different questions:

- **A retired version** tells you *what that version contained*. Because questions are shared by
  reference, it shows each question **as it reads today** — if someone edited it since, you see the
  edit.
- **A player's attempt** tells you *exactly what that player was asked*. The question and answer
  text are snapshotted at the moment they sat it, so it stays truthful forever, no matter what is
  edited or archived afterwards.

Questions are never deleted, only **archived**. A player reviewing an old attempt still sees the
full question, badged as archived.

---

## Sharing Reeve questions between Kingdoms

There is a cross-Kingdom **Global Question Library** for the **Reeve's Test only**. The Corpora
Test is Kingdom-specific by nature, so it isn't shared.

### Opting in

**Admin Tasks → Tests → Configure Tests → Reeve's Test → Opt-in to share questions → Save
Settings.**

This is a single switch with two effects, and they're deliberately tied together: you share your
questions, and you get access to everyone else's. There is no browse-without-contributing.

Sharing is **explicit** — a Kingdom that has never saved its settings is *not* opted in. Press Save.

### What actually gets shared

Only questions that are **active** and **in your published version**. A draft you're still working
on is never exposed — your next test can't leak to other Kingdoms before you've run it.

### Importing

**Add from Library** on the Reeve's questions page. Browse or search, then **Add**. You get your
own independent **copy** — edit it, reword it, archive it, and the originating Kingdom is
unaffected. The copy lands in whichever version you're working in (the draft, if you have one).

Questions you already have are hidden from the library, so you won't import the same thing twice —
this holds **even if you've reworded your copy**, because the ORK remembers where each import came
from. If the library looks empty, it will tell you which it is: nobody has shared anything yet, or
you already have everything that's shared.

The library never shows which answer is correct.

### Flagged questions

Players taking a test can flag a question as unclear, incorrect, outdated, or other. Managers see
the count on each question and can read the reasons, then fix or clear them.

Flags follow questions into the library: heavily-reported questions are marked and sorted to the
bottom, so you can steer clear of them when importing.

---

## Glossary of the badges you'll see

| Badge | Meaning |
|---|---|
| **LIVE** | Players can be asked this right now. Requires a published version with enough questions **and** the Kingdom switch on. |
| **Published (test off)** | The version is ready, but the Kingdom hasn't switched the test on. Nobody can take it. |
| **NOT LIVE** | Nothing is takeable — nothing published yet, or the live version has too few questions. |
| **Draft** | The next version, being built. Invisible to players. |
| **Live** *(on a question)* | This question is in the published version — players are being asked it. |
| **Draft** *(on a question)* | In the draft only. |
| **Unused** | In no version at all — written, but not part of any test. |
| **Archived** | Retired from use. Still shown in past attempts, never deleted. |

---

## Troubleshooting

**"It says NOT LIVE but I have questions."**
Either nothing is published (check for a draft awaiting Publish), or the live version has fewer
active questions than **Questions per test**. A version that can't fill a test can't be taken at
all.

**"Players get 'Not enough active questions available'."**
Same cause. Add questions until the live version has at least **Questions per test**.

**"I published, but players still can't see it."**
The Kingdom switch is off. That's the **Admin → Configuration** panel, and it needs the monarchy —
the GMR cannot flip it, and won't even see the Admin button.

**"The library says nothing is available."**
Either no other Kingdom has opted in and published yet, or you have already imported everything
that's shared. The modal will say which.

**"I'm the GMR and I can't find the settings."**
Kingdom page → **Admin Tasks** tab → **Tests** → **Configure Tests**. (Not the **Admin** button —
that's the monarchy's, and you won't see it.) If the Admin Tasks tab has no **Tests** group at all,
you're not recognised as GMR for that Kingdom in the ORK's officer records — ask the monarchy to
check, or to add you as a Test Manager.
