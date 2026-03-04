# Release Commands - v1.0.1

## Option A - Git-only tag

```bash
git checkout main
git pull
git tag -a v1.0.1 -m "apntalk/ami-client v1.0.1"
git push origin v1.0.1
```

## Option B - Tag + GitHub release (manual notes file)

```bash
git checkout main
git pull
git tag -a v1.0.1 -m "apntalk/ami-client v1.0.1"
git push origin v1.0.1
gh release create v1.0.1 --title "apntalk/ami-client v1.0.1" --notes-file docs/ami-client/releases/v1.0.1/RELEASE_NOTES.md
```

## Pre-flight checks

```bash
vendor/bin/phpunit
```

Use the latest gate evidence before tagging:
- `docs/ami-client/chaos/reports/20260304-053700Z-final-chaos-suite-results.md`
- `docs/ami-client/production-readiness/audits/20260304-054704Z-production-readiness-score.md`
