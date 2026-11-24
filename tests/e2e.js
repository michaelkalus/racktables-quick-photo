'use strict';
// End-to-end tests for Quick Photo against the dev RackTables instance.
// Needs colima docker, ../../racktables-dev and Google Chrome.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const DEV = path.resolve(__dirname, '../../racktables-dev');
const FX = path.join(__dirname, 'fixtures/out');
const BASE = 'http://127.0.0.1:8090/index.php';
const env = fs.readFileSync(path.join(DEV, '.env'), 'utf8');
const PASSWORD = (env.match(/^RT_ADMIN_PASSWORD=(.*)$/m) || [])[1].replace(/^["']|["']$/g, '');
const DOCKER_ENV = Object.assign({}, process.env, { DOCKER_CONTEXT: 'colima' });

function sh(cmd, input) {
  return execSync(cmd, { cwd: DEV, env: DOCKER_ENV, input, maxBuffer: 256 * 1024 * 1024 }).toString();
}
function sql(q) {
  return sh('docker compose exec -T db sh -c \'mariadb -N -uroot -p"$MARIADB_ROOT_PASSWORD" racktables\'', q).trim();
}
function phpInfo(buf) {
  const code = '$b=stream_get_contents(STDIN);require "/var/www/racktables/plugins/quickphoto/lib.php";' +
    '$i=getimagesizefromstring($b);echo $i[0]."x".$i[1]." exif=".(qp_jpeg_has_exif($b)?1:0);';
  return sh("docker compose exec -T web php -r '" + code + "'", buf).trim();
}
function fileBytes(id) {
  return Buffer.from(sql('SELECT HEX(contents) FROM File WHERE id=' + id), 'hex');
}

const results = [];
function check(name, cond, extra) {
  results.push(!!cond);
  console.log((cond ? 'ok   - ' : 'FAIL - ') + name + (cond ? '' : (extra ? ' (' + extra + ')' : '')));
}

const RACK = sql("SELECT id FROM Object WHERE name='A-01' AND objtype_id=1560 LIMIT 1");
const OTHER_RACK = sql("SELECT id FROM Object WHERE objtype_id=1560 AND id<>" + RACK + " LIMIT 1");
const TAB = BASE + '?page=rack&tab=photos&rack_id=' + RACK;
let baseline = 0;

function newFiles() {
  return sql('SELECT id FROM File WHERE id>' + baseline + ' ORDER BY id').split('\n').filter(Boolean).map(Number);
}

async function openTab(ctx) {
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.errors = errors;
  await page.goto(TAB);
  await page.waitForSelector('#quickphoto');
  return page;
}

async function main() {
  baseline = Number(sql('SELECT COALESCE(MAX(id),0) FROM File'));
  const browser = await chromium.launch({ channel: 'chrome' });
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    httpCredentials: { username: 'admin', password: PASSWORD },
  });
  try {
    // ---- Add with big.jpg
    let page = await openTab(ctx);
    const n0 = newFiles().length;
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'big.jpg'));
    await page.waitForSelector('.qp-sheet');
    const sizeText = await page.locator('.qp-sheet .qp-size').innerText();
    check('4000x3000 source is not downscaled', /^4000 x 3000, \d+\.\d MB$/.test(sizeText), sizeText);
    check('sheet has comment field for Add', await page.locator('.qp-sheet .qp-sheet-comment').count() === 1);
    check('sheet preview image present', await page.locator('.qp-sheet img.qp-preview').count() === 1);
    // tap targets
    const heights = await page.evaluate(() =>
      Array.from(document.querySelectorAll('.qp-pick, .qp-sheet button')).map((e) => e.getBoundingClientRect().height));
    check('tap targets >= 44 px', heights.length >= 3 && heights.every((h) => h >= 44), heights.join(','));
    await page.fill('.qp-sheet .qp-sheet-comment', 'e2e <b>comment</b>');
    await page.click('.qp-sheet .qp-save');
    await page.waitForSelector('text=Photo added');
    check('success message visible after reload', true);
    let ids = newFiles();
    check('one file added', ids.length === n0 + 1, ids.join(','));
    if (ids.length === n0 + 1) {
      const info = phpInfo(fileBytes(ids[n0]));
      check('stored 4000x3000 without EXIF', info === '4000x3000 exif=0', info);
      check('comment stored', sql('SELECT comment FROM File WHERE id=' + ids[n0]) === 'e2e <b>comment</b>');
    }
    check('no console errors (add)', page.errors.length === 0, page.errors.join('|'));
    await page.close();

    // ---- Add with EXIF orientation 6
    page = await openTab(ctx);
    const n1 = newFiles().length;
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'portrait-exif6.jpg'));
    await page.waitForSelector('.qp-sheet');
    const t2 = await page.locator('.qp-sheet .qp-size').innerText();
    check('portrait 3000x2000 exif6 shows 2000 x 3000 (no downscale)', t2.startsWith('2000 x 3000'), t2);
    await page.click('.qp-sheet .qp-save');
    await page.waitForSelector('text=Photo added');
    ids = newFiles();
    check('portrait file added', ids.length === n1 + 1, ids.join(','));
    if (ids.length === n1 + 1) {
      const info = phpInfo(fileBytes(ids[n1]));
      check('portrait stored 2000x3000 without EXIF', info === '2000x3000 exif=0', info);
    }
    await page.close();

    // ---- Sources larger than 4032 px are downscaled to 4032
    for (const [file, shown, stored] of [['down.jpg', '4032 x 3024', '4032x3024 exif=0'],
                                         ['portrait-big-exif6.jpg', '3024 x 4032', '3024x4032 exif=0']]) {
      page = await openTab(ctx);
      const nd = newFiles().length;
      await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, file));
      await page.waitForSelector('.qp-sheet');
      const td = await page.locator('.qp-sheet .qp-size').innerText();
      check(file + ' sheet shows ' + shown, td.startsWith(shown + ','), td);
      await page.click('.qp-sheet .qp-save');
      await page.waitForSelector('text=Photo added');
      ids = newFiles();
      check(file + ' added', ids.length === nd + 1, ids.join(','));
      if (ids.length === nd + 1) {
        const info = phpInfo(fileBytes(ids[nd]));
        check(file + ' stored ' + stored, info === stored, info);
        check(file + ' within 8 MB', fileBytes(ids[nd]).length <= 8388608);
      }
      await page.close();
    }

    // ---- Cancel + same file twice
    page = await openTab(ctx);
    const before = newFiles().length;
    for (let i = 0; i < 2; i++) {
      await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
      await page.waitForSelector('.qp-sheet');
      await page.click('.qp-sheet .qp-cancel');
      await page.waitForSelector('.qp-sheet', { state: 'detached' });
    }
    check('cancel twice with same file opens sheet both times, nothing uploaded', newFiles().length === before);
    await page.close();

    // ---- Decode failure
    page = await openTab(ctx);
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'notimage.jpg'));
    await page.waitForSelector('.qp-error');
    const err = await page.locator('.qp-error').first().innerText();
    check('decode failure mentions Most Compatible', err.includes('Most Compatible'), err);
    check('decode failure uploads nothing', newFiles().length === before);
    check('controls re-enabled after failure', await page.locator('form.qp-add input[type=file]').isEnabled());
    await page.close();

    // ---- Simulated Apple encoder: canvas output carries APP1 Exif + APP13
    page = await openTab(ctx);
    const appleB64 = fs.readFileSync(path.join(__dirname, 'fixtures/apple-imageio.jpg')).toString('base64');
    const fakeToBlob = (b64) => page.evaluate((data) => {
      const bytes = Uint8Array.from(atob(data), (c) => c.charCodeAt(0));
      const orig = HTMLCanvasElement.prototype.toBlob;
      HTMLCanvasElement.prototype.toBlob = function (cb) {
        HTMLCanvasElement.prototype.toBlob = orig;
        cb(new Blob([bytes], { type: 'image/jpeg' }));
      };
    }, b64);
    const beforeApple = newFiles().length;
    await fakeToBlob(appleB64);
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
    await page.waitForSelector('.qp-sheet');
    await page.click('.qp-sheet .qp-save');
    await page.waitForSelector('text=Photo added');
    ids = newFiles();
    check('apple-encoder photo added', ids.length === beforeApple + 1, ids.join(','));
    if (ids.length === beforeApple + 1) {
      const info = phpInfo(fileBytes(ids[ids.length - 1]));
      check('apple-encoder photo stored without EXIF and decodes', /^\d+x\d+ exif=0$/.test(info), info);
    }
    await page.close();

    // ---- Truncated garbage from the encoder -> shrink error
    page = await openTab(ctx);
    const beforeBad = newFiles().length;
    await fakeToBlob(Buffer.from([0xff, 0xd8, 0xff, 0xe1, 0x00, 0x40, 0x45, 0x78]).toString('base64'));
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
    await page.waitForSelector('.qp-error');
    const badErr = await page.locator('.qp-error').first().innerText();
    check('malformed encoder output shows error', badErr.includes('Nothing was uploaded'), badErr);
    check('malformed encoder output uploads nothing', newFiles().length === beforeBad);
    check('controls re-enabled after malformed output', await page.locator('form.qp-add input[type=file]').isEnabled());
    await page.close();

    // ---- Encoder output with a 0x00 marker byte before SOS is rejected (as on the server)
    page = await openTab(ctx);
    const beforeZero = newFiles().length;
    const sosTail = [0xff, 0xda, 0x00, 0x02, 0x00, 0xff, 0xd9];
    await page.evaluate((data) => {
      const orig = HTMLCanvasElement.prototype.toBlob;
      HTMLCanvasElement.prototype.toBlob = function (cb) {
        HTMLCanvasElement.prototype.toBlob = orig;
        cb(new Blob([Uint8Array.from(data)], { type: 'image/jpeg' }));
      };
    }, [0xff, 0xd8, 0xff, 0x00, 0x00, 0x04, 0x00, 0x00].concat(sosTail));
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
    await page.waitForSelector('.qp-error');
    check('0x00 marker output shows error', (await page.locator('.qp-error').first().innerText()).includes('Nothing was uploaded'));
    check('0x00 marker output uploads nothing', newFiles().length === beforeZero);
    await page.close();

    // ---- Disabled while processing
    page = await openTab(ctx);
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'big.jpg'));
    const disabledDuring = await page.evaluate(() => Array.from(document.querySelectorAll('.qp-file')).every((i) => i.disabled));
    await page.waitForSelector('.qp-sheet');
    const disabledSheet = await page.evaluate(() => Array.from(document.querySelectorAll('.qp-file')).every((i) => i.disabled));
    check('inputs disabled while processing', disabledDuring);
    check('inputs disabled while sheet open', disabledSheet);
    await page.click('.qp-sheet .qp-cancel');
    check('inputs enabled after cancel', await page.evaluate(() => Array.from(document.querySelectorAll('.qp-file')).every((i) => !i.disabled)));
    await page.close();


    // ---- Upload failures keep the sheet open
    const isAdd = (u) => /op=addPhoto/.test(u.toString());
    const failCases = [
      ['500', (r) => r.fulfill({ status: 500, contentType: 'text/html', body: 'boom' }), /server error 500/],
      ['network failure', (r) => r.abort(), /network error/],
      ['401', (r) => r.fulfill({ status: 401, contentType: 'text/html', body: 'no' }), /no longer logged in/],
      ['413', (r) => r.fulfill({ status: 413, contentType: 'text/html', body: 'big' }), /too large for the server/],
    ];
    for (const [label, handler, re] of failCases) {
      const n0 = newFiles().length;
      page = await openTab(ctx);
      await page.route(isAdd, handler);
      await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
      await page.waitForSelector('.qp-sheet');
      await page.click('.qp-sheet .qp-save');
      await page.waitForFunction(() => /\S/.test(document.querySelector('.qp-sheet-status').textContent) &&
        !/Uploading/.test(document.querySelector('.qp-sheet-status').textContent));
      const st = await page.locator('.qp-sheet-status').innerText();
      check(label + ': sheet stays open with message', re.test(st) && await page.locator('.qp-sheet').count() === 1, st);
      check(label + ': Save and Cancel re-enabled',
        await page.locator('.qp-sheet .qp-save').isEnabled() && await page.locator('.qp-sheet .qp-cancel').isEnabled());
      check(label + ': nothing saved', newFiles().length === n0);
      await page.close();
    }

    // ---- Exception while building the sheet is recovered
    page = await openTab(ctx);
    await page.evaluate(() => {
      const orig = document.body.appendChild;
      document.body.appendChild = function (n) {
        if (n && n.classList && n.classList.contains('qp-overlay')) {
          document.body.appendChild = orig;
          throw new Error('forced');
        }
        return orig.call(this, n);
      };
    });
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
    await page.waitForSelector('.qp-error');
    check('openSheet failure shows error', /Could not prepare/.test(await page.locator('.qp-error').first().innerText()));
    check('openSheet failure re-enables inputs',
      await page.evaluate(() => Array.from(document.querySelectorAll('.qp-file')).every((i) => !i.disabled)));
    check('openSheet failure leaves no overlay', await page.locator('.qp-overlay').count() === 0);
    await page.close();

    // ---- Retake on a shared photo (created by this test)
    const mine = newFiles()[0];
    sql('INSERT INTO FileLink (file_id, entity_type, entity_id) VALUES (' + mine + ", 'rack', " + OTHER_RACK + ')');
    const oldSize = Number(sql('SELECT size FROM File WHERE id=' + mine));
    page = await openTab(ctx);
    const form = page.locator('form.qp-replace').filter({ has: page.locator('input[name=file_id][value="' + mine + '"]') });
    await form.locator('input[type=file]').setInputFiles(path.join(FX, 'small.jpg'));
    await page.waitForSelector('.qp-sheet');
    const warn = await page.locator('.qp-sheet .qp-warning').innerText();
    check('retake sheet shows shared warning', warn.includes('also used in'), warn);
    check('retake sheet has no Name field', await page.locator('.qp-sheet .qp-sheet-name').count() === 0);
    check('retake sheet has no comment field', await page.locator('.qp-sheet .qp-sheet-comment').count() === 0);
    check('retake save label is Replace photo', (await page.locator('.qp-sheet .qp-save').innerText()) === 'Replace photo');
    await page.click('.qp-sheet .qp-save');
    await page.waitForSelector('text=Photo replaced');
    check('replace success message', true);
    const newSize = Number(sql('SELECT size FROM File WHERE id=' + mine));
    check('same id, new size', newSize !== oldSize && newFiles()[0] === mine, oldSize + ' -> ' + newSize);
    check('replaced is 2000x1500 no EXIF', phpInfo(fileBytes(mine)) === '2000x1500 exif=0');
    await page.close();

    // ---- Custom name: prefilled suggestion, edited, stored
    page = await openTab(ctx);
    const sugg = await page.getAttribute('form.qp-add', 'data-name-suggestion');
    check('form has name suggestion', /^A-01_\d{4}-\d\d-\d\d$/.test(sugg || ''), sugg);
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
    await page.waitForSelector('.qp-sheet');
    check('Name field is labelled', (await page.locator('.qp-sheet label', { hasText: 'Name' }).count()) === 1);
    check('Name field prefilled with suggestion', (await page.inputValue('.qp-sheet .qp-sheet-name')) === sugg);
    check('Name field maxlength 100', (await page.getAttribute('.qp-sheet .qp-sheet-name', 'maxlength')) === '100');
    const nBefore = newFiles().length;
    await page.fill('.qp-sheet .qp-sheet-name', 'E2E pohled zep\u0159edu');
    await page.click('.qp-sheet .qp-save');
    await page.waitForSelector('text=Photo added');
    ids = newFiles();
    check('named photo added', ids.length === nBefore + 1, ids.join(','));
    if (ids.length === nBefore + 1) {
      check('stored under edited name',
        sql('SELECT HEX(name) FROM File WHERE id=' + ids[ids.length - 1]) === Buffer.from('E2E pohled zep\u0159edu.jpg', 'utf8').toString('hex').toUpperCase());
    }
    await page.close();

    // ---- Default suggestion saves as <label>_<date>.jpg
    page = await openTab(ctx);
    const sugg2 = await page.getAttribute('form.qp-add', 'data-name-suggestion');
    await page.setInputFiles('form.qp-add input[type=file]', path.join(FX, 'small.jpg'));
    await page.waitForSelector('.qp-sheet');
    await page.click('.qp-sheet .qp-save');
    await page.waitForSelector('text=Photo added');
    ids = newFiles();
    const storedName = sql('SELECT name FROM File WHERE id=' + ids[ids.length - 1]);
    const escSugg = sugg2.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    check('suggested name stored exactly', new RegExp('^' + escSugg + '(_\\d+)?\\.jpg$').test(storedName), storedName + ' vs ' + sugg2);
    await page.close();
  } finally {
    await browser.close();
    sql('DELETE FROM FileLink WHERE file_id>' + baseline);
    sql('DELETE FROM File WHERE id>' + baseline);
    const left = sql('SELECT COUNT(*) FROM File WHERE id>' + baseline);
    check('cleanup left no test files', left === '0', left);
  }
  const failed = results.filter((r) => !r).length;
  console.log(results.length - failed + ' passed, ' + failed + ' failed');
  process.exit(failed ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
