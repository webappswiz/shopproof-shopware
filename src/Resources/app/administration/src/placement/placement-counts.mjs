// The placement result the Shopproof page stores after a sweep (PlacementResult on the server).
// Kept apart from the component so it is tested alone: apps/shopware/tests/admin.

/** True when every page of a sweep got a result (passed, needs a look, failed, blocked or error). */
export function sweepFinished(pages) {
  return pages.length > 0 && pages.every((p) => !['waiting', 'checking'].includes(p.status));
}

/**
 * Counts of a finished sweep. `linked` counts pages that should show the withdrawal link and
 * did: the withdrawal page itself passes without one, so it never counts as a found link.
 */
export function placementCounts(pages) {
  const counts = { pass: 0, warn: 0, fail: 0, skipped: 0, linked: 0 };
  for (const page of pages) {
    if (['pass', 'warn', 'fail'].includes(page.status)) {
      counts[page.status] += 1;
      if (page.status !== 'fail' && page.expectLink !== false) {
        counts.linked += 1;
      }
    } else {
      counts.skipped += 1;
    }
  }
  return counts;
}
