'use strict';

require('should');
const fs = require('fs');
const path = require('path');

// Guards the translation catalogs the project actively maintains (zh_CN is the
// runtime default since lib/settings.js; en is the fallback source language).
// Other locales are Crowdin-managed upstream and may legitimately lag behind,
// so parity is only asserted for zh_CN - lt_LT parity has its own test in
// language.test.js.
const enPath = path.join(__dirname, '../translations/en/en.json');
const zhPath = path.join(__dirname, '../translations/zh_CN.json');

describe('translation consistency', function () {
  let en;
  let zh;

  before(function loadCatalogs () {
    en = JSON.parse(fs.readFileSync(enPath, 'utf8'));
    zh = JSON.parse(fs.readFileSync(zhPath, 'utf8'));
  });

  it('zh_CN covers every key of the English catalog', function () {
    const missing = Object.keys(en).filter(key => !(key in zh));
    missing.should.deepEqual([], 'keys missing from zh_CN.json: ' + JSON.stringify(missing.slice(0, 20)));
  });

  it('zh_CN has no keys absent from the English catalog', function () {
    const extra = Object.keys(zh).filter(key => !(key in en));
    extra.should.deepEqual([], 'stale keys only in zh_CN.json: ' + JSON.stringify(extra.slice(0, 20)));
  });

  it('zh_CN values are non-empty strings', function () {
    const empty = Object.keys(zh).filter(key => typeof zh[key] !== 'string' || zh[key].trim().length === 0);
    empty.should.deepEqual([], 'empty or non-string values: ' + JSON.stringify(empty.slice(0, 20)));
  });

  it('zh_CN preserves the placeholders of the English source', function () {
    const placeholder = /%\d+|\{\d+\}/g;
    const broken = Object.keys(en).filter(key => {
      if (!(key in zh)) return false;
      const enOnes = (en[key].match(placeholder) || []).sort();
      const zhOnes = (zh[key].match(placeholder) || []).sort();
      return JSON.stringify(enOnes) !== JSON.stringify(zhOnes);
    });
    broken.should.deepEqual([], 'placeholder mismatch on: ' + JSON.stringify(broken.slice(0, 20)));
  });
});
