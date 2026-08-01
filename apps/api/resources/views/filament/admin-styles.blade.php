{{--
    The admin panel's own stylesheet, injected once per page via the
    `panels::styles.after` render hook (see AdminPanelProvider).

    WHY THIS FILE EXISTS. Filament v5 ships only its compiled `fi-*` stylesheet.
    It does NOT expose a Tailwind utility layer, so a custom Blade view written
    with `py-2` / `text-sm` / `bg-danger-600` renders with every one of those
    classes matching no CSS at all — measured in the panel: `py-2` → 0px padding,
    `text-sm` → 16px, `font-medium` → weight 400, `tabular-nums` → normal. Every
    custom view in this panel had that bug; the tables had no cell padding or row
    dividers, the failure bars were 0px tall, and the place map iframe fell back
    to its intrinsic 300px instead of filling the panel.

    Everything here is written against Filament's OWN custom properties, so the
    panel's theme (including the amber primary set in AdminPanelProvider) stays
    the single source of colour rather than a hard-coded parallel palette.

    Classes are namespaced `rm-` so they can never collide with `fi-`.
    `PipelineHealthTest` asserts that every `rm-` class used by any admin Blade
    view is defined here — that is the invariant whose absence let the dead
    utilities ship green.
--}}
<style>
    /* Text ------------------------------------------------------------ */
    .rm-muted { color: var(--gray-500); }
    .rm-strong { font-weight: 500; color: var(--gray-950); }
    .rm-note { font-size: 0.875rem; color: var(--gray-500); }
    /* Same as .rm-note, but trailing a block it refers back to. */
    .rm-footnote { font-size: 0.875rem; color: var(--gray-500); margin-top: 0.75rem; }
    .rm-link { color: var(--primary-600); text-decoration: underline; }
    /* Digits meant to be compared down a column need to share a width. */
    .rm-num { font-variant-numeric: tabular-nums; }
    .rm-right { text-align: right; }
    .rm-stack { display: flex; flex-direction: column; gap: 0.5rem; }

    /* Tables ---------------------------------------------------------- */
    .rm-scroll { overflow-x: auto; }
    .rm-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .rm-table th,
    .rm-table td { padding: 0.5rem 0; text-align: left; vertical-align: top; }
    .rm-table th + th,
    .rm-table td + td { padding-left: 1rem; }
    .rm-table th { font-weight: 500; color: var(--gray-500); }
    .rm-table tbody tr + tr { border-top: 1px solid var(--gray-200); }
    /* Beats `.rm-table th`'s own `text-align: left`, so a numeric column can be
       right-aligned in the header as well as the body. */
    .rm-table th.rm-right,
    .rm-table td.rm-right { text-align: right; }
    /* A row that never ran is dimmed, not hidden: in pipeline order a gap is
       itself the finding. */
    .rm-table tr.rm-idle { opacity: 0.5; }
    .rm-none { color: var(--gray-400); }

    /* Pills ----------------------------------------------------------- */
    .rm-pill {
        display: inline-block;
        padding: 0.125rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .rm-pill-warn { background: var(--warning-500); color: var(--gray-950); }
    .rm-pill-danger { background: var(--danger-600); color: #fff; }

    /* Ranked bars ------------------------------------------------------ */
    .rm-mix { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.75rem; }
    .rm-mix-head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; font-size: 0.875rem; }
    .rm-mix-reason { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--gray-950); }
    .rm-mix-count { font-variant-numeric: tabular-nums; color: var(--gray-500); }
    .rm-mix-track { margin-top: 0.25rem; height: 0.375rem; width: 100%; border-radius: 9999px; background: var(--gray-100); overflow: hidden; }
    .rm-mix-fill { height: 100%; border-radius: 9999px; background: var(--danger-600); }

    /* Embedded map ----------------------------------------------------- */
    .rm-map { width: 100%; height: 260px; border: 0; border-radius: 0.5rem; }

    /* Dark ------------------------------------------------------------- */
    .dark .rm-muted { color: var(--gray-400); }
    .dark .rm-strong { color: #fff; }
    .dark .rm-note,
    .dark .rm-footnote { color: var(--gray-400); }
    .dark .rm-table th { color: var(--gray-400); }
    .dark .rm-table tbody tr + tr { border-top-color: var(--gray-800); }
    .dark .rm-none { color: var(--gray-600); }
    .dark .rm-mix-reason { color: #fff; }
    .dark .rm-mix-count { color: var(--gray-400); }
    .dark .rm-mix-track { background: var(--gray-800); }
    .dark .rm-mix-fill { background: var(--danger-400); }
</style>
