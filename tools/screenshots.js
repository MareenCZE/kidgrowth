/*
 * Regenerates the README screenshots in docs/img/.
 *
 * The images have to be reproducible, or they quietly drift out of date with
 * the interface they document. This is the recipe that made them.
 *
 * Not a dependency of the application: KidGrowth itself needs PHP and a
 * writable directory, nothing else. This script is the one place a browser is
 * required, and it is optional tooling.
 *
 *   npm install playwright-core && npx playwright-core install chromium
 *   php -S 127.0.0.1:8126 -t src          # a demo instance, seeded:
 *                                          #   php tools/seed_demo_data.php
 *   node tools/screenshots.js
 *
 * Point BASE elsewhere with KIDGROWTH_URL if your server is on another port.
 * Run it against seeded demo data - never a real family's measurements.
 */
const { chromium } = require('playwright-core');
const path = require('path');

const BASE = process.env.KIDGROWTH_URL || 'http://127.0.0.1:8126';
const OUT = path.join(__dirname, '..', 'docs', 'img');

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox', '--force-color-profile=srgb'] });
    /* 1.5 rather than 2: still crisp on a high-density screen, and it keeps
       the three images to a few hundred kilobytes between them. */
    const page = await browser.newPage({
        viewport: { width: 980, height: 1400 },
        deviceScaleFactor: 1.5,
        colorScheme: 'light',
    });

    await page.goto(BASE + '/child.php?id=1&ref=cav', { waitUntil: 'networkidle' });

    const sections = page.locator('section.growth-section');
    const byHeading = (text) => sections.filter({ has: page.locator('h2', { hasText: text }) }).first();

    await sections.nth(0).screenshot({ path: path.join(OUT, 'growth-chart.png') });
    await byHeading('SD over time').screenshot({ path: path.join(OUT, 'sd-chart.png') });

    /* The demo history is six years long. The README wants the shape of the
       table, so the capture keeps the ten most recent rows and drops the
       rest - a shorter history, not a different one. */
    const table = byHeading('Measurements');
    await table.evaluate((el) => {
        const rows = el.querySelectorAll('tbody tr');
        for (let i = 10; i < rows.length; i++) {
            rows[i].remove();
        }
    });
    await table.screenshot({ path: path.join(OUT, 'measurements.png') });

    console.log('wrote growth-chart.png, sd-chart.png, measurements.png to docs/img/');
    await browser.close();
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
