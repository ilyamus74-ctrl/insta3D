# APP dual-phone LM02.7A.2.1 — depth profile overflow menu

Baseline: `1a5ca4b7e439a0e99c94c0b720946109e8e28042`

## Purpose

Keep the selected and active depth profiles visible without placing five profile
buttons over the operator controls.

## UI contract

* the collapsed control is a single compact row;
* it shows `DEPTH <selected> → <active>` at all times;
* a three-line menu affordance opens the profile list;
* AUTO, ULTRA 960, HIGH 640, QUALITY 480 and BALANCED 320 remain selectable;
* the menu closes after selection or an outside tap;
* a requested/active mismatch is labelled as a thermal/runtime override;
* no clock, transport, depth or thermal policy changes are part of this slice.
