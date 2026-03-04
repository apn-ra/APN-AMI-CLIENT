# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v1.0.1] - 2026-03-04

### Added
- Chaos harness coverage for deterministic parser-recovery, fairness, reconnect, and 1k correlation stress scenarios (S6-S8, S11-S13) with standardized metrics artifacts.
- Structured runtime environment failure classification tooling and scripts for distinguishing `SANDBOX_ENVIRONMENT` constraints from actionable defects.
- Snapshot/diagnostics coverage expansion for correlation counters, reconnect attempt caps, and redacted scenario preview outputs.

### Changed
- Production readiness and release governance docs now enforce latest-artifact gate evidence and explicit readiness threshold references.
- Packaging boundary enforcement keeps core usage framework-agnostic while preserving optional Laravel adapter usage.

### Fixed
- Parser framing/desync hardening around truncation/garbage/oversized frames with bounded recovery behavior.
- Correlation determinism and cleanup confidence through out-of-order stress validation and improved diagnostics visibility.
- Multi-server fairness and reconnect-storm evidence quality by moving from inferred outcomes to explicit scenario assertions.

### Security
- Logging/chaos diagnostics artifacts maintain redaction expectations for sensitive frame content during debug previews.
