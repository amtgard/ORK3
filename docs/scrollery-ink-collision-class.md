# Ink printed on top of ink — what the class is

Four separate one-off fixes shipped for members of this class and it kept recurring.
That is the evidence the class had never been characterised: a defect that is
understood does not come back four times.

This is the characterisation, and it is written from measurement. Where measurement
disagreed with what was expected, the measurement is what is recorded and the
disagreement is said out loud.

The tool is `tools/scroll-sweep/`. Run it with `npm run sweep:scrolls`.

---

## 1. The geometry that makes it possible

A certificate is one flex column (`.Award`) inside a fixed 2550x3300 frame, and
**three independent coordinate systems paint into that frame**:

| layer | what it is | what its position is measured against |
| --- | --- | --- |
| **A** plate artwork | absolutely-positioned plates, everything outside `.Award` | **frame** coordinates — fixed, data-blind |
| **B** ornament | `img.Flourish`, `img.Underline` inside `.Award` | a **text box**, via `top`/`bottom`/`left`/`right` in local `em` |
| **C** glyph ink | the type, in normal flow | **content** — string length x face metrics x column width |

Every member of the class is the same failure:

> **An offset measured in one of those systems is applied to ink that lives in
> another, and the two systems move independently.**

Three sub-families follow, and they are exhaustive over the pairs. All three are real:
the baseline sweep found **380 cases of C∩A alone, 90 of C∩B alone, 395 of C∩C alone,
and 354 with more than one**.

### C∩A — the flow reaches fixed art

The column's extent is data-dependent; the plate's position is not. The sign-and-date
family lives here. Its cause is not the plates: it is that **the date and the signature
had no fitting pass of any kind** — a flat `font-size: 0.7em` in a box 7em wide. The
longest date this app can print takes three lines in that box and a long signer takes
four, and because the block is `align-items: end` every extra line grows it *upward*
into the artwork.

### C∩B — the ornament is pinned to a line that has moved

**This is case zero.** `#BottomTextFlourish` is the swash beside the issuing body's
NAME — that is what it decorates, and it is why `p:nth-of-type(2)` carries a 4.4em left
gutter while the line above it carries none. But it was *positioned* against the
`"Amtgard"` span, which is the FIRST line.

Those are the same place only while the title line takes exactly one line. Measured, on
identical content:

| hand | title line height | |
| --- | --- | --- |
| chancery | 163px | one line |
| gotisch | 174px | one line |
| unicase | 170px | one line |
| **cinzel** | **318px** | **two lines** |
| **uncial** | **320px** | **two lines** |
| **metamorphous** | **323px** | **two lines** |

In a wide hand the title's second line drops into the swash's band — a band with no
gutter, because the gutter was put on the element the swash was never anchored to.

### C∩C — type over type

Structurally possible wherever a block has a negative margin or a reserved height
smaller than its content. **Nobody had reported it and the sweep found it everywhere:**
the flavour line running into the signature, on garber, on jovious, on owl/cyanotype.
Same root cause as the C∩A family — the signature block growing upward.

---

## 2. Why it kept recurring

**Every prior fix corrected a constant. The defect is in the binding — what the
constant is measured against.** A corrected constant is right for exactly the one data
point that was measured. A corrected binding is right for the axis.

| prior fix | verdict |
| --- | --- |
| `--sc-sign-lift-want` (4 orders, 8 rows) | **symptom.** The lift itself is real mechanism — `liftSignature()` measures and clamps. But the *numbers* were derived with the date and signer at their shipped sizes, and both were unfitted. With a long signer the block is 150px taller and the lift is clamped to a gap that is no longer there. |
| `--sc-swash-hang: 0.9em` + paired `padding-right` | **symptom**, and its own comment says so: one value for seventeen frames because five were never measured off alpha. |
| `margin-left: 4.4em` on `p:nth-of-type(2)` | **symptom**, and case zero's near-miss. It reserves a gutter for the swash on the one line the swash was not anchored to. |
| `section.IssuingTextSection { width: 100% }` | **cause.** It separated the ornament's box from the type's box. The right kind of fix, and the only one of the four that is. |

