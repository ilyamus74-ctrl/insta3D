# APP-DUAL-PHONE-LM02.7A.2.1 — depth profile overflow menu

Baseline: `1a5ca4b7e439a0e99c94c0b720946109e8e28042`

Replace the horizontally scrolling depth-profile button row with a compact
three-line overflow control. Preserve the selected and active profile labels on
the collapsed surface. Keep profile selection, adaptive behavior and thermal
overrides unchanged.

Acceptance:

* operator buttons are not covered by profile buttons;
* the collapsed indicator remains visible;
* all five profile modes are available from `DropdownMenu`;
* choosing a mode calls `DualPhoneDepthProfileSelection.select(mode)`;
* the menu closes after selection;
* no changes are made to stereo processing or clock synchronization.
