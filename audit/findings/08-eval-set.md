# Task 08 — Evaluation Set

## Environment adaptation (important for later tasks)

Host CLI PHP **cannot boot this WordPress** — `wp-config.php` sets `DB_HOST='devkinsta_db'` (Docker-internal), and no local MySQL port is open. WordPress + DB were reached **inside the DevKinsta FPM container**:

```
docker exec devkinsta_fpm php /www/kinsta/public/dev-site/wp-content/plugins/knowledgespark/audit/eval/list-posts.php
```

All later WP-booting harnesses (Tasks 09, 10) must use `docker exec devkinsta_fpm php …`. Container PHP is **7.4.33** (matches plugin minimum); WP **7.1.4**; site mounted at `/www/kinsta/public/dev-site` — the plugin's `audit/` folder is visible through the bind mount.

## Site profile

- **541 published posts** (IDs 8–410), a single-niche terrarium-jar blog.
- The 30 most recent posts (Nov 10–15, 2026, 2,200–3,800 words each) form tight clusters: self-sustaining/balance, gnats/pests, moss, jars/gear.
- All recent posts are long-form and similar length — the "long vs short" spread is modest (2,200–3,803).

## Eval set selection (10 posts)

Coverage across intent types: diagnostic (410), emergency troubleshooting (406, 392), comparison/decision (402), myth-busting (399), identification (396), step-by-step tutorial (394, 393), revival tutorial (383), purchase intent (381). Coverage across clusters: balance (3), gnats (5), moss (1), gear (1). Dates span newest (Nov 15) to oldest window edge (Nov 10).

**Ground truth:** 47 ideal links, 18 must-not links (`audit/eval/eval-set.json`, valid JSON, 10 entries).

## Ground-truth coverage limitation (recorded)

Ideal links were judged from titles of the 30-post recent window. The other ~511 older posts were not title-reviewed, so (a) an ideal link could exist outside the window that we cannot credit, and (b) must-not lists only cover near-duplicates inside the window. This biases measured recall *conservatively* (some "misses" may actually be correct suggestions of older posts) — interpretation must treat `must_not_leaked` and duplicate-cluster behavior as the sharper signals, and Task 14's manual review of returned suggestions partly compensates.

## User confirmation

**CONFIRMED** — user approved the eval set and ground truth as-is (2026-11-20, via session question). Live runs authorized: 10 suggest calls + 10 probe searches against the configured Qdrant collection, plus up to 10 AI calls if AI mode is configured.

**DONE WHEN:** met — JSON valid, 10 entries, user confirmed.
