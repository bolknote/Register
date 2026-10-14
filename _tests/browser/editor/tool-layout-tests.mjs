import assert from 'node:assert/strict';

// Increasing the CSS viewport reproduces zooming out: the reading column
// remains capped while the outside margins grow. Use the real stylesheet.
export async function assertToolsStayAttached(page) {
    const desktopGaps = [];
    for (const width of [1280, 1920, 2560, 3840, 1024, 881, 880, 768, 760, 390, 320]) {
        await page.setViewportSize({width, height: 900});
        const geometry = await page.evaluate(() => {
            const header = document.querySelector('.site-header-copy').getBoundingClientRect();
            const create = document.querySelector('.site-header-tools .post-create-start').getBoundingClientRect();
            const card = document.querySelector('.post-card.is-manageable');
            const content = card.getBoundingClientRect();
            const edit = card.querySelector('.post-edit-start').getBoundingClientRect();
            const remove = card.querySelector('.post-delete-start').getBoundingClientRect();
            const rect = bounds => ({left: bounds.left, right: bounds.right, center: (bounds.left + bounds.right) / 2});
            return {header: rect(header), create: rect(create), content: rect(content), edit: rect(edit), remove: rect(remove),
                headerGap: header.left - create.right, postGap: content.left - edit.right,
                postCenterGap: content.left - (edit.left + edit.right) / 2,
                overflow: document.documentElement.scrollWidth > innerWidth};
        });
        assert.equal(geometry.overflow, false, `No horizontal overflow at ${width}px: ${JSON.stringify(geometry)}`);
        for (const name of ['create', 'edit', 'remove']) {
            assert.ok(geometry[name].left >= 0 && geometry[name].right <= width,
                `${name} stays on screen at ${width}px: ${JSON.stringify(geometry)}`);
        }
        if (width > 760) {
            assert.ok(geometry.headerGap >= 0 && geometry.headerGap <= 24,
                `Create stays next to the heading at ${width}px: ${JSON.stringify(geometry)}`);
            assert.ok(geometry.postGap >= 0 && geometry.postGap <= 24,
                `Edit stays next to the post at ${width}px: ${JSON.stringify(geometry)}`);
            // Hover/focus scales a button around its center; its left edge is
            // intentionally allowed to move without detaching from the rail.
            assert.ok(Math.abs(geometry.create.center - geometry.edit.center) < 1,
                `Header and post tools share the same narrow rail at ${width}px: ${JSON.stringify(geometry)}`);
            assert.ok(Math.abs(geometry.edit.center - geometry.remove.center) < 1,
                `Edit and delete stay in the same rail at ${width}px: ${JSON.stringify(geometry)}`);
            if (width >= 1280) desktopGaps.push(geometry.postCenterGap);
        }
    }
    assert.ok(Math.max(...desktopGaps) - Math.min(...desktopGaps) < 1,
        `The gap must not grow as the viewport grows: ${desktopGaps}`);
}

export async function runToolLayoutRegressions(browser, origin) {
    const context = await browser.newContext({javaScriptEnabled: false, colorScheme: 'dark', reducedMotion: 'reduce'});
    const page = await context.newPage();
    try {
        await page.route('**/tool-layout.html', route => route.fulfill({contentType: 'text/html', body: `<!doctype html>
            <html lang="en"><meta name="viewport" content="width=device-width, initial-scale=1">
            <link rel="stylesheet" href="/site.css">
            <header id="header"><div class="site-header-shell">
                <div class="site-header-copy"><h1 class="site-title">Ordinary site</h1><p class="site-tagline">A normal description.</p></div>
                <nav class="site-header-tools"><button class="post-inplace-button post-create-start">+</button></nav>
            </div></header>
            <main id="container"><div id="content"><article class="post-card is-manageable">
                <h2 class="post head">An ordinary post</h2><div class="post time">Publication date</div>
                <nav class="post-inplace-tools"><button class="post-inplace-button post-edit-start">Edit</button>
                    <button class="post-inplace-button post-delete-start">Delete</button></nav>
                <div class="post body"><p>Ordinary content independent of any site.</p></div>
            </article></div></main></html>`}));
        await page.goto(origin + '/tool-layout.html');
        await assertToolsStayAttached(page);
        await page.setViewportSize({width: 1280, height: 900});
        await page.locator('.post-edit-start').hover();
        assert.equal(await page.locator('.post-edit-start').evaluate(button => button.matches(':hover')), true);
        await assertToolsStayAttached(page);
        console.log(`${browser.browserType().name()}: tools stay attached from 320px mobile to a 3840px zoomed-out viewport`);
    } finally { await context.close(); }
}
