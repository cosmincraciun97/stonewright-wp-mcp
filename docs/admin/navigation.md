# Navigation and page layout

The WordPress sidebar is Stonewright's only global navigation. Under **Stonewright**
the pages are grouped into six hubs. A hub is a set of pages that share a landing page
and one row of tabs under the page header.

| Hub (sidebar name) | Tabs |
| --- | --- |
| Overview | Overview |
| Setup | Setup, Troubleshoot |
| AI Abilities | AI Abilities |
| Knowledge | Skills, Memory, Context, Design, Prompt library |
| Custom code | Drafts, Library, Active, Crash recovery, Approvals |
| Activity | Audit log, Block queue, Rescue |

Page addresses did not change: every `page=stonewright-...` link and bookmark still
opens the same page. The Custom code tabs use the `tab` values the Sandbox page
already had (`drafts`, `library`, `mu-plugins`, `crash-recovery`); Approvals is the
Code approval page.

Sources:
- `plugin/includes/Admin/MenuRegistry.php` (the hubs, tabs, titles and order)
- `plugin/includes/Admin/MenuOrder.php` (orders and names the sidebar entries)
- `plugin/includes/Admin/AdminShell.php` (the frame every page is printed in)

## The sidebar

The entries are ordered by hub. The first page of a hub carries the hub's name
(Overview, Setup, AI Abilities, Knowledge, Custom code, Activity) and the others keep
their own (Troubleshoot, Memory, Context, Design, Prompt library, Code approval,
Rescue). The top-level **Stonewright** entry opens the Overview. A page that is
still changing shows the word **Beta** after its name.

## The page frame

Every page starts the same way:

1. A **skip link**, the first stop for the keyboard, that jumps past the header and
   the tab bar to the content.
2. The **page header**: the product name, the page's one `h1`, a one-line
   explanation, and on the right the page's status and main action. A page that is
   still changing also shows a **Beta** badge and one sentence saying it may behave
   differently between releases.
3. The hub's **tab bar**, only when the hub has more than one page. The current page
   is marked, and a tab shows a number when something needs attention: open incidents
   on Audit log, queued or failed block changes on Block queue, changes needing a
   rollback on Rescue. Someone who cannot open a page does not see its tab.
4. The page's own content.

The header scrolls with the page; nothing about it is sticky.

## Notices

- A notice a Stonewright page prints in its own content stays where the page printed
  it. Nothing moves it, folds it or removes it on a timer.
- Notices that WordPress and other plugins print stay where WordPress places them,
  directly under the page header, drawn the way WordPress draws them.
- When more than three of those arrive, they fold into one disclosure titled with
  what it holds, for example "Other WordPress notices: 1 error, 3 notices". It
  starts open whenever it holds an error or a warning.
- An error never disappears by itself.

## Help

Every Stonewright page has two tabs under the **Help** button at the top right:
**What is this page?** (what the page is for, which group it belongs to and its
neighbours) and **Glossary** (Abilities, Skills, Memory, Context, Design direction,
Sandbox).

## From the Plugins screen and after activation

- The Stonewright row on **Plugins** shows **Overview** and **Setup** links before
  **Deactivate**, and a **Docs** link among the row details.
- After the first activation on a site, WordPress opens the Overview once. It does
  not redirect after a bulk activation, in the network admin, for people who cannot
  manage options, or on a site that has already chosen whether Stonewright is on.

## For developers

A page registers itself once, from its own class, before the `admin_menu` hook
finishes:

```php
MenuRegistry::add( 'stonewright-example', __( 'Example', 'stonewright' ), 'activity', [
	'order'      => 40,
	'beta'       => true,
	'capability' => 'manage_options',
	'lede'       => __( 'One sentence about the page.', 'stonewright' ),
] );
```

and prints itself with `AdminShell::open( 'stonewright-example', [ 'actions' => $html ] )`
and `AdminShell::close()`. The title, the explanation and the tab bar come from the
registry unless the page passes its own. A page must not print its own `h1`.
