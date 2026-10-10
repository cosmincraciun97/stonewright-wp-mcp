---
name: stonewright-section-reuse
description: >
  Use when building or extending a page on a site that may already have
  matching sections: a hero, features, testimonials, pricing, FAQ, call to
  action, gallery or contact section. It finds sections the site already has,
  offers them to the user in one short question, copies the ones they pick and
  adapts them in the same batch. Skip it entirely when agent_preferences says
  section_reuse is off.
---

# Stonewright Section Reuse

Section reuse copies a section that already exists on another page of this
site into the page you are building, then adapts its text, images and styling
to the user's instructions and the active Design Direction. Reuse stays inside
one builder family (Gutenberg, Elementor V3, Elementor V4); it never converts
between them.

## Before anything else

Read `agent_preferences.section_reuse` from `stonewright-task-start`.

- `off`: do not use this skill. Build normally and never mention reuse. While it
  is off the two reuse tools are not listed at all (a client that lists tools
  again cannot call them), and a `notices` line such as
  `section_reuse: off - do not offer section reuse` says the same.
- `ask` (the default): continue below.

The setting can change during a session, and a client may keep an old tool
list. If a call returns `stonewright_section_reuse_off` (an `extract`, or an
`insert_section` operation), or an in-process `stonewright-section-reuse-find`
answers `enabled: false`, stop using reuse at once, build the rest normally,
and say nothing about it. The refusal is not a fault and is not retryable:
do not repeat the call and do not rerun it as a dry run.

## 1. Find first, ask second

Call `stonewright-section-reuse-find` before you ask the user anything about
sections. Give it:

- `builder`: `gutenberg`, `elementor-v3` or `elementor-v4`, the builder of the
  page you are building. Check it with `stonewright-site-capabilities` when you
  are not sure.
- `target_post_id`: the page you are building, so its own sections are never
  offered.
- the sections the page needs, as `sections` (a `role` of `hero`, `features`,
  `testimonials`, `pricing`, `faq`, `cta`, `gallery` or `contact`, and
  optionally the `layout` wanted: `columns`, `items`, `depth`), or as one short
  `outline` such as `hero, 3 feature cards, testimonials, contact form`.

The answer lists up to three candidates per role by default. A candidate says
where it lives (`source`: title, status, edit link), where the section sits
(`locator`), its `role`, a `layout` summary, a layout-only `similarity` from 0
to 1 (text, media and style values never count), a short `outline`, and
`warnings`. `scan.truncated` is true when the site has more than 200 sources and
only the most recent were looked at.

Sources may be published, draft, pending, scheduled or private; `source.status`
says which. A private, pending or scheduled page is listed only to a user who
may edit it, and it is extracted as it is. **Never change the status of a page
to extract from it**, and never ask the user to: if a page you expected is not
listed, or extract answers `stonewright_not_found`, the current user may not
edit it, or the page is in the trash.

For Elementor, a candidate can also carry `inner`: the containers nested inside
the section, up to six, the shallower ones first (each with a `locator`, its
`depth`, `type`, number of `children` and `elements`, a `repeated` group such as
a row of cards, and its first `heading`). `inner_truncated` says more exist.
Offer one when the user wants a part of a section, for example one group of
cards, instead of the whole section.

If no role has a candidate, build the page normally and do not mention reuse.

On a block theme the candidates also include the customized templates and
template parts of the active theme (`source.type` is `wp_template` or
`wp_template_part`, `source.kind` is `site-template`), which is where to look
when the work is a template or a header or footer part. A template that exists
only as a theme file has no post and is never a candidate.

## 2. One short question

Otherwise ask the user one short question. Show at most three candidates per
role, one line each: the page it comes from, what it looks like (the outline
heading and the layout), and any warning in plain words. Let them pick any,
or none. Do not ask a second question about reuse. If they pick none, build
that section from scratch.

Warnings to say out loud:

