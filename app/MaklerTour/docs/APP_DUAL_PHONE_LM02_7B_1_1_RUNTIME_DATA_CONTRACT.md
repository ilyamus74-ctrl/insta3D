# APP-DUAL-PHONE-LM02.7B.1.1 runtime data contract

- `build/`, `sessions/`, `archives/` and packed archives are never committed.
- Default host output is outside the repository under XDG state storage.
- Default `MAKLER_ARCHIVE_EVERY=0` disables JPEG frame archiving.
- JSON/JSONL diagnostics remain available for pairing and protocol analysis.
- JPEG recording is explicit and bounded through `MAKLER_ARCHIVE_EVERY`.
- Diagnostic transfer uses `pack_session.sh`; JSON-only is the default.
- Sampled JPEGs are opt-in through `--sample-every N`.
- Existing runtime artifacts are removed from the Git index without deleting
  local files by `untrack_runtime_artifacts.sh`.
