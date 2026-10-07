---
name: how-to-write-skills
description: >
  Use when writing, reviewing, importing, or testing a Stonewright site skill:
  trigger descriptions, body size, version constraints, exposure flags, and the
  checks a skill must pass before agents can use it.
---

# How to Write Skills

A site skill is a Markdown playbook stored in WordPress. Agents read its
description first: `stonewright-task-start` returns the descriptions that match
a task, and the body loads only on demand through `stonewright-skills-get`.
Write each skill so the description routes the right tasks and the body tells
an agent exactly what to do.

## When a skill is the right tool

- A workflow this site repeats, with steps, tools, and checks that do not change
  from task to task.
- A correction that keeps coming back. Record a one-line lesson with
  `stonewright-learning-record` first; it can open a disabled draft skill that
  you then finish here.
- Never store credentials, private hostnames, or one-off notes in a skill.

## Front matter

Front matter is a flat list of scalar fields between `---` lines. `name` and
`description` are required. Nested YAML, anchors, aliases, and repeated keys are
refused. The file must be UTF-8 text no larger than 1 MiB.

```markdown
---
name: Product page refresh
description: Use when refreshing a WooCommerce product page layout on this site.
topic: product-pages
version_constraints: {"woocommerce": "required"}
enable_agentic: true
enable_prompt: true
---
```

On import, the file name decides the identifier: `product-page-refresh.md`
becomes the skill `product-page-refresh`. An optional `requires_provider` field
names a provider the guidance depends on; see Provider requirements below.

## The description is the trigger

- Say when to use the skill, not what it contains. Start with "Use when" and
  name the situations, surfaces, or plugins. Any language works.
- Keep it short. Every auto-matched description travels in the routing index,
  and a description longer than 500 characters draws a warning.
- A skill saved without a description uses its title as the trigger text.
  Write a real description anyway.

## The body

- Lead with the first call and the order of work, then the tools to use, the
  checks that prove success, and what is forbidden.
- Name tools the way agents see them, such as `stonewright-skills-get`. An
  ability name written with a slash must exist on the site; a name that does
  not exist blocks activation.
- A focused auto-matched skill reads in a few hundred words. Put long reference
  playbooks in prompt mode so they stay out of the routing index until someone
  asks for them.

## Version constraints

Constraints say which plugins the guidance needs. Write them as one line of JSON:

- No requirement: leave the field out, or write `{}`.
- A plugin must be active: `{"woocommerce": "required"}`.
- A version range: `{"elementor": ">=3.16"}`.
- Any one of several plugins: `{"any_of": "acf|pods|meta-box"}`.

Repeated plugin names and empty alternatives are refused. Guidance that names
Elementor must declare constraints before it can be enabled. A missing plugin
never blocks enabling: the skill stays hidden from agents until its plugins are
present, and `stonewright-skills-get` reports which requirement is missing.

## Provider requirements

`requires_provider` names a provider the guidance depends on instead of a
plugin. Write one id on one line:

```markdown
requires_provider: elementor-native
```

- The only accepted value is `elementor-native`, lowercase. It is present when
  Elementor's own MCP abilities are registered on the site. An unknown id, a
  list, an empty value, or a repeated key makes the file invalid.
- The key compiles into the constraint `{"provider:elementor-native": "required"}`,
  merged with any `version_constraints` you wrote. Exports and the saved record
  show the constraint, not the key.
- While the provider is absent the skill is hidden from agents and prompts, and
  `stonewright-skills-get` reports `provider:elementor-native` as the missing
  requirement. It never blocks saving or enabling the skill.
- Only the expression `required` is accepted for a provider. Lint reports
  `invalid_provider_requirement:<component>` for anything else, and that blocks
  activation.

## Exposure flags

- `enabled` is the site's master switch for the skill.
- `enable_agentic` lets the description match tasks automatically.
- `enable_prompt` offers the skill as an explicit prompt or command.

Use auto-match for short, broadly useful rules. Use prompt mode for large
playbooks that an agent should open only when asked.

## Trust and lifecycle

- An imported file always lands disabled, as a draft, and the server checks it
  again whatever the file claims about itself. An import never replaces an
  existing skill.
- A file that tells an agent to ignore the plugin's hard rules or safety gates,
  to skip confirmation tokens, or to send credentials elsewhere is refused.
  Mention secrets only to forbid handling them.
- Skills that ship with Stonewright can be disabled but not edited or removed.
  To adapt one, write a new skill with its own slug.
- Saving changed text keeps the previous version, so a skill can be rolled
  back. Trash hides a skill from every agent; restoring it returns a disabled
  draft. Permanent deletion is a separate step from the trash view.

## Test a skill before enabling it

1. Import the file from **Stonewright → Skills → Import** and read the review:
   lint errors, warnings, trust findings, and whether the slug is taken.
2. Fix the file and inspect it again until the review is ready to import.
3. Open the draft in the editor, enable it, and save. Activation checks the
   description, the constraints, and every tool the body names.
4. Call `stonewright-task-start` with one task that should match and one that
   should not, and compare the matched skills.
5. Call `stonewright-skills-list` with mode `agentic`, `prompt`, and `discover`
   to see where the skill appears, and `stonewright-skills-get` to read the
   stored body.
6. Export the skill to keep a copy. The export carries its provenance and a
   content hash, and it imports again on another site.
