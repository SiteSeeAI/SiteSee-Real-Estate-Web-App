# Home cleanup assessment — October 14, 2026

Cleanup is paused. Do not rerun the previously issued cleanup commands. CTC Publishing is outside this work, including read-only inspection of its files, executables, configuration, backups and source. No new cleanup helper or server command is being issued. Launch preparation can proceed separately; this assessment does not establish LIVE readiness or change Stripe TEST mode.

The repeated failures come from a cleanup design that investigates far beyond the seventeen historical SiteSee installers. It inventories another application and follows references into shared system resources. Each newly encountered object can trigger a guard before archiving starts. Adding exceptions for those objects has not established a reliable boundary for the task.

The latest output is not evidence that CTC or ffprobe is broken. It is the cleanup helper refusing to continue. I previously overstated the applicability of the tests: passing the synthetic and Linux checks did not establish that the correction matched the hosting account’s dependency paths, metadata and binary build.

## What the source establishes

The last issued download was `home-cleanup-f18b3f13aa8c`, source `f18b3f13aa8c8d1cd1b38ca72b007db9455d6b42`, helper SHA256 `8f2edc2e6634d6e3e72d7241177d7b2c147622726d57057423f9f91bfd008b40`. This assessment examines those unchanged helper bytes. The latest pasted error contains no checksum/version, so the exact helper used for that individual host attempt is not independently established.

| Finding | Evidence in the examined source |
| --- | --- |
| CTC is explicitly inventoried, rather than merely encountered accidentally. | `host_scan`, line 1018, includes `home / 'ctc-publishing'`. |
| The media exception is conditional. It is not called for a declared source or an `all_files` source. | `scan`, lines 955–956. |
| Following a source alias promotes its target to a declared source. Directory enumeration and run-parts can also strengthen the role. | Lines 746–758 and 932–940. |
| A previously accepted pathname can be inspected again after its role becomes stronger. | The visited key includes the source role, line 888. |
| Ownership, mode, link count, parent permissions, size and ELF structure can each reject an ordinary media reference. | `bundled_media`, lines 657–699. |
| Those different conditions converge on the same message. | Lines 977–978: `Custom binary wrapper requires review`. |

This means two different problems remain indistinguishable in the output: the media classifier was bypassed, or it ran and rejected a condition. The host error does not reveal which happened. There is no basis to claim a particular permission, alias or binary-format problem on the server, and none was investigated inside CTC.

## Reproduction and the testing gap

An independent review exercised 36 controlled scenarios using synthetic ELF headers and program tables in disposable temporary directories. No CTC source or artifact was copied or inspected, and no native executable ran. Three valid controls passed classification. Thirty-three cases reproduced the same generic stop. All seventeen fixture installers retained their exact contents and metadata; no archive was created.

The reproductions include declared-file, file-alias, directory-alias and run-parts paths where the classifier is never called; a path accepted first and rejected after later role promotion; and individual metadata/header/program-table rejections. The [full result matrix](HOME_CLEANUP_ASSESSMENT_RESULTS_2026-10-14.json) records classifier call counts and preservation results. These establish possible failure paths, not the exact host cause.

The existing suite already expected strict source-role cases to stop (`tests/legacy-home-archive.test.py`, lines 113–123). The Linux integration created fresh binaries with mode 0755 and substituted a selected inventory for `host_scan` (`tests/legacy-home-runtime.integration.py`, lines 43–70). It proved preservation for that fixture and exercised real Linux sources. It did not reproduce the hosting account’s complete discovery graph or metadata. The earlier 150-test/CI results remain valid within that tested scope; they did not justify saying the host problem was fixed.

## Required direction for a later fix

Replace the broad scan with an explicit SiteSee-only cleanup scope. Keep the seventeen-name move allowlist and the existing checksum, ownership, atomic-move, durable-journal and guarded-restore protections. CTC must not be inspected, executed, modified, moved, repaired or reconfigured to satisfy cleanup.

Removing the explicit CTC inventory alone is insufficient. References, aliases and process working directories can lead back into it. The boundary must be enforced before filesystem probes, opens and alias traversal, at every discovery entry point. Shared public_html, server and scheduler inventories must also be narrowed to the SiteSee task. A cross-application dependency must produce an incomplete/out-of-scope result and preserve the originals; silently skipping it must not produce an unused-file claim or an archive PASS.

Use structured diagnostic categories for source-role promotion and classifier rejection, with discovery provenance and the exact helper fingerprint. Do not expose configuration contents, credentials or process arguments. Do not weaken the native-wrapper guard globally or change CTC permissions to make an exception pass.

Before another cleanup is offered, exercise the actual inventory orchestration on a fully synthetic server topology, including shared-root entries, aliases, run-parts, role promotion and process paths. Filesystem-access traps must prove that denied CTC paths are never inspected, including metadata access. Preserve all originals when coverage is incomplete. These are acceptance requirements for future implementation, not completed functionality.

## Current disposition

The stopped run failed during dependency checking, before the archive call. It is not evidence of an archive completing, and no host archive destination or `ARCHIVE PASS` has been reported. Existing attempts and any journals should remain preserved.

This investigation changed documentation and saved synthetic evidence only. The helper, application, deployment installers, services, schedules, database and Stripe settings remain unchanged. No hosting operation or CTC access occurred during this assessment. The cleanup remains unfinished and deferred; private installer housekeeping should not consume the launch workstream.
