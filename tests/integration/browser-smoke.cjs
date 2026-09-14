/* Run only against a disposable, loopback Nextcloud with authenticated test storage state. */
const assert = require('node:assert/strict')
const fs = require('node:fs')
const { chromium } = require(process.env.EDUCAI_TEST_PLAYWRIGHT_MODULE || 'playwright')
const baseUrl = process.env.EDUCAI_TEST_BASE_URL || 'http://127.0.0.1:8096'
if (process.env.EDUCAI_TEST_ALLOW_MUTATION !== '1' || !/^http:\/\/127\.0\.0\.1:\d+$/.test(baseUrl)) throw new Error('Disposable loopback test gate required')
if (!process.env.EDUCAI_TEST_STORAGE_STATE) throw new Error('Authenticated test storage-state path required')
;(async () => {
 const browser = await chromium.launch({ headless: true, ...(process.env.EDUCAI_TEST_CHROME ? { executablePath: process.env.EDUCAI_TEST_CHROME } : {}) })
 try {
  const context = await browser.newContext({ storageState: process.env.EDUCAI_TEST_STORAGE_STATE, viewport: { width: 1440, height: 1100 } })
  await context.route('**/*', route => new URL(route.request().url()).origin === baseUrl ? route.continue() : route.abort())
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  page.on('dialog', dialog => dialog.accept())
  await page.goto(baseUrl + '/settings/admin/educai')
  const panel = page.locator('.embedding-maintenance')
  await panel.waitFor()
  await page.waitForFunction(() => document.querySelector('.embedding-maintenance .maintenance-scopes'))
  const all = panel.getByRole('button', { name: 'Reindex All Embeddings', exact: true })
  assert(await all.isVisible(), 'Central reindex control is visible without opening Catalogue')
  assert.equal(await page.locator('#catalogue-endpoint').isVisible(), false, 'Catalogue settings remain independent')
  console.log('PASS visible central maintenance independent of Catalogue')

  await page.locator('#embedding-endpoint').fill('http://127.0.0.1:18095/v1/embeddings')
  await page.locator('#embedding-model').fill('fixture-embedding')
  await page.locator('#embedding-key').fill('synthetic-fixture-only')
  await page.getByLabel('Enable Retrieval-Augmented Generation', { exact: true }).check()
  await page.getByRole('button', { name: /Course catalogue integration/ }).click()
  await page.locator('#catalogue-endpoint').fill('http://127.0.0.1:18095/catalogue')
  await page.getByLabel('Enable course catalogue integration', { exact: true }).check()
  assert(await all.isDisabled(), 'Unsaved settings must block reindexing')

  let received
  const sent = new Promise(resolve => { received = resolve })
  let release
  const held = new Promise(resolve => { release = resolve })
  let holdOnce = true
  await page.route('**/apps/educai/api/v1/settings', async route => {
   if (route.request().method() !== 'PUT' || !holdOnce) return route.continue()
   holdOnce = false
   const response = await route.fetch()
   assert.equal(response.status(), 200, 'Settings save succeeds before its delayed response')
   received()
   await held
   return route.fulfill({ response })
  })
  await panel.getByRole('button', { name: 'Save settings first', exact: true }).click()
  await sent
  await page.locator('#embedding-model').fill('fixture-embedding-new')
  release()
  await page.waitForFunction(() => !document.querySelector('.embedding-maintenance button').disabled)
  assert(await all.isDisabled(), 'An edit during save must stay unsaved')
  assert(await panel.getByRole('button', { name: 'Save settings first', exact: true }).isVisible())
  console.log('PASS save-before-reindex and in-flight edit stays dirty')

  const saved = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().endsWith('/api/v1/settings'))
  await panel.getByRole('button', { name: 'Save settings first', exact: true }).click()
  assert.equal((await saved).status(), 200)
  await page.waitForFunction(() => ![...document.querySelectorAll('.embedding-maintenance button')].find(b => b.textContent.trim() === 'Reindex All Embeddings').disabled)
  await page.reload()
  await page.locator('.maintenance-notice').waitFor()
  assert.match(await panel.innerText(), /indexes need reindexing/, 'Reindex reminder survives reload')
  console.log('PASS saved configuration reminder survives reload')

  const catalogueResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/admin/catalogue/reindex'))
  await panel.getByRole('button', { name: 'Reindex catalogue only', exact: true }).click()
  const catalogue = await catalogueResponse
  assert.equal(catalogue.status(), 200)
  assert.equal((await catalogue.json()).success, true)
  await page.waitForFunction(() => document.querySelector('.embedding-maintenance').textContent.includes('Bot sources were not queued'))
  console.log('PASS catalogue-only action queues asynchronously')

  const queuedResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/admin/embeddings/reindex-all'))
  await all.click()
  const queued = await queuedResponse
  assert.equal(queued.status(), 200)
  const counts = await queued.json()
  assert.equal(counts.already_queued_catalogue_jobs, 1)
  assert.equal(counts.queued_catalogue_jobs, 0)
  assert.equal(counts.success, true)
  console.log('PASS repeated queue request keeps one Catalogue job')

  const anonymous = await browser.newContext()
  const denied = await anonymous.request.get(baseUrl + '/apps/educai/api/v1/admin/embeddings/status', { maxRedirects: 0 })
  assert(denied.status() !== 200, 'Anonymous request must not receive admin index status')
  await anonymous.close()
  assert.deepEqual(errors, [], 'No JavaScript runtime errors')
  fs.mkdirSync('build/validation', { recursive: true })
  await panel.screenshot({ path: 'build/validation/index-maintenance.png' })
  console.log('PASS admin-only status and no browser runtime errors')
 } finally {
  await browser.close()
 }
})().catch(error => { console.error(error.message); process.exitCode = 1 })
