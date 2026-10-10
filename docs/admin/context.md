# Context

**Stonewright → Knowledge → Context** (marked EXP) shows the generated instructions agents get for
this site and holds a block of your own text, the **user context**, that is sent to agents at task
start. Source: `plugin/includes/Admin/Pages/ContextPage.php`, `plugin/includes/Context/UserContext.php`,
`plugin/includes/Context/ContextSnapshot.php` and `plugin/includes/Context/ContextBuilder.php`.

## The page

- **System context** lists the facts agents are told about the site (PHP and WordPress versions,
  mode, tool profile, site address, active plugins) and, under **Show full system context**, the
  generated instructions in a copyable block. URLs, email addresses and post ids are redacted in
  this admin view. The block's **Copy** button changes to a check and says **Copied**.
- **User context** is one switch (**Include user context in task start**), one text area
  (**Persisted user context**) and one primary action, **Save user context**. A badge in the card
  header says **On** or **Off**.

Saving ends in a notice on the page that says how many characters are stored and how much of them
each task-start mode receives, for example "1500 characters stored. Compact task start receives 400
of them; full task start and context-bootstrap receive 1200." When the switch is off the notice says
so, because the text is then kept but not sent.

## What the text is

The user context is plain text. Saving removes tags and turns HTML entities into the characters they
stand for, so `5 < 6`, `A & B` and quotes are stored and sent exactly as typed, and `<b>bold</b>`
becomes `bold`. Nothing is encoded on the way in, so saving the same text again, or saving what the
page shows, changes nothing. The page escapes the text only when it prints it.

A value stored by an earlier release may hold entities such as `&lt;` or `&#039;`. It is read as the
plain text it stands for: the text area shows it decoded and agents receive it decoded. Reading
never rewrites the stored value; the next **Save user context** stores the plain text.

At most 4000 characters are stored; anything beyond that is cut when you save. Newlines are kept.

## What agents receive

| Channel | What carries the user context |
| --- | --- |
| Compact `stonewright-task-start` (the default) | The first 400 characters of the user context followed by the custom instructions, in `context.custom_instructions.text`. |
| `stonewright-task-start` with `responseMode=full`, and `stonewright-context-bootstrap` | The first 1200 characters of the user context in `user_context.text`, and the user context followed by the custom instructions (up to 2400 characters) in `custom_instructions.text`. |
| Connect-time server instructions | Custom instructions only (the first 1200 characters of the custom instructions). The user context is not part of them. |
| Pluginless Direct mode | Nothing: Context is stored in WordPress and Direct mode does not read it. |

The text goes first, so put the most important lines at the top. It is sent only while the switch is
on and the text is not empty. Turning the custom instructions off does not stop the user context
from being sent, and the other way round. With both off, `context.custom_instructions` is absent.

The limits are `UserContext::MAX_STORED` (4000), `UserContext::MAX_COMPACT` (400) and
`UserContext::MAX_INJECTED` (1200). See [Memory & Instructions](memory.md) for the custom
instructions and [Architecture](../architecture.md#agent-context) for the task-start response.