| Warning | Say |
|---|---|
| `dynamic_tags` | It shows content from the page it was on (a title or a field); the copy keeps that link, so check it. |
| `forms` | It holds a form; the copy keeps the form's settings and sends to the same place. |
| `synced_patterns` | It uses a synced pattern; the copy keeps pointing at the pattern unless the user wants to change it. |
| `global_widgets`, `nested_templates` | It uses a global widget or template; it stays linked. |
| `third_party_widgets` | It needs a plugin's widget or block. |
| `draft_source`, `pending_source`, `scheduled_source`, `private_source`, `password_protected_source` | It comes from a draft, pending, scheduled, private or password-protected page, so its text is not public yet; the copy would put it on a page visitors can read. Say so and let the user decide. |
| `legacy_attributes` (extract) | An older block setting was moved to its current form, for example a heading's `textAlign` to `style.typography.textAlign`; nothing else changed. Mention it only when it matters. |
| `css_classes_not_approved` | It uses CSS classes this site has not approved (the warning names them), so the copy is refused until a site administrator adds them to the `stonewright_approved_css_classes` option. Offer another candidate, or copy it without them if the user agrees (see "When the copy is refused for its settings"). |
| `custom_css_needs_approval` | It carries custom CSS, which needs a human-issued custom-code grant. Do not apply one yourself; offer another candidate, or copy it without the CSS if the user agrees (see "When the copy is refused for its settings"). |
| `html_widgets` | It holds an HTML widget; the copy is refused unless the site allows HTML widgets and the operation sets `allow_html_widget: true`. |
| `placeholder_widgets` | It uses a widget whose plugin is not active on this site (Elementor shows a placeholder), so the copy is refused. Offer another candidate. |
| `missing_media` | A picture it refers to is gone. The copy still succeeds, but the image stays broken until the user supplies a new one. |
| other `missing_*` (global colors, fonts, classes, variables, templates, patterns) | Something it refers to no longer exists, so the copy fails with that exact reference until it is created. Offer another candidate. |

## 3. Extract

For each pick, call `stonewright-section-reuse-extract` with the `post_id` and
`locator` of the candidate (or of one of its `inner` containers). It changes
nothing. The locator `{ "kind": "element", "id": "<id>" }` takes the id of a
top-level element or of a container nested at any depth: a V3 container or
section, or a V4 div block or flexbox, with its children. A widget, a legacy
column or a part of a composite element is refused with
`stonewright_section_not_copyable`; copy the container that holds it. A nested
container is inserted the same way as a section, with the same placeholders,
fresh ids, id attribute renaming and element cap. It returns:

- `section`: the portable payload in the builder's own format. Elementor
  element ids and V4 local style ids are placeholders (`ph-1`, `ls-1`);
  Gutenberg anchors are listed. Keep the payload exactly as returned except for
  the changes you mean to make.
- `references`: everything the section refers to, each with `exists`. A global
  color, font, class or variable that does not exist makes the insert fail with
  that exact reference.
- `layout`, `outline` and `warnings`.

Read the Design Direction (`stonewright-design-direction-brief`) before you
decide what to change, then plan every change to the copy.

When extract fails, the message says what is allowed; read it and fix the
call, and do not change the page:

| Code | Meaning |
|---|---|
| `stonewright_not_found` | The post is not there, is in the trash, or the user may not edit it. Statuses allowed: publish, draft, pending, future, private. |
| `stonewright_section_not_found` | No top-level element or nested container has that id, or the saved template is not a section or container template. |
| `stonewright_section_not_copyable` | The id is a widget, a column or a mixed V3 and V4 subtree. Use the container that holds it. |
| `stonewright_no_elementor_document` | The post has no valid Elementor document (`reason`: not built with Elementor, Elementor data without the Elementor mode, or empty or unreadable data). Pick another source. |

## 4. Insert and adapt in one batch

Put the copy and every adaptation in the same batch. One dry run, one apply.

| Builder | Tool | Insert | Adapt |
|---|---|---|---|
| Elementor V3 | `stonewright-elementor-v3-batch-mutate` | `{ "action": "insert_section", "op_id": "feat", "parent_id": "<container id>", "section": <payload> }` | `update_element` with `element_ref: "feat.ph-2"` |
| Elementor V4 | `stonewright-elementor-v4-update-node` with `operations` | the same `insert_section` | `update_node` with `element_ref: "feat.ph-2"` |
| Gutenberg | `stonewright-blocks-batch-mutate` | `{ "action": "insert_section", "op_id": "feat", "path": [], "position": 2, "section": <payload> }` | `update` with `section_ref: "feat"` and `relative_path: [0, 1]` |

Rules the write enforces, so do not work around them:

- Every element gets a fresh id and V4 local styles are remapped; reference the
  new elements as `@<op_id>.<placeholder>`, never by a source id.
