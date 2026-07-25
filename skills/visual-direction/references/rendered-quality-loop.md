# Rendered quality loop

The loop that turns "I built it" into "here is what it looks like and here is
the proof". Run it after the first section, and again before you call the page
done.

## 1. Render

Load the real page in a browser at every breakpoint the direction targets.
A spec, a JSON diff, or a readback of the widget tree is not a rendered result.
An Elementor page that has never been requested over HTTP has never been seen.

If a browser is unavailable, say so and stop. Do not describe what the page
probably looks like.

## 2. Collect evidence

For each targeted breakpoint, record the viewport width, the URL, and the
capture. Note anything the capture cannot show — a hover state, a state behind
an interaction, a font that had not loaded.

Evidence normalization is the same contract the builder skills use: keep
measured values and provenance, drop the raw vendor tree.

## 3. Checkpoint after the first section

Call `stonewright-design-checkpoint-record` with the section, the evidence, and
the direction revision it was built against. Show the user the rendered
result and wait.

This is the cheapest place to be wrong. A direction that is off by one type
scale costs one section to fix here and a whole page to fix later.

## 4. Quality check

Call `stonewright-design-quality-check` with the collected evidence. The report
separates:

- **Failures** — a named rule did not pass. These block.
- **Warnings** — worth a look, not a stop.
- **Unverifiable** — a rule that had no evidence to run against. Treat this as
  missing work, not as a pass.

Fix failures, then re-run the check against fresh evidence. Do not carry an old
report forward after a write; the report describes the render it was given.

## 5. Protect the breakpoints you were not asked to touch

Before and after a responsive edit, read back the values for every breakpoint,
not only the one you targeted. Report non-target breakpoints as unchanged, and
mean it. Elementor's responsive controls inherit, so an edit written at the
wrong level silently moves values you never intended to touch.

If a non-target breakpoint did change, say so, name the control, and offer to
restore it from the snapshot taken before the write.

## 6. Report

State what was built, which breakpoints were rendered, which rules passed,
which failed, and what is still unverified. Unverified is not a synonym for
fine. If you skipped a breakpoint, name it and say why.
