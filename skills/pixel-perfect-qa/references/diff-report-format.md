# Diff Report Format

The QA loop produces a structured report after each screenshot-diff cycle.

## Report shape

```json
{
  "post_id": 42,
  "snapshot_id": "snap_1716300000_42",
  "url": "https://example.com/home/",
  "screenshot_path": "/tmp/stonewright-qa/snap-42-live.png",
  "reference_path": "/tmp/stonewright-qa/reference-42.png",
  "diff": {
    "score": 0.12,
    "threshold": 0.05,
    "passed": false,
    "regions": [
      {
        "x": 120, "y": 340, "width": 480, "height": 60,
        "description": "Hero heading font weight mismatch"
      },
      {
        "x": 0, "y": 800, "width": 1280, "height": 120,
        "description": "Footer background color drift"
      }
    ]
  },
  "accessibility": {
    "violations": [
      {
        "id": "color-contrast",
        "impact": "serious",
        "wcag": "1.4.3",
        "nodes": [".hero-cta"],
        "description": "Element has insufficient color contrast of 2.1:1 (required 4.5:1)"
      },
      {
        "id": "image-alt",
        "impact": "critical",
        "wcag": "1.1.1",
        "nodes": ["img.hero-image"],
        "description": "Image does not have an alt attribute"
      }
    ]
  },
  "lighthouse": {
    "performance": 72,
    "accessibility": 81,
    "best_practices": 95,
    "seo": 89
  }
}
```

## Interpreting the score

- `score`: 0.0-1.0 representing the fraction of differing pixels.
- `threshold`: 0.05 means passes if under 5% pixel difference.
- `passed: false` triggers the fix loop.

## Fix priority mapping

| Axe impact | Diff region area | Priority |
|---|---|---|
| critical | any | critical |
| serious | large (> 20% viewport) | critical |
| serious | small | major |
| moderate | any | major |
| minor axe or diff only | small | minor |

## Iteration cap

After 3 iterations without reaching `passed: true` on the diff, surface the
remaining regions to the user with a note that manual inspection is needed.
Do not loop indefinitely.