Three of four were symptom-level. That is the answer to "why does this keep coming
back".

---

## 3. The five lessons, and what each one cost

The brief for this work listed five reasons earlier passes missed the class. Every one
of them reappeared *inside the harness built to check it*, which is the strongest
evidence they are the real traps:

1. **Layout boxes are not ink.** Boxes are used now only as a *screen* — they
   over-report, so they cannot miss a pair — and every verdict is on pixels.
2. **Real data is not the worst case.** Both are swept: a synthetic profile (which
   manufactures the unbreakable tokens that overflow a fixed box) and the actual
   longest values in the ORK.
3. **A sample is not a sweep.** Enumerated. The one factorisation — the custom recipe
   cross — is validated by an exhaustive tier rather than assumed.
4. **The typeface axis was never swept.** It is the dominant axis. At the synthetic max
   profile, **cinzel 19/19, metamorphous 19/19 and uncial 20/20 were dirty** against
   roughly 30% for the narrower hands.
5. **Moving something creates something else.** Confirmed the hard way: fitting the
   title line to one line shortened the column, `.Award` re-centred, and the flavour
   line moved down into the signature on jovious. Caught by the regression diff.

---

## 3b. What this closed, and what it did not

Measured, at 1:1, before and after (full numbers in
`docs/scrollery-ink-collision-results.md`):

- **C∩B, text over ornament — CLOSED.** Case zero 975 -> 5 ink px; the same case in
  Uncial 1877 -> 0; the issuer name on rose's knotwork 3121 -> 0.
- **C∩C, text over text — CLOSED** on every case measured. garber 89 -> 0.
- **C∩A, text over plate artwork — OPEN**, and on two cases marginally worse: crown
  1368 -> 1421, garber 927 -> 1035. `liftSignature()` is correct mechanism with nothing
  to work with, because its lift is clamped to the room the citation left and at the
  maximum citation there is none. The rest is concentrated in orders whose grounds are
  busy full-page artwork — smith's map, battle's rune wash — where "over the artwork"
  and "on its designed ground" genuinely blur and a threshold cannot decide it.

## 4. Where it is fixed now

In cause-before-symptom order — the lowest-numbered mechanism that can express a fix
is the one used.

1. **Ornaments follow the block they decorate.** `anchorOrnaments()` measures where the
   name paragraph actually ended up and re-offsets the swash from it, preserving the
   gap the design has in the one-line case. Clamped by `--sc-orn-travel-max`; the CSS
   default is unchanged, so a render with no JavaScript draws exactly what it drew
   before.
2. **The title line has a fitting pass.** `fitTitle()`, which shrinks `#IssuerTitle`
   only — never the versal — and measures the *paragraph's* line count to decide.
   Cinzel and Metamorphous now fit on one line; Uncial legitimately does not, and the
   anchor is what carries that case.
3. **The date and the signature have fitting passes.** This was expected to answer both
   the C∩C flavour family and the C∩A sign-and-date family — fewer lines is a shorter
   block, and a shorter block should reach neither. It answered the first and NOT the
   second: garber's flavour-into-signature went 89 -> 0, but its text-over-artwork went
   288 -> 397, because the shorter block sits lower, where the tape-measure plate is.
   The measurement is what is recorded here, not the expectation.
4. **`overflow-wrap: anywhere` on the signature block.** Not cosmetic — it is what
   makes (3) work. The fitting pass counts line boxes, and an unbreakable token that
   overflows its box is still exactly one line box, so the ladder saw a name that
   "fitted" at full size while it lay across the laurel.

---

## 5. When it recurs — and it will

Run `npm run sweep:scrolls`. Read `tools/scroll-sweep/README.md` first; the two rules
that matter most are there:

- **never measure against white** — measure against the certificate's own ground;
- **`display:none` is forbidden in the isolation CSS** — isolation must not reflow.

`ScrollSweepCoverageTest` fails the build if a hand or an order partial is added
without being swept. That test is the reason this document should not need writing
again.