- Existing global references are kept. Dynamic tags are kept and flagged.
- The widget type is never changed and no setting is stripped on its own. In V3
  a setting the live schema does not accept blocks the copy, and the refusal
  names every such setting (see "When the copy is refused for its settings").
  A stored value the live control no longer lists but still maps (a heading
  `align` of `left`) is accepted as stored; any other unlisted value blocks the
  copy with its setting named.
- A duplicate Gutenberg anchor is renamed (`name-2`) with its links. An
  Elementor id attribute (`_element_id` in V3, `_cssid` in V4) the page already
  uses is renamed the same way, with the links of the copy, and an
  `anchors_renamed` warning says so.
- Give every operation its own `op_id`; a repeated one is refused. One batch may
  add at most 2000 elements in total; split larger work into batches.
- V4 text (`e-heading` title, `e-paragraph` paragraph, `e-button` text) is
  written with the type the live widget declares, `escaped-html` on a current
  Elementor (`{ "$$type": "escaped-html", "value": "text" }`), not `html-v3`.
  A copy whose text is stored in another type than the live one is refused until
  you rewrite it with `update_node` in the same batch.
- A synced pattern stays a reference (`detach_patterns: false`, the default).
  Only when the user wants to edit it, set `detach_patterns: true` (every
  synced pattern of the section) or a list of pattern ids to make a local copy.
  Ask first.
- In Gutenberg, a change to a static block may change its text, links and
  images, not its tags. Use the finalizer for structural changes.
- Send `settings_evidence` and the Design Direction values for visual changes
  the way you would for any Elementor write. Never use `php-execute`, raw meta
  or WP-CLI to copy a section.
- Production-safe: an Elementor V3 copy needs a `confirmation_token` issued for
  the exact call (every `elementor-v3-batch-mutate` write that is not a dry run
  does); a Gutenberg copy needs one only when the same batch removes a block;
  Elementor V4 writes stay blocked there. Dry runs need no token.

### When the copy is refused for its settings (Elementor V3)

`stonewright_section_settings_not_reusable` means the live Elementor schema does
not accept some settings of the section as they are. Nothing was written. The
message names up to five rejected keys with their element placeholder and type.
The answer carries the full list as `rejected_settings` (up to 25 entries: the
element, its type, the path, the code and the control type when there is one)
and `drop_settings_proposal`, the exact value of the option that copies the
section without those settings.

1. Stop. Do not repeat the call and do not rerun it as a dry run.
2. Tell the user which section it is and which keys are rejected: the source
   container (the page and the section you offered) and the rejected keys by
   name, for example "the hero of the Home page holds `acme_old_style` on its
   heading and `acme_legacy_root` on its container, and this Elementor does not
   accept them".
3. Offer the three ways out: choose another section, activate the plugin that
   provides the settings, or copy the section without exactly those settings.
4. Only when the user agrees to the third, run the insert again with
   `drop_settings` set to `drop_settings_proposal`, copied unchanged. Never add
   or remove entries, never send it on your own, and never send it to get past a
   different refusal. If the list no longer matches
   (`stonewright_section_drop_settings_mismatch`), tell the user what changed
   (`drop_settings_missing`, `drop_settings_unexpected`) and ask again.
5. After the apply, tell the user which settings were left out
   (`removed_settings`), because the copy can look different from its source.

In production-safe, issue the confirmation_token for the exact call that carries
`drop_settings`.

`drop_settings` exists only for Elementor V3 sections. An Elementor V4 or Gutenberg
insert that carries it is refused with
`stonewright_section_drop_settings_unsupported` and nothing is written; never send
it there.

## 5. Verify

After the apply:

1. Elementor: run `stonewright-elementor-css-regenerate` for the page, then
   `stonewright-elementor-post-write-verify` with the new element ids. Never
   clear CSS site-wide. In production-safe, `stonewright-elementor-css-regenerate`
   and a rollback with `stonewright-change-restore` each need a
   `confirmation_token` issued for the exact call.
2. Check the `change_set`: `reuse_source` names the page each section came
   from, `verification.status` must be `verified`, `unexpected` must be empty.
3. Open the page in a separate browser tab and check desktop, tablet and
   mobile.
4. Tell the user which sections came from which pages and what you changed.

## Never

- Never edit or delete the source page, and never change its status, to extract from it.
- Never copy between builders, or hand-convert a section.
- Never offer reuse when the setting is off, and never retry a
  `stonewright_section_reuse_off` refusal.
- Never reuse a form, dynamic tag or synced pattern without telling the user.
