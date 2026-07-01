Repo: ilyamus74-ctrl/make_multi

Task: add a parallel zoom calibration movement mechanism without removing the existing impulse-based mechanism.

Current situation:
- ZOOM CALIB currently uses:
  - Impulse ms
  - Settle ms
  - Samples
  - Cmd
  - Wide hold ms
  - Direction
  - Wide sign
- Existing mechanism must remain working.
- User wants a new movement mode based on full edge-to-edge sweep time:
  - Samples=10 means sample indexes 0..9.
  - Full sweep ms=1500 means movement from edge 0 to edge 9 takes 1500 ms.
  - per_step_ms = full_sweep_ms / (samples - 1).
  - moving 1 → 4 should hold zoom command for per_step_ms * 3.
  - moving 0 → 9 should hold zoom command for full_sweep_ms.

Goal:
Add new selectable movement mode while preserving old behavior.

Required UI changes in web/index.html:

1. Add a movement mode selector in ZOOM CALIB:

   Movement:
     [LEGACY IMPULSE]
     [SWEEP TIME STEPS]

   Suggested select id:
     zoomMoveMode

   Values:
     legacy_impulse
     sweep_time_steps

2. Keep existing fields visible for now:
   - Impulse ms
   - Settle ms
   - Samples
   - Cmd
   - Wide hold ms
   - Direction
   - Wide sign

3. Add new fields:
   - Full sweep ms
     id: zoomFullSweepMs
     default: value from wide_hold_ms if no separate value exists
   - Step ms readonly
     id: zoomStepMsPreview
     computed as:
       full_sweep_ms / max(1, samples - 1)

4. Update Step ms live when:
   - Samples changes
   - Full sweep ms changes
   - movement mode changes

5. UI status should show selected mode:
   - mode=legacy impulse
   - mode=sweep time steps step=166.7ms

6. Auto-save:
   - movement mode and full_sweep_ms must save immediately like other ZOOM CALIB fields.

Backend changes in mjpeg_gst_http.cpp:

7. Extend zoom calibration settings JSON with:
   - zoom_move_mode: "legacy_impulse" | "sweep_time_steps"
   - full_sweep_ms: integer

8. Backward compatibility:
   - If zoom_move_mode missing, default to "legacy_impulse".
   - If full_sweep_ms missing, default to wide_hold_ms.
   - Keep existing fields:
     - impulse_ms
     - settle_ms
     - wide_hold_ms
     - cmd_abs
     - wide_cmd_sign
     - calibration_direction

9. Existing legacy movement must remain unchanged:
   - If zoom_move_mode == "legacy_impulse":
     use current impulse-based calibration logic exactly as before.

10. Add new helper:

   move_zoom_by_sample_delta_sweep_time(
     current_idx,
     target_idx,
     samples,
     full_sweep_ms,
     cmd_abs,
     direction,
     sign,
     settle_ms
   )

   Logic:
   - delta = target_idx - current_idx
   - if delta == 0:
       do not send zoom movement command
       sleep settle_ms
       return current_idx
   - per_step_ms = full_sweep_ms / max(1, samples - 1)
   - hold_ms = round(per_step_ms * abs(delta))
   - zoom direction sign:
       positive delta means moving forward in calibration order
       if calibration_direction == "wide_to_tele":
         forward should move WIDE→TELE
       if calibration_direction == "tele_to_wide":
         forward should move TELE→WIDE
   - send zoom command:
       Z or existing zoom serial command with cmd_abs and computed sign
   - sleep hold_ms
   - send zoom stop
   - sleep settle_ms
   - return target_idx

11. Calibration loop with new mode:
   - Rehome to start edge first using existing edge-hold behavior.
   - current_idx = 0.
   - For i in 0..samples-1:
       if zoom_move_mode == "sweep_time_steps":
         current_idx = move_zoom_by_sample_delta_sweep_time(current_idx, i, ...)
       else:
         use legacy impulse movement
       capture AprilTag measurement
       save profile_idx=i and step=i

12. Important:
   - In sweep_time_steps mode, Samples is exact output count.
   - Samples=10 means output sample indexes 0..9.
   - BUILD MASTER PROFILE must output exactly 10 points.
   - PTZ SPEED FROM ZOOM MASTER must output exactly 10 speed points.
   - PTZ SPEED TUNE must show buttons 0..9.

13. Add diagnostic fields to calibration profile output:
   - zoom_move_mode
   - full_sweep_ms
   - step_ms
   - move_hold_ms
   - move_delta
   - current_idx
   - target_idx

14. During calibration status/logging, show:
   sample 4/9 mode=sweep_time_steps delta=3 hold=500ms settle=190ms

15. RUN FULL CALIB:
   - Must pass zoom_move_mode and full_sweep_ms into each anchor run.
   - Clear old profiles once.
   - Run all enabled anchors.
   - Build master profile.
   - Build PTZ speed profile.
   - Final sample count must equal Samples.

16. /api/zoom_calibration/settings:
   - GET must return:
     zoom_move_mode
     full_sweep_ms
   - POST must accept and persist them.
   - Validate:
     zoom_move_mode in ["legacy_impulse", "sweep_time_steps"]
     full_sweep_ms between 100 and 20000

17. UI labels:
   - Rename old "Wide hold ms" visually to "Legacy edge hold ms" or keep as "Wide hold ms" for now.
   - Add new "Full sweep ms" next to it.
   - Add readonly "Step ms".

18. Do not remove:
   - Impulse ms
   - legacy calibration
   - TEST WIDE
   - TEST TELE
   - old RUN APRILTAG ZOOM CALIB behavior

19. Testing:
   - Set movement mode = legacy_impulse.
     RUN FULL CALIB should behave as before.
   - Set movement mode = sweep_time_steps.
     Samples=10, Full sweep ms=1500.
     UI must show Step ms ≈ 166.7.
   - RUN FULL CALIB.
     Logs should show:
       sample 0 delta=0 hold=0
       sample 1 delta=1 hold≈167
       sample 2 delta=1 hold≈167
       ...
       sample 9 delta=1 hold≈167
   - /api/zoom/master_profile returns exactly 10 points.
   - /api/autopilot/speed_profile returns exactly 10 points.
   - PTZ SPEED TUNE shows buttons 0..9.
   - No console ReferenceError.