# Navigation and page layout

Two things list the Stonewright pages: the WordPress sidebar and the **band** at the top of
every Stonewright page. Under **Stonewright** in the sidebar the pages are grouped into six
hubs. A hub is a set of pages that share a landing page and a group of links in the band.

| Hub (sidebar name) | Pages |
| --- | --- |
| Overview | Overview |
| Setup | Setup, Troubleshoot |
| AI Abilities | AI Abilities |
| Knowledge | Skills, Memory, Context, Design, Prompt library |
| Custom code | Custom code (Drafts, Library, Active and Crash recovery tabs), Code approval |
| Activity | Audit log, Block queue, Rescue |

Page addresses did not change: every `page=stonewright-...` link and bookmark still
opens the same page. The Custom code tabs use the `tab` values the Sandbox page
already had (`drafts`, `library`, `mu-plugins`, `crash-recovery`); Code approval is its
own page.

Sources:
- `plugin/includes/Admin/MenuRegistry.php` (the hubs, tabs, titles and order)
- `plugin/includes/Admin/MenuOrder.php` (orders and names the sidebar entries)
- `plugin/includes/Admin/AdminShell.php` (the frame every page is printed in)
- `plugin/includes/Admin/Ui/Band.php` (the band)

## The sidebar

The entries are ordered by hub. The first page of a hub carries the hub's name
(Overview, Setup, AI Abilities, Knowledge, Custom code, Activity) and the others keep
their own (Troubleshoot, Memory, Context, Design, Prompt library, Code approval,
Rescue). The top-level **Stonewright** entry opens the Overview.

A page that is still changing (Troubleshoot, Context and Design) shows a small **EXP**
marker after its name. While the pointer is over the marker, a tooltip to its right says
"This feature is experimental." It appears at once, and also in the folded sidebar's flyout
and in the mobile menu. The marker cannot be focused; screen readers read the same words as
part of the entry.

## The band

The band is a dark panel across the top of every Stonewright page, above the page header.
It shows the Stonewright mark and name, then one link to every page, in the order of the
sidebar, in groups by hub. A hub with two or more links shows its name in capitals and a
thin rule in front of its links (Setup, Knowledge, Custom code, Activity); Overview and AI
Abilities are single links and have neither. Someone who cannot open a page does not see its
link.

- The link for the page that is open is highlighted and marked as the current page, on any
  of that page's tabs.
- A link shows a number when something needs attention: open incidents on Audit log, queued
  or failed block changes on Block queue, changes needing a rollback on Rescue. Screen readers
  also read what the number counts.
- A page that is still changing (Troubleshoot, Context, Design and Block queue) has a small
  raised **EXP** marker on its link. Pointing at the link, or moving the keyboard to it, shows
  the tooltip "This feature is experimental." above the link. **Escape** closes it. Screen
  readers read the same words as part of the link.
- On a narrow screen the links wrap onto more rows; at 400 pixels and below each group is
  a row and the group names and rules are hidden.

The band scrolls with the page; nothing about it is sticky. The Stonewright sign-in consent
screen has no band.

## The page frame

Every page starts the same way:

1. A **skip link**, the first stop for the keyboard, that jumps past the band and the
   header to the content.
2. The **band**, as above.
3. The **page header**: the page's one `h1`, a one-line explanation, and on the right the
   page's status and main action.
4. A **tab bar**, only on a page that has tabs of its own. Custom code has Drafts, Library,
   Active and Crash recovery; the current tab is marked. The tab bar does not list other pages:
   the band does. Setup's four views (Get started, Settings, Connections, Updates) are tabs
   inside the page.
5. The page's own content.

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
and `AdminShell::close()`. The title, the explanation, the band link and the tab bar come from
the registry unless the page passes its own. `beta` puts the EXP marker on the page's band
link and sidebar entry. A page that is a view of another registered page passes `current`, the
slug of the page whose band link is marked. A page must not print its own `h1`.
